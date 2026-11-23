"""Apply reviewed corrections inside RC5's temporary candidate, never to production."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent


def checked(root, relative):
    path = root / relative
    if Path(relative).is_absolute() or '..' in Path(relative).parts or path.resolve() != path:
        raise ValueError('Unsafe review path: ' + relative)
    return path


def verify(path, expected):
    if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != expected:
        raise ValueError('Review input hash mismatch: ' + str(path))


def apply(site):
    site = site.resolve()
    manifest = json.loads((HERE / 'manifest.json').read_bytes())
    patch = HERE / 'runtime.patch'
    verify(patch, manifest['patch_sha256'])
    # Validate the entire input before changing even one file.
    for name, hashes in manifest['patched_files'].items():
        verify(checked(site, name), hashes['before'])
    for name, entry in manifest['additional_files'].items():
        if checked(site, name).exists():
            raise ValueError('Review addition already exists: ' + name)
        verify(checked(REPO, entry['source']), entry['sha256'])
    subprocess.run(['git', 'apply', '--check', str(patch)], cwd=site, check=True)
    subprocess.run(['git', 'apply', str(patch)], cwd=site, check=True)
    for name, entry in manifest['additional_files'].items():
        target = checked(site, name)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(REPO / entry['source'], target)
    for name, hashes in manifest['patched_files'].items():
        verify(site / name, hashes['after'])
    return {'review_patched': len(manifest['patched_files']), 'review_added': len(manifest['additional_files'])}

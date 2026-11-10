"""Extract the pinned, private Stage 2–10 reference data for the two Laravel QA tests."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import tempfile
import zipfile

EVIDENCE_SHA = "e67756051e8422fc5acb4c79d4586251d6142cc9c5f128cc29eb0523fe4c3fc6"
BANKS_SHA = "9f1f4ff4d23e10dd3ffb7572d8014b08f7a09f2215d73dfce81453356423ee9b"
SECTIONS = ("analysis_context", "foundation_plan", "recommendations_plan", "activities_plan",
            "closing_sections_plan", "semantic_brief", "input_manifest")


def verified_archive(path, expected):
    if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
        raise ValueError(f"Unexpected archive checksum: {path.name}")
    return zipfile.ZipFile(path)


def prepare(evidence, banks, dest):
    dest = dest.resolve()
    if dest.exists() or any((p / ".git").exists() for p in dest.parents):
        raise ValueError("Choose a new private directory outside the repository.")
    if any(dest.is_relative_to(p) for p in ("/opt", "/srv", "/var/www")):
        raise ValueError("Choose a private QA directory outside live applications.")
    with tempfile.TemporaryDirectory(dir=dest.parent) as temp:
        out = Path(temp) / "inputs"
        (out / "review").mkdir(parents=True)
        (out / "banks").mkdir()
        with verified_archive(evidence, EVIDENCE_SHA) as archive:
            paths = {n.replace('\\', '/'): n for n in archive.namelist()}
            for section in SECTIONS:
                name = section + ".json"
                (out / "review" / name).write_bytes(archive.read(paths["evidence/" + name]))
        with verified_archive(banks, BANKS_SHA) as archive:
            files = json.loads(archive.read("replay_input.json"))["files"]
            manifest = json.loads(archive.read("offline_reference/INPUT_REQUIREMENTS.json"))["required_files"]
            for name, entry in manifest.items():
                if not name.startswith("banks/"):
                    continue
                bank = Path(name)
                if bank.parent != Path("banks") or bank.suffix != ".json":
                    raise ValueError("Invalid bank filename")
                content = base64.b64decode(files[name]["base64"], validate=True)
                digest = hashlib.sha256(content).hexdigest()
                if digest != files[name]["sha256"] or (entry["sha256"] is not None and digest != entry["sha256"]):
                    raise ValueError(f"Bank bytes differ: {bank.name}")
                (out / bank).write_bytes(content)
        out.rename(dest)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    for arg in ("evidence_zip", "banks_zip", "new_private_directory"):
        parser.add_argument(arg, type=Path)
    opts = parser.parse_args()
    os.umask(0o077)
    prepare(opts.evidence_zip, opts.banks_zip, opts.new_private_directory)

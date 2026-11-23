#!/usr/bin/env python3
"""Build the tested RC1 integration candidate in a new private directory."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

from prepare_qa import HERE, build, check_file, private_output


def assemble(sources, output):
    output = private_output(sources, output)
    output.mkdir(mode=0o700, exist_ok=False)
    try:
        with tempfile.TemporaryDirectory(dir=output) as temporary:
            scratch = Path(temporary)
            stats = build(sources, scratch / "verified")
            source = scratch / "verified/source"
            site = source / "site"
            stage = scratch / "rc1"
            builder = HERE.parent / "gardner-rc1-20261109/builder/stage_candidate.py"
            subprocess.run([sys.executable, "-B", str(builder), "--stage", "--site", str(site),
                            "--r27", str(source / "opt/dz-gardner-r27-live"), "--out", str(stage)], check=True)
            changes = json.loads((stage / "manifest.json").read_bytes())
            for group in ("base_files", "new_files"):
                for entry in changes[group]:
                    name = entry["relative_path"]
                    target = source / name if name.startswith("opt/") else site / name
                    if group == "base_files":
                        check_file(target, entry["source_sha256"])
                    elif target.exists():
                        raise ValueError(f"Addition already exists: {name}")
                    payload = stage / "candidate" / name
                    check_file(payload, entry.get("result_sha256") or entry["sha256"])
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(payload, target)
            extra = json.loads((HERE / "candidate-files.json").read_bytes())
            for name, sha in extra["additional_files"].items():
                payload = HERE / "overlay" / name
                target = site / name
                check_file(payload, sha)
                if target.exists():
                    raise ValueError(f"Addition already exists: {name}")
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(payload, target)
            for name, hashes in extra["patched_files"].items():
                check_file(site / name, hashes["before"])
            patch = str(HERE / "candidate.patch")
            subprocess.run(["git", "apply", "--check", patch], cwd=site, check=True)
            subprocess.run(["git", "apply", patch], cwd=site, check=True)
            for name, hashes in extra["patched_files"].items():
                check_file(site / name, hashes["after"])
            source.rename(output / "source")
        stats.update(rc1_changed=len(changes["base_files"]), rc1_added=len(changes["new_files"]),
                     restored_files=len(extra["additional_files"]), patched_files=len(extra["patched_files"]))
        return stats
    except BaseException:
        shutil.rmtree(output)
        raise


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source_directory", type=Path)
    parser.add_argument("new_private_directory", type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    print(json.dumps(assemble(args.source_directory, args.new_private_directory), sort_keys=True))

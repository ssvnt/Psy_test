#!/usr/bin/env python3
"""Reconstruct the pinned, private Laravel/Vue + orchestrator QA source tree."""
import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import tarfile
import tempfile


HERE = Path(__file__).resolve().parent
EVIDENCE = HERE / "evidence"
MANIFEST = "DZ_SOURCE_MANIFEST_20261109.json"
MANIFEST_SHA256 = "4d622009d79171729d36b849c1cf7f395f08af62e8e2219afa54b2778f02d142"
ARCHIVES = {
    "site": ("DZ_SITE_SOURCE_20261108T225027Z.tar.gz", "f9ada8dc23910aefdb8c2b6f3a9aa279dc8767b01f3447a272df3c9a1c476672"),
    "opt": ("DZ_ORCHESTRATOR_SOURCE_20261108T225027Z.tar.gz", "b5f5be1ab9e6a441e262b244336bcee4b1917447dd4b1d8cb58999b2724712f5"),
}
REVIEW_SHA256 = {
    "DZ_PRIVATE_SOURCE_TRIAGE_RC5_20261110.zip": "25e495d932bd0f9236449b03a85ef41e06e3c1ca92e82f9cf1ae41fcd555b26d",
    "PRIVATE_STAGE_RISK_REVIEW(1).json": "da186434074cac4d7d051430e6887fd051005ef811333b2e0ef20fef505f8eff",
    "DZ_SOURCE_TRIAGE_REDACTED.json": "c039f5f1c06a74b9ae08ffd9bd4379fbeb98d2ddaa5e1058b58f306e257e2078",
}


def check_file(path, expected):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for part in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(part)
    if digest.hexdigest() != expected:
        raise ValueError(f"Checksum mismatch: {path.name}")


def build(sources, output):
    for name, expected in REVIEW_SHA256.items():
        check_file(EVIDENCE / name, expected)
    check_file(sources / MANIFEST, MANIFEST_SHA256)
    for name, expected in ARCHIVES.values():
        check_file(sources / name, expected)

    records = json.loads((sources / MANIFEST).read_bytes())["files"]
    manifest = {row["path"]: row for row in records}
    if len(records) != 1800 or len(manifest) != len(records):
        raise ValueError("Unexpected source inventory")

    private = json.loads((EVIDENCE / "PRIVATE_STAGE_RISK_REVIEW(1).json").read_bytes())
    triage = json.loads((EVIDENCE / "DZ_SOURCE_TRIAGE_REDACTED.json").read_bytes())
    flags = {row["path"]: (row["sha256"], sorted(row["codes"])) for row in private["files_requiring_manual_review"]}
    checked = {row["path"]: (row["sha256"], sorted(row["original_audit_flags"])) for row in triage["flagged_files"]}
    if len(flags) != 348 or flags != checked or any(manifest[p]["sha256"] != digest for p, (digest, _) in flags.items()):
        raise ValueError("Risk reports disagree with source inventory")

    output = output.resolve()
    protected = (HERE.parents[1], sources.resolve(), Path("/opt"), Path("/srv"), Path("/var/www"))
    if any(output.is_relative_to(path) for path in protected) or any((p / ".git").exists() for p in output.parents):
        raise ValueError("Choose a new private directory outside Git worktrees, inputs and live applications")
    output.mkdir(mode=0o700, exist_ok=False)
    try:
        seen = set()
        copied = Counter()
        with tempfile.TemporaryDirectory(dir=output) as temporary:
            tree = Path(temporary) / "source"
            for prefix, (name, _) in ARCHIVES.items():
                with tarfile.open(sources / name, "r:gz") as archive:
                    for entry in archive:
                        path = PurePosixPath(entry.name)
                        if not entry.isfile() or path.is_absolute() or ".." in path.parts or path.parts[0] != prefix:
                            raise ValueError("Unexpected archive entry")
                        row = manifest.get(entry.name)
                        if entry.name in seen or row is None or entry.size != row["size"]:
                            raise ValueError("Archive and manifest disagree")
                        seen.add(entry.name)
                        data = archive.extractfile(entry).read()
                        if hashlib.sha256(data).hexdigest() != row["sha256"]:
                            raise ValueError("Source content mismatch")
                        if len(path.parts) > 2 and path.parts[:2] == ("site", "public") and (path.parts[2] == "build" or path.parts[2].startswith(".dz-")):
                            continue
                        dest = tree / path
                        dest.parent.mkdir(parents=True, exist_ok=True)
                        with dest.open("xb") as stream:
                            stream.write(data)
                        os.chmod(dest, 0o700 if entry.mode & 0o111 else 0o600)
                        copied[prefix] += 1
            if seen != manifest.keys() or copied != {"site": 1196, "opt": 70}:
                raise ValueError("Incomplete snapshot")
            tree.rename(output / "source")
    except BaseException:
        shutil.rmtree(output)
        raise
    return {"verified": len(seen), "site": copied["site"], "orchestrators": copied["opt"],
            "generated_omitted": len(seen) - sum(copied.values()), "risk_rows_verified": len(flags)}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source_directory", type=Path)
    parser.add_argument("new_private_directory", type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    print(json.dumps(build(args.source_directory, args.new_private_directory), sort_keys=True))

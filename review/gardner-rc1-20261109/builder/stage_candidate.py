#!/usr/bin/env python3
"""Fail-closed RC1 patch application to a PRIVATE STAGING DIRECTORY ONLY.

No action in this program writes into /var/www/bribon or /opt/dz-gardner-r27-live.
It does not access DB, bank, LLM, SMTP, MAX, cache, queue or systemd control.
"""
from __future__ import annotations
import argparse
import difflib
import hashlib
import json
import os
import pathlib
import py_compile
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

from spec import TARGETS

HERE = pathlib.Path(__file__).resolve().parent.parent
DEFAULT_SITE = pathlib.Path('/var/www/bribon')
DEFAULT_R27 = pathlib.Path('/opt/dz-gardner-r27-live')
DEFAULT_OUT = pathlib.Path('/root/dz-gardner-rc1-staged-20261109')
RC_ID = 'DZ_GARDNER_RC1_20261109'


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def event(name: str, **kw):
    print(json.dumps({'event': name, **kw}, ensure_ascii=False, sort_keys=True), flush=True)


def path_for(relative: str, site: pathlib.Path, r27: pathlib.Path) -> pathlib.Path:
    if relative.startswith('opt/dz-gardner-r27-live/'):
        root = r27
        tail = relative[len('opt/dz-gardner-r27-live/'):]
    else:
        root = site
        tail = relative
    # Anchored relative paths only. Reject parent traversal and symlinks.
    rel = pathlib.PurePosixPath(tail)
    if not tail or rel.is_absolute() or '..' in rel.parts:
        raise RuntimeError('INVALID_SOURCE_PATH')
    result = root / tail
    if any(p.is_symlink() for p in [result, *list(result.parents)][:len(rel.parts)]):
        raise RuntimeError('SOURCE_SYMLINK_BLOCKED')
    if not result.is_file():
        raise RuntimeError('SOURCE_MISSING')
    return result


def apply_one(src: str, ops, relative: str) -> tuple[str,list[dict]]:
    current = src
    changes = []
    for change in ops:
        occurrences = current.count(change.before)
        if occurrences != change.count:
            # We never force a near-match or match by approximate line number.
            raise RuntimeError('ANCHOR_MISMATCH:'+relative+':'+change.label+':'+str(occurrences))
        current = current.replace(change.before, change.after, change.count)
        changes.append({'id':change.label,'occurrences':occurrences})
    if current == src: raise RuntimeError('PATCH_NOOP:'+relative)
    return current,changes


def prepare(site: pathlib.Path, r27: pathlib.Path):
    source_records=[]
    for item in TARGETS:
        src_path = path_for(item.relative,site,r27)
        data=src_path.read_bytes()
        actual=sha(data)
        if actual != item.sha256:
            raise RuntimeError('BASELINE_SHA256_MISMATCH:'+item.relative+':sha_changed')
        try: original=data.decode('utf-8')
        except UnicodeDecodeError as exc: raise RuntimeError('BASELINE_NOT_UTF8:'+item.relative) from exc
        updated,changes=apply_one(original,item.ops,item.relative)
        source_records.append({'relative_path':item.relative,'source_file':src_path,'before_bytes':data,
            'after_bytes':updated.encode('utf-8'),'source_sha256':actual,'result_sha256':sha(updated.encode('utf-8')),
            'changes':changes})
    return source_records


def ensure_outside_live(out: pathlib.Path,site: pathlib.Path,r27: pathlib.Path):
    resolved=out.resolve()
    for forbidden in (site.resolve(),r27.resolve()):
        if resolved==forbidden or forbidden in resolved.parents:
            raise RuntimeError('STAGING_MUST_NOT_BE_INSIDE_LIVE_PROJECT')
    if resolved==pathlib.Path('/') or len(resolved.parts)<3:raise RuntimeError('UNSAFE_STAGING_PATH')
    if out.exists():raise RuntimeError('STAGING_DESTINATION_EXISTS_NO_OVERWRITE')
    if out.is_symlink():raise RuntimeError('STAGING_SYMLINK_BLOCKED')


def check_added_files(base: pathlib.Path):
    root=HERE/'additions'
    items=[]
    for f in sorted(root.rglob('*')):
        if not f.is_file():continue
        if f.is_symlink():raise RuntimeError('ADDITION_SYMLINK')
        rel=f.relative_to(root).as_posix()
        if rel.startswith(('.env','storage/','public/')):raise RuntimeError('UNSAFE_ADDITION')
        dest=base/rel
        if dest.exists():raise RuntimeError('ADDITION_WOULD_REPLACE_EXISTING:'+rel)
        items.append({'relative_path':rel,'source_file':f,'bytes':f.read_bytes(),'sha256':sha(f.read_bytes())})
    if len(items)!=4:raise RuntimeError('EXPECTED_FOUR_NEW_FILES')
    return items


def lint(path: pathlib.Path):
    if path.suffix=='.php' and not path.name.endswith('.blade.php'):
        cp=subprocess.run(['php','-l',str(path)],capture_output=True,text=True,timeout=15)
        if cp.returncode!=0:raise RuntimeError('PHP_LINT_FAIL:'+path.name)
    elif path.suffix=='.py':
        py_compile.compile(str(path), doraise=True, cfile=str(path)+'.lint-pyc')
        pathlib.Path(str(path)+'.lint-pyc').unlink(missing_ok=True)


def write_stage(out: pathlib.Path, records, additions):
    out.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.dz-gardner-rc1-',dir=out.parent) as tmp:
        work=pathlib.Path(tmp)
        os.chmod(work,0o700)
        manifest={'schema':'dz-gardner-rc1-staged-v1','release_id':RC_ID,
            'created_utc':datetime.now(timezone.utc).isoformat(),
            'live_written':False,'payment_calls':0,'model_calls':0,'smtp_calls':0,'max_calls':0,
            'base_files':[],'new_files':[]}
        diff=[]
        for rec in records:
            rel=rec['relative_path']
            base=work/'baseline'/rel
            target=work/'candidate'/rel
            base.parent.mkdir(parents=True,exist_ok=True)
            target.parent.mkdir(parents=True,exist_ok=True)
            base.write_bytes(rec['before_bytes'])
            target.write_bytes(rec['after_bytes'])
            lint(target)
            diff+=list(difflib.unified_diff(rec['before_bytes'].decode().splitlines(keepends=True),
                rec['after_bytes'].decode().splitlines(keepends=True),
                fromfile='a/'+rel,tofile='b/'+rel))
            manifest['base_files'].append({key:rec[key] for key in ('relative_path','source_sha256','result_sha256','changes')})
        for rec in additions:
            rel=rec['relative_path'];t=work/'candidate'/rel
            t.parent.mkdir(parents=True,exist_ok=True)
            t.write_bytes(rec['bytes']);lint(t)
            manifest['new_files'].append({'relative_path':rel,'sha256':rec['sha256']})
            diff+=list(difflib.unified_diff([],rec['bytes'].decode().splitlines(keepends=True),
                fromfile='/dev/null',tofile='b/'+rel))
        (work/'RC1_REVIEW_ONLY.patch').write_text(''.join(diff),encoding='utf-8')
        (work/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
        (work/'DO_NOT_DEPLOY_AUTOMATICALLY.txt').write_text(
            'REVIEW ONLY. Candidate was generated from exact pinned source hashes. '
            'No live files were changed. Run isolated Laravel tests, Vite build, staging E2E, '
            'review secret scan and explicit rollback rehearsal before any deployment.\n')
        for p in work.rglob('*'):
            if p.is_file():os.chmod(p,0o600)
            elif p.is_dir():os.chmod(p,0o700)
        os.rename(work,out)
        event('DZ_RC1_STAGED',base_files=len(records),new_files=len(additions),
              live_writes=0,model_calls=0,bank_calls=0,mail_sends=0,max_sends=0,
              staging=str(out),manifest_sha256=sha((out/'manifest.json').read_bytes()))


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--check',action='store_true',help='Read source + verify exact SHA/anchors. NO writes anywhere.')
    parser.add_argument('--stage',action='store_true',help='Write ONLY private candidate and diff outside live paths.')
    parser.add_argument('--site',type=pathlib.Path,default=DEFAULT_SITE)
    parser.add_argument('--r27',type=pathlib.Path,default=DEFAULT_R27)
    parser.add_argument('--out',type=pathlib.Path,default=DEFAULT_OUT)
    args=parser.parse_args()
    if args.check==args.stage: parser.error('Select exactly one of --check or --stage')
    try:
        records=prepare(args.site,args.r27)
        additions=check_added_files(args.site)
        event('DZ_RC1_PREFLIGHT_PASS',baseline_files=len(records),additions=len(additions),
              all_shas_match=True,all_anchors_match=True,live_writes=0)
        if args.stage:
            ensure_outside_live(args.out,args.site,args.r27)
            write_stage(args.out,records,additions)
    except Exception as e:
        # Never print potentially private exception body or source to stdout.
        reason=str(e)
        category=reason.split(':',1)[0]
        if category not in {'BASELINE_SHA256_MISMATCH','ANCHOR_MISMATCH','SOURCE_MISSING',
                            'SOURCE_SYMLINK_BLOCKED','INVALID_SOURCE_PATH','BASELINE_NOT_UTF8',
                            'PATCH_NOOP','STAGING_MUST_NOT_BE_INSIDE_LIVE_PROJECT',
                            'STAGING_DESTINATION_EXISTS_NO_OVERWRITE','STAGING_SYMLINK_BLOCKED',
                            'ADDITION_WOULD_REPLACE_EXISTING','ADDITION_SYMLINK','UNSAFE_ADDITION',
                            'EXPECTED_FOUR_NEW_FILES','PHP_LINT_FAIL'}:
            category='CANDIDATE_PRECHECK_FAILED'
        event('DZ_RC1_BLOCKED',code=category,live_writes=0,model_calls=0,payment_calls=0)
        return 2
    return 0


if __name__=='__main__':sys.exit(main())

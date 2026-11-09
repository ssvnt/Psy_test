#!/usr/bin/env python3
"""Privacy-preserving read-only summary of an already generated Gardner quality.json.

Never prints field content, original answers, prompts, person info, keys or raw errors.
Never calls a model or changes a checkpoint / QA result.
"""
import argparse
import json
import pathlib
import re
import sys
from collections import Counter

SAFE_CODE = re.compile(r'^[A-Z][A-Z0-9_]{1,70}$')
SAFE_FIELD = re.compile(r'^expert_[0-9]{4}$')


def inspect(path: pathlib.Path) -> dict:
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 8*1024*1024:
        raise ValueError('QUALITY_FILE_MISSING_OR_UNSAFE')
    data = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(data, dict):raise ValueError('QUALITY_NOT_OBJECT')
    fields=data.get('fields',{})
    if not isinstance(fields, dict):raise ValueError('QUALITY_FIELDS_NOT_OBJECT')
    stats=Counter();fails=0;known=0
    for key,obj in fields.items():
        if not isinstance(key,str) or not SAFE_FIELD.fullmatch(key):
            # Non-expert probe/check fields are ignored; never print their names.
            continue
        known+=1
        if not isinstance(obj,dict):
            fails+=1;stats['FIELD_MALFORMED']+=1;continue
        good=obj.get('status')=='PASS' or obj.get('ok') is True
        if good:continue
        fails+=1
        code=obj.get('code') or obj.get('reason_code') or 'QUALITY_REVIEW_REQUIRED'
        stats[code if isinstance(code,str) and SAFE_CODE.fullmatch(code) else 'QUALITY_REVIEW_REQUIRED']+=1
    status=data.get('status')
    return {'event':'GARDNER_QUALITY_READONLY','schema':'dz-quality-review-summary-v1',
        'status':status if status in ('PASS','FAIL') else 'UNRECOGNIZED',
        'expert_fields_count':known,'expert_fields_needing_review':fails,
        'class_counts':dict(sorted(stats.items())),'text_exported':False,
        'model_calls':0,'ledger_writes':0,'publication_writes':0}


def main():
    p=argparse.ArgumentParser()
    p.add_argument('--quality',type=pathlib.Path,required=True)
    args=p.parse_args()
    try:result=inspect(args.quality)
    except (ValueError,OSError,json.JSONDecodeError):
        result={'event':'GARDNER_QUALITY_READONLY_BLOCKED','code':'INVALID_OR_UNAVAILABLE_QUALITY_FILE',
            'text_exported':False,'model_calls':0};print(json.dumps(result));return 2
    print(json.dumps(result,ensure_ascii=False,sort_keys=True));return 0

if __name__=='__main__':sys.exit(main())

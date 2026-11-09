#!/usr/bin/env python3
"""Privacy-preserving read-only QA metadata inspection, no model calls.

Never logs source text, child profile, email, requests, tokens or raw errors.
"""
import argparse,json,pathlib,re,sys,collections
CODE=re.compile('^[A-Z][A-Z0-9_]{1,79}$')
FIELD=re.compile('^expert_[0-9]{4}$')

def main():
    p=argparse.ArgumentParser();p.add_argument('--file',required=True,type=pathlib.Path)
    a=p.parse_args();f=a.file
    if not f.is_file() or f.is_symlink() or f.stat().st_size>8*1024*1024:
        print(json.dumps({'event':'QUALITY_DIAG_BLOCKED','code':'UNSAFE_OR_MISSING_FILE'}));return 2
    try:obj=json.loads(f.read_text('utf8'))
    except (OSError,UnicodeError,ValueError):
        print(json.dumps({'event':'QUALITY_DIAG_BLOCKED','code':'INVALID_JSON'}));return 2
    if not isinstance(obj,dict):return 2
    fields=obj.get('fields');stats=collections.Counter();invalid=0;failed=[]
    if isinstance(fields,dict):
        for key,val in fields.items():
            if not isinstance(key,str) or not FIELD.fullmatch(key):continue
            if isinstance(val,dict) and val.get('status')=='PASS':continue
            failed.append(key)
            if not isinstance(val,dict):invalid+=1;stats['INVALID_FIELD']+=1;continue
            codes=val.get('codes') or [val.get('code') or val.get('reason_code') or 'QUALITY_UNCLASSIFIED']
            if not isinstance(codes,list):codes=['QUALITY_UNCLASSIFIED']
            for code in codes:
                stats[code if isinstance(code,str) and CODE.fullmatch(code) else 'QUALITY_UNCLASSIFIED']+=1
    print(json.dumps({'event':'DZ_QA_READONLY_DIAG','status':obj.get('status') if obj.get('status') in ('PASS','FAIL') else 'UNKNOWN',
        'scanners_confirmed':obj.get('scanners_executed')==['humanizer-ru','pishi-normalno'],
        'field_count':len([x for x in fields if isinstance(x,str) and FIELD.fullmatch(x)]) if isinstance(fields,dict) else None,
        'failed_count':len(failed),'failed_field_ids':failed[:200],'issue_codes':dict(sorted(stats.items())),
        'invalid':invalid,'text_exported':False,'site_writes':0,'model_calls':0,'bank_calls':0},ensure_ascii=False,sort_keys=True))
    return 0
if __name__=='__main__':sys.exit(main())

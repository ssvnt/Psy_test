"""Read-only demonstration of classifier, QA plan and persistence, not a worker."""
from __future__ import annotations
import argparse,json,sys,tempfile
from pathlib import Path
from .quality import parse_quality
from .policy import classify,Evidence
from .store import Store

def main()->int:
    p=argparse.ArgumentParser()
    p.add_argument('--quality-json',type=Path,required=True)
    p.add_argument('--identity',default='1'*64)
    p.add_argument('--synthetic-chunks',type=int,default=61)
    args=p.parse_args()
    q=json.loads(args.quality_json.read_text())
    plan=parse_quality(q,args.identity)
    with tempfile.TemporaryDirectory(prefix='dz-rc2-offline-') as d:
        state=Store(Path(d)/'scratch.sqlite3')
        state.generation(args.identity,'2'*64,args.synthetic_chunks)
        for i in range(args.synthetic_chunks):state.chunk(args.identity,f'chunk_{i:04d}',f'{i+1:064x}')
        state.project_qa(plan)
        result=state.status(args.identity)
        state.close()
    result.update({'event':'DZ_RC2_SYNTHETIC_SIMULATION','privacy':'hashes_and_codes_only',
        'sample_stop':classify(Evidence('quality_rejected')).action.value,
        'generation_identity':'REDACTED','source_inputs':'SYNTHETIC','live_site_writes':0})
    result.pop('identity')
    print(json.dumps(result,ensure_ascii=False,sort_keys=True))
    return 0

if __name__=='__main__':sys.exit(main())

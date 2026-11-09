"""Durable, idempotent, private RC2 test state machine (SQLite, no network).

NEVER attach to production; the adapter to Laravel's SQL/queues is future work.
All stored content is limited to hashes, states and machine error codes.
"""
from __future__ import annotations
from dataclasses import dataclass
import json
import re
import sqlite3
import time
from pathlib import Path
from .quality import QaPlan, QaItem, SHA

class StateConflict(RuntimeError):pass

SCHEMA = '''
CREATE TABLE IF NOT EXISTS generation (
 identity TEXT PRIMARY KEY, source_sha TEXT NOT NULL, expected_chunks INTEGER NOT NULL,
 qa_state TEXT NOT NULL DEFAULT 'generation', full_qa_receipt TEXT);
CREATE TABLE IF NOT EXISTS chunks (
 identity TEXT NOT NULL, chunk TEXT NOT NULL, content_sha TEXT NOT NULL,
 PRIMARY KEY(identity,chunk));
CREATE TABLE IF NOT EXISTS tasks (
 task_id TEXT PRIMARY KEY, identity TEXT NOT NULL, field_name TEXT NOT NULL,
 source_sha TEXT NOT NULL, policy_sha TEXT NOT NULL, codes TEXT NOT NULL,
 state TEXT NOT NULL DEFAULT 'local_pending', local_attempts INTEGER NOT NULL DEFAULT 0,
 editor_attempts INTEGER NOT NULL DEFAULT 0, regen_attempts INTEGER NOT NULL DEFAULT 0,
 revision_sha TEXT, lease_until INTEGER NOT NULL DEFAULT 0, last_error TEXT,
 UNIQUE(identity,field_name,source_sha,policy_sha));
CREATE TABLE IF NOT EXISTS events (
 key TEXT PRIMARY KEY, identity TEXT NOT NULL, event TEXT NOT NULL,
 payload TEXT NOT NULL, transport_state TEXT NOT NULL DEFAULT 'pending', created_utc INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS attempts (
 request_id TEXT PRIMARY KEY, identity TEXT NOT NULL, task_id TEXT,
 actor TEXT NOT NULL CHECK(actor IN ('generation','editor','regen')),
 reserved_micro_rub INTEGER NOT NULL CHECK(reserved_micro_rub>=0),
 billing_state TEXT NOT NULL CHECK(billing_state IN
 ('reserved','completed','billing_unknown','proven_no_charge','provider_rejected')),
 evidence_sha TEXT, created_utc INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_tasks_state ON tasks(state,lease_until);
CREATE INDEX IF NOT EXISTS idx_events_state ON events(transport_state,created_utc);
'''

class Store:
    def __init__(self, path: Path):
        path=Path(path)
        if path.exists() and path.is_symlink():raise StateConflict('STATE_PATH_SYMLINK')
        path.parent.mkdir(parents=True,exist_ok=True)
        self.db=sqlite3.connect(str(path),isolation_level=None, timeout=10)
        self.db.execute('PRAGMA busy_timeout=10000')
        self.db.execute('PRAGMA journal_mode=WAL')
        self.db.execute('PRAGMA synchronous=FULL')
        self.db.executescript(SCHEMA)
    def close(self):self.db.close()
    def _begin(self):self.db.execute('BEGIN IMMEDIATE')
    def _commit(self):self.db.execute('COMMIT')
    def _rollback(self):self.db.execute('ROLLBACK')
    def transaction(self,fn):
        self._begin()
        try:r=fn();self._commit();return r
        except BaseException:self._rollback();raise
    def generation(self,identity:str,source_sha:str,expected_chunks:int):
        if not SHA.fullmatch(identity) or not SHA.fullmatch(source_sha) or not 1<=expected_chunks<=1000:
            raise StateConflict('GENERATION_BINDING_INVALID')
        def f():
            self.db.execute('INSERT OR IGNORE INTO generation(identity,source_sha,expected_chunks) VALUES(?,?,?)',
                            (identity,source_sha,expected_chunks))
            row=self.db.execute('SELECT source_sha,expected_chunks FROM generation WHERE identity=?',(identity,)).fetchone()
            if row!=(source_sha,expected_chunks):raise StateConflict('GENERATION_IDENTITY_CHANGED')
        self.transaction(f)
    def chunk(self,identity:str,chunk:str,content_sha:str):
        if not SHA.fullmatch(content_sha) or not re.fullmatch(r'[a-zA-Z0-9_-]{1,80}',chunk):raise StateConflict('CHUNK_INVALID')
        def f():
            if not self.db.execute('SELECT 1 FROM generation WHERE identity=?',(identity,)).fetchone():raise StateConflict('GENERATION_MISSING')
            self.db.execute('INSERT OR IGNORE INTO chunks VALUES(?,?,?)',(identity,chunk,content_sha))
            actual=self.db.execute('SELECT content_sha FROM chunks WHERE identity=? AND chunk=?',(identity,chunk)).fetchone()[0]
            if actual!=content_sha:raise StateConflict('ACCEPTED_CHUNK_IMMUTABLE')
        self.transaction(f)
    def event(self,identity:str,event:str,key:str,meta:dict|None=None):
        if not re.fullmatch(r'[A-Z][A-Z0-9_]{2,80}',event):raise StateConflict('EVENT_INVALID')
        if not re.fullmatch(r'[a-f0-9]{64}',key):raise StateConflict('EVENT_KEY_INVALID')
        # Exact allow-list; no child text, name, email or model output.
        meta=meta or {}
        if not isinstance(meta,dict) or any(k not in {'field_count','rejected_count','task_count','reason','code'} for k in meta):
            raise StateConflict('EVENT_PAYLOAD_NOT_ALLOWLISTED')
        payload=json.dumps(meta,ensure_ascii=False,sort_keys=True)
        def f():
            self.db.execute('INSERT OR IGNORE INTO events(key,identity,event,payload,created_utc) VALUES(?,?,?,?,?)',
                            (key,identity,event,payload,int(time.time())))
        self.transaction(f)
    def project_chunk_qa(self,plan:QaPlan,chunk_name:str,chunk_sha:str):
        """QA each saved chunk immediately, without waiting for other chunks.

        This is the nonblocking stage-18 contract. Only the QA projection is
        modified: provider and original checkpoint data are never mutated.
        """
        if not SHA.fullmatch(chunk_sha) or not re.fullmatch(r'[a-zA-Z0-9_-]{1,80}',chunk_name):
            raise StateConflict('CHUNK_QA_BINDING_INVALID')
        identity=plan.generation_identity
        def f():
            row=self.db.execute('SELECT content_sha FROM chunks WHERE identity=? AND chunk=?',
                                (identity,chunk_name)).fetchone()
            if row is None or row[0]!=chunk_sha:raise StateConflict('CHUNK_QA_BINDING_INVALID')
            for it in plan.tasks:
                existing=self.db.execute('SELECT source_sha,policy_sha,codes FROM tasks WHERE task_id=?',(it.task_id,)).fetchone()
                if existing is not None and existing!=(it.source_sha256,it.policy_sha256,json.dumps(it.issue_codes)):
                    raise StateConflict('TASK_BINDING_CHANGED')
                self.db.execute('INSERT OR IGNORE INTO tasks(task_id,identity,field_name,source_sha,policy_sha,codes) VALUES(?,?,?,?,?,?)',
                                (it.task_id,identity,it.field,it.source_sha256,it.policy_sha256,json.dumps(it.issue_codes)))
            state=self.db.execute('SELECT qa_state FROM generation WHERE identity=?',(identity,)).fetchone()
            if state is None:raise StateConflict('GENERATION_MISSING')
            if state[0]=='qa_pass' and plan.tasks:
                raise StateConflict('PASSED_QA_CANNOT_BE_REOPENED')
            if plan.tasks and state[0] not in ('qa_pass','qa_repair_pending'):
                self.db.execute('UPDATE generation SET qa_state=? WHERE identity=?',('qa_repair_pending',identity))
            event='QA_REPAIR_STARTED' if plan.tasks else 'QA_LANGUAGE_PASS'
            key=__import__('hashlib').sha256((identity+':chunk:'+chunk_name+':'+event+':'+plan.tasks[0].policy_sha256 if plan.tasks else identity+':chunk:'+chunk_name+':'+event).encode()).hexdigest()
            self.db.execute('INSERT OR IGNORE INTO events(key,identity,event,payload,created_utc) VALUES(?,?,?,?,?)',
                (key,identity,event,json.dumps({'field_count':plan.field_count,'rejected_count':plan.rejected_count}),int(time.time())))
        self.transaction(f)

    def project_qa(self,plan:QaPlan):
        g=plan.generation_identity
        def f():
            row=self.db.execute('SELECT expected_chunks,qa_state FROM generation WHERE identity=?',(g,)).fetchone()
            if not row:raise StateConflict('GENERATION_MISSING')
            count=self.db.execute('SELECT count(*) FROM chunks WHERE identity=?',(g,)).fetchone()[0]
            if count!=row[0]:raise StateConflict('CHUNKS_NOT_COMPLETE')
            if row[1] not in ('generation','qa_repair_pending','qa_pass'):
                raise StateConflict('QA_STATE_UNEXPECTED')
            for it in plan.tasks:
                self.db.execute('INSERT OR IGNORE INTO tasks(task_id,identity,field_name,source_sha,policy_sha,codes) VALUES(?,?,?,?,?,?)',
                                (it.task_id,g,it.field,it.source_sha256,it.policy_sha256,json.dumps(it.issue_codes)))
                saved=self.db.execute('SELECT source_sha,policy_sha,codes FROM tasks WHERE task_id=?',(it.task_id,)).fetchone()
                if saved!=(it.source_sha256,it.policy_sha256,json.dumps(it.issue_codes)):
                    raise StateConflict('TASK_BINDING_CHANGED')
            state='qa_pass' if not plan.tasks else 'qa_repair_pending'
            if row[1]=='qa_pass' and state!='qa_pass':raise StateConflict('PASSED_QA_CANNOT_BE_REOPENED')
            if state=='qa_pass':
                # This is *only* a scanner-level pass; publish remains prohibited
                # until explicit complete source and exact-artifact verification.
                self.db.execute('UPDATE generation SET qa_state=? WHERE identity=?',('qa_pass',g))
            elif row[1]=='generation':
                self.db.execute('UPDATE generation SET qa_state=? WHERE identity=?',(state,g))
            event='QA_REPAIR_STARTED' if plan.tasks else 'QA_LANGUAGE_PASS'
            event_key=__import__('hashlib').sha256((g+':'+event+':'+plan.tasks[0].policy_sha256 if plan.tasks else g+':'+event).encode()).hexdigest()
            self.db.execute('INSERT OR IGNORE INTO events(key,identity,event,payload,created_utc) VALUES(?,?,?,?,?)',
                (event_key,g,event,json.dumps({'field_count':plan.field_count,'rejected_count':plan.rejected_count}),int(time.time())))
        self.transaction(f)
    def claim_local(self,identity:str,*,lease_seconds:int=300,now:int|None=None)->dict|None:
        now=int(time.time()) if now is None else now
        if lease_seconds<1 or lease_seconds>3600:raise ValueError('LEASE_INVALID')
        def f():
            row=self.db.execute('SELECT task_id,field_name,source_sha,codes,local_attempts FROM tasks '
                'WHERE identity=? AND ((state="local_pending") OR (state="local_claimed" AND lease_until<=?)) '
                'ORDER BY field_name LIMIT 1',(identity,now)).fetchone()
            if row is None:return None
            if row[4]>=1:
                self.db.execute('UPDATE tasks SET state="editor_pending",lease_until=0 WHERE task_id=?',(row[0],))
                return None
            self.db.execute('UPDATE tasks SET state="local_claimed",local_attempts=local_attempts+1,lease_until=? WHERE task_id=?',
                            (now+lease_seconds,row[0]))
            return {'task_id':row[0],'field':row[1],'source_sha':row[2],'issues':json.loads(row[3])}
        return self.transaction(f)
    def local_result(self,task_id:str,revision_sha:str,*,scanner_pass:bool,guards_pass:bool):
        if not SHA.fullmatch(revision_sha):raise StateConflict('REVISION_SHA_INVALID')
        def f():
            row=self.db.execute('SELECT identity,state FROM tasks WHERE task_id=?',(task_id,)).fetchone()
            if row is None or row[1]!='local_claimed':raise StateConflict('TASK_NOT_CLAIMED')
            target='accepted' if scanner_pass and guards_pass else 'editor_pending'
            self.db.execute('UPDATE tasks SET state=?,lease_until=0,revision_sha=? WHERE task_id=?',
                (target,revision_sha,task_id))
            event='QA_REPAIR_COMPLETED' if target=='accepted' else 'QA_EDITOR_REQUIRED'
            ek=__import__('hashlib').sha256((task_id+':'+event).encode()).hexdigest()
            self.db.execute('INSERT OR IGNORE INTO events(key,identity,event,payload,created_utc) VALUES(?,?,?,?,?)',
                (ek,row[0],event,json.dumps({'task_count':1}),int(time.time())))
        self.transaction(f)
    def attention(self,task_id:str,reason:str):
        if not re.fullmatch('[A-Z][A-Z0-9_]{2,80}',reason):raise StateConflict('ATTENTION_CODE_INVALID')
        def f():
            row=self.db.execute('SELECT identity FROM tasks WHERE task_id=?',(task_id,)).fetchone()
            if not row:raise StateConflict('TASK_UNKNOWN')
            self.db.execute('UPDATE tasks SET state="owner_attention",last_error=?,lease_until=0 WHERE task_id=?',(reason,task_id))
            key=__import__('hashlib').sha256((task_id+':attention:'+reason).encode()).hexdigest()
            self.db.execute('INSERT OR IGNORE INTO events(key,identity,event,payload,created_utc) VALUES(?,?,?,?,?)',
                (key,row[0],'QA_OWNER_ATTENTION',json.dumps({'reason':reason}),int(time.time())))
        self.transaction(f)
    def reserve_paid_attempt(self,identity:str,request_id:str,task_id:str|None,actor:str,cost_micro:int,cap_micro:int):
        if not SHA.fullmatch(request_id) or actor not in ('generation','editor','regen') or cost_micro<0 or cap_micro<0:
            raise StateConflict('BUDGET_ARGUMENT_INVALID')
        def f():
            if not self.db.execute('SELECT 1 FROM generation WHERE identity=?',(identity,)).fetchone():raise StateConflict('GENERATION_MISSING')
            existing=self.db.execute('SELECT identity,task_id,actor,reserved_micro_rub FROM attempts WHERE request_id=?',(request_id,)).fetchone()
            if existing:
                if existing!=(identity,task_id,actor,cost_micro):raise StateConflict('REQUEST_REUSE_CHANGED')
                return False
            # Conservatively reserve unknown/pending/completed; no automatic reset.
            spent=self.db.execute("SELECT COALESCE(SUM(reserved_micro_rub),0) FROM attempts WHERE identity=? AND billing_state != 'proven_no_charge'",(identity,)).fetchone()[0]
            if spent+cost_micro>cap_micro:raise StateConflict('BUDGET_EXHAUSTED_NO_AUTO_INCREASE')
            self.db.execute('INSERT INTO attempts(request_id,identity,task_id,actor,reserved_micro_rub,billing_state,created_utc) VALUES(?,?,?,?,?,?,?)',
                            (request_id,identity,task_id,actor,cost_micro,'reserved',int(time.time())))
            return True
        return self.transaction(f)
    def billing_unknown(self,request_id:str):
        self.db.execute("UPDATE attempts SET billing_state='billing_unknown' WHERE request_id=? AND billing_state='reserved'",(request_id,))
    def billing_proven_no_charge(self,request_id:str,evidence_sha:str):
        if not SHA.fullmatch(evidence_sha):raise StateConflict('BILLING_PROOF_INVALID')
        def f():
            row=self.db.execute('SELECT billing_state FROM attempts WHERE request_id=?',(request_id,)).fetchone()
            if not row:raise StateConflict('BILLING_ATTEMPT_MISSING')
            if row[0]=='completed':raise StateConflict('PAID_RESPONSE_CANNOT_BE_FREE')
            if row[0]=='proven_no_charge':return
            # This method may ONLY be invoked after an independently verified
            # provider-level proof; it is NOT called by the automatic watchdog.
            self.db.execute('UPDATE attempts SET billing_state=?,evidence_sha=? WHERE request_id=?',
                            ('proven_no_charge',evidence_sha,request_id))
        self.transaction(f)
    def status(self,identity:str)->dict:
        gen=self.db.execute('SELECT qa_state,expected_chunks,source_sha FROM generation WHERE identity=?',(identity,)).fetchone()
        if not gen:raise StateConflict('GENERATION_MISSING')
        counts=dict(self.db.execute('SELECT state,COUNT(*) FROM tasks WHERE identity=? GROUP BY state',(identity,)))
        return {'identity':identity,'qa_state':gen[0],
                'accepted_chunk_count':self.db.execute('SELECT COUNT(*) FROM chunks WHERE identity=?',(identity,)).fetchone()[0],
                'expected_chunks':gen[1], 'tasks':counts,
                'unknown_billing':self.db.execute('SELECT COUNT(*) FROM attempts WHERE identity=? AND billing_state=?',(identity,'billing_unknown')).fetchone()[0],
                'events':self.db.execute('SELECT COUNT(*) FROM events WHERE identity=?',(identity,)).fetchone()[0],
                'publish_allowed':False,'model_calls':0,'email_sends':0,'max_sends':0}

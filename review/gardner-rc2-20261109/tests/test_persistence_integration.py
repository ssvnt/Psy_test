import tempfile,unittest,sqlite3,json,hashlib
from pathlib import Path
from dz_rc2.store import Store,StateConflict
from dz_rc2.quality import parse_quality,hash_text

F=Path(__file__).resolve().parent.parent/'fixtures/QA_106_SYNTHETIC_181_51_FAIL.json'
I='1'*64;SRC='2'*64

class PersistenceIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.path=Path(self.tmp.name)/'test.sqlite3';self.s=Store(self.path)
        self.s.generation(I,SRC,61)
        for i in range(61):self.s.chunk(I,'chunk_%04d'%i,('%064x'%(i+1)))
        self.plan=parse_quality(json.loads(F.read_text()),I)
    def tearDown(self):self.s.close();self.tmp.cleanup()
    def test_per_chunk_qa_does_not_wait_for_remaining_chunks(self):
        other='d'*64
        self.s.generation(other,'e'*64,61)
        self.s.chunk(other,'chunk_0000','7'*64)
        qa=json.loads(F.read_text())
        qa['fields']={'expert_0001':qa['fields']['expert_0001']}
        qa['input_sha256']={'expert_0001':qa['input_sha256']['expert_0001']}
        plan=parse_quality(qa,other)
        self.s.project_chunk_qa(plan,'chunk_0000','7'*64)
        result=self.s.status(other)
        self.assertEqual(result['accepted_chunk_count'],1)
        self.assertEqual(result['tasks']['local_pending'],1)
        self.assertEqual(result['qa_state'],'qa_repair_pending')
        self.s.chunk(other,'chunk_0001','8'*64) # generation keeps progressing
        self.assertEqual(self.s.status(other)['accepted_chunk_count'],2)
    def test_chunk_qa_hash_must_match_accepted_checkpoint(self):
        qa=json.loads(F.read_text())
        qa['fields']={'expert_0001':qa['fields']['expert_0001']}
        qa['input_sha256']={'expert_0001':qa['input_sha256']['expert_0001']}
        plan=parse_quality(qa,I)
        with self.assertRaisesRegex(StateConflict,'CHUNK_QA_BINDING_INVALID'):
            self.s.project_chunk_qa(plan,'chunk_0000','f'*64)
    def test_repeat_chunk_qa_does_not_enqueue_twice(self):
        qa=json.loads(F.read_text());qa['fields']={'expert_0001':qa['fields']['expert_0001']}
        qa['input_sha256']={'expert_0001':qa['input_sha256']['expert_0001']}
        plan=parse_quality(qa,I)
        self.s.project_chunk_qa(plan,'chunk_0000','1'.zfill(64))
        self.s.project_chunk_qa(plan,'chunk_0000','1'.zfill(64))
        self.assertEqual(self.s.status(I)['tasks']['local_pending'],1)
        self.assertEqual(self.s.status(I)['events'],1)

    def test_immutable_chunk_replay(self):
        self.s.chunk(I,'chunk_0000','1'.zfill(64))
        with self.assertRaisesRegex(StateConflict,'ACCEPTED_CHUNK_IMMUTABLE'):
            self.s.chunk(I,'chunk_0000','f'*64)
    def test_immutable_identity(self):
        with self.assertRaisesRegex(StateConflict,'GENERATION_IDENTITY_CHANGED'):
            self.s.generation(I,'a'*64,61)
    def test_full_qa_pass_cannot_be_invalidated_by_late_chunk_patch(self):
        qa=json.loads(F.read_text())
        for v in qa['fields'].values():
            v['status']='PASS';v.pop('codes',None)
        qa['status']='PASS'
        self.s.project_qa(parse_quality(qa,I))
        assert self.s.status(I)['qa_state']=='qa_pass'
        failed=json.loads(F.read_text())
        failed['fields']={'expert_0001':failed['fields']['expert_0001']}
        failed['input_sha256']={'expert_0001':failed['input_sha256']['expert_0001']}
        with self.assertRaisesRegex(StateConflict,'PASSED_QA_CANNOT_BE_REOPENED'):
            self.s.project_chunk_qa(parse_quality(failed,I),'chunk_0000','1'.zfill(64))
        self.assertEqual(self.s.status(I)['tasks'],{})

    def test_must_collect_before_qa(self):
        j='e'*64;self.s.generation(j,'f'*64,61)
        with self.assertRaisesRegex(StateConflict,'CHUNKS_NOT_COMPLETE'):
            self.s.project_qa(parse_quality(json.loads(F.read_text()),j))
    def test_qa_fail_queues_tasks_instead_of_failed_order(self):
        self.s.project_qa(self.plan)
        result=self.s.status(I)
        self.assertEqual(result['accepted_chunk_count'],61)
        self.assertEqual(result['tasks'].get('local_pending'),51)
        self.assertEqual(result['qa_state'],'qa_repair_pending')
        self.assertFalse(result['publish_allowed'])
    def test_duplicate_qa_ingestion_idempotent(self):
        self.s.project_qa(self.plan);self.s.project_qa(self.plan)
        self.assertEqual(self.s.status(I)['tasks']['local_pending'],51)
        self.assertEqual(self.s.status(I)['events'],1)
    def test_restarts_durable(self):
        self.s.project_qa(self.plan)
        self.s.close()
        self.s=Store(self.path)
        self.assertEqual(self.s.status(I)['tasks']['local_pending'],51)
        self.assertEqual(self.s.status(I)['accepted_chunk_count'],61)
    def test_concurrent_claim_distinct_tasks(self):
        self.s.project_qa(self.plan)
        s2=Store(self.path)
        a=self.s.claim_local(I,now=100)
        b=s2.claim_local(I,now=100)
        self.assertIsNotNone(a);self.assertIsNotNone(b)
        self.assertNotEqual(a['task_id'],b['task_id'])
        s2.close()
    def test_expired_local_lease_is_not_unlimited(self):
        self.s.project_qa(self.plan)
        first=self.s.claim_local(I,now=100,lease_seconds=10)
        # On a stale lease, no second free local normalization is authorized.
        self.s.claim_local(I,now=111)
        row=self.s.db.execute('SELECT state,local_attempts FROM tasks WHERE task_id=?',(first['task_id'],)).fetchone()
        self.assertEqual(row,('editor_pending',1))
    def test_48_local_repair_3_editor_handoff(self):
        self.s.project_qa(self.plan)
        for n in range(51):
            x=self.s.claim_local(I,now=100)
            self.assertIsNotNone(x)
            self.s.local_result(x['task_id'],hash_text('SYNTHETIC_'+str(n)),scanner_pass=n<48,guards_pass=True)
        r=self.s.status(I)
        self.assertEqual(r['tasks'].get('accepted'),48)
        self.assertEqual(r['tasks'].get('editor_pending'),3)
        self.assertEqual(r['accepted_chunk_count'],61)
        self.assertEqual(r['events'],52) # QA repair started + per-field transition
    def test_no_publish_if_all_qa_tasks_accepted_standalone(self):
        self.s.project_qa(self.plan)
        for _ in range(51):
            x=self.s.claim_local(I,now=100)
            self.s.local_result(x['task_id'],hash_text(x['field']),scanner_pass=True,guards_pass=True)
        self.assertFalse(self.s.status(I)['publish_allowed'])
    def test_budget_and_unknown_protected(self):
        self.s.reserve_paid_attempt(I,'3'*64,None,'generation',100,150)
        self.s.billing_unknown('3'*64)
        with self.assertRaisesRegex(StateConflict,'BUDGET_EXHAUSTED_NO_AUTO_INCREASE'):
            self.s.reserve_paid_attempt(I,'4'*64,None,'editor',100,150)
        self.assertEqual(self.s.status(I)['unknown_billing'],1)
    def test_idempotent_reservation(self):
        a=self.s.reserve_paid_attempt(I,'a'*64,None,'editor',50,50)
        b=self.s.reserve_paid_attempt(I,'a'*64,None,'editor',50,50)
        self.assertTrue(a);self.assertFalse(b)
    def test_same_request_changed_is_rejected(self):
        self.s.reserve_paid_attempt(I,'a'*64,None,'editor',50,200)
        with self.assertRaisesRegex(StateConflict,'REQUEST_REUSE_CHANGED'):
            self.s.reserve_paid_attempt(I,'a'*64,None,'editor',51,200)
    def test_reconciled_no_charge_requires_proof_hash(self):
        self.s.reserve_paid_attempt(I,'a'*64,None,'editor',100,100)
        self.s.billing_unknown('a'*64)
        with self.assertRaisesRegex(StateConflict,'BILLING_PROOF_INVALID'):
            self.s.billing_proven_no_charge('a'*64,'NOT_AN_EVIDENCE_SHA')
        self.assertEqual(self.s.status(I)['unknown_billing'],1)
    def test_billed_result_cannot_be_marked_free(self):
        self.s.reserve_paid_attempt(I,'a'*64,None,'editor',100,100)
        self.s.db.execute("UPDATE attempts SET billing_state='completed' WHERE request_id=?",('a'*64,))
        with self.assertRaisesRegex(StateConflict,'PAID_RESPONSE_CANNOT_BE_FREE'):
            self.s.billing_proven_no_charge('a'*64,'b'*64)
    def test_owner_event_once_and_redacted(self):
        self.s.project_qa(self.plan)
        task=self.plan.tasks[0]
        self.s.attention(task.task_id,'OWNER_APPROVAL_REQUIRED')
        self.s.attention(task.task_id,'OWNER_APPROVAL_REQUIRED')
        events=self.s.db.execute('SELECT event,payload FROM events WHERE identity=?',(I,)).fetchall()
        self.assertEqual(sum(x[0]=='QA_OWNER_ATTENTION' for x in events),1)
        self.assertFalse(any('synthetic parent email' in x[1] for x in events))
    def test_event_disallows_private_payloads(self):
        with self.assertRaisesRegex(StateConflict,'EVENT_PAYLOAD_NOT_ALLOWLISTED'):
            self.s.event(I,'QA_REPAIR_STARTED','a'*64,{'email':'example@example.com'})
    def test_no_max_auto_resubmit_unknown(self):
        self.s.project_qa(self.plan)
        self.s.db.execute("UPDATE events SET transport_state='unknown' WHERE identity=?",(I,))
        rows=self.s.db.execute("SELECT COUNT(*) FROM events WHERE transport_state='pending'").fetchone()[0]
        self.assertEqual(rows,0)

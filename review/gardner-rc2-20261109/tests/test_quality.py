import unittest,copy,json
from pathlib import Path
from dz_rc2.quality import (parse_quality,normalize_safe,assert_numeric_negation_locks,
                          validate_editor_candidate, editor_task_contract, hash_text)

FIXTURE=Path(__file__).resolve().parent.parent/'fixtures/QA_106_SYNTHETIC_181_51_FAIL.json'
GEN='1'*64

class QualityTests(unittest.TestCase):
    def setUp(self): self.qa=json.loads(FIXTURE.read_text(encoding='utf8'))
    def test_181_fields_51_tasks(self):
        plan=parse_quality(self.qa,GEN)
        self.assertEqual(plan.field_count,181)
        self.assertEqual(plan.rejected_count,51)
        self.assertEqual(len(plan.tasks),51)
    def test_task_stability_idempotent(self):
        self.assertEqual(parse_quality(self.qa,GEN).tasks,parse_quality(self.qa,GEN).tasks)
    def test_one_field_change_changes_task_id(self):
        before=parse_quality(self.qa,GEN).tasks[0].task_id
        self.qa['input_sha256']['expert_0001']='3'*64
        self.assertNotEqual(before,parse_quality(self.qa,GEN).tasks[0].task_id)
    def test_scanner_must_be_real_names(self):
        self.qa['scanners_executed']=['mock-scanner']
        with self.assertRaisesRegex(ValueError,'QUALITY_SCANNERS_NOT_VERIFIED'):parse_quality(self.qa,GEN)
    def test_language_pass_without_error(self):
        for v in self.qa['fields'].values():v['status']='PASS';v.pop('codes',None)
        self.qa['status']='PASS'
        self.assertEqual(parse_quality(self.qa,GEN).rejected_count,0)
    def test_pass_with_rejection_is_invalid(self):
        self.qa['status']='PASS'
        with self.assertRaisesRegex(ValueError,'QUALITY_PASS_WITH_FAILED_FIELDS'):parse_quality(self.qa,GEN)
    def test_field_binding_missing_hash(self):
        self.qa['input_sha256'].pop('expert_0001')
        with self.assertRaisesRegex(ValueError,'QUALITY_FIELD_BINDING_INVALID'):parse_quality(self.qa,GEN)
    def test_unapproved_field_rejected(self):
        self.qa['fields']['score_001']={'status':'FAIL','codes':['QUALITY_REVIEW_REQUIRED']}
        self.qa['input_sha256']['score_001']='b'*64
        with self.assertRaisesRegex(ValueError,'QUALITY_FIELD_SCHEMA_INVALID'):parse_quality(self.qa,GEN)
    def test_local_unicode_only(self):
        s='Ребёнок\u00a0наблюдает.\r\n  Явные признаки\t навыка  '
        t=normalize_safe(s)
        self.assertNotIn('\u00a0',t)
        self.assertNotIn('\r',t)
        self.assertEqual(normalize_safe(t),t)
    def test_numeric_lock(self):
        with self.assertRaisesRegex(ValueError,'NUMBER_CHANGED'):
            assert_numeric_negation_locks('Оценка 42%','Оценка 43%')
    def test_negation_lock(self):
        with self.assertRaisesRegex(ValueError,'NEGATION_CHANGED'):
            assert_numeric_negation_locks('Не утверждать диагноз','Утверждать диагноз')
    def test_protected_other_fields(self):
        a={'expert_0001':'Предпочитает читать.','expert_0002':'Играет в шахматы.'}
        b={'expert_0001':'Предпочитает чтение.','expert_0002':'Играет в шахматы.'}
        sha='b'*64
        validate_editor_candidate(a,b,{'expert_0001'},scanners_pass=True,original_json_sha256=sha,expected_json_sha256=sha)
        b['expert_0002']='Играет в футбол.'
        with self.assertRaisesRegex(ValueError,'ACCEPTED_FIELD_CHANGED'):
            validate_editor_candidate(a,b,{'expert_0001'},scanners_pass=True,original_json_sha256=sha,expected_json_sha256=sha)
    def test_scanner_recheck_required(self):
        a={'expert_0001':'Предпочитает читать.'}
        with self.assertRaisesRegex(ValueError,'SCANNER_RECHECK_REQUIRED'):
            validate_editor_candidate(a,a,set(),scanners_pass=False,original_json_sha256='a'*64,expected_json_sha256='a'*64)
    def test_editor_requests_language_only(self):
        task=parse_quality(self.qa,GEN).tasks[0]
        c=editor_task_contract(task)
        self.assertEqual(c['model'],'GigaChat')
        self.assertEqual(c['purpose'],'LANGUAGE_ONLY')
        self.assertFalse(c['semantic_depth_review'])
        self.assertTrue(c['requires_ledger'])
        self.assertIn('CHANGE_NUMBERS',c['prohibit'])
    def test_edit_rejects_changed_values(self):
        a={'expert_0001':'Семья не посещает 3 кружка.'}
        b={'expert_0001':'Семья посещает 4 кружка.'}
        with self.assertRaises(ValueError):
            validate_editor_candidate(a,b,{'expert_0001'},scanners_pass=True,original_json_sha256='a'*64,expected_json_sha256='a'*64)

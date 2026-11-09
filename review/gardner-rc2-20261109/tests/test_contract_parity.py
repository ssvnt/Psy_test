import json,subprocess,unittest
from pathlib import Path
from dz_rc2.policy import classify,Evidence
BASE=Path(__file__).resolve().parent.parent

class CrossRuntimeParityTests(unittest.TestCase):
    def test_php_and_python_actions_match_synthetic_incidents(self):
        cases=json.loads((BASE/'fixtures/STOP_CLASSIFICATION_SYNTHETIC.json').read_text())
        result=subprocess.run(['php',str(BASE/'tests/php_policy_json_cli.php')],
                              input=json.dumps(cases),text=True,capture_output=True,timeout=10,check=True)
        php=[json.loads(line) for line in result.stdout.strip().splitlines()]
        self.assertEqual(len(php),len(cases))
        for case,actual in zip(cases,php):
            with self.subTest(code=case['code'],proof=case['proof']):
                p=classify(Evidence(case['code'],**case['proof']))
                self.assertEqual(actual['action'],p.action.value)
                self.assertEqual(actual['scope'],p.scope)
                self.assertEqual(actual['reason'],p.reason)
                self.assertEqual(actual['retry_after_seconds'],p.retry_after_seconds)
                self.assertFalse(actual['new_billable_request_authorized'])
                self.assertFalse(actual['publish_allowed'])
                self.assertEqual(actual['action'],case['action'])

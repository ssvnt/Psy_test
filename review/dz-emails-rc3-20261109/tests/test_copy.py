import json,pathlib,re,unittest
BASE=pathlib.Path(__file__).resolve().parent.parent
DATA=json.loads((BASE/'drafts/PROPOSED_COPY_NOT_VERIFIED_AGAINST_UPLOADED_ZIP.json').read_text('utf8'))
class CopySafety(unittest.TestCase):
 def test_two_products_and_four_events(self):
  self.assertEqual(set(DATA),{'gardner','teen'})
  for p,d in DATA.items():
   self.assertEqual(set(d),{'test_completed_offer','unpaid_12h','payment_confirmed','report_ready'})
 def test_buttons(self):
  for p,d in DATA.items():
   for name,rec in d.items():
    expected={'report_url','consultation_url'} if name=='report_ready' else {'account_url'}
    self.assertEqual({b['key'] for b in rec['buttons']},expected)
 def test_subjects_and_claims(self):
  for p,d in DATA.items():
   for name,rec in d.items():
    s=rec['subject']; self.assertTrue(10<len(s)<90)
    alltext=' '.join([s,rec['title'],*rec['paragraphs']])
    for forbidden in ['гарантируем','безработица','заменит всех','диагноз','судьба предрешена','100%']:
     self.assertNotIn(forbidden,alltext.lower())
    if name=='payment_confirmed': self.assertIn('24 часов',alltext)
    if name=='unpaid_12h': self.assertNotIn('24 часа',alltext)
 def test_neutral_payment_notice_always_available(self):
  for p,d in DATA.items():
   self.assertEqual(len(d['payment_confirmed']['status_only_paragraphs']),3)
   self.assertNotIn('24 час',' '.join(d['payment_confirmed']['status_only_paragraphs']))
 def test_no_private_data_or_external_links_in_drafts(self):
  for p,d in DATA.items():
   for name,rec in d.items():
    raw=json.dumps(rec,ensure_ascii=False)
    self.assertNotIn('http:',raw);self.assertNotIn('https:',raw)
    self.assertNotRegex(raw,r'[a-f0-9]{8}-[a-f0-9]{4}-')
 def test_marketing_unsubscribe_copy(self):
  for p,d in DATA.items():
   for e in ('test_completed_offer','unpaid_12h'):
    self.assertIn('{{unsubscribe_url}}',d[e]['footer'])
if __name__=='__main__':unittest.main(verbosity=2)

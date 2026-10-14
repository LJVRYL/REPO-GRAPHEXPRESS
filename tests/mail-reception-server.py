import importlib.util, unittest, tempfile
from pathlib import Path
from email.message import EmailMessage
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('receiver',Path(__file__).parents[1]/'tools/mail-reception-server.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class Reception(unittest.TestCase):
 def test_large_attachment_keeps_text(self):
  m=EmailMessage();m['From']='client@example.test';m['Subject']='Presupuesto';m.set_content('Necesito dos corporeos e instalacion.');m.add_attachment(b'x'*(5*1024*1024),maintype='application',subtype='pdf',filename='original.pdf')
  raw=m.as_bytes();self.assertGreater(len(raw),4*1024*1024)
  h,body,parts,truncated=r.parse_message(raw);self.assertIn('dos corporeos',body);self.assertEqual(len(parts),1);self.assertEqual(len(parts[0]['payload']),5*1024*1024);self.assertFalse(truncated)
  h,body,parts,truncated=r.parse_message(raw[:r.PREFIX],True);self.assertIn('dos corporeos',body);self.assertTrue(truncated);self.assertIsNone(parts[0]['payload'])
 def test_transport_identity_matches_existing_receiver(self):
  self.assertEqual(r.identity('1791585156',288)[1],'c980a19d7aa412bbb1c8d81dbaded0b56215bad4a0fdc26a651fb660a3f0e8d6')
 def test_plain_html_never_executes_or_fetches(self):
  m=EmailMessage();m.set_content('<script>steal()</script><p>Presupuesto</p><img src="https://example.test/private">',subtype='html')
  h,body,_,_=r.parse_message(m.as_bytes());self.assertIn('Presupuesto',body);self.assertNotIn('steal',body);self.assertNotIn('https',body)
 def test_path_requires_unique_non_symlink(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);(root/'cur').mkdir();(root/'new').mkdir();(root/'cur/id:2,S').write_text('mail')
   with patch.object(r,'MAILDIR',root):
    self.assertEqual(r.source_path('id'),(root/'cur/id:2,S').resolve())
    with self.assertRaises(ValueError):r.source_path('../id')
    (root/'new/id').symlink_to(root/'cur/id:2,S')
    self.assertEqual(r.source_path('id'),(root/'cur/id:2,S').resolve())
 def test_no_completion_until_crm_confirms(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);(root/'state').mkdir();mail=root/'mail';mail.write_bytes(b'From: client@example.test\nSubject: Presupuesto\nContent-Type: text/plain\n\nDos corporeos')
   def dove(args):
    if args[0]=='mailbox':return 'INBOX uidvalidity=123 messages=1 uidnext=2'
    if args[0]=='search':return 'guid 1'
    return 'uid: 1\nstorageid: id\ndate.received.unixtime: 1791899995'
   with patch.object(r,'ROOT',root),patch.object(r,'dovecot',side_effect=dove),patch.object(r,'source_path',return_value=mail):
    def fail(data):
     if data.get('operation')=='health':return {'committed':True}
     raise RuntimeError('endpoint failed')
    with patch.object(r,'deliver',side_effect=fail):self.assertEqual(r.scan()['failed'],1)
    self.assertFalse((root/'state/receipts.json').exists())
    def commit(data):return {'committed':True,'record_id':55,'duplicate':True}
    with patch.object(r,'deliver',side_effect=commit):
     self.assertEqual(r.scan()['duplicates'],1);self.assertEqual(r.scan()['duplicates'],0)
    self.assertTrue((root/'state/receipts.json').exists())
    with patch.object(r,'dovecot',side_effect=lambda args:'INBOX uidvalidity=124 messages=1 uidnext=2' if args[0]=='mailbox' else dove(args)):
     with self.assertRaises(RuntimeError):r.scan()
    self.assertTrue((root/'state/receipts.json').exists())
if __name__=='__main__':unittest.main()

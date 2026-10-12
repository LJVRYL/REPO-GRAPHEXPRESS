import importlib.util, hashlib
from pathlib import Path
spec=importlib.util.spec_from_file_location('worker',Path(__file__).parents[1]/'ops/newsletter-worker.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
mid='ge-nl-'+'a'*64+'@graphex.ar';row={'id':1,'message_id':'<'+mid+'>','recipient_sha256':hashlib.sha256(b'one@example.invalid').hexdigest()}
received='2026-10-12 12:00:00 abc123-def456-AB <= servicio@graphex.ar id='+mid
assert m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB ** one@example.invalid SMTP 550'],[row])[0]['kind']=='hard_bounce'
assert m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB == one@example.invalid mailbox full'],[row])[0]['freeze']
assert not m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB ** other@example.invalid SMTP 550'],[row])
assert not m.feedback(['2026-10-12 12:00:01 other1-other2-AB ** one@example.invalid SMTP 550'],[row])
assert not m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB => one@example.invalid SMTP accepted'],[row])
assert not m.feedback([received.replace(mid,'unrelated@example.invalid'),'2026-10-12 12:00:01 abc123-def456-AB ** one@example.invalid SMTP 550'],[row])
print('passed: 6 trusted-MTA correlation checks; zero mail calls')

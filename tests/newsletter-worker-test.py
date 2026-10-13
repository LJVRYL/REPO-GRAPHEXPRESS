import importlib.util, hashlib
from pathlib import Path
spec=importlib.util.spec_from_file_location('worker',Path(__file__).parents[1]/'ops/newsletter-worker.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
mid='ge-nl-'+'a'*64+'@graphex.ar';row={'id':1,'message_id':'<'+mid+'>','recipient_sha256':hashlib.sha256(b'one@example.invalid').hexdigest()}
received='2026-10-12 12:00:00 abc123-def456-AB <= servicio@graphex.ar id='+mid
assert m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB ** one@example.invalid SMTP 550 5.1.1'],[row])[0]['kind']=='hard_bounce'
assert m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB == one@example.invalid mailbox full'],[row])[0]['freeze']
assert not m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB ** other@example.invalid SMTP 550'],[row])
assert not m.feedback(['2026-10-12 12:00:01 other1-other2-AB ** one@example.invalid SMTP 550 5.1.1'],[row])
assert not m.feedback([received,'2026-10-12 12:00:01 abc123-def456-AB => one@example.invalid SMTP accepted'],[row])
assert not m.feedback([received.replace(mid,'unrelated@example.invalid'),'2026-10-12 12:00:01 abc123-def456-AB ** one@example.invalid SMTP 550 5.1.1'],[row])
dsn=('From: Mail Delivery System <mailer-daemon@dattaweb.com>\r\nMessage-ID: <report@example.invalid>\r\nContent-Type: multipart/report; report-type=delivery-status; boundary="b"\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nDelivery failed\r\n--b\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; smarthost.dattaweb.com\r\n\r\nFinal-Recipient: rfc822; one@example.invalid\r\nAction: failed\r\nStatus: 5.2.2\r\n\r\n--b\r\nContent-Type: message/rfc822\r\n\r\nMessage-ID: <'+mid+'>\r\nTo: one@example.invalid\r\n\r\nOriginal\r\n--b--\r\n').encode()
arrival='2026-10-12 12:00:01 abc123-def456-AB <= <> H=smarthost [200.58.116.136] P=esmtps id=report@example.invalid'
assert m.delivery_reports([dsn],[arrival],[row])[0]['kind']=='temporary_bounce'
assert m.delivery_reports([dsn],[arrival.replace('200.58.116.136','203.0.113.1')],[row])[0]['kind']=='review_bounce'
assert not m.delivery_reports([dsn.replace(b'one@example.invalid',b'other@example.invalid')],[arrival],[row])
assert not m.delivery_reports([dsn.replace(mid.encode(),b'unrelated@example.invalid')],[arrival],[row])
assert not m.delivery_reports([dsn],[arrival.replace('<= <>','<= attacker@example.invalid')],[row])
for peer in ('200.58.114.24','200.58.124.17'):assert m.delivery_reports([dsn],[arrival.replace('200.58.116.136',peer)],[row])
for code,kind in [('5.1.1','hard_bounce'),('5.7.1','policy_bounce'),('4.2.2','temporary_bounce'),('5.2.2','temporary_bounce'),('5.0.0','review_bounce')]:
 assert m.failure_kind(code)[0]==kind
 assert m.delivery_reports([dsn.replace(b'5.2.2',code.encode())],[arrival],[row])[0]['kind']==kind
assert m.failure_kind('5.0.0','smtp; 550 5.7.1 blocked')[0]=='policy_bounce'
assert m.failure_kind('5.0.0','smtp; 550 5.1.1 mailbox unknown')[0]=='hard_bounce'
assert not m.delivery_reports([dsn],[],[row])
assert m.delivery_reports([dsn],[],[row],allow_unverified=True)[0]['kind']=='review_bounce'
assert m.delivery_reports([dsn],[],[row],allow_unverified=True)[0]['evidence_ref'].startswith('maildir_review:')
print('passed: 28 trusted-MTA and MIME-DSN correlation checks; zero mail calls')

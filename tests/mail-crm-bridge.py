import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('bridge', Path(__file__).parents[1] / 'tools/graphex-mail-crm-bridge.py')
bridge = importlib.util.module_from_spec(spec); spec.loader.exec_module(bridge)

class DB:
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def execute(self, *args): return self
    def fetchone(self): return {'created': 1791540000}

class Core:
    def __init__(self): self.acks = []
    def pending(self, account, limit):
        assert account == 'graphex'
        return {'events': [{'type': 'mail.received', 'account': 'graphex', 'event_id': 'stable-event', 'message': {'id': 'opaque'}, 'historical_backfill': True}]}
    def read_message(self, account, ident, limit):
        return {'id': ident, 'headers': {'From': 'Client <client@example.invalid>', 'To': 'servicio@graphex.ar', 'Reply-To': '', 'Message-ID': '<root@example.invalid>'}, 'body_text': 'Hola'}
    def db(self): return DB()
    def acknowledge(self, account, event_id, outcome): self.acks.append(outcome)

class Tests(unittest.TestCase):
    def test_read_only(self):
        core = Core(); result = bridge.consume(core, lambda _: self.fail('No writes'), dry_run=True)
        self.assertEqual(1, result['historical']); self.assertEqual([], core.acks)
    def test_commit_before_ack(self):
        core = Core(); bridge.consume(core, lambda _: {'committed': True, 'record_id': 9}, dry_run=False)
        self.assertEqual(['crm_persisted'], core.acks)
    def test_unconfirmed_receipt_retry(self):
        core = Core(); bridge.consume(core, lambda _: {'committed': False}, dry_run=False)
        self.assertEqual(['retry'], core.acks)
    def test_crash_retry(self):
        core = Core()
        def fail(_): raise TimeoutError()
        self.assertEqual(1, bridge.consume(core, fail, dry_run=False)['retry'])
        self.assertEqual(['retry'], core.acks)
    def test_stable_replay(self):
        core = Core(); event = core.pending('graphex', 1)['events'][0]; message = core.read_message('graphex', 'opaque', 1)
        self.assertEqual(bridge.envelope(event, message, 1791540000), bridge.envelope(event, message, 1791540000))
    def test_foreign_account(self):
        core = Core(); event = core.pending('graphex', 1)['events'][0]; event['account'] = 'tickex'
        with self.assertRaises(ValueError): bridge.envelope(event, core.read_message('graphex', 'opaque', 1), 1791540000)
    def test_ambiguous_reply(self):
        core = Core(); event = core.pending('graphex', 1)['events'][0]; message = core.read_message('graphex', 'opaque', 1)
        message['headers']['Reply-To'] = 'other@example.invalid'
        self.assertTrue(bridge.envelope(event, message, 1791540000)['reply_ambiguous'])
    def test_body_truncation(self):
        core = Core(); event = core.pending('graphex', 1)['events'][0]; message = core.read_message('graphex', 'opaque', 1)
        message['body_text'] = 'x' * 12001
        packet = bridge.envelope(event, message, 1791540000)
        self.assertTrue(packet['body_truncated']); self.assertEqual(12000, len(packet['body']))
    def test_absent_reply_header_requires_review(self):
        core = Core(); event = core.pending('graphex', 1)['events'][0]; message = core.read_message('graphex', 'opaque', 1)
        del message['headers']['Reply-To']
        self.assertTrue(bridge.envelope(event, message, 1791540000)['reply_ambiguous'])

if __name__ == '__main__': unittest.main()

"""Consume the existing mail receiver; acknowledge only the committed CRM receipt.

No IMAP/SMTP implementation or credentials live here. Tickex is never consumed.
"""
import argparse
import datetime
import email.utils
import hashlib
import importlib.util
import json
import re
import subprocess
import time
import os
from pathlib import Path


def envelope(event, message, observed_at):
    if event.get("type") != "mail.received" or event.get("account") != "graphex":
        raise ValueError("Only registered Graphex mail events")
    headers = {str(k).lower(): str(v) for k, v in message["headers"].items()}
    addresses = [x[1] for x in email.utils.getaddresses([headers.get("from", "")])]
    valid = len(addresses) == 1 and bool(re.fullmatch(r"[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+", addresses[0]))
    sender = addresses[0] if valid else "unresolved-" + event["event_id"][:16] + "@message.invalid"
    reply = [x[1].lower() for x in email.utils.getaddresses([headers.get("reply-to", "")])]
    ambiguous = "reply-to" not in headers or not valid or bool(reply and (len(reply) != 1 or reply[0] != sender.lower()))
    roots = re.findall(r"<[^<>\s]{1,300}>", headers.get("references", ""))
    if not roots:
        roots = re.findall(r"<[^<>\s]{1,300}>", headers.get("in-reply-to", "") or headers.get("message-id", ""))
    conversation = hashlib.sha256((roots[0] if roots else message["id"]).encode()).hexdigest()
    subject = headers.get("subject", "")
    recipients = [x[1].lower() for x in email.utils.getaddresses([headers.get("to", "")])]
    if "servicio@graphex.ar" not in recipients:
        ambiguous = True
    return {
        "organization_id": "graph-express", "channel": "email", "account_ref": "graphex",
        "external_id": event["event_id"], "conversation_id": conversation,
        "source_ref": "mail:" + message["id"],
        "received_at": datetime.datetime.fromtimestamp(observed_at, datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "from": sender, "to": "servicio@graphex.ar", "subject": subject[:180],
        "body": message.get("body_text", "")[:12000], "headers": headers,
        "attachment_refs": ["mail-part:" + message["id"] + ":" + str(a["part_index"]) for a in message.get("attachments", [])],
        "historical_backfill": bool(event.get("historical_backfill", False)),
        "body_truncated": bool(message.get("body_truncated", False) or len(subject) > 180 or len(message.get("body_text", "")) > 12000),
        "reply_ambiguous": ambiguous,
    }


def consume(core, deliver, limit=20, dry_run=True):
    counts = {"read": 0, "committed": 0, "duplicates": 0, "retry": 0, "historical": 0, "external_sent": 0, "external_uncertain": 0}
    for event in core.pending("graphex", limit)["events"]:
        try:
            message = core.read_message("graphex", event["message"]["id"], 12000)
            if "reply-to" not in {k.lower() for k in message["headers"]}:
                # Reuse the provider's bounded read-only parser, never a second transport.
                _, original = core.raw_message("graphex", event["message"]["id"])
                message["headers"]["Reply-To"] = ", ".join(str(v) for v in original.get_all("Reply-To", []))
                if len(original.get_all("From", [])) != 1:
                    message["headers"]["From"] = ", ".join(str(v) for v in original.get_all("From", []))

            with core.db() as db:
                row = db.execute("SELECT created FROM events WHERE id=? AND account=?", (event["event_id"], "graphex")).fetchone()
            if not row:
                raise ValueError("Missing durable transport event")
            packet = envelope(event, message, row["created"])
            counts["read"] += 1
            counts["historical"] += int(packet["historical_backfill"])
            if dry_run:
                continue
            result = deliver(packet)
            if not result.get("committed") or not result.get("record_id"):
                raise RuntimeError("CRM did not confirm durable receipt")
            counts["external_sent"] += int(result.get("ack_state") == "sent")
            counts["external_uncertain"] += int(result.get("ack_state") == "unknown")
            core.acknowledge("graphex", event["event_id"], "crm_persisted")
            counts["committed"] += 1
            counts["duplicates"] += int(bool(result.get("duplicate")))
        except Exception:
            if not dry_run:
                core.acknowledge("graphex", event["event_id"], "retry")
            counts["retry"] += 1
    return counts


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--transport-root", type=Path, required=True)
    parser.add_argument("--apply", action="store_true", help="Consume into the deployed native CRM; default is read-only")
    parser.add_argument("--limit", type=int, default=20, choices=range(1, 51))
    parser.add_argument("--verified-release", type=Path)
    parser.add_argument("--watch", action="store_true")
    parser.add_argument("--evidence-file", type=Path)
    args = parser.parse_args()
    if args.apply:
        if not args.verified_release:
            parser.error("Verified production release required")
        release = json.loads(args.verified_release.read_text(encoding="utf-8"))
        if not release.get("production_verified") or release.get("bridge_sha256") != hashlib.sha256(Path(__file__).read_bytes()).hexdigest():
            parser.error("Unverified production consumer or bridge drift")
    if args.watch and (not args.apply or not args.evidence_file):
        parser.error("Continuous consumer requires verified production and evidence file")
    spec = importlib.util.spec_from_file_location("graphex_existing_mail_transport", args.transport_root / "mail_core.py")
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)

    def deliver(packet):
        run = subprocess.run([
            "ssh", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes", "-p", "5133", "root@200.45.208.16",
            "python3 /home/graphexpress/crm-inbound/crm-inbound-stdin.py production",
        ], input=json.dumps(packet, ensure_ascii=False).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90)
        if run.returncode:
            raise RuntimeError("CRM transport call failed; retain durable local event")
        output = run.stdout.decode("utf-8", "replace")
        return json.loads(output[output.index("{"):])

    lock = None
    if args.watch:
        import msvcrt
        lock = args.evidence_file.with_suffix(".lock").open("a+b")
        lock.seek(0); lock.write(b"1"); lock.flush(); lock.seek(0)
        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    while True:
        try:
            counts = consume(core, deliver, args.limit, dry_run=not args.apply)
            if args.apply:
                h = core.health()
                account = next((a for a in h["accounts"] if a["account"] == "graphex"), {})
                with core.db() as db:
                    backlog = db.execute("SELECT COUNT(*) AS count, MIN(created) AS oldest FROM events WHERE account=? AND status!=?", ("graphex", "crm_persisted")).fetchone()
                health = {"operation": "health", "receiver_running": h["continuous_receiver"] == "running_local", "poll_failed": bool(account.get("error_code")), "pending_events": backlog["count"], "oldest_pending_age_seconds": max(0, time.time() - backlog["oldest"]) if backlog["oldest"] else 0}
                deliver(health)
            evidence = {"checked_at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "status": "running" if args.watch else "completed", "counts": counts, "account": "graphex"}
        except Exception:
            evidence = {"checked_at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "status": "retry_required", "account": "graphex"}
        if args.evidence_file:
            temporary = args.evidence_file.with_suffix(".tmp")
            temporary.write_text(json.dumps(evidence, indent=2), encoding="utf-8")
            os.replace(temporary, args.evidence_file)
        if not args.watch:
            print(json.dumps(evidence)); return
        time.sleep(60)


if __name__ == "__main__":
    main()

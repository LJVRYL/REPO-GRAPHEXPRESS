# Commercial mail reception

The native CRM receives the Graphex Dovecot mailbox on the VPS through
`graphex-mail-reception.service`. Windows is no longer the CRM consumer.
The separate Windows header receiver remains available for local mail tools
and Tickex; its aggregate pending count is not the Graphex CRM backlog.

## Data and processing

- Mailbox: existing Graphex service account, local Dovecot storage. No new
  credentials or external transport.
- Receiver: `/home/graphexpress/mail-reception/mail-reception-server.py`.
- Sink: private `mail-reception-native.php`, explicit production/QA DB guards.
- State: private `state/receipts.json`, `pending.json`, `uidvalidity.json`.
- Identity: account, INBOX, UIDVALIDITY and UID encoded identically to the
  original receiver. Message subjects are never a dedupe or attribution key.
- Completion is saved only after the CRM record, review task and applicable
  internal alert are verified. An interrupted sink resumes without rewriting
  the original event or creating another customer account.
- Full MIME parsing is bounded to 16 MiB. Larger messages keep a 256 KiB
  preview, the source identity and review task, with incomplete attachments
  explicitly pending. HTML is extracted as text; nothing is executed or fetched.
- A mailbox generation change blocks imports and surfaces a health failure
  until the identities are reconciled. Pending failures persist across restarts.

Attachments are checksum verified outside the public web root. The worker owns
them; the existing web-server group has read access only. A staff-only native
download action checks CRM organization access, nonce, path, and checksum and
returns an attachment with `nosniff`. Original artwork is not edited or approved.

The receiver sets `external_reply_suppressed` explicitly. It does not call SMTP
or routine acknowledgments. Systemd additionally isolates networking and allows
Unix sockets only. Human-authorized replies use the existing CRM outbox.

## Health and campaign safety

The CRM and Communications panel show reception heartbeat, pending messages and
runtime. Missing heartbeat, processing errors or backlog are actionable warnings.
Delivery-feedback health cannot substitute for commercial reception when
enabling the campaign engine. An expired launch authorization stays expired.

## Verification and rollback

Private production evidence and backups:
`/home/graphexpress/releases/mail-reception-20261013`.
The source files and native records were backed up and decoded/checksummed before
changes. `core.original.php`, `engine.before.php`, `alerts.before.php` and the
hash-named attention backup retain the original deployment.

Rollback: stop the new service, verify current hashes against the final manifest,
restore only the changed source files from verified originals and move the new
MU plugin into private quarantine. Preserve all recovered CRM records, notes,
attachments, receipt state and later staff edits. Never replace the CRM table.
Do not re-enable the former Windows consumer until its bounded-reader issue and
the overlap with recovered source identities have been resolved.

Checks: synthetic large MIME, truncated preview, HTML isolation, path/symlink
guards, commit-before-completion, endpoint failure, restart/deduplication,
UIDVALIDITY change, existing and unmatched customers, current `_ge_cuit` matching,
attachment retention on edits, native notification links, explicit outbound
suppression, and stale/backlogged reception with healthy delivery feedback.

# Customer work documents

Customers and authorized staff can download the customer-safe work order and receipt X from the current order detail. Files are generated on demand through `admin_post_ge_customer_work_pdf`, require a logged-in actor and order/kind nonce, and use native ownership/organization/finance rules.

- Work order: commercial issuer/receiver snapshots, quantities/specifications, current production state, recorded promised date, shared originals with bounded first-page thumbnails. No supplier costs or internal notes. Up to six thumbnails within a bounded rendering budget; unavailable/unverified previews show a placeholder.
- Optional customer conformity is revision-bound and separate from artwork release. It records only a comment/decision; never approves print files or changes production.
- Receipt X: reconciled credited balance, individual manual payments, historical credits without fabricated dates, unidentified gateway remainder explicitly labeled. No invoice/CAE and no new payment. Unpaid, dependent child, payment-attempt and inconsistent balance cases are rejected. The RX reference is internal and revision-based, not fiscal numbering.
- Downloads do not write payment, order or approval data. Original files stay private. Existing quote and invoice outputs are unchanged.
- Existing dedicated legacy historical portal renderer is outside this integration; current commercial orders are supported.

Validation: `php tests/customer-work-documents-contract.php`; lint both changed includes. Read-only production probe verifies actual receipt totals, owner/non-owner permissions, unchanged order and a PDF thumbnail. Visual PDF QA is required because the shared engine may fall back to UTF-8 on iconv runtimes; text operands are normalized to WinAnsi before stream lengths/xrefs are calculated.

Deployment: signed code broker, exact file allowlist, baseline hashes and verified backup. Rollback restores the prior issued-documents include (which disconnects the new class); no ledger rollback is needed. Retain the unused new file unless removal is separately authorized. Conformity metadata is additive and remains as audit evidence.

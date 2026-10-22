# Historical work reconciliation

`/gestion/?ge_work_reconciliation=1` provides private historical declarations, independently of immutable quotes, invoice documents, payment transactions and production commands. Importing a declaration does not certify bank reconciliation or create receipt dates, payment methods, delivery events, production dispatch or notifications.

Access requires the existing bound organization owner/admin boundary and native orders, quotes and finance module permissions. Import, preview, publication, rollback and backup download use separate authenticated POST operations with native nonces. JSON input is explicitly projected and bounded; money uses integer cents. Unknown fields/states, missing or foreign references, duplicate source IDs and contradictory unperformed/delivered declarations are rejected.

Preview is server-side, actor-bound and expires after 15 minutes. Publication revalidates referenced source records and their fingerprints under an organization-scoped database lock. The expected declaration revision prevents lost updates. One non-autoloaded option write appends the complete revision, with the previous revision retained, actor, UTC recording time, source hash, source fingerprints and operation. Identical replay is a no-op. Ambiguous persistence requires history readback before retry. No production tables or order metadata are patched.

The work listing displays the declaration as `Conciliado`, including the declared collection state. Opening a reconciled row leads to its declaration and original-document links. Original transaction totals remain visible in their original records. Newer changes to the underlying source suppress the list overlay and request review; historical declarations remain available as dated evidence. Quote-only references without a corresponding listed entity remain in the full reconciliation view. No monetary aggregate is calculated because quote/order/invoice records and partial scopes are not additive.

Backup: download the full declaration history before/after each import. The first publication starts with no declarations, so its before-image is the explicit empty revision. Later revisions retain exact before-images. Rollback appends a revision copied from a previous declaration or empty state; it preserves audit history and does not restore a database. Code rollback: remove only these reconciliation changes, preserve the non-autoloaded history option, and keep all business records. Existing exporter behavior is unchanged; declaration history has its own private download.

Local verification:

- `php tests/work-reconciliation-fixture.php`: synthetic validation, organization boundaries, locking, stale source/revision, persistence failure, replay, rollback, list freshness.
- `python tests/work-reconciliation-http.py`: actual loopback HTTP, role/module/nonces, preview/confirmation, UTF-8/escaping, publication, download and rollback.
- `php tests/work-reconciliation-native-policy.php`: actual native organization action classification.
- Existing exporter fixture regression.

Live acceptance remains separate: verify deployed hashes, authorized and anonymous behavior, empty before-image, exact preview count/scope/amounts, publish once, download/read back the complete declaration history, confirm source export unchanged, then inspect desktop/mobile listing and original links. Never claim local tests as production verification.

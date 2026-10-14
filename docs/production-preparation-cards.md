# Production preparation cards

Five native cards save/resume one step at a time: planning, exact files and prepress, technical instructions, internal/supplier output, final control. Payment and invoicing are independent of production authorization. Quote ownership/version, active order, promised date, source ownership and exact artwork approval remain mandatory. Financial values and WooCommerce payment status are not rewritten.

Internal authorization starts physical production and creates one native customer notice per authorization generation. Supplier dispatch waits for explicit business acceptance through the private portal or a staff-recorded reference to actual acceptance. SMTP acceptance, copying a message or opening WhatsApp does not start production. Supplier rejection/delay creates a native CRM task. Reopening preparation revokes the previous supplier links and requires a new exact preview. No automated WhatsApp transport is asserted.

Customer approval remains a native customer-only action for the displayed fingerprint. Replacing bytes, source, version or dimensions invalidates the previous approval. AI uses the existing capability-gated candidate workflow; unavailable execution remains unavailable, and candidates are never approved automatically.

Operations permissions, organization binding, nonces, per-order locks, revision conflicts and private source checks protect mutations. Saved fields survive validation/conflict and local disconnected drafts. Native supplier/customer mail uses the site's configured sender and trace records; uncertain attempts require review and known failures have bounded manual retries.

## Validation

`tests/production-cards-wp.php` refuses any database other than `graph_job_flow_20261007`, intercepts all mail and external HTTP, and uses synthetic private artifacts. It checks unpaid/partial/pending/confirmed payments without mutations, missing dates/art, customer ownership/hash and changed versions/bytes, source and tenant separation, supplier transport versus acceptance, duplicate notices, manual evidence, rejection/reopening, failure/retry limits, historical preservation, read-only and production roles, and resolved CRM tasks. UI checks cover a single visible card, Continue/Back persistence and mobile layout.

## Activation and rollback

Activation option: `ge_production_cards_v2_enabled=yes`, only after all release hashes and syntax checks pass. New controller defaults disabled. Production release reference: `production-cards-20261013`; verified scoped source backups and the private order-3528 before snapshot are outside the public document root.

Rollback checks that no later source change occurred, moves this release's loader to private backup and restores only the seven original native files from verified backups. It does not restore the full database, delete contacts, revert payments or erase new business operations. Before any rollback after staff use, review new dispatches/notices and their exact versions. Order 3528 is verified read-only; Leo selects the output and finishes its control. No real production, bank confirmation, invoice or supplier/client message is sent by QA/deployment checks.

Canonical branch is a draft PR stacked on the commercial mail recovery branch. No automatic main merge.

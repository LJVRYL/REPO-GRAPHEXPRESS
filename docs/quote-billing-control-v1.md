# Quote Billing Control v1

Quote-specific recipient and issuer selection reuses Customer Branches, Billing Issuers and Customer Tax Resolver. Several profiles require an explicit recipient. Snapshots are retained through send, approval, email/PDF/portal and conversion.

Click the quote customer name or Ver ficha cliente for the quick drawer. Contact fields, fiscal profiles, lookup controls, explicit staff verification and delivery addresses use the existing model. Abrir ficha completa opens Customer Workspace.

Unverified decisions allow manual recipient and issuer selection. Verified decisions lock both. Cambiar igualmente requires manage_options or ge_manage_billing_issuers and a reason; operations permission alone cannot override. The backend applies the same guard to full revisions. Sources are manual, customer and arca; ARCA freshness uses the existing resolver. Staff confirmation is distinct from official ARCA verification.

Profile edits never refresh existing quotes. Actualizar este presupuesto con los datos nuevos opens explicit refresh. Identity changes create a new version and preserve items, amounts, files and commercial state. An identical computed fiscal total can be attached. Otherwise amounts remain and sending/conversion requires explicit reconciliation through quote editing. Fiscal emission remains disabled.

New PDFs read receiver_snapshot and issuer_snapshot. Legacy PDFs retain their previous byte output. Ordinary revisions keep saved parties; account edits never rewrite sent snapshots.

QA: tests/quote-billing-control.php accepts an isolated /work/qa/site/wp-load.php and task work directory, rejects other DB names and intercepts mail. Existing resolver backend and WSAA tests remain unchanged. Full operational conversion is tested separately in QA.

Deployment: selective code only; no database migration. Guard all exact production source hashes. Back up and rehearse restore outside the site. Upload dependencies before activation; verify hashes, PHP74 lint, quote986/PDF/customer29 and counters. Rollback restores original code, retains inert new dependencies, snapshots and audit data. Never restore a database over current business records.

# Quote detail and compact A4 commercial PDF

Candidate based on canonical master `db2936111951bc5781b61e2134fff59844adf327`, 2026-10-03.

The staff quote detail rendered billing twice and put edit/send/conversion after files and receipts. The commercial PDF for internal ID 986 (commercial #10002, version 3) used two pages; its second page contained only the portal CTA. Production was inspected through the authenticated UI and the exact downloaded PDF.

Changes: show the saved total and existing state-dependent actions at the top; one billing summary inside an accessible disclosure, with a visible pending-fiscal notice that opens the disclosure; payments and receipts together; activity collapsed with an event count and translated artwork association events; file analysis grouped with its file. Preserve all form endpoints, nonces, identity/price/version guards, preview restrictions and file associations. Responsive action targets are at least 44 px.

PDF: keep A4, all frozen identity/contact/amount/condition fields and original text sizes. Group receiver identity and contact on compact lines, reduce header and vertical spacing, and use denser leading only when necessary. Normal item descriptions stay with their first line across pages. Separators and the total background have clearance from adjacent text. No recalculation, cropping, omitted items or preview tokens.

Validation on a separate synthetic database with external HTTP and mail intercepted: billing-control 39, billing-edge 13, conversion 5, customer portal artwork 10 checks pass. PHP and JavaScript syntax pass. Desktop and 390 px mobile reviewed, no horizontal overflow or browser errors; fiscal disclosure and customer dialog checked without saving. The exact snapshot remains unchanged. Exact #10002, six items and three approximately 300-character descriptions each produce one A4 page; all source words and amounts from the current #10002 remain present. Thirty items produce three complete A4 pages. Rendered pages checked for clipping and overlap.

One-page capacity is determined by wrapped content and issuer/contact/conditions, not an arbitrary fixed item limit. Extremely large proposals cannot be complete and legible on one A4 page. A possible separately approved future option is a one-page commercial summary with a clearly identified detailed annex; this candidate preserves complete pagination instead of silently discarding content.

State: candidate, not merged or deployed. Follow docs/GRAPHEX_RELEASE_POLICY.md after explicit integration/publication approval: fresh remote/concurrency check, verified selective backup, merge canonical, deploy exactly four changed runtime files from canonical, PHP 7.4 syntax, authenticated smoke, freshly downloaded PDF, protected quote/catalog hashes and final manifest verification. Do not deploy from a dirty historic checkout.

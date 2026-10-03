# External artwork references v1

The deployed implementation extends `GE_WTP_Quote_Artwork_V2` and the existing quote/order document collections. An external reference has no private bytes or analysis result. Explicit staff import creates a separate immutable local version with provenance and queues the canonical File Analyzer.

`deploy.py` and `rollback.py` are guarded release snapshots for the 2026-10-02 deployment. The source manifest must match the target exactly. Rollback refuses later file changes, preserves all DB metadata and file bytes, and reloads PHP-FPM after restoring code. Do not reuse the release directory against a different environment without reconciling its manifest and paths.

The QA scripts capture the integration checks actually executed against an isolated WordPress/MariaDB restore, not standalone unit tests. They require the task's own `qa/site`, context JSON, fixture accounts, private storage and local HTTP router. They must never be pointed at production as a test database. Browser checks use the installed Playwright runtime and browser. Fixture data and private database configuration are intentionally not committed.

Verified: URL/SSRF restrictions; provider recognition; real public download; HTML rejection; permissions/nonce/session scope; retry identity; mixed uploads and links; Create/Edit persistence; detach with history; stable item UUID mapping; quote-to-order preservation; production opening/import; order-to-quote synchronization; canonical analyzer reference; desktop/mobile layout.

Operational limits: 250 MiB/file, 40-second download budget, 3 redirects, public IPv4 destinations only, 30 import attempts/hour/actor, shared 2 GiB daily reservation, 2 GiB disk reserve, 30 references/quote. Google Drive public file links and Dropbox public downloads have narrow URL adapters. WeTransfer/OneDrive landing pages and protected Drive resources stay external unless the URL yields permitted file bytes. The browser Drive picker is not a backend OAuth credential.

No bytes are imported when saving a link. No new antivirus or OAuth integration is provided. Staging retention remains a separate maintenance decision. Pure external references cannot approve or release production artwork.

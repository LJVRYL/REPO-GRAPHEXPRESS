# Deco Prints and artist portal

Native customer gallery management, private originals, reviewed public revisions,
frame composition and quality checks, and separate Mercado Pago artist onboarding.
All feature flags default off and require private instance configuration.

The payment candidate includes Checkout Pro preferences, signed queued notifications,
server reconciliation, ownership checks and reversal guards. Local tests use synthetic
accounts and payments. Live sales must remain disabled until real account onboarding,
reviewed processing tariffs, accounting acceptance and controlled payment acceptance pass.
Zero artwork prices remain configurable; first-party processing for those orders needs
acceptance before enabling sales. Each party invoices its own portion as requested.

Do not commit credentials, originals, source mappings, commercial recipes or backups.
Set GE_DECO_FRAME_SOURCE_HOSTS to an explicit JSON hostname allowlist in private worker
configuration; missing configuration fails closed. Price observations never change
approved selling prices. Schedule observations weekly with an eight-day freshness guard.

Deploy only reviewed runtime paths from canonical Git, after verifying actual target
hashes and selective backups. Use deployment locks and stop active requests during
replacement. Verify bootstrap and re-read deployed hashes before reopening traffic.
Restore the exact backed-up files on failure; disable private feature flags first.

Local evidence: 306 assertions in ten groups, plus syntax and release-path checks.
Real transactions and production readiness are separate acceptance gates.

# Graphex artwork improvement production bridge

Deployed 2026-10-01 America/Buenos_Aires. UI is Beta and records an order-scoped Task Pack. Image execution is unavailable: Runtime Certification found loadable agent methods but no certified image executor or provisioned HTTPS bridge. A saved Graph request is not an AI-GRUPO queued task.

Canonical Graph service: `GE_WTP_AI_Artwork`; existing Documents metadata/storage/analyzer and Artwork Library approval fingerprint remain authoritative. Never overwrite a source. Candidate import checks request/task, parent, source checksum, MIME, size and output checksum; each successful result is a new UUID with parent_version_id. Candidate visibility is filtered only in customer views/downloads, not mutation accessors. Selection requires human review, invalidates previous approval and cannot replace a released product. Customer approval remains a separate exact-version/fingerprint gate. Preflight remains pending human review.

Task Pack includes project, order_id, order_item_id, artwork_id, version_id, base_version_id, artifact_ref, checksum_sha256, file_analysis_ref, preflight_summary, instruction, requested_capability, requested_skill and output_contract=new_candidate_version. Status/result Actions use existing SSH actor transport. The multipart creation callback is not a duplicate CLI binary importer; graph.artwork.create_version remains planned.

The Actions registry and unavailable Next adapter are prepared in feature/graph-ai-improve-production, pending main integration and coordinated bundle update. Do not infer live main capability from branch metadata. No live image executor has been certified. A method's runtime_available alone cannot certify image execution. Future activation needs fresh (<300s) runtime_available + executor_available evidence, a private HTTPS endpoint and credential provisioning. Never put secrets in this runbook.

Backup: `/root/backups/graph-wordpress/20261002T004615Z`, hashes/gzip/list/counts verified. Code rollback scripts in `/root/ge-backups/ai-improve-state-20261002`, `ai-improve-preview-20261002`, `ai-improve-workflow-20261002`, `ai-improve-20261002`; execute in that order to restore pre-feature code, preserving DB/uploads/version history. No automatic data restoration.

Read the current Result Pack via results Registry objective graph-artwork-ai-improve-production for evidence, commits, limits and screenshots. To activate execution, certify a real executor with an isolated fixture and provision the bridge; blocked requests never replay automatically.

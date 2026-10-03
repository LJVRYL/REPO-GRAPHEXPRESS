# Graphex release operations

Antes de modificar, verificar repositorio, rama, worktree, status y workers concurrentes. Preservar cambios ajenos y usar checkout aislado.

La rama canónica vigente es master; verificar HEAD remoto antes de cada release. Seguir docs/GRAPHEX_RELEASE_POLICY.md y GRAPHEX_CANONICAL_STATE.md. Flujo obligatorio: branch/worktree → tests → merge canonical → deploy desde canonical → verified → canonical_synced. No marcar DONE por un deploy sin merge; reconciliar en el mismo Work, salvo excepción explícita del usuario.

Antes de integrar/deploy respaldar y verificar; comparar manifiestos y proteger snapshots, numeración y datos reales. Publicar sólo allowlist custom. Nunca copiar DB/config/secrets/backups a Git, reemplazar producción completa, reset/clean/force-push ni borrar ramas/worktrees sin autorización.

Result Pack: repo/rama/HEAD, source commits, tests, manifest, backup/rollback, estados candidate/merged/deployed/verified/canonical_synced y blockers concretos. No afirmar sincronización si un hash productivo queda sin explicar.

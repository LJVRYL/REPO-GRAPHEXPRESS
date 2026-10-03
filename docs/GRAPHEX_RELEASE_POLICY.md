# Graphex — política de releases

Vigencia: 2026-10-03. Proyecto: graph-express. Recurso: graph-wordpress-prod.

## Flujo obligatorio

`branch/worktree → tests → merge canonical → deploy from canonical → verify`

La rama canónica se consulta en Git y en el remoto antes de cada release. En esta auditoría es `master`; no se sustituye por el HEAD de un checkout de trabajo.

Estados: `candidate`, `merged`, `deployed`, `verified`, `canonical_synced`.

`DONE` requiere `canonical_synced=true`: el commit está contenido en la rama canónica, el manifest desplegado es reproducible desde ella y las diferencias están explicadas. Un despliegue previo al merge requiere excepción explícita y reconciliación dentro del mismo trabajo antes de DONE. «Desplegado, sin merge» es un pendiente, no un cierre normal.

## Evidencia mínima

- Repo, remoto, rama canónica y HEAD antes/después; integration/release commit y relación de ancestros.
- Archivos autorizados, SHA-256 de sus bytes y diferencias de producción explicadas; no sólo versión nominal del plugin.
- Matriz de pruebas de la versión final integrada. Los resultados de releases anteriores aportan contexto, pero no reemplazan QA del candidato.
- Backup privado verificado, guardas de concurrencia y rollback selectivo utilizable.
- Deploy desde el commit canónico. Si producción ya coincide, registrar `NO-OP` y ejecutar smoke.
- Relectura de hashes tras verificar; cambio concurrente requiere revisar, recapturar e integrar antes del cierre.

## Preservación y coordinación

No alterar el checkout ajeno ni incorporar sus cambios abiertos indiscriminadamente. Usar aislamiento; conservar historia y provenance de código recuperado de producción. No borrar branches/worktrees, hacer force push ni ejecutar operaciones destructivas.

Inventariar workers activos. Cada worker publica HEAD, manifest, pruebas y backup en su Result Pack; debe declarar `canonical_synced=false` mientras falte integración. Sólo el coordinador que verifica la rama y los hashes puede cambiarlo a true.

No poner backups, base, wp-config, originales de clientes ni secretos en Git. Los registros contienen únicamente referencias simbólicas a credenciales y rutas privadas de rollback.

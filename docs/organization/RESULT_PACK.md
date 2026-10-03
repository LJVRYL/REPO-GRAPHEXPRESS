# Result Pack — GRAPHEX SaaS Foundation v1

Continuación operativa de Mi empresa, verificada y desplegada por milestones. Modelo entregado: **una organización por instancia/DB/docroot/principal/salts/storage propios**. No se habilitó multitenancy de DB compartida ni signup público.

## Resultado

- Graph Express mantiene su recurso y sus históricos.
- Empresa QA independiente operativa: usuario y cliente propios, presupuesto, pedido, emisor, branding, documentos/PDF, artwork privado, proveedor y stock.
- Roles reales y once módulos protegidos server-side; herencia de privilegios WP y administración de código ejecutable bloqueadas para usuarios de empresa.
- Onboarding admin/QA, import real de configuración, export versionada de configuración y export separada de datos operativos sin secretos.
- PDFs de presupuesto y pedido del portal usan el snapshot de organización/emisor/receptor; emails y portal leen settings. QA intercepta correo/HTTP: no hubo envío real, pago ni emisión fiscal.

QA local: http://localhost:19050/qa-preview?view=company (vista previa con datos sintéticos); portal: http://localhost:19050/qa-preview?view=portal. Las sesiones reales, guardado y descargas se probaron por HTTP normal con credenciales de fixture privadas fuera de Git.

## Evidencias

Consultar tenant-acceptance-results.json, tenant-hardening-results.json, http-tenant-qa-results.json, reciprocal-isolation-results.json, qa-auth-isolation-results.json, graph-auth-isolation-results.json, fresh-instance-results.json, qa-foundation-results.json y production-endpoint-scope-matrix.json. release-state.json consolida cantidades y HEAD final.

La producción conserva hashes del presupuesto 986, PDF986, catálogo de emisores, reglas de numeración y cantidades de pedidos/presupuestos. Backups, rollback e integración: DEPLOY_ROLLBACK_INTEGRATION.md. Matriz de todas las entidades: TENANT_SCOPING_MATRIX.md. Readiness completo: GRAPHEX_SAAS_READINESS.md.

## Git / integración

Branch: feature/graphex-saas-foundation-v1. HEAD final: release-state.json. Baseline canónico master: 2ef226cae78391c7719fce5de5b96d3047e5b866. No se modificó el checkout compartido ni se hizo merge/push desde esta tarea. La reconciliación canónica autorizada en otro trabajo incorpora estos commits, manifests y los cambios concurrentes Portal/Landing/CRM/Funnel. canonical_synced=false hasta verificar inclusión en master.

## Decisiones y límites

El scoping por recurso dedicado es la arquitectura V1, no un simulacro de tenant en una DB global. No hubo backfill/migración destructiva. El código nuevo se reconcilió con Portal v3 y Landing v1 y las guardas detuvieron un intento cuando detectaron drift concurrente; se resolvió antes de escribir.

Una corrección del harness QA recuperó sólo el registry local desde su auditoría después de un error de charset al cambiar puerto; producción y datos operativos permanecieron intactos. Las pruebas finales se repitieron después. Otro error del harness de core compartido fue resuelto vinculando las rutas administrativas al ABSPATH propio; el provisionador nuevo copia el core físicamente. Cookies de pruebas se regeneran para evitar falsos fallos por expiración.

Pendientes fuera del cierre operativo: import arbitrario de datos con ID remapping, ZIP binario desde UI, conectores QA reales, aislamiento OS/FPM/cuotas/observabilidad para terceros no confiables y servicios propios de SaaS público. Están marcados PARTIAL/BLOCKED sin presentarlos como entregados; signup, suscripciones, billing SaaS y DNS/SSL automatizado siguen excluidos.

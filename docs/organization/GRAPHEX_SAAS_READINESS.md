# GRAPHEX SAAS READINESS — Foundation v1

Fecha: 2026-10-03. Foundation operativa verificada; SaaS público no habilitado. Reemplaza el cierre parcial de configuración.

## Arquitectura

Una organización por instancia WordPress, base, principal SQL limitado a esa base, document root, salts/cookies, storage y credenciales propios. GE_ORGANIZATION_INSTANCE_ID se fija por despliegue; ge_organization_instance_binding_v1 verifica organización/DB/root/home/storage. La URL nunca selecciona otro recurso. Esta estrategia equivalente segura evita migrar masivamente el SQL y los archivos legacy.

Graph conserva su instancia. empresa-qa es una instalación nueva funcional con datos sintéticos propios. Registros históricos pertenecen a Graph por su recurso exclusivo; nuevos usuarios/CPT/pedidos reciben stamps. Los registros de configuración QA del registry no habilitan operación en la DB de Graph.

## Readiness

| Área | Estado | Evidencia / límite |
|---|---|---|
| Organization / Mi empresa | READY | Identidad, branding, emisores, numeración, documentos, preferencias, equipo, módulos, integraciones, onboarding en Gestión |
| Scoping operativo | READY | Repositorios SQL y recursos separados; TENANT_SCOPING_MATRIX.md |
| Data isolation | READY | SELECT/UPDATE cruzados denegados; URLs/headers no seleccionan organización operativa |
| Auth/users | READY | Usuarios propios y salts distintos; credenciales/cookies ajenas rechazadas |
| Roles | READY | Siete roles operativos, guards de rutas/servicios; herencia de privilegios WP bloqueada; empresas no instalan/editan código |
| Module flags | READY | Once módulos protegidos server-side en frontend/admin-post/AJAX/REST |
| QA operativa | READY | Usuario→cliente→presupuesto→pedido→PDF→archivo→proveedor/stock propios |
| PDF/email/portal | READY | Settings de empresa y snapshots; PDF real del presupuesto y del pedido del portal; emisor/receptor conservados |
| Fiscal profiles | READY | Modelo existente reutilizado; seed Graph bloqueado fuera de Graph; ARCA/emisión fiscal deshabilitados |
| Numeración | READY | Secuencia por DB; prefijos nuevos; IDs/históricos sin renumerar; ajustes avanzados de secuencia requieren operación admin controlada |
| Config export/import | READY | Manifest v1, identidad/branding/módulos/roles disponibles/emisores; import real en QA sin secretos ni verificación fiscal transferida |
| Export de datos operativos | READY | JSON separado, owner/admin, clientes/equipo/versiones/pedidos/items/proveedores/stock/finanzas/costos/referencias documentales; sin credenciales |
| Import/restore de datos operativos | PARTIAL | Export estructurado disponible; restore completo por backup del recurso. Import libre/ID remapping requiere migración controlada |
| File isolation | READY | Roots propios, directorios privados, ownership/nonce y bloqueo estático; históricos sin migración masiva |
| Bundle binario de archivos | PARTIAL | Sin ZIP de artworks/facturas desde UI; se respalda el storage privado del recurso |
| Integrations/secrets | READY | Config/referencias por empresa; entorno propio; secrets fuera de Git/export y sin herencia automática |
| Conexiones QA reales | PARTIAL | Mail/HTTP QA interceptados; SMTP, ARCA, pagos, comercio externo/Drive se reconfiguran y validan en destino |
| Commerce provider | READY | none/woocommerce; Woo conserva storage transaccional interno legacy, sin exigir conexión a tienda externa; catálogo Graph no compartido |
| Onboarding admin/QA | READY | Provisioning limpio + seis pasos + habilitar operación; signup público cerrado |
| Audit | READY | Organización, actor, UTC, before/after; operaciones conservan auditoría transaccional; export auditado |
| Backups/rollback | READY | Backup privado verificado por milestone, guards SHA/concurrencia, rollback automático y prueba QA conservando datos |
| Observability | PARTIAL | Auditoría/logs/manifest/smoke; faltan alertas y métricas centralizadas por empresa |
| Deployment model | READY | Instancias dedicadas, código reusable, despliegue selectivo; sin router multiempresa compartido |
| Aislamiento OS/FPM público | PARTIAL | QA local comparte usuario de desarrollo/código confiable. Terceros no confiables requieren usuarios OS/pools/contenedores/cuotas propios |
| Shared-DB multitenancy | BLOCKED | No habilitado; requeriría columnas/FKs/repositorios y nueva auditoría. No se presenta como implementado |
| SaaS público | BLOCKED | Sin signup público, planes, subscriptions, billing SaaS ni DNS/SSL automáticos |
| Canonical sync | PARTIAL | Branch propia conservada; reconciliación coordinada en otro trabajo. canonical_synced=false hasta verificar HEAD final en master |

## Verificación y límites

Los JSON finales de aceptación, hardening, HTTP, autenticación y aislamiento recíproco son la evidencia. Result Pack consolida cantidades/commits. No hubo correos reales, emisión fiscal, cobros ni cambios sobre Multiplex 986. Emisor QA sintético sin verificación oficial ARCA.

## Gaps para SaaS público

Automatizar provisioning/restore, aislamiento de procesos y cuotas, lifecycle de organizaciones, observabilidad, conectores reales por instancia, dominios y backups programados. Suscripciones/billing/signup siguen fuera del alcance. Cambiar a DB compartida sería otro proyecto; no es requisito para operar otra empresa con esta foundation.

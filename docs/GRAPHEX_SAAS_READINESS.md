# GRAPHEX SAAS READINESS — v1

Fecha: 2026-10-02. Evaluación del código productivo reconciliado con Customer Tax Resolver `a55f2be`. Esta entrega introduce una capa de configuración; no convierte los repositorios operativos globales en multi-tenant.

## Arquitectura

- Organización: raíz `ge_organizations_v1`, claves estables, revisión, estado, modo, timestamps, settings, miembros y progreso de onboarding.
- Graph Express: `graph-express`, modo `legacy-primary`. Migración explícita, idempotente y aditiva; no cambia clientes, documentos, numerador o pedidos.
- Empresa QA: ID nuevo generado en servidor, modo `qa-config-only`. Settings y emisores independientes. Sin operación global habilitada.
- Usuario personal: cuenta WordPress existente, separada de membresía/rol de empresa.
- Emisores: catálogo productivo `ge_billing_issuer_profiles_v1` reutilizado. QA usa el mismo normalizador y esquema con almacenamiento por organización; no se copia habilitación fiscal ni credenciales.
- Integración: provider de comercio configurable `none` / `woocommerce`. Referencias simbólicas por organización; cada adaptador todavía necesita consumirlas.
- Storage: constructor `organizations/{organization_id}/{domain}/{filename}` con dominio allowlisted y rechazo de traversal. No mueve archivos ni reemplaza autorización de descarga.
- Implementación aditiva como MU-plugin, separada del control de facturación del presupuesto que se encontraba en curso.

## Matriz de scoping

| Dominio | Fuente real inspeccionada | Clasificación actual | Foundation / requisito antes de operación multiempresa |
|---|---|---|---|
| Organization/settings | `ge_organizations_v1` | tenant-scoped | READY: acceso por membresía, revisión y auditoría |
| Emisores | option `ge_billing_issuer_profiles_v1` | global legacy / QA scoped | PARTIAL: catálogo original conservado; adaptar todos los consumidores a org context |
| Usuarios | WordPress users + membresías de organization | global identity / scoped membership | PARTIAL: no conceder caps WP globales por roles de empresa |
| Customers | `GE_WTP_Customers`, users/usermeta | necesita migration | Vincular cliente a organización; filtrar búsquedas, perfil, portal, export, IDs directos |
| Quotes | CPT `ge_commercial_quote`, snapshots/meta | necesita migration | Tag/backfill y guards en todas las lecturas/escrituras/descargas |
| Orders | WooCommerce orders, items, meta | necesita migration | Repositorio por organización, checkout, estados, pagos y portal |
| Suppliers | supplier portals + `ge_op_supplier_refs` | necesita migration | Aislar usuarios, accesos, despacho y documentos |
| Stock | `ge_op_stock_items`, moves, purchases, receipts | necesita migration | Claves org + filtros de transacción; no compartir existencias |
| Finance | tablas `ge_op_*` financieras | necesita migration | Segregar entradas, cuentas, cobros y documentos |
| Products/costs | WooCommerce + Cost Engine | global / necesita migration | Adaptador commerce, catálogo/costos por org |
| Communications | notification/newsletter/logs | necesita migration | Destinatarios, sender, templates, auditoría y permisos |
| Documents | order meta `_ge_markcom_documents` + storage | necesita migration | Namespace y autorización org en carga/descarga |
| Artifacts/artwork | artwork library / quote files | necesita migration | Filtrar biblioteca e IDs y paths por org |
| Work numbers | tabla `ge_work_numbers` AUTO_INCREMENT | global legacy | Numerador transaccional por org; mantener históricos y floors |
| Referencias técnicas | monedas, zonas horarias, países | shared/reference | No confundir datos de referencia con datos comerciales |
| WP core/plugins | una instalación | global | Una sola empresa operativa en v1 |

## Readiness

| Área | Estado | Evidencia / límite |
|---|---|---|
| Tenant settings | READY | CRUD con validación, membresías, revisión y bloqueo de escrituras |
| Auth/users | PARTIAL | Identidad WP reutilizada; QA-only bloqueado en frontend, admin/AJAX y REST |
| Roles | PARTIAL | Siete roles de organización; no son aún una matriz de autorización operativa |
| Data isolation | BLOCKED | Repositorios operativos sin org filter; segunda empresa no puede operar |
| File isolation | PARTIAL | Namespace validado; consumidores y permisos aún legacy |
| Branding | PARTIAL | Gestión y correo leen configuración; PDF renderer y portal completo siguen pendientes |
| Fiscal profiles | PARTIAL | Catálogo existente reutilizado; consulta ARCA real sigue pendiente de habilitación |
| Integrations | PARTIAL | Providers/refs configurables, adapters siguen globales |
| Secrets | PARTIAL | No secretos ni refs en export; registro externo existente, falta resolución org-aware en cada adapter |
| Backups | PARTIAL | Rollback aditivo por archivo; restore por tenant operativo aún bloqueado |
| Export/import config | READY | JSON versionado, validación, nuevo ID QA, no usuarios/secretos, nueva verificación fiscal |
| Export operational/files | BLOCKED | No implementar export global bajo apariencia de tenant export |
| Module flags | PARTIAL | Preferencias persistidas por org; falta enforcement completo por adapter/endpoint |
| Onboarding | PARTIAL | Importación admin crea configuración QA; wizard operativo y dashboard vacío pendientes |
| Observability | PARTIAL | Auditoría de cambios de configuración con org/actor/before/after |
| Deployment model | PARTIAL | MU-plugin aditivo, una instalación/empresa operativa; registro público cerrado |

## Ajustes visibles y efecto real

Mi empresa: datos generales, branding, emisores, numeración, documentos/PDF, preferencias comerciales, usuarios, integraciones, email, portal, operaciones, módulos y export/import. Los campos de numeración/comerciales/documentos/portal/operaciones se guardan como configuración preparatoria; no se afirma que ya modifiquen todos los consumidores. Mi perfil muestra la identidad personal y acceso al flujo de cambio de contraseña existente.

La marca en la cabecera/pie de Gestión y textos de emails pasan por la organización primaria. El renderer PDF sigue usando su composición anterior: cambiar logo/accent/footer de PDF requiere un hito propio con snapshot y QA visual para no modificar documentos históricos. El correo real no se envía durante QA y se conserva el transporte/remitente SMTP vigente.

## Gaps para SaaS público

1. Repositorios y servicios tenant-aware; no confiar sólo en el menú ni en parámetros del navegador.
2. Backfill de pertenencia y constraints; fixtures A/B con pruebas de acceso a IDs, búsquedas, archivos, REST, AJAX, jobs y webhooks.
3. Resolver actor + organization desde sesión autenticada; platform-admin auditado; prohibir cambio implícito de org.
4. Roles operativos por organización sin caps globales; invitaciones y revocación.
5. Snapshots de branding/settings en documentos nuevos; conservar PDFs históricos.
6. Adaptadores de numeración, impuestos, defaults, email, portal, operación y módulos.
7. Export/import de datos operativos con remapeo de IDs, validación de relaciones y archivos opcionales.
8. Wizard de seis pasos y dashboard vacío operativo; QA config-only no equivale a ese criterio.
9. Integraciones/credential provider y storage plenamente scoped, custom domains y DNS/SSL por separado.
10. Restore por tenant, backups consistentes, observabilidad, cuotas y eventual billing SaaS.

## Estado de hitos

M0 auditoría/reconcile: realizado. M1 raíz + UI: implementado. M2 settings/issuer: parcial. M3 scoping: matriz y bloqueo preventivo, migración pendiente. M4 roles: configuración implementada, autorización operativa pendiente. M5 export/import configuración: implementado. M6 flags: persistencia implementada, enforcement pendiente. M7 onboarding: import admin/QA parcial. M8 namespaces/refs: preparación parcial. M9 readiness: entregado. M10: resultados de pruebas y estado de deploy se registran en RESULT_PACK.md.

No se habilita registro público, billing SaaS, multiempresa operativa, emisión fiscal ni migración de secretos. No se realiza merge automático.

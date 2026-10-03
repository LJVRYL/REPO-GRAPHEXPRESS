# Tenant scoping — SaaS Foundation v1

El modelo operativo vigente es **una organización por instancia WordPress, base de datos, usuario SQL limitado a esa base, document root, cookies/salts, almacenamiento e integraciones propios**. La URL nunca selecciona el recurso. No se habilitó un router multiempresa sobre una base compartida.

| Dominio | Fuente real | Scope vigente | Aislamiento / autorización |
|---|---|---|---|
| Organization/settings | `ge_organizations_v1` | Raíz de instancia | ID fijado por despliegue; binding de DB/root/home/storage; owner/admin, nonce, revisión y auditoría |
| Clientes | WP users/usermeta | Tenant por DB dedicada | Usuarios y sesiones propios; fichas/acciones por permisos y ownership |
| Usuarios/equipo | WP users + membresía | Tenant por DB dedicada | Roles operativos; sin heredar privilegios de otras organizaciones; creación local |
| Presupuestos | CPT `ge_commercial_quote`, versiones | Tenant por DB dedicada + stamp nuevo | Módulo/rol en rutas y mutaciones; ownership del cliente; snapshots congelados |
| Solicitudes | CPT `ge_quote_request` | Tenant por DB dedicada + stamp nuevo | Guards del módulo presupuestos; ownership existente; Portal v3 preservado |
| Pedidos | Woo orders/items/meta | Tenant por DB dedicada + stamp nuevo | Server-side módulo/rol y ownership; snapshot propio heredado del presupuesto |
| Proveedores | Supplier Dispatch/Workspace, opciones/usuarios | Tenant por DB dedicada | Sin proveedores legacy de Graph en nuevos tenants; escritura validada por módulo/rol |
| Stock/equipos | `ge_op_*` | Tenant por DB dedicada | Sin seed comercial de Graph; permisos diferenciados de consulta/escritura |
| Administración/finanzas | Ledger, gastos, pagos y pedidos en `ge_op_*` | Tenant por DB dedicada | Roles financieros; ledger/auditoría propios; no pruebas con pagos reales |
| Costos/productos | Cost Engine, CPT rates, productos Woo, recetas | Tenant por DB dedicada | Capabilities de costos; catálogo Graph legacy bloqueado en otra instancia; commerce provider opcional |
| Documentos | Metadatos por pedido + storage privado | Tenant por DB/root dedicados | Descarga con nonce/ownership; URLs privadas denegadas; sin migración masiva |
| Artworks/archivos | Artwork Library/v2, metadatos, documentos | Tenant por DB/root dedicados | IDs locales, uploads y archivos privados propios; uploader y descargas autorizados |
| Comunicaciones | CPT/options/logs del sitio | Tenant por DB dedicada | Módulo comunicaciones; remitente/branding del sitio; QA sin salida externa |
| CRM/respuestas rápidas | Overlay adicional desplegado | Tenant por DB/organization explícita del módulo | Guards de comunicaciones reconciliados; se conserva la política propia del módulo |
| Emisores | `ge_billing_issuer_profiles_v1` | Tenant por DB dedicada | Modelo existente reutilizado; seed legacy sólo Graph; import invalida verificación y elimina referencias |
| Integraciones | Opciones/constants/provider refs propios | Tenant por recurso y credenciales | Referencias simbólicas; nunca secretos en export/Git; conexiones se reconfiguran en destino |
| Auditoría | `ge_org_audit`, eventos operativos | Tenant por DB + organization_id | Actor, UTC, before/after; export auditado; revisiones y lock de settings |
| Export | JSON versionado config/datos | Tenant actual únicamente | Owner/admin; sin passwords, tokens, claves, certificados privados, cookies ni referencias de credenciales |
| Numeración | Secuencia comercial en base local | Tenant por DB dedicada | Prefijos en snapshots nuevos; IDs y documentos históricos preservados |
| Código/framework | WP/Woo/plugin/componentes | Shared/reference confiable | Código reusable; usuarios de empresa no pueden instalar/editar código ejecutable |
| Referencias técnicas | Tipos fiscales/materiales/esquemas | Shared/reference | No son registros de clientes; normativa efectiva se mantiene en el resolver existente |
| Branding público de Graph | Assets comerciales/landing de Graph | Global público del sitio Graph | No se copia a nueva empresa; identidad propia desde Mi empresa |

No hay tablas operativas de A y B coexistiendo en el mismo repositorio SQL de aplicación. Los registros históricos pertenecen implícitamente a Graph por su recurso exclusivo; se agregan stamps a registros nuevos sin backfill destructivo.

**Migration pendiente sólo si se elige otro modelo:** compartir una DB entre empresas requeriría columnas/FKs, repositorios filtrados y nueva auditoría completa. Ese modo no está habilitado. Para SaaS público con administradores no confiables también se requieren contenedores/usuarios OS/FPM separados y límites de recursos; la QA local comparte código confiable y demuestra aislamiento de aplicación, bases y autenticación.

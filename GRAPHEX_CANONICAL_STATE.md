# GRAPHEX_CANONICAL_STATE

Última reconciliación: 2026-10-03. Estado: **canonical_synced=true**. Último release: detalle de presupuesto, PDF A4 con QR, IVA por versión y elección de alternativas. Código desplegado desde el merge canónico y verificado; el cierre documental no modifica el payload. El release-state.json externo registra el HEAD exacto actual y las verificaciones finales.

- Repositorio canónico local: `/mnt/f/GIT/REPO-GRAPHEXPRESS` (`F:\GIT\REPO-GRAPHEXPRESS`).
- Remoto: `https://github.com/LJVRYL/REPO-GRAPHEXPRESS.git`.
- Rama canónica real: **master**, confirmada por HEAD remoto. HEAD anterior: `2ef226cae78391c7719fce5de5b96d3047e5b866`.
- HEAD de código integrado y verificado antes del commit documental: `7e9379990623575b9953d918a0accd2ea8719a6e`.
- Canonical HEAD: `refs/heads/master`; resolver al consultar porque el documento se versiona dentro de la propia rama. Merge de reconciliación: `ceba7258a2bfd2db03961fc091d152cd6e2e6630`. Tag de código verificado: `graphex-canonical-20261003` (mismo payload productivo; el cierre documental posterior no cambia ese código). El SHA exacto actual queda en el Result Pack y release-state.json entregados.
- Rama de integración: `reconcile/graphex-prod-to-canonical-2026-10-03`, checkout aislado `/tmp/graphex-canonical-reconcile-20261003-remote`.
- Captura de drift: `reconcile/prod-drift-20261003`; cada historial importado conserva sus padres mediante merges, incluidos árboles originalmente en raíz de plugin alineados como subtree.
- Producción: perfil SSH simbólico `ai-grupo-ferozo-prod`, `/home/graphexpress/public_html`. No es Git checkout; usa deploy selectivo.
- Código custom: 722 archivos, hashes en `docs/production-custom-files.sha256`. Fingerprint del mapa JSON ordenado: `65cb7b37ed3b582dbc997deef7eb9e1603b68b37eb1a28a49642c52a1495a566`.
- Producción y candidato: cero diferencias de bytes, archivos faltantes o archivos extra dentro de los roots activos. WordPress core, WooCommerce, runtime externo y secretos de instancia se provisionan como dependencias; no están incluidos como código custom.
- Dependencias verificadas: PHP 7.4 productivo, PHP 8.3 QA; versiones WordPress/WooCommerce y hashes protegidos constan en production-readonly-smoke.json. File Analyzer usa `/opt/ge-file-analyzer/runtime/bin/python3` y los binarios fijados en `bin/runtime-toolchain.json`.

## Protección e integración

Se preservaron numeración, múltiples artes/chunk upload, enlaces externos, analizador, quote→order, snapshots de emisor/receptor, PDF, portal/solicitudes, costos/productos, CRM, Gestión/operaciones, proveedores y organización. No hubo migración ni copia de DB productiva. El presupuesto 986 y catálogo de emisores conservaron sus hashes durante el smoke de sólo lectura.

El Portal SaaS 6fd9b9d fue sucedido sólo en su archivo portal por el hotfix Legales 0e26728. La versión live preservada elimina la ruta/nav Legales; conserva branding, seguridad y snapshots. No se despliega el candidato completo del funnel.

Las pruebas históricas se alinearon con dependencias actuales y contratos explícitos: actor SaaS, perfil/emisor fiscal seleccionado, refresco confirmado y privacidad Portal v3. Son cambios de tests; el código deployable permanece byte por byte idéntico a producción. QA nueva: `graphex_crm_v1_qa_reconcile_20261003`, correos y HTTP externo interceptados.

## Ramas pendientes

- Funnel v1.1 `ff37575`: candidato no desplegado; completar QA visual y luego tests→merge master→deploy selectivo. Sólo `0e26728` está publicado e integrado en esta reconciliación.
- Quick Replies `7747611`: candidato no desplegado, conservado sin habilitar. La referencia estática ya usada por CRM no equivale a desplegar su módulo completo.
- Checkout compartido `feature/customer-portal-preview-artwork`: conservar su trabajo local y ramas históricas; no usar sus archivos sucios como fuente de deploy de esta release.
- Worktrees y ramas antiguas siguen disponibles como merged/stale. Inventario completo y ancestros en el Result Pack. Ninguno se elimina en este trabajo.
- AI-GRUPO Actions/runtime/registry relacionados: referencias registradas separadamente; no se reconcilia aquí su código de producto. Sí se integró la política documental a AI-GRUPO/main, merge `8b32b56d91c28f9a02a01fd7b6b4fa938fcfe305`.

## Despliegue reproducible

1. Partir del commit/tag canónico aprobado; comprobar master remoto, árbol limpio y matriz verde. Usar checkout aislado si el principal tiene trabajo abierto.
2. Construir artefacto con `git archive` del commit canónico y allowlist exacta de roots custom activos: `wp-content/plugins/ge-webtoprint-calculator`, `wp-content/mu-plugins`, `wp-content/themes/graphexpress-child`. Validar cada SHA-256 del manifiesto. El theme antiguo `printing-services-child` conservado del master histórico no forma parte del deploy activo.
3. Provisionar por separado core/plugins de terceros y runtime fijado; configurar DB, salts, rutas privadas y credential_ref por mecanismo seguro. Nunca incluir wp-config, uploads de clientes ni respaldos en Git/artefacto custom.
4. Comparar con producción. Coincidencia exacta significa NO-OP deploy y smoke. Diferencias requieren plan selectivo, respaldo verificado, hashes antes/después y rollback.
5. Desplegar sólo los archivos aprobados desde canonical; verificar PHP, smoke, 986, permisos, hash de cada archivo y release-state. No reemplazar docroot completo por checkout antiguo.

## Backups y rollback

- Backup custom inicial: `/root/ge-backups/canonical-reconcile-20261003T040212Z`, SHA-256 `886dbee2cc0557b49a2bcc2dd08dfe706848bb7cfec0b3c1ff6ccbb022303a2a`.
- Backup custom final: `/root/ge-backups/canonical-final-20261003T053321Z`, SHA-256 `27b274bf49b3071ea867fa124d2cb27dc8d70e4271a13892117fb7404b0f28d6`. Tar/gzip verificadas y sin cambios concurrentes durante captura. Sin DB sensible.
- Último backup SaaS: `/root/ge-backups/saas-operational-v1-20261003T050617Z`.
- Git inicial y candidato: bundles privados verificados en el directorio de trabajo/backup, referencias documentadas en release-state.json.
- Esta reconciliación requiere NO-OP de producción; rollback productivo no se ejecuta. Si un deploy posterior necesita rollback, restaurar únicamente sus archivos allowlisted desde el backup privado, verificar hashes y smoke; conservar datos y secretos de instancia. Para Git publicado usar revert revisado, nunca force-push/reset/clean.

## Regla permanente

Consultar `docs/GRAPHEX_RELEASE_POLICY.md`: branch/worktree→tests→merge canonical→deploy desde canonical→verified→canonical_synced. DONE exige canonical_synced=true salvo excepción humana explícita y documentada. Un deploy previo a merge queda pendiente hasta reconciliarse en el mismo Work.


## Release de presupuesto / QR / selección — 2026-10-03

- Merge de código desplegado: `48b28ae15f18104616d36252f401843bcf06c706`.
- Diez archivos allowlisted; dependencias nuevas primero, luego modelo y consumidores.
- 115 checks de QA (67 regresiones + 48 IVA/selección); seis PDFs representativos en una página A4, incluida elección descargada del portal; 30 ítems conservan paginación completa.
- PDF de producción #10002 v3: una página, QR verificado. Snapshot y metadatos de 986 y catálogo de emisores intactos. Cero envíos/pagos/ediciones comerciales productivas.
- Fuente de comunicación: `docs/GRAPHEX_CUSTOMER_COMMUNICATION.md`. Alternativas explícitas por grupo; aceptación guarda elección separada de propuesta original. C no agrega IVA; configuración comercial no modifica identidad fiscal.
- Backup selectivo verificado: `/root/ge-backups/quote-detail-qr-20261003T170723Z`. Restaurar solo los seis archivos presentes según manifest; los cuatro nuevos figuran en absent_before. No restaurar DB ni sobrescribir cambios ajenos. Rollback exige revalidar el entorno y el drift, ejecutar restauración selectiva y verificar hashes/smoke; para Git, revert revisado.
- Estado exacto y evidencia del cierre: release-state.json y RESULT-PACK del trabajo. Resolver `refs/heads/master` para HEAD documental actual, reproducible desde el manifest.

# Graphex: perfiles fiscales, sucursales y documentos emitidos v1

Estado: candidato local, no desplegado. Base: `feature/graphex-gestion-v2` en `f36db9c`.

## Modelo existente y compatibilidad

- El cliente comercial es un usuario WordPress/WooCommerce. El perfil principal vive en `_ge_billing_profile`, con CUIT histórico en `_ge_cuit` y razón social en `billing_company`.
- Las direcciones de entrega están en `_ge_delivery_addresses`. Este cambio les asigna IDs estables al volver a guardar la ficha; las entradas anteriores siguen resolviéndose por índice mientras no se modifiquen.
- Los documentos privados de pedido viven en `_ge_markcom_documents` y se descargan mediante `GE_WTP_Documents`, que comprueba propiedad de la orden o permisos internos. Los comprobantes de transferencia permanecen en su flujo de pagos.
- Los presupuestos comerciales versionan su snapshot. `GE_WTP_Billing` decide el tipo fiscal según el perfil receptor y la configuración real del emisor. Este cambio usa ese resolver sin inferir A/B/C desde un flag nuevo.

## Modelo añadido

- `_ge_billing_profiles` en el usuario contiene perfiles adicionales: ID, etiqueta/sucursal, razón social, CUIT, condición fiscal, dirección fiscal, email fiscal, contacto, activo/default y auditoría. El perfil principal se expone virtualmente como `default`; no se migra ni se inventan datos históricos.
- El presupuesto conserva `billing_profile_id`, `delivery_address_id`, el snapshot fiscal ya existente y un snapshot de destino al enviarse. El pedido generado por ese presupuesto copia ambos snapshots a metadatos de orden y a campos WooCommerce pertinentes. Un pedido manual guarda los snapshots al crearse, sin recalcular importes fiscales que el flujo manual no modela.
- El documento emitido reutiliza `_ge_markcom_documents` y el almacenamiento privado. Agrega `issued_by_graphex`, tipo, número, fecha, actor/fecha de subida, snapshot fiscal y relación de versión reemplazada. La versión anterior permanece descargable sólo por staff. No hay delete físico.

## Permisos y límites

- Sólo staff crea y reemplaza documentos emitidos. Cliente autenticado propietario de la orden ve y descarga la versión vigente. El portal no acepta categorías fiscales en su formulario de carga.
- Las organizaciones siguen representadas por un usuario de portal. No existe un modelo de usuarios separados por sucursal; si se habilita uno en el futuro, deberá restringir vistas por sede antes de invitar usuarios parciales.
- La búsqueda de pedidos agrega sucursal, CUIT y dirección al filtro actual, que sólo procesa la ventana de 250 pedidos recientes. La búsqueda histórica completa requiere índice/consulta dedicada.
- Los documentos antiguos categorizados como `factura` continúan en la biblioteca genérica. No se marcan automáticamente como emitidos por Graphex porque el origen no está probado.

## Despliegue y rollback

1. Obtener fuente exacta y checksum del plugin productivo; reconciliar contra este candidato y el trabajo paralelo de papelera/acciones de cliente.
2. Montar staging aislado con copia restaurable, correo capturado, pagos bloqueados o sandbox, acceso privado y noindex. Probar matriz de perfiles, snapshots, factura PDF, reemplazo y permisos, con capturas.
3. Backup nuevo de base, plugin y almacenamiento privado; verificar hashes y restore aislado. Preparar paquete selectivo y copia previa de cada archivo tocado.
4. Desplegar sólo tras QA verde. Hacer smoke test autenticado de Gestión y portal. Si falla, restaurar archivos del plugin y conservar los metadatos/documentos nuevos para conciliación; no borrar snapshots ni facturas.

## Action Registry

`GE_WTP_Documents::attach_issued` y `issued_documents` son servicios internos para `graph.invoice.attach`, `graph.invoice.replace_version` y `graph.invoice.list_by_order`. El adaptador de AI-GRUPO y `graph.invoice.get` deben seguir en estado planned hasta que haya staging, verificación de permisos por orden y despliegue certificado. No activar escrituras remotas en producción desde este candidato.

# Supplier Workspace v1 · contrato y operación

## Fuente de datos

- Identidad, email, WhatsApp, canal y notas: `ge_wtp_supplier_profiles`, con claves de `GE_WTP_Production::suppliers()`. No se renombran claves como `mardones`.
- Datos ampliados y estado explícito: `ge_wtp_supplier_workspace`, por clave. La ausencia de estado significa **sin definir**, no activo.
- Asignaciones: `_ge_production_supplier` en orden e ítems. La vista lee ambos, incluidos pedidos históricos. La asignación directa desde ficha sólo se permite si el pedido no usa el Workflow por ítems y no tiene otro proveedor concreto.
- Obligaciones: `_ge_supplier_payables[$supplier]` en la orden, moneda ARS, monto y vencimiento opcional. Este registro no modifica el total de WooCommerce ni el cobro del cliente.
- Pagos, documentos, listas y notas: CPT privado `ge_supplier_entry`, metadatos `_ge_supplier_key` y `_ge_supplier_entry`. No hay borrado desde la interfaz. Los archivos van a `wp-content/ge-private/supplier-workspace/<key>` y sólo se descargan con acceso interno y nonce.
- Facturas ya capturadas en `ge_supplier_invoice`: lectura en la ficha con enlace al expediente existente. No se migran ni se duplican.

## Cálculo de cuenta

Saldo conocido ARS = suma de costos cargados en pedidos vinculados − pagos ARS registrados. Los pedidos sin costo se cuentan y advierten al operador; el saldo visible no se presenta como deuda total. Los pagos USD se muestran como asientos separados, sin conversión implícita.

## Acción Graph propuesta

El Action Registry de AI-GRUPO no se modifica desde esta rama porque su checkout y contrato autenticado no están verificados. Adaptar estas acciones a los servicios internos tras reconciliar la fuente:

| Acción | Lectura/escritura | Regla |
| --- | --- | --- |
| `graph.supplier.find` | Lectura | Clave estable; búsqueda por nombre/contacto/rubro. |
| `graph.supplier.get` | Lectura | Perfil, condiciones y cuenta sin enlaces privados públicos. |
| `graph.supplier.create` | Escritura | Evitar duplicados por identidad confirmada; auditar. |
| `graph.supplier.update` | Escritura | Guardado por bloque, validación y auditoría. |
| `graph.supplier.assign_order` | Escritura | Respetar Workflow por ítem y asignaciones existentes. |
| `graph.supplier.list_orders` | Lectura | Filtrar por orden e ítem; paginar. |
| `graph.supplier.price_list.attach` | Escritura | Archivo privado e historial; una vigente. |
| `graph.supplier.document.attach` | Escritura | Tipo explícito, pedido opcional, sin borrado contable. |
| `graph.supplier.payment.record` | Escritura | Asiento de pago; no ejecutar transferencia. |
| `graph.supplier.get_balance` | Lectura | Saldo conocido con conteo de costos faltantes. |
| `graph.supplier.notify` | Escritura futura | Requiere destinatario verificado, remitente Graphex, clave idempotente, aprobación del flujo y registro de envío. |

## Portal externo futuro

El límite de acceso será la clave de proveedor asociada al usuario autenticado. Sólo podrá ver órdenes liberadas para sus ítems, descargar archivos con grants acotados y vencimiento, confirmar recepción, informar estado y subir facturas. Las notas internas, costos de otros proveedores, pagos del cliente y datos privados del cliente nunca se expondrán. v1 no crea usuarios ni login externo.

## Liberación

Esta rama parte de `c6c2e4a` y no representa por sí sola el plugin activo en producción. Antes de desplegar: comparar hashes con fuente productiva, integrar cambios paralelos de Customer Workspace y Orders Trash, restaurar backup fresco en staging aislado, verificar descarga privada también en el servidor web real, probar permisos/archivos/cuentas con datos QA, y capturar desktop y móvil. Preparar rollback de archivos y datos. Sin esas verificaciones, estado NO-GO.

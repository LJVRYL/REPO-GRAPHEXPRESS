# Graph Express: perfil y resolución fiscal para presupuestos

Estado al 30/09/2026: implementado el perfil del cliente, la configuración del emisor y un resolver reutilizable. El presupuesto comercial, su checkout y Action Registry aún deben integrar este contrato antes de activar cobros.

## Fuentes y decisión

ARCA publica la matriz vigente de comprobantes por condición fiscal de emisor y receptor: [régimen general](https://www.arca.gob.ar/facturacion/regimen-general/comprobantes.asp) y [monotributo](https://arca.gob.ar/facturacion/monotributo/comprobantes.asp). El selector «Necesito Factura A» es una solicitud comercial, no la decisión fiscal. Responsable inscripto con destinatario que corresponda y habilitación efectiva puede emitir A; monotributista o exento emite C en operaciones internas. La capacidad concreta del punto de venta debe configurarse y verificarse en Graph.

No se cargó ningún CUIT, razón social, punto de venta ni tasa real en código. Configuración: `Gestión → Configuración → Facturación`; solo administrador. El valor vacío bloquea la emisión o el cobro que dependa de esa configuración. No guardar credenciales ARCA en esta opción.

## Modelo y contrato de integración

- Perfil persistido en `_ge_billing_profile`: `billing_mode`, CUIT, razón social, condición IVA, email, domicilio y fecha de verificación. La ficha existente conserva compatibilidad con `_ge_cuit` y `billing_company`.
- Emisor en opción `ge_wtp_billing_entity`: razón social, CUIT, condición IVA, punto de venta, tipos autorizados, política de precio por flujo y tasa en puntos básicos. La tasa es configuración, no una constante fiscal.
- `GE_WTP_Billing::resolve($entity, $profile, $subtotal_cents, $rate)` devuelve flujo, base, impuesto, total, tipo de comprobante y bloqueos. Importes en centavos enteros. La interpretación `tax_inclusive` / `tax_exclusive` es explícita por flujo.
- Al **publicar** cada versión de presupuesto comercial, llamar `GE_WTP_Billing::resolve_net_quote(...)` y guardar `GE_WTP_Billing::snapshot(...)` junto al snapshot de líneas/precios. El presupuesto nuevo recibe precio neto cargado por staff; si la política configurada no es `tax_exclusive`, el resolver devuelve un bloqueo, sin reinterpretar el número.
- Al aceptar y antes de crear cualquier intento de cobro, ejecutar `assert_can_accept_or_pay($snapshot, GE_WTP_Billing::entity())`. Si cambió el emisor o hay datos incompletos, bloquear y abrir revisión/version nueva. No recalcular silenciosamente un presupuesto aceptado.
- El intento de cobro conserva el total fiscal del snapshot y el ajuste explícito del medio elegido. Calcular la seña con `GE_WTP_Quote_Balance::deposit($final_total_cents, 5000)` y mantener `amount_paid` y `amount_due` en la orden principal.
- Action Registry debe invocar el mismo validador para `graph.customer.update_billing` y `graph.quote.create/send`. Responder `blocked/missing_input` ante CUIT o datos obligatorios ausentes. El worker nunca debe completar datos fiscales por inferencia.

La UI de perfil permite elegir el flujo durante registro y completar/editar los datos después. La fecha de verificación se limpia si cambian datos, y solo staff la vuelve a marcar. Los cambios dejan evento con actor, fecha y nombres de campos; no copia CUIT ni contenido sensible en la auditoría.

## Publicación y rollback

No desplegar este commit aislado como flujo de presupuestos. Integrar primero con la rama de presupuesto, revisar diferencias entre plugin local y producción y probar en staging WooCommerce/MP sandbox. Antes de producción tomar backup actual de DB, plugin y configuración, comprobar checksum y restauración aislada. Activar por bandera tras probar registro, perfil, aceptación, 50%, 100%, saldo, duplicados de webhook y órdenes históricas. Rollback: desactivar la bandera y revertir archivos del plugin; conservar snapshots y cobros para conciliación, sin borrar datos históricos.

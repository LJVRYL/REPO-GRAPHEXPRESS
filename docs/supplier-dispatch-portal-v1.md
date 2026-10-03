# Supplier Dispatch + Portal v1

El servicio canónico `GE_WTP_Supplier_Portal` extiende el workflow productivo vigente y reutiliza `GE_WTP_Supplier_Workspace` del candidato bd932c2. No reemplaza el checkout, las aprobaciones ni los pagos del cliente.

## Operación

Gestión → Producción → pedido → Salida al proveedor: elegir producción interna o un proveedor, guardar destino, editar notas técnicas, preparar la vista previa y enviar. Los contactos existentes siguen disponibles hasta que staff los marque inactivos; no se presume que su estado comercial esté confirmado. El selector admite búsqueda por nombre/rubro y conserva las claves existentes.

La producción interna usa el equivalente existente `_ge_production_supplier=internal` y `_ge_production_source=internal`. Cambiar de destino revoca los accesos previos; se conserva el historial. Cada ítem guarda su destino; el selector principal aplica a los ítems pendientes/en producción. Los destinos múltiples existentes se muestran por proveedor.

La vista previa crea una orden técnica privada en `ready_to_send`. Su token no permite acceso externo hasta registrar un envío por email o manual. El envío manual conserva `email_status=not_sent`; copiar no equivale a enviar. Registrar manual habilita el portal después de haber compartido el mensaje por el canal elegido. La falta de confirmación del proveedor no bloquea el aviso al cliente.

## Envío y seguridad

Cada snapshot registra ítems, especificaciones técnicas explícitas, notas, fecha, archivos, version_id y SHA-256. No serializa metadata arbitraria ni importes, márgenes o datos fiscales. Una modificación exige revisión y un despacho nuevo; no pisa la versión anterior. Se conserva el registro privado original y se comprueba el checksum físico antes de descargar; un binario modificado en el mismo path se rechaza. En v1 el despacho requiere almacenamiento privado local/VPS; R2 se rechaza explícitamente hasta certificar snapshots inmutables.

Token de 256 bits, hash para autorización y copia cifrada AES-256-GCM con clave derivada del salt de WordPress para copiar el enlace desde Gestión. Expira a los 30 días y es revocable. El portal corresponde al supplier_id del grant y permite ver hasta 100 órdenes propias enviadas mediante su índice de proveedor, incluyendo historial sin acceso para grants vencidos/revocados. Cada descarga exige el token propio de la orden y el ID/checksum de su snapshot. Cabeceras noindex, no-cache, no-referrer, nosniff, DENY y CSP local. Los formularios usan un HMAC CSRF vinculado al grant/token. La trazabilidad de email omite el bearer token.

Notify usa lock MySQL por pedido, dispatch_id y fingerprint. Persiste el intento antes de contactar el mailer. Doble click/retry exitoso devuelve el mismo envío; un fallo confirmado permite reintento manual. Si el proceso se corta durante el mailer, no reenvía automáticamente: staff debe revisar Notificaciones y confirmar el reintento. SMTP no garantiza exactamente una entrega tras un fallo ambiguo, por eso se evita el reintento automático. message_id se registra si el mailer lo expone. `sent` significa aceptación por el mailer, no lectura/entrega al destinatario; localhost deja trazabilidad `simulated`.

## Estados y archivos recibidos

Dispatch: ready_to_send, sent, acknowledged, in_production, ready, received, cancelled. Sin grant se distingue proveedor sin asignar/asignado en la UI. Email: not_sent, pending interno durante el intento, sent, failed. Source: internal/supplier. El proveedor confirma recepción, informa ETA, señala en producción/listo; `ready` no marca recibido, no entrega al cliente y no modifica pagos. Staff registra recibido/cancelado; cancelled revoca el acceso. Las acciones repetidas no retroceden el estado. ETA valida fechas reales entre hoy y dos años.

Factura/remito desde el portal reutiliza el uploader y CPT privado del Workspace, con scope supplier/order y CSRF. El documento aparece en la ficha del proveedor, separado del expediente de facturación fiscal. No crea facturas fiscales ni pagos. El almacenamiento del Workspace queda bajo `ge-private/supplier-workspace`, con denegación Apache 2.4 certificada y descarga staff autenticada. La ficha interna muestra estado, ETA, documentos, costos/pagos/listas existentes e historial.

## Graph Actions

Se agregaron las nueve Actions pedidas al Registry en una rama aislada AI-GRUPO y al adapter CLI privado existente. Todas pasan por el servicio canónico y actor staff verificado. Notify exige aprobación de la acción y no crea side effects desde una lectura. Los enlaces que retorna el adapter son capacidades privadas: no persistir sus valores en Task/Result Packs ni logs. Registry y cambios locales no se fusionan automáticamente a main.

## QA y rollback

Suite WordPress aislada: source, privacidad, checksum/versionado, idempotencia, error/retry, permisos, lock concurrente, confirmación/ETA y cancelación. HTTP: archivos v1/v2 exactos, grants cruzados/alterados, CSRF y cabeceras. Navegador: ambos destinos, selector, preview, envío simulado, portal, ETA, documentos y ficha interna; desktop/móvil.

Deploy sólo con comparación del código activo, backup fresco verificado, rollback de archivos ensayado, lint PHP 7.4 y HTTP privado 403. El rollback repone sólo los archivos anteriores y preserva DB/uploads; los archivos nuevos quedan sin referencias activas. No se prueba envío real a proveedores ni se alteran #975/#978 como fixtures.

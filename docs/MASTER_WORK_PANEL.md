# Ficha común de trabajo

El presupuesto, el pedido comercial y la orden de producción conservan sus modelos y controladores. `GE_Work_Master` agrega una navegación común de Solicitud, Presupuesto, Pedido, Producción y Entrega sobre las URL existentes. No crea pedidos, cambia estados ni acredita pagos al navegar.

El encabezado comercial reutiliza las acciones nativas, incluido PDF, vista previa de correo, edición, publicación/reenvío y portal. Productos, especificaciones, acceso a archivos y resumen financiero quedan visibles en las etapas operativas. La gestión financiera conserva sus permisos y formularios. Entrega reutiliza destino/logística, etiquetas/QR, documentos y solicitud de revisión.

Las órdenes de trabajo recuperan su origen solamente cuando coinciden cliente, moneda, organización y vínculo recíproco entre ítem y orden. El total comercial y el de la orden se presentan separados. `work_order_id` conserva la pieza al recorrer las etapas; se valida nuevamente en cada vista. Los presupuestos históricos conservan su registro y requieren vínculos comprobables para enlazar un pedido.

Las etapas inexistentes se muestran sin enlace. Los editores y altas mantienen sus pantallas nativas. Las preparaciones nuevas y la producción histórica comparten el contexto, conservando sus propios controles y estados. Los límites de pago, aprobación exacta del archivo y despacho no cambian.

## Validación

`tests/master-work-panel-wp.php` requiere el entorno sintético `graph_integral_guard_20261014`, con correo y HTTP interceptados. Verifica separación de clientes, reciprocidad, continuidad de la pieza, historial sin mutación, permisos financieros y conservación de formularios. La regresión nativa continúa en `tests/production-cards-wp.php`. La revisión visual comprueba escritorio y móvil, navegación, formularios visibles, carga de archivos y aviso de cambios sin guardar.

## Release y rollback

Allowlist: entrada MU `ge-work-master.php`, `ge-work-master/master.css`, `ge-work-master/master.js`, `ge-production-cards/ui.php`, y clases `staff-portal`, `commercial-quote-ui`, `email-templates` del plugin. No hay migración de base ni modificación de originales de clientes.

Respaldo privado: `/home/graphexpress/releases/master-work-panel-20261014/backup`. La prueba de respaldo verifica bytes y concurrencia de los cuatro archivos existentes; los otros tres deben estar ausentes antes del primer deploy. Revalidar los hashes antes de publicar desde el commit canónico autorizado.

Rollback selectivo: verificar que los siete archivos aún coincidan con el manifiesto publicado; restaurar los cuatro originales; desactivar la entrada nueva MU trasladándola al directorio privado del release. CSS/JS nuevos pueden conservarse sin cargar. Refrescar el pool PHP 7.4 del dominio y verificar presupuesto, pedido, producción e importes. No cambiar datos, opciones, pagos, archivos ni excepciones para revertir esta presentación.

La integración inicial fue autorizada y publicada desde master en PR22. La verificación de registros antiguos agregó el nombre/email de facturación para invitados sin cuenta y muestra el cierre operativo en el encabezado, conservando los datos y controles nativos. El estado activo, commit y manifiesto se registran en la biblioteca del proyecto y en el Result Pack del release.

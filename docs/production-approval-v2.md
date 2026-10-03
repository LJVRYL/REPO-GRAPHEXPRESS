# Liberación de arte · política v2

Fuente: pedido del titular del 01/10/2026 y código del release Quotes v2 `1f1070e`.

La aceptación comercial, pago y aprobación de arte son ejes independientes. La referencia comercial textual es opcional; la conversión interna registra actor, fecha y source staff.

La aprobación de cliente se exige solamente cuando `_ge_item_artwork_client_required=yes`. La liberación valida referencias de archivo y fingerprint exacto y acepta `client_approved OR (staff_approved AND NOT client_required)`. Un archivo o versión nuevo invalida el release anterior. El fingerprint incluye fuentes, sus hashes, versión y especificaciones.

Desde presupuesto se puede aprobar el archivo vigente internamente, elegir producto y marcar si requiere cliente. Se conserva file ID, SHA-256, versión comercial, actor y fecha. Al convertir se hereda la referencia privada, sin copiar el binario. Una nueva versión sin revisar no recibe la aprobación de una versión anterior. Si hay múltiples archivos o productos, completar y comprobar su asignación en el control de arte del pedido.

Desde el pedido, guardar el control interno permite liberar el arte sin OK del cliente cuando el requisito está apagado. La aprobación exacta basta para elegibilidad; el estado genérico de revisión no introduce otra confirmación. La fecha prometida continúa siendo requerida por el workflow.

Conversión: consulta vínculo directo y también vínculo inverso. Un pedido completo existente se reconcilia y reutiliza; uno parcial o múltiples pedidos requieren revisión y nunca generan otro automáticamente. Persistir procedencia tempranamente reduce las ventanas de pedidos incompletos sin vínculo.

Los escritores de checkout usan locks MySQL por conexión (GET_LOCK / RELEASE_LOCK). Un request caído libera el lock al cerrar la conexión. Las opciones de lock anteriores se retiran sólo tras adquirir el lock nuevo. Requests simultáneos reciben busy; la UI vuelve al presupuesto con aviso. El botón de conversión se deshabilita tras submit. Éxito abre Gestión → Producción con order_id; error vuelve al detalle del quote.

No usar la antigua regla de exigir simultáneamente aprobación interna y del cliente. No registrar un OK del cliente por inferencia ni aprobar internamente un archivo que no fue revisado.

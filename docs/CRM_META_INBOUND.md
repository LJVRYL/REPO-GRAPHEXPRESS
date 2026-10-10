# Recepción Meta en el CRM existente

Un receptor y un worker privado para WhatsApp, Instagram y Messenger. Usa las tablas de conversaciones/tareas actuales y una única cola de transporte InnoDB (`ge_whatsapp_inbound`, nombre histórico), con originales AES-256-GCM. No incluye ninguna API de envío. No genera ventas GA4 adicionales.

## Endpoints

- WhatsApp: `https://graphex.ar/wp-json/ge/v1/crm/whatsapp-webhook`
- Instagram/Messenger: `https://graphex.ar/wp-json/ge/v1/crm/meta-webhook`

GET valida el token y devuelve el challenge literal. POST valida HMAC SHA256 sobre bytes exactos y la lista de activos antes de confirmar. Falta de configuración o persistencia responde 503; firma/activo ajeno 403. Tamaño máximo 2 MiB. Confirmación únicamente después del INSERT durable. La misma entrega no crea otra fila; el mismo ID de mensaje no crea otra conversación ni tarea. Cambios de contenido son incidentes; nunca sustituyen el original.

## Servidor y credenciales

Configuración privada: `/home/graphexpress/whatsapp-crm/config.json`, fuera de public_html. Valores reales de secretos permanecen allí, nunca en Git ni informes. Referencias: `credential_ref: graphex-meta-app-secret`, `credential_ref: graphex-meta-webhook-verification`, `credential_ref: graphex-meta-queue-key`. Aplicación Meta única para ambos endpoints; usar otra aplicación exige separar sus secretos y volver a verificar el receptor.

Claves no secretas: `organization_id`, `enabled` (WhatsApp), `social_enabled`, `waba_id`, `phone_number_id`, `number`, `page_ids`, `instagram_ids`. Las dos habilitaciones son independientes. Nunca activarlas por una cuenta meramente vinculada en Business Suite.

Ejecutable: `tools/meta-inbound-worker.php`, instalado fuera del directorio público. `GE_CRM_SITE` admite únicamente producción o QA aislada. Requiere principal CRM autorizado. Cron del VPS cada minuto, sin depender de la computadora del operador. Un advisory lock impide trabajadores superpuestos. Recupera leases antiguos, reintenta ocho veces con backoff y conserva fallos para revisión humana. No borrar la cola al hacer rollback; conservar su clave privada.

## Cobertura y límites

WhatsApp: texto, títulos de botones/listas, referencias de medios, estados, ecos, eventos de historial/coexistencia, desconexión/reconexión. Contactos no se fusionan automáticamente. Historial no inicia una ventana nueva ni autoriza respuesta. Grupos y dispositivos no compatibles están fuera de cobertura. Archivos requieren descarga segura adicional: una referencia no es un archivo recibido/aprobado. El onboarding de coexistencia y la sincronización de historial requieren pasos oficiales y consentimiento; este módulo no registra, migra ni desregistra el número.

Instagram/Messenger: mensajes directos con mid, ecos, avisos de borrado, entregas por mid y lectura por watermark de conversación. Los tipos no interpretados se conservan para revisión. La lectura no se atribuye falsamente a un mid particular. Comentarios, reacciones y eventos extraordinarios no se anuncian como cobertura completa. Las APIs de conversaciones e importación paginada del historial están pendientes de token/acceso aprobado; no existe recuperación arbitraria garantizada. No hay backfill ejecutado.

Las identidades Page-scoped/Instagram-scoped están separadas por canal y cuenta. No son teléfonos ni identidades de clientes. WhatsApp vincula sólo una coincidencia exacta de teléfono existente; toda ambigüedad queda sin vincular. Clasificación acotada y tareas humanas; no se presupuestan terminaciones o datos inciertos, ni se inicia producción. SLA: tarea pendiente por más de una hora; cola atrasada por más de 15 minutos requiere revisión.

## Habilitación Meta pendiente

Crear/habilitar cuenta de Meta for Developers, aplicación y permisos oficiales. Messenger requiere acceso correspondiente a Page y `pages_messaging`; Instagram profesional usa la ruta elegida de Facebook Login (`instagram_manage_messages` y metadata Page) o Instagram Login (`instagram_business_manage_messages`); no mezclar tokens/rutas. Advanced Access/review puede ser necesario para personas ajenas a roles de la app. Suscribir los activos reales y comprobar evento entrante → CRM/tarea antes de afirmar conectado. No colocar tokens en URLs ni logs.

Fuentes primarias: [Instagram de Meta](https://www.postman.com/meta/instagram/), [Messenger de Meta](https://www.postman.com/meta/messenger-platform-api/), [coexistencia WhatsApp](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users), [endpoint firmado WhatsApp](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint).

## Verificación y rollback

`tests/meta-inbound-wp.php` usa mensajes sintéticos en `graph_job_flow_20261007`, nunca datos de clientes ni envíos externos. Verifica firma, cifrado, rechazo de otros activos, persistencia, deduplicación, clasificación sin acuse, aislamiento, recuperación de leases, backoff y fallo con tarea. Ejecutar también `tests/crm-attention-wp.php`; preservar bytes del clasificador de correo porque su política de acuse los fija.

Deployment selectivo desde commit canónico; respaldar/hashear archivos anteriores, configuración y cron. Rollback primero desactiva config y cron, restaura solamente archivos existentes y mueve los nuevos fuera del webroot. No elimina filas ni originales. Receptor inicialmente desactivado hasta habilitación real Meta; los endpoints responden 503 y el panel debe declarar ese estado.

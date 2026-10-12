# Comunicaciones de Graphex

El panel nativo organiza Contactos → Audiencias → Plantillas → Campañas. Estado del motor, Historial de correos, Métricas y Diagnóstico leen las tablas y los registros existentes. Conserva la identidad GRAPHEX publicada en 9c7010c y la separación de correos operativos y campañas.

## Lanzamiento autorizado del 12/10/2026

El titular autorizó hoy únicamente la primera audiencia, 2.103 contactos seleccionados de YONIO 1, YONIO 2 y correspondencia histórica. Los 21.458 posibles contactos quedan fuera. La selección del titular no se presenta como consentimiento personal del destinatario ni como prueba de entrega histórica. Los borradores eliminados se conservan y no se restauran automáticamente.

Responsable confirmado por el titular: AYALA LEONARDO JAVIER, Av. San Martín 6177, PB, CABA. El pie incluye el mecanismo de baja y las transcripciones pertinentes de la Ley 25.326 y el Decreto 1558/2001, verificadas en Argentina.gob.ar. El asunto conserva PUBLICIDAD. Cada destinatario recibe su enlace nativo y List-Unsubscribe/One-Click.

## Motor y evidencia

La política exige campaña y audiencia autorizadas, vencimiento, cuota documentada, recepción CRM reciente, aviso legal, revisión del contenido y una prueba real de la misma versión. Configuración Exim consultada: límite autenticado general de 500/hora. El relay WordPress actual es local, sin SMTP AUTH; se fija igualmente un máximo propio de 400/hora, 2.500/día, dejando margen para correo operativo. No se interpreta esa configuración como garantía de reputación o entrega.

Una transacción registra el lote exacto y el evento CRM antes de enviar. La clave única campaña/contacto y un bloqueo MySQL impiden colas y ejecuciones duplicadas. Cada intento se persiste como sending antes de contactar SMTP, con Message-ID determinista. Un resultado falso, una excepción, un registro faltante o un worker interrumpido queda unknown y pausa la campaña. No se reintenta automáticamente.

GET_LOCK es global en el servidor MySQL, no por base. El bloqueo de dispatch deriva de base/prefijo/organización fuera del recurso de producción; conserva la clave vigente de Graphex para no perder coordinación con procesos activos durante esta publicación. Las pruebas de concurrencia usan dos conexiones reales al recurso QA y su propia clave.

El worker privado reside fuera del document root y valida sus archivos con SHA-256. Correlaciona los fallos del log local de Exim con Message-ID y hash del destinatario. Suspende el contacto ante fallos permanentes o diferimientos y conserva las bajas voluntarias. Un mensaje diferido correlacionado se congela en Exim: no se borra la cola. El log no prueba lectura ni entrega al buzón. Los DSN externos que no puedan correlacionarse requieren revisión; no se interpretan instrucciones del cuerpo del correo.

Los DSN MIME delivery-status del relay también se correlacionan leyendo únicamente informes recientes de la Maildir del servicio, sin marcarlos, moverlos ni eliminarlos. Exige prueba local Exim de recepción con envelope vacío desde los peers verificados del relay, Message-ID del informe, Message-ID original y Final-Recipient exactos. Otros informes quedan para revisión; no se confía en texto libre ni en un remitente declarado. La supresión del contacto no garantiza que un relay externo cancele un mensaje que ya tenía pendiente.

Las respuestas recibidas por el consumidor existente se vinculan al envío si mantienen References/In-Reply-To y coinciden con el destinatario; excluye Auto-Submitted. Conserva el mensaje en su registro CRM original. Los correos sin referencia siguen en el CRM, sin atribución inventada. No se habilitan píxeles ni se identifica una visita anónima como destinatario.

## Operación y rollback

Pausar detiene los próximos intentos; el intento ya iniciado puede terminar. Los resultados inciertos requieren conciliación con el log nativo y Exim antes de una reanudación. Una señal de recepción antigua bloquea nuevos envíos. La política vence al terminar el día autorizado.

El worker continúa conciliando feedback después del vencimiento, sin nuevos envíos. La recepción y clasificación de respuestas en el CRM conserva el consumidor existente en la computadora; esa señal debe mantenerse reciente para despachar. El servicio VPS se administra como graphex-newsletter.service. Para rollback: desactivar envíos, detener el servicio y aplicar rollback de código; conservar toda evidencia de intentos sin confirmar.

Publicación localizada: newsletter, revisión, audiencias y dos helpers nuevos. Backup privado de esos archivos, contactos, campañas, deliveries, metadatos y política; verificación SHA y restauración de código en directorio separado más reconstrucción de filas en SQLite en memoria. Rollback de código mantiene los intentos, contactos y eventos; no devuelve campañas enviadas a borrador ni permite repetir la cola. No modifica los archivos protegidos del portal ni el clasificador CRM.

Pruebas aisladas interceptan correo y HTTP: revisión exacta, exclusiones, permisos, dos conexiones MySQL concurrentes, replay de cola, pausa por resultado falso/crash, cuota, caducidad, recepción antigua, cambios de contenido, supresión temporal y respuesta vinculada. PHP 7.4 y 8.1. El parser Exim prueba correlación de emisor, destinatario, queue ID y estados; no confunde aceptación con rebote.

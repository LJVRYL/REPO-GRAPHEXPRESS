# Rebotes y avisos del servidor

Los originales se conservan en el correo y en el CRM. La bandeja comercial excluye avisos automáticos; la sección «Rebotes / avisos del servidor» permite consultar sus originales agrupados por referencia de campaña. La clasificación no crea clientes, presupuestos ni tareas comerciales. Las tareas humanas se conservan.

El mantenimiento privado exige administrador y registra los estados anteriores. No envía correo. `archive_server_notices` es idempotente y cierra únicamente tareas automáticas de recepción vinculadas a los avisos. Las referencias de campaña sirven para navegación y no autorizan exclusiones.

La conciliación exige Message-ID original y destinatario exactos, más evidencia de recepción en Exim. Reportes de un origen no verificado generan una pausa para revisión, nunca una baja por dirección inválida. Se mantienen el log actual y su rotación anterior para no perder la evidencia al cambiar de día.

- Dirección inválida confirmada: `hard_bounce`, dirección excluida del marketing.
- Casilla llena / fallo temporal: `temporary_bounce`, pausa sin reintento automático.
- Bloqueo del proveedor: `policy_bounce`, pausa; revisar autenticación o política.
- Causa u origen pendientes: `review_bounce`, pausa para revisión humana.
- Baja voluntaria: `unsubscribed`, se conserva por encima de los fallos del transporte.

La reimportación nunca reactiva estas exclusiones. La cuenta del cliente y el historial se conservan. La aceptación SMTP no acredita entrega al destinatario. Informes repetidos de igual clasificación no generan nuevos eventos; una evidencia más débil no reemplaza una exclusión más específica.

## Respaldo y rollback

La publicación se limita a CRM, audiencias, motor y su trabajador privado. Antes del cambio se guardan copias de estos archivos y un respaldo privado de los registros afectados, contactos de primera audiencia y las 2.103 entregas. El respaldo se prueba reconstruyendo tablas temporales y comparando cada fila.

Para revertir código, detener el trabajador, restaurar únicamente los archivos enumerados en el manifiesto privado, conservar sus propietarios, restaurar el manifiesto del trabajador y validar los hashes antes de reiniciar. Mantener la política de envío vencida.

Para revertir estados, usar los estados anteriores de la auditoría y el respaldo privado, comprobar revisiones antes de modificar cada registro y añadir un evento de reversión. No reemplazar la base completa ni restablecer contactos dados de baja después del respaldo. Conservar todos los intentos, mensajes y eventos posteriores; las exclusiones requieren revisión explícita antes de reactivarlas.

No integrar automáticamente a la rama canónica. La revisión de higiene depende de PR16 y no concede autorización para integrarlo.

## Recepción de textos largos

El consumidor limita asunto y cuerpo en bytes UTF-8 (180 / 12.000), preserva caracteres completos y marca el recorte para revisión. El original sigue en el archivo privado del receptor. Los eventos se confirman en la cola local sólo después de una respuesta nativa `committed` con ID del CRM. La corrección del límite evita reintentos interminables de reportes que ocupan más bytes que caracteres. El consumidor local mantiene su mecanismo existente de inicio oculto y credenciales.

# Identidad de correos Graphex

El correo de la instancia usa la marca vigente de `GE_Organization_Runtime`, el remitente operativo de Notificaciones y un componente compartido. La marca de Graphex es **GRAPHEX**. La razón social y los snapshots fiscales se conservan tal como están registrados.

## Fuentes

- Organización activa: `general.brand_name`, `general.email`, `email.reply_to` y datos de contacto completos que existan.
- Identidad publicada: tema `graphexpress-child/inc/graphex-brand.php` y `assets/css/graphex-brand.css`.
- Símbolo oficial: `assets/images/graphex-simbolo.svg` del tema. SHA-256 de bytes canónicos LF: `fee22c91b6d417587de0ae7e052ff6ea8925d2c8c08a42cfe219c4993957ce3b`.
- Exportación para correo: PNG transparente de 192 × 192, mostrado a 48 × 48. No se generó un logo alternativo.
- El correo no inventa teléfonos, direcciones, responsables ni datos legales. Los campos vacíos se omiten.

## Conexión real

`GE_WTP_Email_Branding` prepara HTML en tablas con estilos inline y texto alternativo. `GE_WTP_Notifications::send` agrega el PDF comercial existente antes de preparar el correo, bloquea el envío si falla y limpia el archivo temporal en `finally`. El filtro final `wp_mail` también cubre fragmentos, avisos del núcleo y el fallback de postulaciones. Distingue texto plano de HTML antes de decorar; no interpreta etiquetas aportadas en un comentario o mensaje de texto.

La decoración conserva destinatarios, adjuntos, cabeceras de conversación y baja. No sustituye el SMTP por Gmail. Agrega la firma de la organización y conserva nombres de clientes, proveedores y emisores. Los enlaces privados de invitación, verificación y despacho a proveedores se omiten de los logs, incluso cuando falla la preparación o el transporte.

WooCommerce conserva sus constructores, estados habilitados, variables de seguridad y CSS inline nativo. Los encabezados y pies comparten la identidad; los puentes de bienvenida y reset cambian sólo la variable de nombre del sitio antes de incluir el template nativo. WordPress conserva sus generadores y contenidos de seguridad; sus filtros sólo reemplazan slots propios de identidad.

## Revisión sin envío

Gestión → Comunicaciones → Plantillas muestra asuntos, firma, botones, metadatos de adjuntos y texto. El presupuesto y las plantillas manuales conservan edición, control de permisos y versiones. Los demás contenidos se administran en su sistema de origen.

Los ejemplos de sistema usan constructores puros compartidos con el envío. WooCommerce usa objetos de ejemplo sin guardar, claves explícitamente inválidas y sus templates nativos. No llama `trigger`, `send`, `save`, generadores de contraseñas ni filtros de adjuntos. Las 30 referencias del núcleo de WordPress se etiquetan como **referencias de firma**: no se simula que ejecutan el aviso real.

La galería no certifica correos enviados directamente por un proveedor externo ni plugins que eludan `wp_mail`. Los avisos de Mercado Pago emitidos por su plataforma requieren revisión en ese proveedor. Tampoco equivale a una prueba de entrega o apertura en Outlook, Gmail o Thunderbird.

## Concurrencia y newsletter

La dependencia desplegada `b815aca` se integra preservando el módulo de revisión y el bloqueo de test-send, queue y cron. No se cambia consentimiento, selección de destinatarios ni estado de campañas. La revisión de lote incorpora el hash de la presentación renderizada: un cambio visual invalida la revisión anterior. La plantilla v3 permanece inmutable; el renderer agrega la identidad compartida sin cambiar el contenido guardado. Un respaldo o rollback de esta release no debe restaurar contactos o campañas ni reactivar el dispatcher anterior.

QA: `tests/email-identity-readonly-wp.php` aplica guardas contra escrituras y envíos, usa clases de candidato aisladas y verifica también el acceso AJAX. `tests/email-branding-tenant.php` comprueba que otra organización no recibe assets ni contactos Graphex. El Result Pack externo contiene hashes, respaldos, rollback y resultados visuales de la versión exacta publicada.

# Tarjetas: página dedicada y contacto digital

Decisión de Leo, 05/10/2026: la tarjeta digital con QR está incluida con la compra de tarjetas físicas. Crear y gestionar el perfil requiere cuenta registrada Graphex. Los destinatarios del QR pueden leer el contacto publicado sin registrarse.

## Rutas y consentimiento

`/tarjetas/` muestra productos, especificaciones y acceso al catálogo de Canva. Los CTA llevan al configurador y carga privada existentes. `/tarjetas/demo/` es una muestra explícitamente ficticia; no corresponde a una ficha real. `/tarjetas/mi-vcard/` usa la sesión de WordPress y el registro/ingreso existentes; conserva la intención mediante una cookie HTTPS, HttpOnly y SameSite=Lax con duración de 30 minutos.

El perfil se guarda como un CPT privado `ge_contact_card`, limitado al propietario autenticado y la organización vinculada al recurso. Un borrador no es accesible por URL pública. Publicar requiere nonce, revisión vigente, autorización explícita para los campos visibles y compra válida de productos 78 u 83 del mismo cliente/organización, pagada y en procesamiento o completada. Reembolsos totales por cantidad, importe o estado anulan la habilitación. El perfil queda privado si falla una actualización; el formulario nunca importa datos fiscales o datos de cuenta automáticamente. Todas las escrituras se serializan por cliente con un bloqueo temporal y comparación de revisión.

El enlace `/contacto/{token-aleatorio}/` se mantiene al actualizar datos. Lectura pública y archivo vCard requieren perfil publicado, consentimiento y derecho vigente. Las descargas QR del servicio requieren además propietario autenticado y nonce. El QR puede verse dentro del perfil público como parte del contacto compartido. La publicación se puede retirar desde el formulario; eso desactiva el destino del QR, sin borrar la ficha. Los perfiles y archivos de contacto envían noindex/no-cache. El borrador permite vista previa privada; no entrega un QR final de destino inactivo. El QR final se habilita después de publicar con una compra válida. Se incorpora al archivo exacto y se revisa antes de producción.

## Archivos

Contacto vCard 3.0 UTF-8, CRLF, campos escapados y líneas plegadas a 75 octetos sin dividir caracteres. QR generado localmente con la biblioteca MIT existente del repositorio; no se envían contactos ni enlaces a generadores externos. SVG negro sobre blanco con cuatro módulos de quiet zone, tamaño de referencia 25 mm. PNG con 16 píxeles enteros por módulo. Probar lectura a tamaño de impresión y aprobar el archivo exacto que incorpora el QR. Drive no se usa para alojamiento web; una futura exportación/backup .vcf requiere su autorización específica.

## Activación y rollback

MU-plugin independiente `ge-cards-experience.php` y vistas/CSS en su carpeta. Opción `ge_cards_experience_enabled_v1` por defecto `no`; activa rutas y operaciones únicamente con `yes`. Requiere PHP 7.4, WooCommerce, portal y organización existentes. No sustituye archivos de portal/correo/landing general. Antes de activar: validar colisión `/tarjetas`, lint y políticas aisladas, parser de vCard y lectura QR, QA visual escritorio/móvil, merge canónico, backup verificable de archivos/opción.

Rollback: restaurar opción anterior o `no`, retirar el MU-plugin a backup privado y conservar los perfiles/adjuntos ya creados. No borrar datos. La desactivación temporal interrumpe los enlaces públicos; coordinar mantenimiento antes de hacer rollback si ya hay QR impresos. En la publicación piloto no hay perfiles reales ni QR impresos creados por el agente.

## Límites actuales

Un perfil de contacto editable por cuenta. La comprobación busca hasta 1.000 pedidos pagados recientes y luego deriva el caso a atención si no encuentra la compra; primero verifica el pedido que habilitó un perfil existente. La incorporación del QR al arte y su aprobación siguen el flujo actual de prueba antes de producción. No envía invitaciones, correos, campañas ni crea cobros automáticamente.

Corrección 05/10/2026: las acciones de guardar y descargar QR usan el prefijo ge_customer_contact_card_ para que Organization Runtime las reconozca dentro del módulo customers; la descarga se clasifica como lectura. Se mantienen sesión, nonce, autor y organización. Backup verificado: /root/ge-backups/cards-actions-20261005. Verificación: 62 pruebas aisladas y guard_action real con cuenta customer, sin modificar registros.

Release producto 05/10/2026: configuración arriba a la izquierda e imagen/preview a la derecha; móvil inserta preview en carga. PDF.js 6.4.299 de npm oficial con SHA512 verificado y licencia, archivos locales sin CDN. Preview PDF/JPG/PNG, límite visual25MB independiente del original, selector páginas/frente-dorso, cancelación de render anterior, estado carga privada; no sustituye aprobación técnica. QR final sólo perfil publicado con compra/consentimiento y descarga del dueño autenticado; borrador sin QR final. Mi tarjeta digital en navegación portal. Marketing email opcional sin check inicial, reutiliza newsletter y auditoría organización con texto versión; no modifica baja/suppression al guardar vCard ni envía campañas. Backup /root/ge-backups/cards-product-vcard-20261005. Pruebas 74 políticas aisladas; carga real de guía ficticia2p en privado sin pedido, dorso y cantidad200 conservados; móvil390 sin overflow; PDF inválido oculta canvas previo. Canva automático continúa pendiente.

UX publicación explícita: Guardar borrador privado no exige consentimiento y retira una publicación previa sólo mediante botón etiquetado; Publicar y obtener QR exige casilla pública, mensaje junto al formulario y foco sobre la casilla si falta. Backend también exige. Backup /root/ge-backups/cards-explicit-publish-20261005. QA real UI con datos ficticios sin guardar ni publicar.

Selección premium inicial definida por Leo: producto83 papel350g, impresión doble faz, terminación mate; cantidad100 y laminado simple faz preservados del catálogo existente. Precio validado por backend: ARS22200 sin IVA para100; no se cambiaron precios. Configuración real _ge_digital_config modificada por comparación del valor previo, backup verificable /root/ge-backups/cards-premium-20261005/config.json. Defaults sólo al entrar sin selección válida guardada; elecciones conservadas durante1h en sessionStorage por cuenta/producto, sin archivos o datos personales.

Privacidad producto personalizado: respuestas del producto para usuarios registrados envían no-cache y Referrer-Policy no-referrer porque pueden contener la vista previa de su propia ficha; no se comparten con cachés.

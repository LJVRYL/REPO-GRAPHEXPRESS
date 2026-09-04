# Portal Markcom · MVP local

## Alcance

El portal privado se implementa dentro del plugin `ge-webtoprint-calculator` y utiliza WordPress para identidad y WooCommerce para productos y pedidos.

Incluye:

- página privada `/cliente-markcom/`;
- rol `ge_markcom_client`;
- catálogo exclusivo con nueve familias y diez presentaciones;
- cuatro escalas de cantidad por producto;
- conversión ARS/USD mediante una cotización administrable;
- carrito persistente por usuario;
- generación de pedidos WooCommerce en USD;
- snapshot del precio ARS, tipo de cambio y observaciones;
- estados de producción específicos;
- carga y descarga autenticada de documentos;
- panel de configuración para Graph Express.

TotalCoin queda fuera del MVP. La pasarela general de la tienda se implementará en una etapa posterior.

## Archivos

- `ge-webtoprint-calculator.php`: bootstrap del plugin.
- `includes/class-ge-wtp-plugin.php`: instalación, rol, página y estados.
- `includes/class-ge-wtp-catalog.php`: catálogo, precios y sincronización WooCommerce.
- `includes/class-ge-wtp-orders.php`: carrito y creación de pedidos.
- `includes/class-ge-wtp-documents.php`: almacenamiento privado y descarga autorizada.
- `includes/class-ge-wtp-portal.php`: interfaz y acciones del portal.
- `includes/class-ge-wtp-admin.php`: configuración administrativa.
- `assets/css/portal.css`: presentación responsive.
- `assets/js/portal.js`: actualización de precios por escala.

## Uso local

1. Copiar el plugin al WordPress local y mantenerlo activo.
2. Visitar WordPress una vez para ejecutar la actualización de datos.
3. Configurar el tipo de cambio desde `Web-to-Print` en el administrador.
4. Crear usuarios con el rol `Cliente Markcom`.
5. Abrir `http://localhost/index.php/cliente-markcom/`.

La ruta local contiene `index.php` por la configuración actual de enlaces permanentes. En producción debe publicarse como `/cliente-markcom/`.

## Seguridad documental

Los documentos se guardan en `wp-content/ge-private/markcom`, fuera de la biblioteca pública de medios. Apache bloquea su acceso directo y la descarga se realiza mediante una acción autenticada que verifica propiedad del pedido o permisos de administración.

Formatos iniciales: PDF, JPG, PNG y ZIP, con límite de 20 MB por archivo.

## Validación realizada

- sintaxis PHP de todos los archivos;
- acceso anónimo limitado al login;
- acceso del rol Markcom al portal;
- sincronización de productos privados;
- agregado y eliminación del carrito;
- cálculo de subtotal, IVA y total;
- creación de pedido WooCommerce;
- conservación de referencia PO y tipo de cambio;
- visualización del pedido desde el portal.

## Pendientes antes de producción

- reemplazar la cotización de demostración por la fuente operativa elegida;
- definir usuarios y contraseñas reales;
- revisar límites de archivos y formatos de arte finales;
- configurar correo saliente;
- probar subida y descarga con documentos reales de prueba;
- resolver el error FTP observado al ejecutar ciertas tareas administrativas de WooCommerce/Dokan;
- actualizar el PHP del servidor productivo antes de desplegar;
- realizar backup completo y prueba en staging.

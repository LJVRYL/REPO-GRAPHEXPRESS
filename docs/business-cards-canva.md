# Tarjetas personales y Canva

Producto: Graph Express. Recurso: WordPress Graphex, PHP 7.4. Integración: Canva Connect.

## Publicación inicial

MU-plugin independiente `wp-content/mu-plugins/ge-business-cards.php`; aplica solamente a los slugs `tarjetas-personales` y `tarjetas-express`. Reutiliza carga privada y checkout existentes. No reemplaza archivos de storefront, portal, correo ni landing. Imagen aprobada y tres PDF públicos dentro de `ge-business-cards/`. Opción `ge_business_cards_image_id_v1` selecciona una imagen válida, con fallback a la anterior. No cambia cantidades, precios, papeles ni terminaciones.

Medidas: lienzo 95 × 65 mm, corte 90 × 50 mm y zona segura 80 × 40 mm centrados. La plantilla doble faz tiene dos páginas. Los PDF con guías son sólo referencia. Imágenes a 300 ppp efectivos o contenido vectorial; fuentes incrustadas o curvas.

El botón abre el catálogo de tarjetas de Canva. El cliente descarga PDF para impresión y lo carga en la ficha. No se anuncia una importación automática que aún no esté disponible.

## Integración automática pendiente

App `AAHOGKFtIfg`, client ID público `OC-AaEDyKvmyCBN`. A 05/10/2026 sigue en borrador, con sólo `design:content:read` y `design:meta:read`. OAuth, exportación, regreso firmado y reexportación están probados localmente con la cuenta del negocio. Canva exige revisión antes de abrir una integración pública a todos los usuarios: https://www.canva.dev/docs/connect/submitting-integrations/.

La prueba local no se publica en internet. Faltan callbacks HTTPS del producto, cliente OAuth con secretos seguros del VPS, tokens por cliente, vinculación de trabajos de exportación a sesión/diseño/producto y preflight previo al carrito. El parser debe ejecutarse con aislamiento y límites. No copiar las credenciales DPAPI de Windows al VPS. Usar `credential_ref: graphex-canva-production-client`, sin valores reales en repositorio ni documentación.

## Rollback

Antes de publicar, guardar la opción de imagen anterior y los archivos anteriores si existieran, con checksums. Restaurar esa opción y retirar de carga automática el nuevo MU-plugin moviéndolo a la carpeta privada de backup. Conservar el adjunto nuevo y los PDF para no borrar datos. Comprobar ficha y carga existente. Sin cambios de pedidos, clientes o pagos.

# Experiencia compartida de productos · 09/10/2026

Project: graph-express. Resource: graph-wordpress-prod. Referencia visual real: `/product/volantes-full-color/`.

El módulo `ge-product-experience` aplica a las fichas WooCommerce: título, descripción y guía arriba; imagen y configuración en columnas iguales; móvil con imagen seguida de configuración. Se usan los callbacks reales de WooCommerce y Knowledge Base. Volantes conserva su introducción, matriz, calendario y preprensa propios. Colores, tipografía, logo Graphex y tokens proceden del tema vigente (`graphex-brand.php`, `graphex-brand.css`).

Catálogo consultado: 155 productos publicados, todos simples; 149 usan storefront y seis el configurador digital. Las variantes comerciales son opciones de esos motores, no productos WooCommerce variables. No se cambia ningún selector, precio, cantidad, medida, calendario, regla fiscal, enlace comercial, carga privada ni validación de carrito.

## Canva

Un botón con icono oficial abre un `dialog` nativo. El panel original se mueve intacto después de que la integración haya registrado sus eventos. Se mantienen selectores, formularios, estado, paginación, reintentos, OAuth, importación y preflight existentes. Cerrar no cancela una importación. La validación de carrito sigue perteneciendo al módulo Canva; si bloquea, el panel vuelve a abrir para exponer el mensaje.

Altura máxima: viewport menos 32 px (16 px móvil), cabecera fija y cuerpo con scroll propio. Escape, cierre explícito y clic fuera; foco restituido y página inmovilizada durante apertura. Retornos `returned`, `denied`, `invalid_return`, `connect` y ancla `#canva` abren el panel. Estado resumido en el botón con dos líneas máximas. Listados en dos columnas/escritorio y una/móvil.

Tarjetas Express conserva las capacidades que habilite su integración: entrada manual pública y flujo automático sólo para usuarios autorizados hasta aprobación pública. Tarjetas personales conserva catálogo + PDF manual, dentro del mismo contenedor visual; no se habilita OAuth allí. No se agrega Canva a otros productos.

El backend actual devuelve títulos, ID y cantidad de páginas, no miniaturas: no se inventan imágenes. La vista previa real del PDF y su selector de páginas permanecen en la ficha; el panel incluye retorno para revisarlos. Una futura incorporación de miniaturas corresponde a la integración y debe respetar URLs, permisos y expiración.

Logo sin alteraciones: `https://www.canva.dev/assets/connect/Canva-logos.zip`, archivo `Canva logos/svg/Canva Icon logo.svg`. Guía oficial: https://www.canva.dev/docs/apps/rest-apis/brand-guidelines/ . Tamaño 32 px y 8 px de espacio alrededor.

## Publicación y reversión

Payload aditivo de cuatro archivos: loader PHP y tres assets propios; ningún archivo compartido de Canva, Volantes o flujo comercial se sobrescribe. Rama canónica master, checkout aislado, manifiesto SHA-256 y despliegue selectivo desde commit canónico. Respaldo privado verificado, baseline de dependencias y comprobación de ausencia de archivos nuevos antes de instalar. El loader se instala al final. Rollback selectivo: mover loader y assets a respaldo privado tras verificar sus hashes; no eliminar datos ni alterar otros módulos.

Verificación: sintaxis PHP 7.4/JS; 155 encabezados contra WordPress sin duplicación; recorrido visual escritorio/móvil; estrés ficticio con 80 resultados; comparación antes/después de controles del catálogo. QA real de configuración → archivo → carrito sin comprar ni crear pedidos. Evidencia y hashes finales en Result Pack externo. Sin cambios de aprobación pública de Canva.

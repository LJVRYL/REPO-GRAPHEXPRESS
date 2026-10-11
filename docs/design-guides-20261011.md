# Graphex: guías y plantillas de diseño v1

Project: graph-express. Resource: graph-wordpress-prod. Fecha: 11/10/2026.

Módulo aditivo `ge-design-guides.php`, índice con shortcode `[graphex_plantillas]` en `/plantillas-de-diseno/`. Descargas de tarjetas existentes conservan sus rutas y panel; los tres PDF se mejoran eliminando borrador y placeholders. No se cambian catálogos, precios, títulos, matrices comerciales, Canva, pedidos ni correos.

## Cobertura y límites

- 33 PDF originales Graphex: 4 guías generales/específicas, 4 bases/referencias y 25 fichas familiares.
- Tarjetas 90×50: base limpia 95×65 mm, TrimBox centrado90×50, seguridad80×40. Extra2,5mm lateral/7,5mm vertical. Dos páginas frente/dorso; simple faz sólo primera. Contrato vigente confirmado por Leo03/10.
- Volantes200×150: base210×160, TrimBox200×150, seguridad190×140. Contrato Graphex08/10: sangrado5mm, seguridad5mm, CMYK, imágenes300ppp, fuentes incrustadas/curvas, mínimo6pt. Representativa adaptable, no cubre todos los tamaños con PDFs separados ni plegados.
- Bases limpias: páginas vacías sin instrucciones, textos, logos ni líneas; referencias contienen sólo geometría con OCG PrintState OFF. Importaciones pueden perder ese comportamiento; no exportar la referencia como arte. Instrucciones en otro PDF.
- Windbanners: 164 opciones, 25 familias; 125 opciones con 102 PDF proveedor públicos leídos y SHA256/páginas/MediaBox inventariados. 33 opciones sin matriz; 6 accesorios no requieren arte; 9 opciones usan PDF genérico; 6 conflictos de rótulo: wb-186 Gota 320 → Gota 230, wb-194 Pluma 420 → Pluma 320, wb-192 Surf 320 → Surf 230, wb-169 Cube 420 → Cube 320, wb-167 Gota 420 → Gota 430, wb-287 Arco 350 → Wall Banner 225x200. Confirmación del proveedor requerida; no inferir geometría por el título.
- No se reproducen contornos ni se rebrandearon PDF ajenos. Índice enlaza originales con autoría; no se publican como matrices Graphex validadas. Falta confirmación de geometría, márgenes/costuras y permiso de reconstrucción para cumplir la parte de nuevas matrices textiles1:1. Nunca usar el tamaño MediaBox o un mockup como área imprimible.

## Fuentes

Identidad: tema vigente Graphex, símbolo aprobado, Jost, tokens `graphex-brand.css`. Tarjetas: contrato existente `business-cards-canva.md` y guía anterior. Volantes:`ge-volantes/prepress-spec.json`. Windbanners: catálogo canónico conciliado10/10, instructivo https://grupowindbanners.com.ar/manual y PDFs de las opciones, leídos11/10. Inventario público sólo datos técnicos y referencias, sin costos internos.

## Release

Branch aislada → pruebas PHP/mapping y dimensiones/font/OCG → QA visual Poppler de todas las páginas → integración canónica autorizada → backup verificado de archivos previos y estado de página → deploy desde commit canónico y creación de página bajo guardas → verificar hashes, índice, descargas,25 fichas y selección de matriz.

Rollback selectivo: restaurar sólo3 PDF anteriores y retirar módulo nuevo a backup privado con guardas SHA; conservar assets y cambiar sólo estado de la página creada para este release si no tiene cambios posteriores. No borrar ni restaurar bases completas.

Canva futuro complementa estas bases con enlaces propios sin cambiar la integración actual. La carga sigue requiriendo prueba del archivo exacto y aprobación antes de producción.

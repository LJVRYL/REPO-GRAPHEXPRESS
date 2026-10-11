# Bolsas — mockups propios GRAPHEX, 11/10/2026

Alcance autorizado: imágenes de 17 fichas publicadas, 14 MS Bags y 3 Elementi. Inventario real previo, no cantidad inferida desde categorías. No modificar precios, opciones, impuestos, títulos de producto, terminaciones, mapping, colecciones, experiencia de producto ni Canva.

## Origen y marca

Sin evidencia disponible de licencia para editar/rebrandear fotos del proveedor: **no se editaron ni se suministraron esas fotos a ImageGen**. Se inspeccionaron para verificar formas y materiales, y se crearon 22 mockups originales a partir de descripciones técnicas. Fuente de características: catálogo real y configuradores productivos; MS Bags https://msbags.com.ar/ y Elementi https://www.elementi.com.ar/categoria-de-producto/bolsas/. No actualizar tarifas usando esas consultas.

Identidad verificada contra el sitio actual y el repositorio: `graphex-simbolo.svg`, SHA-256 `fee22c91b6d417587de0ae7e052ff6ea8925d2c8c08a42cfe219c4993957ce3b`. Se usa el mismo símbolo oficial G que en el lote Windbanners. Sus trazados y degradado se incorporan como vector exacto en composiciones SVG; ImageGen genera sólo la bolsa sin logo. Escala uniforme, sin reconstrucción generativa, recoloración ni deformación. La diferencia CRLF/LF del checkout no reemplaza los bytes del asset oficial verificado en producción. Exportador SVG con soporte de degradado; no usar la primera salida de prueba de PyMuPDF, que pintaba el símbolo en negro.

Fondos de estudio neutros y materiales coherentes. Lienzo: algodón natural, manijas correspondientes. Friselina: no tejida, asa de tira o troquelada según modelo, con fuelle de 10 cm en MS Bags cuando corresponde. String: poliéster 210D, cordón negro. E-commerce: negra lisa, sin asas; logo **fuera de la bolsa**, porque la configuración vigente no ofrece impresión. No inventar tamaños ni acabados.

Cada imagen dice «Imagen ilustrativa». Las estampas son ejemplos de personalización, no evidencia de unidades fabricadas ni promesa de impresión incluida. Alt/caption/description lo reiteran. Las variantes sin personalización siguen siendo configurables al precio actual. Dos tamaños de friselina Elementi y cinco de lienzo Elementi reciben imágenes propias; no se deforma un asset para otro formato. Se reemplazan las galerías genéricas anteriores por estas variantes, conservando las opciones de color del configurador y los respaldos originales.

## Publicación reversible

`assets/images/bags/graphex-20261011/manifest.json` registra assets, hashes, producto, variante, prompt, origen y símbolo. PNG originales generados, SVG nativos y evidencia se conservan en el workspace fuera de Git; los WebP de 1200 × 1200 y manifiesto se versionan.

`tools/bags-image-release.php`: CLI explícita fuera del runtime. Preflight de las 17 fichas (ID/SKU/slug/imagen/galería) y 22 hashes antes de crear adjuntos. Backup privado nuevo de posts/metadatos/asociaciones y archivos originales, verificado por hash. Sólo actualiza `_thumbnail_id` y `_product_image_gallery`; huella de post y demás metadatos protegidos antes/después. Journal guarda adjuntos y cambios. Copias nuevas al directorio uploads por API WordPress, metadata/thumbs por API. No usar sync de catálogo completo. No borrar originales ni adjuntos nuevos.

Rollback selectivo `--rollback`: verificar respaldo, huellas y asociaciones posteriores antes de restituir sólo miniatura/galería previas. Si hubo deriva, detener. No restaurar toda la DB ni borrar archivos. Ante release parcial, revisar journal y restituir sólo IDs aplicados bajo guardas; no asumir que un fallo dejó todo intacto.

Autorización del milestone: publicar imágenes verificadas; **no auto merge a master**. Commit de candidato en rama aislada y publicación de datos documentada como excepción a la secuencia de merge habitual. `canonical_synced=false` hasta integración explícitamente autorizada. No cambiar código productivo del feed, que tiene un candidato ajeno pendiente de merge.

## Contrato Meta

Fuente para consumir: `https://graphex.ar/wp-json/ge/v1/commerce-feed.csv`. La actualización de imagen conserva IDs de oferta, precios, colecciones y enlaces. Verificar 200/hash de imágenes nuevas, HTML de producto y referencias públicas del feed; no afirmar ingestión Meta/WhatsApp sin comprobarla en esa integración. El worker de Meta consume el nuevo feed y revalida importación y teléfono, sin campañas ni mensajes enviados desde este milestone.

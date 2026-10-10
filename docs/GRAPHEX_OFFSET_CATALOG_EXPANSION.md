# Ampliación de productos terminados — 10/10/2026

Cuatro fichas independientes: carpetas offset, postales impresas, señaladores offset y almanaques personalizados 2027. Temporada 2027 confirmada expresamente por Leo. No son variantes de tarjetas ni reutilizan sus precios.

## Fuente y estado comercial

`catalog-expansion-20261010/products.json` separa datos confirmados, propuestas y pendientes. La oferta actual de Unigraf se consultó sólo como referencia de catálogo: https://unigraf.com.ar/productos/carpetas/ y https://www.unigraf.com.ar/productos/. El enlace de tarifa en https://www.unigraf.com.ar/gremio devolvió 404 el 10/10/2026; no valida precio ni vigencia. No se copian fotos, diseños ni marca del proveedor.

Los productos se crean **en borrador, ocultos y sin precio**. No hay nuevos motores, tarifas, conectores Canva ni modificaciones del flujo comercial vigente. El diseño unificado del sitio ya aplica por su plantilla común. Imágenes y galería permanecen pendientes: no se presenta un mockup como foto real.

Carpetas: capacidad offset existente confirmada por Leo; formato, troquel, solapas, soporte y terminaciones requieren validación. Postales: pieza propia; no extrapolar desde tarjetas. Señaladores: 5 × 18 cm es propuesta, no medida técnica validada. Almanaques: 2027 confirmado; 2000 unidades es propuesta, no mínimo ni oferta. Sin calendario gráfico hasta definir formato y revisar fechas y grilla.

## Habilitación

Para cada ficha aprobar papel/gramaje, medida y plantilla, caras/tintas, acabados, mínimos/tiradas, plazo, foto propia y precio con fuente/vigencia/margen/impuestos. Cotización manual si falta una tarifa; compra directa sólo con configuración comercial validada. Mantener el archivo exacto, prueba y aprobación del portal antes de producir. No indicar un sangrado universal: preprensa confirma el del formato y troquel concretos.

Galería: `gallery-rights.json` está vacía y deshabilitada. Cada activo necesita licencia y evidencia para uso comercial e impresión, modificaciones y atribución. No usar resultados de buscador como prueba de autorización.

## Contrato para logística

`catalog-expansion-20261010/logistics-contract.json` propone un intercambio reutilizable de cantidad, masa neta, embalaje, bultos y dimensiones exteriores. Todos los valores físicos sin evidencia quedan nulos; las estimaciones se rotulan y se validan con muestra medida. No confundir desperdicio de producción con peso enviado, masa neta con tara, ni peso real con volumétrico. Divisor, redondeo y reglas del transportista pertenecen al trabajo de logística. Su aceptación del contrato aún debe registrarse; disponibilidad de un archivo no implica integración.

## Operación reversible

`tools/create-offset-product-drafts.php` se ejecuta explícitamente por administración; no es plugin ni se instala como runtime. Verifica dominio, categoría, colisiones por SKU/slug y plan de cuatro borradores. Respalda y verifica el inventario publicado y la ausencia de productos destino en un directorio privado nuevo. Registra cada ID creado antes de avanzar. Releer estado/precio/comprabilidad y comprobar que el catálogo publicado permaneció intacto.

Rollback selectivo: consultar `created.json` privado; verificar ID, `_ge_expansion_key`, revisión, estado draft y hash de contenido. Si coincide, pasar sólo esos borradores a papelera con API WordPress. Si alguien editó/publicó uno, detener rollback y revisar. No borrar definitivamente productos ni categorías, no restaurar la base completa. El respaldo original representa ausencia de las cuatro fichas, y el journal conserva los IDs para reversión. No versionar respaldos ni datos privados.

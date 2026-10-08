# Volantes Full Color · fuente operativa

Producto: `volantes-full-color` (ID 81). Project: graph-express. Resource: graph-wordpress-prod. Integration de precios: Druck. Vigencia: 2026-10-08.

## Precios y alcance

`wp-content/mu-plugins/ge-volantes/druck-prices.json` contiene 436 combinaciones verificadas por respuesta del calculador Druck: Obra 80 g (96), Ilustración 115 g (316), Ilustración 150 g (24), sin doblado. Los precios de origen son netos. Cada lote Graphex cuesta `ceil(neto Druck × 1.50)` pesos, más IVA 21%. La orientación intercambia ancho y alto sin cambiar el precio. El servidor valida la combinación y la cantidad; el carrito de bloques fija mínimo, máximo y múltiplo al lote, desactiva la edición y vuelve a validar al continuar; otros papeles requieren cotización manual y no se compran a precio cero.

115 g, 20 × 15 cm horizontal, 1.000, doble faz: costo $54.000; venta $81.000 netos; IVA $17.010; final $98.010. Recargo 50% sobre costo, margen bruto 33,33% sobre venta, diferencia $27.000 antes de diseño, envío, comisiones y demás gastos. No es ganancia neta. Un descuento de 33,33% sobre el neto consume esa diferencia incluso antes de otros gastos. El pedido de referencia vino por WhatsApp, según Leo; no se encontró una venta equivalente registrada y no se creó ninguna.

En 115 g, 20 × 45 y 50 × 70, 30.000 unidades, ambas caras de impresión, el proveedor devuelve un lote distinto y excluye 30.000 del selector. Esas cuatro combinaciones no se publican. No se extrapolan precios. 150 g sólo dispone de 1.000 y 2.000 en las respuestas verificadas. Frente y doble faz coinciden en precio cuando así lo informa Druck.

La extracción conserva URL, fecha y SHA-256 de la respuesta. Las respuestas originales se guardan en el paquete de evidencia de la tarea; los precios necesitan nueva verificación al actualizarlos.

## Calendario confirmado por Leo

Zona horaria Buenos Aires. Se considera tomado el pedido únicamente con pago confirmado, arte exacto aprobado y documentación lista. La creación de una orden no acredita esa condición.

- Desde viernes 12:00 hasta martes 00:00, sin incluir el martes: viernes siguiente.
- Desde martes 00:00 hasta viernes 12:00, sin incluir el corte: miércoles después de ese viernes. Ejemplo: martes 13/10/2026 → miércoles 21/10/2026.
- Obra 80 g e Ilustración 150 g: aproximadamente 15 días, fecha de salida a coordinar.
- Feriados y excepciones: coordinar, sin desplazamiento automático ni promesa de entrega.

Configuración en Gestión → Calendario de volantes (`admin.php?page=ge-volantes-calendar`), meta `_ge_production_calendar` del producto. Permite ajustar cortes, días adicionales de preprensa, demoras y fechas excepcionales. La ficha muestra una previsión condicionada a completar el pedido ahora. Los nuevos pedidos guardan proveedor Druck, pero dejan la fecha prometida vacía hasta la confirmación operativa. Las asignaciones históricas no se recalculan.

## Preprensa y aprobación

Contrato versionado: `wp-content/mu-plugins/ge-volantes/prepress-spec.json`. Sólo volantes. Decisión expresa de Leo ante discrepancia entre guía y plantillas Druck: 5 mm de sangrado por lado y 5 mm de seguridad interior. Archivo final CMYK, raster a 300 dpi efectivos, tipografía mínimo 6 pt con trazos medios o gruesos. PDF con fuentes incrustadas o curvas; doble faz dos páginas frente/dorso.

20 × 15 cm final → documento 21 × 16 cm → área segura centrada 19 × 14 cm. El prompt cambia con medida, orientación y caras; copiar «CMYK» o «300 dpi» no certifica un archivo generado por IA. La exportación y revisión del archivo exacto siguen siendo obligatorias.

Cada nuevo ítem conserva especificaciones inmutables para su análisis. Los hooks genéricos añadidos al analizador y a producción sólo cambian volantes que tengan ese contrato. Dimensiones, páginas, resolución y espacios de color se informan según lo realmente detectable. Tamaño de fuentes, trazos, contenido dentro de seguridad y extensión real del fondo permanecen UNVERIFIED cuando no se verifican. Vista previa y carga no equivalen a aprobación. Sigue vigente el flujo de ficha, vinculación, prueba exacta y aprobación del portal.

La vista previa reutiliza el componente de tarjetas, con selector de producto configurable y sus controles de privacidad intactos. No incorpora Canva ni vCard a volantes.

## Release y reversión

Rama aislada → pruebas → merge fast-forward de master → push → artefacto obtenido del commit canónico → backup privado verificado → deploy selectivo → hashes, producto, datos comerciales y navegador. `tools/release-volantes-20261008.php` exige host, dominio, base, ID y rutas esperados, lock y hashes previos. No sincronizar todo el catálogo ni guardar el producto mediante `WC_Product::save`: este último programa reanálisis histórico.

Modos del script: `backup`, `deploy`, `verify`, `rollback`, seguidos de ruta del artefacto `/tmp/ge-volantes-release-…` y backup `/root/ge-backups/volantes-20261008…`. Ejecutar con `/opt/php8-3/bin/php-cli`. El backup guarda bytes previos, metadatos seleccionados, descripción, miniatura y archivos de imagen anteriores. Se valida antes del despliegue. Rollback restaura sólo esa lista, exige que no haya cambios posteriores, retira archivos nuevos a la carpeta privada y conserva la nueva imagen sin uso para trazabilidad. No restaura bases completas ni cambia pedidos/presupuestos.

Diferencias previas explicadas: el analizador tiene CRLF en Git/producción (se conservan); la vista previa de producción estaba una corrección por detrás de master en el control de huella del archivo. Se despliega la corrección ya canónica junto con el selector configurable.

Pruebas: `tests/volantes-20261008.php`, 3.544 comprobaciones de precios, IVA, opciones, corte temporal, cantidades, contratos, preflight e historial; PHP 7.4 y 8.3. `tests/production-closure.php` mantiene el contrato comercial. QA de navegador: opciones dependientes, manual bloqueado, prompt/copia, PDF frente/dorso y archivo inválido sin miniatura anterior, móvil sin desborde. No se generan órdenes ni se envían correos de prueba.

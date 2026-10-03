# Configurador de presupuestos

Gestión y portal usan una elección de modelo, terminación y papel. Cada combinación de modelo/terminación apunta a un ítem con precio congelado; el papel elegido se valida contra sus opciones. No se suman las alternativas.

El selector usa miniaturas privadas del archivo exacto y permite exportar PDF sin aceptación, checkout, pago ni producción. La plantilla comercial existente conserva encabezado, marca, emisor, bloques, QR y paginación: sólo recibe el snapshot elegido. PDF sin selección se rechaza para staff y cliente. La aceptación fija selección y configuración aparte de la propuesta; pago y conversión usan ese alcance. Sólo el PDF del modelo elegido se hereda, no miniaturas ni artes de otras alternativas.

QA: 36 combinaciones de seis modelos, tres terminaciones y dos papeles; 278 comprobaciones de precio/cantidad, exclusividad, papel inválido, modelo desconocido, versión vencida, round trip, PDF, aceptación, conversión y herencia de arte. Fixtures sintéticos en base aislada, sin mail externo. Regresiones de billing/portal se registran en el Result Pack. Exportación real desde navegador: modelo 5 + 350 g mate + laminado brillo = ARS375000, una página con plantilla existente.

Release: allowlist de siete archivos, backup privado con tar/hash verificados y guardas de concurrencia. Desplegar desde master canónico; confirmar manifiesto completo y hashes del presupuesto 986. Configuración operativa de 1002 permanece borrador, precios finales con Factura C, 100 unidades totales; no envío, aceptación, cobro ni liberación de producción.

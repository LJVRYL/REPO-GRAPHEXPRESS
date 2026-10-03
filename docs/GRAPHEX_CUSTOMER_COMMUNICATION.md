# Comunicación comercial con clientes

Vigencia: 2026-10-03. Project: graph-express. Resource: graph-wordpress-prod.

PDF, portal y correo muestran productos, variantes disponibles, cantidades, precios,
IVA pertinente, descuentos, total elegido y condiciones comerciales. No muestran
conciliación fiscal pendiente, QA, canonical, snapshots, verificaciones internas,
estados de infraestructura ni instrucciones para el personal. Si una operación no
está disponible, el mensaje indica una acción útil para el cliente; el diagnóstico
detallado se conserva en Gestión y sus registros. Nunca se simula un estado fiscal
válido para eliminar un aviso.

El tratamiento comercial se define por versión de presupuesto: configuración
heredada, IVA agregado cuando corresponde o precio final con IVA incluido cuando
corresponde. No modifica la condición fiscal del emisor ni habilita comprobantes.
Factura C / emisor sin IVA adicional conserva el precio final. Las versiones
existentes no se recalculan por una lectura o descarga.

Las alternativas se declaran explícitamente por grupo y permiten una elección por
grupo; los adicionales se seleccionan por separado y los ítems incluidos se
acumulan. No se deduce exclusividad del nombre del producto. Antes de elegir no se
presenta la suma de alternativas como precio del trabajo. Vista previa y descarga
son operaciones de lectura. La aceptación auténtica del cliente guarda la elección
con versión, actor, fecha y propuesta de origen, sin sobrescribir esa propuesta.
El pedido y el cobro utilizan el mismo alcance aceptado; elegir o descargar no
aprueba el arte ni inicia producción. El comparativo del personal se identifica
como tal y no presenta un total ficticio de todas las alternativas.

El PDF incluye un QR al presupuesto en el portal; no imprime una URL extensa ni
incluye tokens de vista previa, nonces o credenciales en el QR. La sesión y los
permisos normales del portal siguen siendo necesarios.

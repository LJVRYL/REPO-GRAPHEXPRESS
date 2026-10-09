# Facturación electrónica ARCA

Integración WSAA/WSFEv1 con configuración externa `GE_WTP_ARCA_EMISSION_CONFIG_FILE`. Las claves, certificados y tickets deben permanecer fuera del sitio y Git, con permisos privados. Cada emisor tiene referencias simbólicas propias; homologación y producción nunca comparten numeración ni publicación.

La pantalla del pedido permite revisar una factura ordinaria local A/B/C en ARS. Exige permisos financieros, emisor verificado, evidencia fiscal vigente del receptor, punto de venta activo, condición IVA, fechas y coincidencia entre detalle y totales. No implementa FCE, exportación, notas asociadas, monedas extranjeras ni regímenes especiales. La emisión fiscal exige revisión final y autorización explícita del comprobante exacto.

La revisión fiscal opcional consulta datos actuales del mismo CUIT emisor y del receptor. Se registra en el payload aprobado y conserva los snapshots comerciales originales. Un cambio de CUIT o alícuota requiere rectificación comercial previa. Las descripciones incluyen nombre y especificaciones públicas de los ítems del pedido.

El registro `ge_arca_invoices` conserva cada intento. Un bloqueo de base serializa CUIT/ambiente/punto de venta/tipo. La solicitud se guarda antes del envío; una respuesta incierta bloquea nuevas emisiones en ese alcance. Recuperar consulta únicamente el número registrado y valida sus datos: nunca reenvía automáticamente. Una negativa definitiva permite un nuevo intento revisado, conservando el rechazo anterior y utilizando la numeración vigente de ARCA.

El PDF usa el payload fiscal autorizado, la identidad vigente de Graphex, CAE, vencimiento y QR oficial. Homologación muestra SIN VALIDEZ FISCAL y no publica documentos al cliente. Producción publica en el almacenamiento privado y respeta la autorización de descarga existente. Un pedido con solicitud productiva pendiente o autorizada bloquea cambios de emisor y reemplazos manuales de factura.

## Verificación del candidato

- `tests/arca-emission-wp.php`: base aislada obligatoria; transporte simulado, autorización, concurrencia, timeout, recuperación, rechazos, permisos, protección de documentos y paginación.
- Prueba real de homologación autorizada y consultada; ninguna solicitud de CAE productiva.
- Consulta WSFE productiva y WSCI productiva verificadas mediante credenciales externas.
- Revisión fiscal con datos actuales verificada sin cambiar snapshots comerciales ni emitir.
- Regresión `tests/issued-document-version.php` y compatibilidad PHP 7.4/8.1.

## Despliegue y recuperación

Aplicar la política de releases: rama aislada, QA, integración canónica, respaldo privado verificado, allowlist de seis archivos PHP, configuración externa y smoke. El expediente de operación y el Result Pack deben registrar hashes, HEAD, referencias de respaldo y estados reales. No guardar secretos en este documento.

Rollback: comparar primero el estado actual con el manifiesto del release. Restaurar sólo los tres archivos PHP preexistentes y las referencias de configuración respaldadas; desactivar la carga del módulo nuevo. Conservar tabla fiscal, PDFs, claves, certificados y auditorías. Nunca revertir datos fiscales autorizados ni restaurar una base completa para deshacer el código. La reversión del catálogo de emisores requiere revisión de los pedidos creados desde el cambio y una nueva versión auditada.

Tickex puede reutilizar el transporte y el registro, pero requiere su propia identidad, instancia, permisos, emisor, referencias privadas y política comercial. No compartir credenciales ni habilitarlo a partir de los datos de Graphex.

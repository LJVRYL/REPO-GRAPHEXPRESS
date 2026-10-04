# Mis facturas — carga de originales y revisión

Staff: Gestión → Clientes → ficha del cliente → **Facturas y comprobantes** → **Cargar factura o comprobante**.

Cliente: Portal → **Mis facturas**, también tarjeta en Resumen. Lista completa de documentos del registro nuevo y documentos fiscales/comprobantes históricos de pedidos propios. Filtros tipo, fecha y revisión; detalle, aclaraciones, descarga del original e historial de consulta.

## Carga comercial

1. Verificar la cuenta correcta (Multiplex: customer 29) y seleccionar explícitamente el receptor que consta en el original. No asignar por nombre de sucursal, dominio de email ni semejanza de razón social.
2. Elegir tipo correcto: factura ya emitida, comprobante de pago, recibo, nota de crédito/débito, presupuesto/proforma o remito. Cargar número, fecha, emisor literal, moneda e importe del documento; seleccionar pedido/presupuesto propio si corresponde. Un receptor distinto al del vínculo registrado bloquea la carga.
3. Subir PDF/JPG/PNG exacto, máximo20MiB. Aclaración pública separada de nota interna. Confirmar los datos contra el original. La carga publica en la cuenta seleccionada y no envía email automáticamente.
4. Abrir detalle, descargar y comparar SHA-256 con el original. Revisar vista previa del cliente, receptor y aclaración antes de **Avisar al cliente que está disponible**. La acción usa el remitente de Notificaciones de graphex.ar y se registra una vez por archivo; no sustituir por Gmail.
5. Revisar Notificaciones para aceptación/fallo del transporte. Aceptación SMTP no prueba entrega a la bandeja del destinatario. Los emails de QA son simulados.

Subir un original no emite una factura fiscal ni modifica el PDF. El importe mostrado no equivale a deuda ni saldo. Una nueva versión conserva todas las anteriores; una nota de crédito es un comprobante separado y no se usa el control de versiones para simular anulación fiscal.

## Revisión

Cliente → detalle → Informar un problema → comentario obligatorio. Historial: Recibido, En revisión, Respondido, Resuelto. Puede agregar comentarios; un comentario posterior a respuesta/resolución reabre en Recibido. Staff responde y selecciona estado desde la misma pestaña del cliente; **Avisar por correo al cliente** es opcional y está desmarcado por defecto. Cambiar estado requiere un comentario público o una nota interna del equipo. La revisión no anula documentos ni altera pagos.

Aviso cliente y aviso staff usan GE_WTP_Notifications, con trazabilidad ge_email_log. Alerta interna existente enlaza al cliente/documento. La persistencia ocurre antes del intento de correo; la confirmación de comentario indica guardado, no entrega SMTP. Reintentos del mismo formulario no duplican eventos ni intentos de correo. Fallos/estados attempting requieren revisar la trazabilidad antes de una intervención operativa; no se reenvía automáticamente un resultado incierto.

## Modelo y autorización

- ge_customer_invoice privado / _ge_customer_invoice: customer_id, profile_id, snapshot receptor, tipo/número/fecha/emisor/moneda/importe, vínculos, notas separadas, descriptor privado, SHA256, autor/fecha, replaces.
- ge_invoice_case privado / _ge_invoice_case: referencia exacta, cliente/perfil, estado e historial con token de operación, actor, fecha, mensaje y resultado de avisos. Una conversación por original, enlazada a la campana existente.
- Registro y autorización por organización vinculada a DB/root y customer_id exacto. Perfil debe pertenecer al cliente (incluye perfiles archivados). No incorporación automática de pedidos invitados por email.
- Staff lee con permiso finance; carga/respuesta/aviso requieren finance write (owner/admin/administracion según roles vigentes). Producción y comercial no reciben acceso fiscal por su rol. Cliente solo puede consultar/descargar/comentar lo propio; preview staff permanece sin acciones del cliente.
- No se exponen URLs públicas de originales. VPS privado existente, fallback privado protegido por .htaccess; download server-side con nonce, autorización, no-store, nosniff, attachment y verificación checksum. R2 histórico usa firma temporal tras autorizar.
- No migración destructiva ni emisión ARCA. No edita presupuestos, pagos, perfiles ni documentos históricos. Documentos legacy conservan metadatos disponibles; no se inventan importe/emisor/fecha faltantes.

## Prueba independiente

Tests WordPress: tests/customer-invoices-wp.php. Usar un WordPress nuevo con WooCommerce/GE y mu-plugins canónicos, DB `graphex_invoices_qa_20261003`, administrador fixture `invoice_qa_admin`, correo interceptado y private storage propio. Core materializado (sin symlinks a otro sitio). Ejecutar con GE_INVOICE_QA_SITE apuntando al sitio QA. El test rechaza otro nombre de DB, crea usuarios/pedidos sintéticos y guarda reporte opcional GE_INVOICE_QA_REPORT. Nunca ejecutarlo en producción.

QA adicional por HTTP: multipart → listado → descarga byte exacta → comentario → avisos simulados trazables → respuesta/resolución. Acceso cruzado, anónimo, CSRF, double click, MIME falso y más20MiB rechazados. Browser1440/390px sin overflow, foco y pestaña fiscal; capturas en Result Pack.

## Release/rollback

Allowlist8 archivos, hashes de origen y destino; rama→tests→merge master→deploy desde bytes de commit canónico→smoke→relectura hashes. Guardas abortan si hubo cambio concurrente. Backup privado de5 originales de código y SQL72tablas, restauración de código a scratch verificada y gzip/tabla/hash del SQL. No se restaura SQL durante rollback.

Rollback selectivo: ejecutar `python3 <backup>/rollback.py rollback` solo tras comprobar que los8 hashes todavía corresponden a este release. Restaura5 archivos de integración, conserva los3 archivos nuevos inactivos, DB, originales y consultas. La feature deja de estar visible hasta reactivar; los datos siguen preservados. No aplicar sobre una publicación posterior sin reconciliar guardas.

## Multiplex

Esta implementación no carga comprobantes comerciales ni envía correos reales a Multiplex. El auditor de documentos realiza la carga después del readiness publicado/verificado, con el original exacto y el receptor acreditado. No repetir su inventario ni afirmar que ambas razones sociales son intercambiables.

## Bandeja y notas internas

Gestión → **Revisiones** (`/gestion/?section=invoice-reviews`) lista conversaciones de la organización actual, 50 por página, con cliente, receptor, comprobante, última actividad, estado y enlace al detalle. Requiere finance read. No lista el contenido de notas privadas.

Desde el detalle, **Comentario interno → Guardar nota interna** inicia o continúa el mismo caso con finance write. No se publica ni envía correo. El cliente solo ve eventos públicos; la vista previa muestra el formulario deshabilitado y explica cómo escribir desde una cuenta de cliente o abrir Gestión para una nota interna. Respuesta pública separada, con opción explícita de aviso y trazabilidad. Reintentos con token mantienen un único evento; no convierten una nota privada en respuesta pública ni agregan un envío después.

QA adicional reusable: `tests/customer-invoice-reviews-wp.php`, con el mismo GE_INVOICE_QA_SITE y DB fixture independiente del test original; GE_REVIEW_QA_REPORT permite guardar el reporte. Prueba creación privada, permisos, aislamiento, privacidad cliente/preview, idempotencia, notas/respuestas/estados y correo simulado. Nunca ejecutar contra producción.

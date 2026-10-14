# Compromiso de producción y seña — release 14/10/2026

La preparación y las vistas previas permanecen disponibles antes del pago. Liberar producción interna, enviar una orden al proveedor y registrar su primera aceptación requieren la condición de pago congelada del pedido o una excepción explícita de Administración. Un comprobante o el estado `paid` sin evidencia no bastan; se reutiliza `GE_WTP_Job_Flow::payment_check` y su ledger.

La excepción registra actor, fecha, motivo, vencimiento (máximo 30 días), pedido y huella de importe, moneda, cliente, versión/snapshot comercial, condiciones y renglones/terminaciones. Cambiar esa huella o revocar/vencer la excepción impide nuevos compromisos. Nunca marca dinero recibido. Cuenta corriente ya autorizada conserva su condición nativa.

Administración opera desde el pedido; owner/admin también desde Control final. El rol producción ve el bloqueo y puede preparar archivos, pero no autoriza excepciones. La acción se clasifica en `finance`, conservando la separación de permisos existente. Los envíos previos y hechos físicos no se deshacen al revocar; una primera aceptación todavía pendiente sí requiere revisar su autorización.

## Alcance y dependencias

PR18, PR19 y PR20 integrados en master con autorización expresa de Leo; release selectivo del hito P1. Reemplaza la regla general del PR19 de autorizar producción impaga. No incorpora conciliación bancaria automática ni certifica todos los canales, costos, stock o entrega. La política existente de saldo antes de entrega sigue en su propio control.

Autorización previa de 3528 (quote975 v2, ARS330000) preservada como excepción auditada por el release autorizado: pago pending/0 y preparación sin cambios; vigencia hasta 21/10/2026 01:58 Buenos Aires. No se liberó producción.

## QA reproducible

`tests/production-payment-guard.php`: aritmética/ledger nativos con stubs de entorno, sin DB; cero/receipt/status solo/confirmación manual/seña exacta/insuficiente/ledger inconsistente/replays, permisos, revocación, expiración y cambio de huella. 33 comprobaciones por runtime PHP7.4/8.1.

`tests/production-cards-wp.php`: `GE_QA_SITE` admite la QA dedicada con DB `graph_integral_guard_20261014` o la QA histórica explícita. Nunca correr contra producción. Todos los fixtures registran excepción explícita cuando corresponde; se comprueba denegación sin seña, aprobación exacta, bytes/propiedad, manual/proveedor, revocación antes de envío y aceptación, rechazo/reapertura, avisos/replays y roles. Correo/HTTP interceptados; base nueva sin copiar clientes.

Vistas nativas sintéticas renderizadas en navegador a 1366 y 390 píxeles: una tarjeta, sin desbordamiento; formulario de excepción sólo para administración. También se verificaron formularios autenticados con sesiones y pedido sintéticos: rechazo 403 del rol producción, autorización administrativa persistente y revocación que vuelve a bloquear.

## Release y rollback

1. Revisar la cadena de PRs: PR18 parte de newsletter-hygiene, PR19 de PR18. Auditar dependencias antes de integrar a master; no habilitar campañas.
2. Con autorización explícita de integración, construir el payload desde el commit canónico final y repetir la QA necesaria si cambia. No publicar desde este checkout sin reconciliar.
3. Allowlist: módulo nuevo, core/UI de tarjetas, payment-policy, supplier-dispatch, supplier-portal, workflow. Verificar backup/manifest y ausencia de drift justo antes de deploy. La captura actual está privada en `/home/graphexpress/releases/integral-guard-qa-20261014/production-source-backup-final`.
4. Publicar dependencia nueva antes de consumidores; verificar PHP7.4 real/vhost/pool y todos los hashes después. No emitir avisos ni cambiar pagos en smoke.
5. Rollback selectivo: verificar que bytes actuales coincidan con payload desplegado y backup con `before_sha256`; restaurar sólo las rutas originales, mover el módulo nuevo a cuarentena privada. Abortarlo si hay drift. Preservar pagos, envíos, aprobaciones y hechos físicos; revisar compromisos posteriores antes de revertir a la regla anterior permisiva. Revalidar caché/runtime y smoke.

Estado, commit canónico y manifiesto exactos en el Result Pack del hito. El rollback final está en rollback-canonical.py con respaldo canonical-source-backup; debe abortar ante drift. El flujo integral posterior (conciliación/costos/entrega) sigue pendiente.

Corrección de smoke: las fechas sin hora se muestran literalmente en la zona configurada; no se cambia la fecha comercial guardada (19/10 no debe mostrarse 18/10).

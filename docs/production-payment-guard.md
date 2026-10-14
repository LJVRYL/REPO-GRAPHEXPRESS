# Compromiso de producción y seña — candidato 14/10/2026

La preparación y las vistas previas permanecen disponibles antes del pago. Liberar producción interna, enviar una orden al proveedor y registrar su primera aceptación requieren la condición de pago congelada del pedido o una excepción explícita de Administración. Un comprobante o el estado `paid` sin evidencia no bastan; se reutiliza `GE_WTP_Job_Flow::payment_check` y su ledger.

La excepción registra actor, fecha, motivo, vencimiento (máximo 30 días), pedido y huella de importe, moneda, cliente, versión/snapshot comercial, condiciones y renglones/terminaciones. Cambiar esa huella o revocar/vencer la excepción impide nuevos compromisos. Nunca marca dinero recibido. Cuenta corriente ya autorizada conserva su condición nativa.

Administración opera desde el pedido; owner/admin también desde Control final. El rol producción ve el bloqueo y puede preparar archivos, pero no autoriza excepciones. La acción se clasifica en `finance`, conservando la separación de permisos existente. Los envíos previos y hechos físicos no se deshacen al revocar; una primera aceptación todavía pendiente sí requiere revisar su autorización.

## Alcance y dependencias

Candidato apilado sobre PR19/PR18; no fusionado ni desplegado. Reemplaza la regla general del PR19 de autorizar producción impaga. No incorpora conciliación bancaria automática ni certifica todos los canales, costos, stock o entrega. La política existente de saldo antes de entrega sigue en su propio control.

Antes de activar en producción, preservar la excepción previa de 3528 (quote975 v2, ARS330000) con evidencia de autorización de Leo y alcance/vigencia auditados; no inferir que está pagado. Esta release candidata no modifica el pedido.

## QA reproducible

`tests/production-payment-guard.php`: aritmética/ledger nativos con stubs de entorno, sin DB; cero/receipt/status solo/confirmación manual/seña exacta/insuficiente/ledger inconsistente/replays, permisos, revocación, expiración y cambio de huella. 33 comprobaciones por runtime PHP7.4/8.1.

`tests/production-cards-wp.php`: `GE_QA_SITE` admite la QA dedicada con DB `graph_integral_guard_20261014` o la QA histórica explícita. Nunca correr contra producción. Todos los fixtures registran excepción explícita cuando corresponde; se comprueba denegación sin seña, aprobación exacta, bytes/propiedad, manual/proveedor, revocación antes de envío y aceptación, rechazo/reapertura, avisos/replays y roles. Correo/HTTP interceptados; base nueva sin copiar clientes.

Vistas nativas sintéticas renderizadas en navegador a 1366 y 390 píxeles: una tarjeta, sin desbordamiento; formulario de excepción sólo para administración. Es QA de render, no prueba de mutación desde navegador autenticado.

## Release y rollback

1. Revisar la cadena de PRs: PR18 parte de newsletter-hygiene, PR19 de PR18. Auditar dependencias antes de integrar a master; no habilitar campañas.
2. Con autorización explícita de integración, construir el payload desde el commit canónico final y repetir la QA necesaria si cambia. No publicar desde este checkout sin reconciliar.
3. Allowlist: módulo nuevo, core/UI de tarjetas, payment-policy, supplier-dispatch, supplier-portal, workflow. Verificar backup/manifest y ausencia de drift justo antes de deploy. La captura actual está privada en `/home/graphexpress/releases/integral-guard-qa-20261014/production-source-backup-final`.
4. Publicar dependencia nueva antes de consumidores; verificar PHP7.4 real/vhost/pool y todos los hashes después. No emitir avisos ni cambiar pagos en smoke.
5. Rollback selectivo: verificar que bytes actuales coincidan con payload desplegado y backup con `before_sha256`; restaurar sólo las rutas originales, mover el módulo nuevo a cuarentena privada. Abortarlo si hay drift. Preservar pagos, envíos, aprobaciones y hechos físicos; revisar compromisos posteriores antes de revertir a la regla anterior permisiva. Revalidar caché/runtime y smoke.

Estado `canonical_synced=false` hasta integrar y verificar el release. El backup actual es preparatorio y debe recapturarse si otros workers cambian esas rutas.

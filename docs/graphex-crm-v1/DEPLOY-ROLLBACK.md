# Graphex CRM v1 · operación

Instancia: graphex.ar · `/home/graphexpress/public_html` · recurso `graph-wordpress-prod`. Perfil OpenSSH verificado `ai-grupo-ferozo-prod`; credential_ref: `ferozo-prod-root`. Sin credenciales incluidas en este documento.

## Release efectivo

2026-10-03 03:40 UTC. Release privado: `/root/ge-releases/crm-v1-20261003T034007Z`. Respaldo: `/root/ge-backups/crm-v1-20261003T034007Z`.

El respaldo contiene el código de dependencias revisadas, el paquete CRM, estado previo de las opciones afectadas y cron, y hashes. Está restringido al servidor, porque el cron puede contener contexto privado. Se verificaron lectura del JSON, integridad gzip, listado tar y checksums. Antes del cambio no existían las tablas ni el loader CRM. No se necesitó copiar datos operativos ni restaurar una base.

## Etapas y comprobaciones

1. M1: seis referencias OSS, decisión nativa y mapeo de entidades reales.
2. M2: schema/lead/opportunity/task en QA fresca, revisión InnoDB, transacciones y aislamiento.
3. M3: pipeline, cambios con revisión y timeline; POC real presupuesto→pedido en QA.
4. M4: extensión Customer Workspace, sin reemplazar su ficha ni sus datos.
5. M5: lectura de comunicaciones, biblioteca de respuestas rápidas y contrato de revisión, sin delivery externo.
6. M6: tareas internas idempotentes de solicitudes/seguimiento/inactividad.
7. M7: navegador desktop/móvil, respaldo, preflight PHP 7.4, schema vacío primero y loader último, smoke de servidor y HTTP.

El despliegue es aditivo y se activa con un único loader tras preparar sus componentes. No modificó los archivos compartidos ni incluyó el trabajo pendiente de otras ramas. El guard de hashes frenó un intento al detectar la evolución de Solicitudes; se revisó el diff, se probó su contrato actual y se actualizó sólo el manifiesto. Ese código ajeno se preservó.

Se revisaron también dos limitaciones del runtime: `/opt/php7-4/bin/php` es CGI y `/opt/php7-4/bin/php-cli` está vacío. Se usó el ejecutable válido con `-q` y archivos PHP; no se instaló ni alteró PHP.

## Rollback conservador

Mover únicamente `/home/graphexpress/public_html/wp-content/mu-plugins/ge-crm-v1.php` a un nombre nuevo dentro del release privado. No eliminarlo ni sobrescribir otros loaders. Ejecutar `prod-rollback.php` del release con `/opt/php7-4/bin/php -q`: valida docroot, comprueba que CRM ya no está cargado y limpia exclusivamente `ge_crm_daily`.

Preservar `ge-crm/`, las dos tablas CRM, configuración y todos los registros creados. No restaurar la opción cron completa porque pisaría trabajos concurrentes. No restaurar tablas canónicas: este release no las migró. Verificar sitio y Gestión previos, loader ausente, cron CRM ausente y conteos preservados. Para reactivar, revisar las dependencias y mover el loader de vuelta; reinstalar de forma idempotente restaura su schedule.

El ensayo se hizo en QA con loader desactivado y reactivado: tablas/registros/eventos permanecieron iguales y el schedule propio se retiró/restauró. Producción quedó activa; no se simuló una interrupción real allí.

## Evidencia

70 controles de backend, 8 adicionales (configuración, landing actual, desactivación de automatizaciones y rollback de transacción), 17 de navegador, 3 de rollback de módulo, 16 de smoke producción y 5 del shell completo. HTTP: home=200, CRM anónimo=403, REST anónimo=403. Los conteos canónicos y el presupuesto protegido #986 se mantuvieron iguales durante el rollout. No se crearon clientes/presupuestos/pedidos QA en producción y no se enviaron mensajes.

Las capturas son de una base QA sintética con código autorizado de producción y el shell real de Gestión. No contienen capturas de clientes reales.

## Repositorio y mantenimiento

Rama aislada `feature/graphex-crm-v1`, base `origin/master` 2ef226cae78391c7719fce5de5b96d3047e5b866. El checkout canónico y su rama de Portal con cambios abiertos se conservaron. No se mergeó ni pusheó automáticamente a master. La rama agrega sólo el módulo, documentación, pruebas y herramientas propias; no importa snapshots de producción al repositorio.

Antes de integrar la rama en el repositorio remoto, reconciliar los commits actuales de Foundation, Portal, Solicitudes y Gestion v3; el manifiesto de dependencias registra la base efectiva del release. Si cambia el shell o la API canónica, repetir el smoke del wrapper y el POC. La aplicación sin sus dependencias Foundation/Staff no activa el loader.

Pendientes de escala: paginación de listas/selectores y revisión diaria por lotes. Pendientes de canal: receptor externo web/email, WhatsApp real y IA. No son necesarios para el CRM manual v1 ya activo.

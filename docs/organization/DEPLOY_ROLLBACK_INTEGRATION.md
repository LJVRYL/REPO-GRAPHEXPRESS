# Deploy / backup / rollback / integración

## Recursos

Graph: graph-wordpress-prod, /home/graphexpress/public_html, DB graphexpress_wp. Credential ref: ferozo-prod-root. QA: empresa-qa, DB ge_org_empresa_qa, http://localhost:19050. Cada nueva empresa requiere su propia DB/principal SQL/docroot/salts/storage/config y credenciales. No copiar wp-config, usuarios, sesiones, certificados ni options de integraciones de Graph.

## Onboarding admin

1. Provisionar recurso nuevo con núcleo WordPress físico propio y código reusable. El provisionador QA genera una DB vacía, principal limitado y salts aleatorios; rechaza sobrescribir instancias existentes. No se publica signup.
2. Instalar WordPress/Woo como motor transaccional y el plugin; activar binding del recurso y la raíz organization.
3. En Mi empresa → Onboarding: identidad/país/moneda, branding, emisores, owner/equipo, módulos, integraciones opcionales.
4. Finalizar onboarding y abrir dashboard. Resolver fiscal/emisor antes de operar; importar configuración no transfiere estado verificado ni habilita ARCA.
5. Configurar correo/integraciones desde el mecanismo seguro de la instancia. Nunca guardar valores secretos en refs o exports.

## Backups verificados de esta continuación

Todos bajo /root/ge-backups/ con prefijo saas-operational-v1-:
- 20261003T034718Z: aislamiento/roles/módulos y consumidores/documentos/portal (25 archivos).
- 20261003T035055Z: bloqueo de edición/instalación de código y lectura de presupuesto por rol (2).
- 20261003T035548Z: PDF del pedido desde portal con snapshot (1).
- 20261003T035921Z: catálogo y seed fiscal legacy exclusivos de Graph (2).
- 20261003T040539Z: export operativa separada (4).
- 20261003T041657Z: namespaces/CRM/AI guards (1).
- 20261003T042103Z: consultas REST de lectura con acciones protegidas (2).
- 20261003T042649Z: eliminación de fallback logo Graph para otras organizaciones (1).

Cada backup contiene manifest.json, source-before.tar.gz verificado leyendo bytes del TAR, before.json con hashes/counts, resultados por stage y smoke. No se tocaron registros operativos de producción para pruebas. El binding nuevo es aditivo; no cambia datos de negocio.

## Rollback

Restaurar milestones en orden inverso usando exclusivamente los paths del manifest del backup. Primero confirmar host/root y SHA actual contra el after; nunca pisar archivos que otro release haya cambiado. Restaurar desde source-before.tar.gz los existentes, con archivos temporales y rename atómico; retirar únicamente archivos nuevos de ESE milestone si nadie los consume. El deploy helper ya hace ese rollback automático ante fallo de guard/históricos/smoke. No restaurar una DB vieja ni hacer reset/clean/delete masivo. Si otro release depende de esta versión, preparar corrección compatible en lugar de rollback ciego.

La prueba QA deshabilitó/restauró sólo el loader de Foundation en la réplica de Graph, comprobando que datos/settings permanecen y el flujo legacy carga; nunca se apagó Foundation en producción. Para restore completo por tenant respaldar coherentemente su DB y storage privados, con credenciales fuera del bundle portable y destinos seguros. La importación operativa arbitraria todavía requiere ID remapping controlado; el import de configuración fue probado en la QA.

## Integración canónica

No modificar el checkout compartido ni master desde esta tarea. Default real: master, último baseline 2ef226cae78391c7719fce5de5b96d3047e5b866. Branch propia: feature/graphex-saas-foundation-v1. Se conserva la cadena Foundation inicial → Quote Billing Control reconciliado → Foundation operativa final, además de provenance Portal v3/Landing v1.

El trabajo autorizado «Reconciliar repo canónico de Graphex» integra todos los releases en una rama aislada. Debe incorporar el HEAD final indicado por release-state.json, tomar snapshot productivo posterior al último deploy, resolver diferencias semánticas con Funnel/CRM/Portal y ejecutar suites sobre el candidate. No elegir ours/theirs ni redeployar un árbol viejo. Sólo después de pruebas y diff explicado podrá mergear master. canonical_synced permanece false hasta demostrar que master contiene el HEAD final. Si candidate ya reproduce producción, deploy de verificación NO-OP.

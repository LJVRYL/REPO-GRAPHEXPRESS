# CRM · Agente supervisado con presupuesto

Modelo: gpt-5.4-mini-2026-03-17, Responses API, sólo texto. Tarifa estándar verificada 10/10/2026: US$0.75/M entrada, US$4.50/M salida; entrada cacheada se contabiliza al precio completo de forma conservadora. Sin herramientas de pago. Validación de tarifas vence 10/11/2026; después se pausa hasta revisión.

Tope autorizado: US$50 por mes calendario de Argentina; aviso visible a US$40; US$2 por conversación al mes. La cuota pertenece a este agente y no limita usos externos de la misma clave ni sustituye la factura del proveedor. Recomendado un proyecto/clave dedicado.

Reserva durable antes de llamar, bloqueada con flock entre procesos. Los timeouts conservan la reserva y no se reintentan automáticamente. Respuestas HTTP200 incompletas se contabilizan pero no se muestran como borradores. La solicitud idéntica reutiliza el borrador. Registro en /home/graphexpress/crm-agent/budget.json, fuera del DocRoot, con permisos 0600. Nunca borrar el registro para restablecer consumo. Credencial privada config.json; credential_ref: graphex-openai-project. El valor nunca va en Git, informes ni logs.

Interfaz: CRM → Conversaciones → Agente de Graphex → Conectar OpenAI de forma privada. Sólo owner/admin configura. En una conversación: Preparar respuesta y datos del pedido. Sólo usuarios con escritura CRM pueden llamar a la IA. Toda salida requiere revisión humana; este módulo no envía mensajes, crea pedidos ni libera producción.

Contexto acotado: mensaje actual hasta 10000 bytes, hasta cinco mensajes anteriores de la misma conversación/organización, referencias al cliente/presupuesto/pedido y hasta ocho respuestas rápidas activas. No incluye directorios de clientes, archivo binario, secretos ni historial completo. No promete acceso a precios/stock sin integración específica. Store=false; no significa retención cero contractual del proveedor.

Despliegue selectivo: ops/deploy-crm-agent.py exige hash del CRM UI base, valida PHP7.4 y pruebas aisladas antes de modificar, respalda y verifica recuperación de UI, instala loader al final. Rollback ops/rollback-crm-agent.py valida hashes, retira loader a respaldo privado y restaura UI; conserva credenciales, presupuestos y borradores privados. No requiere migración de DB.

Pendiente de este milestone: clave API, prueba real de borrador y costo; no activar atención automática sin verificar salidas. Milestones siguientes: permisos/transporte de canales, contexto comercial vivo, creación de pedido usando servicios existentes, File Analyzer, aprobación exacta, liberación autorizada, envío y correlación de devoluciones del proveedor.

Documentación: https://developers.openai.com/api/docs/models/gpt-5.4-mini y https://developers.openai.com/api/docs/guides/structured-outputs

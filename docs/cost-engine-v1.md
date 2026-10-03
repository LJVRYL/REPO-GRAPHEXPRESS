# Graphex — Costos y Productos v1

Verificado el 2026-10-02. Entrada: https://graphex.ar/gestion/?section=costs

## Fuentes de verdad
- Proveedores: Supplier Dispatch/Workspace, clave estable; vinculación de supplier_refs de Operations.
- Listas: ge_supplier_entry, _ge_supplier_entry y _ge_price_normalized. Originales privados, checksum SHA-256, versiones independientes; draft → parsed → reviewed → active → archived.
- Costos: proyección de listas vigentes, Stock latest/average por moneda, tasas internas ge_cost_source versionadas, y _ge_supplier_costs existente de WooCommerce.
- Costos Woo existentes: importe en su meta original, revisión semántica en _ge_cost_source_mapping. Cambiar el checksum del origen invalida la revisión. Nunca duplicar importes ni inferir impuestos.
- Recetas: tabla Operations recipes, extensión nullable cost_json, versiones parent_id; las líneas de consumo se preservan.
- Productos/precios: Woo CRUD y las configuraciones reales _ge_storefront_config, _ge_public_price_sections, _ge_digital_config. Las fórmulas digitales requieren mapeo explícito.
- Auditoría: Operations audit, actor, timestamp y before/after.
- Presupuesto: _ge_cost_quote_version_{version} privado; costo nunca incorporado al snapshot del cliente.

## Operación
1. Subir lista en Proveedores o Costos/Listas; nunca reemplazar un archivo anterior.
2. Parsear CSV/XLSX/XLS/PDF determinísticamente; elegir hoja, mapear columnas y revisar moneda/unidad/impuestos/escalas. Imagen/PDF escaneado: revisión asistida o manual, sin activación automática.
3. Comparar y revisar ítems. Activar sólo con hash exacto revisado y fecha vigente.
4. Completar costos internos/Stock y elaborar receta, merma y reglas de markup o margen.
5. Vincular receta al producto/opción. Analizar impacto y crear preview.
6. Aplicar sólo desde sesión Gestión autorizada y con aprobación explícita. Agentes/CLI devuelven enlace de aprobación; jamás aplican.
7. Masivo: hasta 30 productos, selección explícita, snapshot, transacción y auditoría. Un miembro obsoleto rechaza todo el lote seleccionado.
8. Rollback de precios: nuevo preview del snapshot, siempre aprobación humana y sin sobrescribir cambios posteriores.

## AI-GRUPO
Cola privada, heartbeat de 180 segundos, paquetes acotados con datos de archivo no confiables. Structured Outputs sin herramientas, store:false, propuesta sólo. Runtime WSL graphex-cost-engine-v1; systemd worker/web usan drop-ins propios 90-graphex-cost-engine.conf. OPENAI_API_KEY no configurada: asistencia deshabilitada hasta aprovisionamiento seguro. No usar OAuth de Codex como clave de API. Configuración del modelo: AI_COST_ASSIST_MODEL, fallback AI_LEAD_RESEARCH_MODEL, fallback gpt-5; validar modelo/permisos al provisionar.

## Deploy y rollback
Producción: PHP74, sitio /home/graphexpress/public_html, plugin ge-webtoprint-calculator, recurso Ferozo del Registry. Feature flags ge_cost_engine_enabled y ge_cost_product_writes_enabled. Reconciliar hashes actuales antes de publicar; no sobrescribir módulos comerciales compartidos.
Backup completo verificado: /root/backups/graph-wordpress/20261002T162108Z.
Artefactos privados de despliegue: /root/ai-grupo/cost-engine-v1, final-manifest.json, rollback-files y patch-rollback-*.
Rollback autorizado: rollback-production.py valida hashes, deshabilita flags, restaura sólo archivos propios previos y bridge; mantiene columna nullable, datos y auditoría. Si hay drift, reconciliar manualmente. Nunca restaurar toda la DB sobre cambios ajenos.
Rollback AI autorizado: rollback-runtime.py en WSL administrador valida/elimina sólo los dos drop-ins propios y vuelve al release previo graphex-analyzer-ecff65dfa625. Backup /home/leo/ai-grupo-runtime-backups/cost-engine-v1. Scripts preparados y revisados; rollback del despliegue no ejecutado en producción.

## Verificación y pendientes
53 checks PHP en DB graph_cost_engine_v1 aislada, 27 tests Actions, TypeScript y build Next; 19 checks visuales móviles/tablet/desktop; retorno real de popup a ítem de presupuesto. Parser XLSX/PDF y previews de lectura en servidor. QA modifica sólo productos draft etiquetados QA, nunca precios productivos.
Producción: 169 productos, 71 costos Woo proyectados, 0 listas normalizadas y 0 recetas iniciales. Precios/configuración fingerprint 47dc41443aee5f71281f9960cd3cfbd61686bdcc9d0fdea4376652fcce11b058 intacto al habilitar escrituras.
Pendientes humanos: revisar semántica de los 71 costos, cargar listas actuales, crear recetas/reglas reales y vincular productos. Proveedor de inferencia pendiente; no se certificó una llamada real de modelo. Edición de estructura de variaciones existentes y pricing digital complejo requieren extensión específica; lectura y precios de variaciones soportados. OCR automático no provisionado: PDF sin texto se deriva a asistencia/manual.

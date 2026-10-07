# Presupuestos por cards — candidato 07/10/2026

Estado: candidate. Base: master remoto e2089d8e654a535c4eb95c203884309ab745f33d.

El formulario separa contacto, datos fiscales manuales, emisor, receptor/entrega, productos, IVA, descuentos, condiciones y archivos. Se usan tokens Graphex existentes, texto de 16px, cards numeradas y navegación libre con indicador de sección. Los controles de auditoría de revisiones siguen disponibles.

## Contratos

- Nombre/email permiten guardar un borrador sin productos, archivos, emisor o receptor completo. `draft_incomplete` bloquea envío y operaciones fiscales; no produce un total cobrable. Los ítems a medio completar se conservan en `draft_lines` sin inventar precios.
- La ficha se resuelve por email; buscar/seleccionar no guarda. Guardar el borrador registra/vincula la ficha; el único camino de envío conserva las notificaciones existentes.
- CUIT, razón social, condición y domicilio se cargan manualmente o se reutilizan del perfil autorizado. Los datos del receptor pertenecen al cliente; la dirección de entrega se valida por separado.
- `revise()` conserva snapshots y versiona propuestas enviadas. `expected_hash` complementa `expected_version` para detectar cambios dentro de una misma versión de borrador. Se mantienen permisos, bloqueos, motivos y auditoría de cambios verificados.
- Descuentos siguen las reglas monetarias existentes. IVA mantiene modos final/added y resolución fiscal; no se convierte en descuento ni se agrega sobre un precio final.
- `deposit_enabled` desactiva seña en UI, PDF y checkout. Los snapshots anteriores sin ese campo mantienen su comportamiento. La seña activada valida porcentaje y muestra importe calculado.
- Las notas internas quedan privadas. Los archivos siguen el almacenamiento, Analyzer, referencias y bloqueo de sesión existentes.
- Guardado asíncrono conserva valores, referencias y archivos ante errores. Resumen e inline enlazan/focalizan el campo. Reintento utiliza el guardado idempotente existente; un envío fallido después de guardar conserva ID/versión/hash y renueva sesión para corregir sin duplicar.
- Se corrige una colisión local de `$label`: los controles de variantes no sustituyen el nombre del producto por «ID de la miniatura privada».

## Verificación realizada

- PHP 8.4 Windows: sintaxis; regresiones commercial-quote-snapshot, billing-flows y quote-balance.
- 26 checks del modelo real con adaptador WP en memoria: borrador mínimo, permisos, receptor/dirección ajenos, no envío/cobro, revisión/historia, snapshots previos, conflicto de hash, ítems parciales, descuentos y seña.
- 5 checks del generador PDF real: bytes válidos, privacidad de nota interna, nota comercial, una página A4 y seña off/on. Render visual Poppler sin solapamientos; advertencia local de fuente Symbol, sin defecto visible.
- Navegador integrado sobre PHP real y dependencias sintéticas: foco/resumen/inline, mínimo contacto alcanza endpoint, conservación tras 422, selección cliente y reutilización fiscal, seña off/on, bloqueo previo de envío incompleto. Capturas desktop/móvil; 390px sin overflow, texto 16px y numeración blanca con contraste.
- Pantalla productiva inspeccionada por lectura: sigue con formulario anterior y reproduce la colisión del nombre del producto. No se envió ningún formulario productivo.

## Pendientes obligatorios de release

1. QA WordPress/WooCommerce integral aislada, PHP 7.4, con correo/HTTP externos interceptados: creación cliente real sintética, persistencia/uploads/Analyzer/reemplazo, variantes, selección, fiscal C/A/final/added, revisión de receptor y permisos, checkout desactivado/activado, errores/dobleclick/reintento.
2. SSH: servidor acepta publickey existente, pero el sandbox no puede consultar el canal del agente Windows para firmar (Permission denied). No hay ruta de habilitación soportada confirmada; no se alteraron claves, ACL ni WSL.
3. Revalidar master y hashes productivos; backup privado verificado, rollback selectivo y guardas de concurrencia.
4. Tests integrados → merge canonical → deploy desde canonical → smoke → relectura de hashes.

merged=false, deployed=false, verified=false (integral/productivo), canonical_synced=false. Este candidato no es un release terminado.


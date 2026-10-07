# Presupuestos — asistente secuencial 07/10/2026

Base canónica y producción comprobada por lectura: 1d4c0caa46b7d06359e8c944121a58080cf62af6. Esa versión anterior muestra todas las cards y navegación numerada; el nuevo candidato corrige esa presentación.

Una sola card visible a la vez. Orden: cliente → fiscal opcional → emisor → receptor/entrega opcional → productos → descuentos e IVA → validez/seña/notas → archivos opcionales → revisión y envío.

- Indicador discreto «Paso X de 9», progreso accesible y título. Sin links numerados/subrayados ni badges. Fuente Jost heredada y botones existentes de Gestión.
- Continuar valida solamente el paso actual. Atrás conserva controles, valores, UUIDs y widgets sin reconstruirlos. Omitir avanza los pasos opcionales sin borrar valores.
- Guardar borrador está disponible en todos los pasos tras completar nombre/email. Conserva guardado asíncrono, errores, idempotencia y hash optimista.
- Enviar aparece sólo en la revisión final: cliente, datos fiscales, emisor, receptor, entrega, productos, selección, totales del calculador existente, descuentos, IVA, condiciones, notas públicas/privadas y archivos.
- Editar lleva al paso correspondiente. La confirmación de revisión está en el último paso y se invalida al modificar datos.
- Errores de envío abren el paso/campo exacto y conservan valores. Foco en título al navegar, resumen al fallar y campo al seguir enlace.
- Enter en texto/número no envía ni guarda implícitamente; selects, archivos, textarea y botones conservan interacción de teclado. Doble clic no saltea pasos ni activa envío al entrar en revisión. Guardado conserva bloqueo de concurrencia.

Contratos conservados: borrador mínimo sin fiscal/emisor/productos/archivos; pendiente sin total cobrable y sin envío/cobro/conversión. Snapshots, permisos, auditoría, revisiones, IVA, descuentos, seña, PDF/checkout, almacenamiento y Analyzer conservan caminos existentes.

QA del candidato:
- PHP 8.4 Windows lint del renderer; JavaScript syntax; git diff check.
- Regresiones: commercial-quote-snapshot, 26 quote-steps-draft, 5 quote-steps-pdf, billing-flows y quote-balance.
- Navegador con formulario PHP real, estilos staff/gestion existentes y dependencias/HTTP sintéticos: card única, pasos, opcionales, guardado mínimo alcanza endpoint, error422 abre paso5 conserva valores, errores/foco, Enter, doble clic, productos, descuento>100/motivo, seña inválida, notas, revisión, links de error entre pasos.
- Desktop/móvil390px: fuente cuerpo/input/botón Jost heredada, sin links de progreso, card única y sin overflow tras corregir footer.
- Adjuntar archivo sintético fue rechazado por navegador; no repetido ni eludido. Controles y referencias permanecen intactos, pero no se afirma QA nueva de upload/Analyzer.
- Los54 checks WordPress/PHP7.4 del release anterior son históricos, no QA integral nueva de este candidato.

Release pendiente: sesión actual restringe red para Git y resuelve SSH a .sbx-denybin; sin bypass, cambios de claves, ACL o WSL. Completar QA exacta PHP7.4/WordPress aislado, backup/preimages/rollback, merge canonical, deploy de tres archivos, smoke/relectura hashes.

candidate=true; merged=false; deployed=false; canonical_synced=false para este asistente. Release previo permanece en producción.

# Canva para tarjetas: candidato de producción

Estado 2026-10-05: código preparado y probado con fixtures; no instalado ni habilitado para clientes. `ge_cards_canva_pilot_enabled_v1` tiene valor inicial `no` y exige una lista de usuarios piloto y credenciales privadas fuera del document root. No contiene secretos.

Cliente: OAuth PKCE con `design:meta:read` y `design:content:read`, propiedad de usuario y equipo, regreso firmado Ed25519, exportación PDF, expiración y límites. Adaptador: organización, cuenta, sesión, nonce, cifrado secretbox, trabajos opacos y descarga privada sin entregar la URL de exportación al navegador. Desconexión/reconexión invalida trabajos de la conexión anterior.

El catálogo abre Canva en otra pestaña. El cliente debe guardar la plantilla en su cuenta, seleccionarla entre sus propios diseños y abrir el editor desde Graphex para obtener el retorno firmado. No se promete regreso automático desde un enlace arbitrario del catálogo.

Producto piloto: 83, tamaño 5×9. La exportación exige el número de páginas correspondiente a la impresión elegida. El PDF vuelve al input de archivos del configurador y utiliza la carga privada existente. La vista previa no acredita dimensiones, resolución ni aprobación de producción.

Verificaciones: 40 pruebas del protocolo y 26 del adaptador con respuestas ficticias, sin red, base WordPress ni credenciales reales; revisión de sintaxis JavaScript. Repetir después de cambios. La prueba de navegador del candidato todavía está pendiente.

Pendientes antes de activar: preflight del archivo privado exacto y bloqueo coherente del carrito; prueba de permisos del Organization Runtime; limpieza acotada del registro antirrepetición; transferencia segura de credenciales; configurar y confirmar destinos HTTPS en Canva; prueba real de OAuth, regreso y exportación; revisión pública de Canva. No habilitar durante estos pendientes. Rollback: mantener la opción en `no`, preservar conexiones y archivos privados, retirar únicamente el nuevo módulo si fuese necesario.

Destinos propuestos: `https://graphex.ar/tarjetas/canva/callback/` y `https://graphex.ar/tarjetas/canva/return/`. Cambiar la aplicación de Canva requiere la confirmación correspondiente en el momento de modificar sus destinos de acceso.

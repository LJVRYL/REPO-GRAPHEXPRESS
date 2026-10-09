# Canva para tarjetas: candidato de producción

Estado 2026-10-05: código preparado y probado con fixtures; no instalado ni habilitado para clientes. `ge_cards_canva_pilot_enabled_v1` tiene valor inicial `no` y exige una lista de usuarios piloto y credenciales privadas fuera del document root. No contiene secretos.

Cliente: OAuth PKCE con `design:meta:read` y `design:content:read`, propiedad de usuario y equipo, regreso firmado Ed25519, exportación PDF, expiración y límites. Adaptador: organización, cuenta, sesión, nonce, cifrado secretbox, trabajos opacos y descarga privada sin entregar la URL de exportación al navegador. Desconexión/reconexión invalida trabajos de la conexión anterior.

El catálogo abre Canva en otra pestaña. El cliente debe guardar la plantilla en su cuenta, seleccionarla entre sus propios diseños y abrir el editor desde Graphex para obtener el retorno firmado. No se promete regreso automático desde un enlace arbitrario del catálogo.

Producto piloto: 83, tamaño 5×9. La exportación exige el número de páginas correspondiente a la impresión elegida. El PDF vuelve al input de archivos del configurador y utiliza la carga privada existente. La vista previa no acredita dimensiones, resolución ni aprobación de producción.

Verificaciones: 40 pruebas del protocolo y 26 del adaptador con respuestas ficticias, sin red, base WordPress ni credenciales reales; revisión de sintaxis JavaScript. Repetir después de cambios. La prueba de navegador del candidato todavía está pendiente.

Pendientes antes de activar: preflight del archivo privado exacto y bloqueo coherente del carrito; prueba de permisos del Organization Runtime; limpieza acotada del registro antirrepetición; transferencia segura de credenciales; configurar y confirmar destinos HTTPS en Canva; prueba real de OAuth, regreso y exportación; revisión pública de Canva. No habilitar durante estos pendientes. Rollback: mantener la opción en `no`, preservar conexiones y archivos privados, retirar únicamente el nuevo módulo si fuese necesario.

Destinos propuestos: `https://graphex.ar/tarjetas/canva/callback/` y `https://graphex.ar/tarjetas/canva/return/`. Cambiar la aplicación de Canva requiere la confirmación correspondiente en el momento de modificar sus destinos de acceso.

## Auditoría y avance 2026-10-09

Rama aislada `canva-real-return-20261009`, basada en origin/master `91d8243`. Ningún cambio en Volantes, renderer compartido, presupuestos, órdenes, correo o datos de clientes. Servidor verificado: candidato no instalado y archivo seguro de credenciales de producción ausente.

Aplicación existente verificada en el portal Canva: AAHOGKFtIfg, versión1, Public/Borrador, propietario cuenta Graph Imprenta. Dos permisos de lectura; design:content:write desactivado. OAuth probado anteriormente. Callback vigente http://127.0.0.1:18865/canva/callback y retorno http://127.0.0.1:18865/canva/return. Estado muestra 20 requisitos pendientes de revisión. No se cambiaron permisos, URLs, términos, colaboradores ni secretos.

Oficial: retorno firmado soportado por https://www.canva.dev/docs/apps/rest-apis/return-navigation-guide/ . Crear diseño custom requiere design:content:write: https://www.canva.dev/docs/apps/rest-apis/reference/designs/create-design/ . Copia por API desde diseño o brand template sigue preview y no admite publicación general. Exportación PDF admite páginas explícitas y calidad pro; elementos premium pueden rechazarla según compra/plan: https://www.canva.dev/docs/apps/rest-apis/reference/exports/create-design-export-job/ . El contrato PDF revisado no ofrece controles CMYK, sangrado ni DPI: no prometerlos. Ningún fallback silencioso a calidad menor.

Cambio candidato: OAuth pendiente cifrado separado por state para varias pestañas; cancelación consume únicamente su state; guardia atómica antirrepetición. Pruebas adaptador ampliadas de26 a32, ejecutadas en fixtures sin red o base WordPress.

Perfil técnico nuevo, todavía NO conectado al endpoint/carro: hechos del analizador existente, páginas exactas, tamaños95×65mm en todas las páginas (tolerancia0.2mm), fuentes no incrustadas, imágenes debajo300ppi y cifrado. Nunca convierte, gira o modifica el original; área segura, geometría de corte y conversión de color requieren revisión, producción_approved siemprefalse. Once fixtures nuevos pasan. Falta integrar propietario/claim/SHA al endpoint, pruebas reales del analizador, formas de crear diseño a medida, idempotencia export y QA end-to-end; esto no constituye integración operativa.
# Canva para tarjetas: candidato de producción

Estado vigente 2026-10-09: candidato instalado con respaldo verificado; deshabilitado. Repositorio principal integrado. Los apartados anteriores de avance son históricos; rige el cierre actual al final. `ge_cards_canva_pilot_enabled_v1` tiene valor inicial `no` y exige una lista de usuarios piloto y credenciales privadas fuera del document root. No contiene secretos.

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
## Candidato importación y carrito 2026-10-09

Exportación conserva selección; descarga guarda SHA256; preflight valida claim privado, cuenta, configuración y bytes exactos. Usa cola/CLI FileAnalyzer existente; no libera el endpoint global para archivos ajenos. Guardia antes del carrito rechaza archivo alterado, selección cambiada, análisis pendiente o bloqueos. El motor de precio/IVA y asociación al ítem no se reemplaza. La revisión de área segura/corte/color y aprobación exacta permanecen separadas. Reintentos con request_id propio recuperan el mismo trabajo, sin otra llamada Canva. Input de archivo bloqueado durante la importación.

44 fixtures adaptador PASS;9 acciones verificadas con guard/handlers reales de cliente, sin alterar registros. Dos PDF sintéticos leídos con CLI real y PATH del worker: plantilla95×65 bloqueada por fuentes sin incrustar; PDFCanva88.9×50.8 bloqueado por dimensiones. No confundir esas pruebas negativas con un recorrido Canva real aprobado. Falta archivo válido y OAuth/retorno/importación/carrito reales.

Credencial existente instalada por DPAPI→SSHstdin fuera del documentroot, luego de autorización humana directa. Referencia simbólica:graphex-canva-production-client; ningún valor en documentación/repositorio/chat. Primer intento bloqueado por auto-review; no hubo transferencia hasta aprobación. El envío del candidato aGitHub también se detuvo para verificar el destino contra fuentes estructuradas y origin.

### Requisitos públicos observados (20)

Todos obligatorios para revisión pública según pantalla Canva; no hay opcionales en esta lista. Para piloto autorizado con propietario: dos destinosHTTPS y OAuth real. Ficha/medios/documentación/legal se completan antes de disponibilidad general.

| Requisito | Uso | Estado observado |
|---|---|---|
| Return URL no localhost | Piloto y público | localhost, pendiente |
| Redirect URL default no localhost | Piloto y público | localhost, pendiente |
| Short description | Público | Pendiente |
| Description | Público | Pendiente |
| App icon | Público | Pendiente |
| Featured image | Público | Pendiente |
| Outside Canva destination | Público | Pendiente |
| Company/Website URL | Público | Pendiente |
| Terms and conditions URL | Público | Pendiente |
| Privacy policy URL | Público | Pendiente |
| Support URL | Público | Pendiente |
| About app/platform | Público | Pendiente |
| Testing steps | Público | Pendiente |
| Walkthrough video | Público | Pendiente |
| Login de prueba | Público | Pendiente, no divulgar cuentas reales |
| Privacy/data management | Público | Pendiente |
| Security requirements | Público | Pendiente |
| Security practices | Público | Pendiente |
| Developer details | Público | Pendiente |
| Confirmación cumplimiento legal | Público, acción humana | Pendiente; no aceptar automáticamente |

Primer piloto: diseño propio existente→editor con retorno firmado→PDF, sólo dos permisos de lectura ya aprobados. No usa autofill/brand templates ni exige Enterprise; elementos premium pueden impedir export según compra/plan. Crear diseño custom automáticamente requiere design:content:write y se prepara después de cerrar este piloto; no se amplió el permiso. Copia API preview excluida de publicación general. Destinos HTTPS propuestos todavía requieren confirmación en el momento de modificarlos.
## Estado de instalación 2026-10-09

Cambios integrados en master; módulo instalado y contenido comparado con candidato. Respaldo /root/ge-backups/canva-cards-pilot-20261009 con opciones previas, paquete, SHA256 verificado y rollback. Opción permanece no. Lista piloto estricta, incluidos administradores: ninguna cuenta fuera de la lista puede usarlo. Producto real verificado:83 Tarjetas Express, /product/tarjetas-express/. Producto78 es Tarjetas personales y queda fuera de este primer piloto.

Pruebas actuales:40 protocolo,44 adaptador,11 perfil técnico;9 guardias/controladores en WordPress real después de instalación. JavaScript válido; reintento conserva identidad y espera de carga privada limitada a120segundos. Dos pruebas negativas reales de PDF ya documentadas. No hay prueba positiva end-to-end ni habilitación pública.

Pendiente inmediato: confirmación humana en navegador para ambos destinos HTTPS, sesión normal de la cuenta propia en Chrome Graph, y recorrido real OAuth/editor/retorno firmado/export/importación privada/vista previa/preflight/carrito. No se hicieron compras, correos ni pedidos. Mantener apagado hasta completar la configuración y habilitar sólo cuenta propia para QA. Limpieza del registro antirrepetición y bloqueo concurrente de exportación pendientes antes de disponibilidad general.
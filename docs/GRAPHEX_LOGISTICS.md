# Graphex: coordinación logística

Estado: candidato de release 2026-10-10. El origen es privado y sólo se muestra a personal autorizado en Configuración → Logística. El retiro se coordina con cita; el portal no publica ubicación. «Flex» significa mensajería local propia para Graphex.

## Operación

1. El cliente solicita modalidad y completa receptor, teléfono, calle, localidad, provincia argentina y CP/CPA. El ingreso manual siempre funciona.
2. El personal revisa el destino, cobertura y bultos embalados. Registra cantidad, peso real y tres medidas por tipo de bulto. Una estimación no habilita despacho.
3. Se registra una cotización confirmada: costo final en ARS, servicio, domicilio o agencia concreta, plazo desde despacho, referencia y vencimiento. El selector de empresa por sí solo no ofrece cobertura ni precio.
4. El cliente acepta la opción vigente. El transporte se coordina y paga por separado: no cambia el total de un presupuesto de productos ya aceptado ni genera cobros automáticos.
5. Antes de entregar al transportista, se exige producción lista, política de pago del pedido cumplida, destino revisado, peso y dimensiones medidos, cotización vigente, condición de pago del transporte verificada y guía real para transporte nacional. Se confirma entrega física. Despachado y entregado son estados distintos.
6. Las incidencias internas quedan visibles sólo para personal. El seguimiento admite HTTPS en dominios oficiales del transportista y permanece dentro del portal autenticado.

Cambiar dirección, bultos, productos, versión u origen invalida cotizaciones. Al convertir presupuesto en pedido se hereda coordinación y bultos; se vuelve a confirmar el transporte porque cambia el contexto del pedido. Las acciones usan permisos actuales, nonce, revisión optimista, exclusión por registro e idempotencia por solicitud.

## Tarifas y servicios

Retiro, mensajería local, Uber Envíos, Vía Cargo, Correo Argentino y Andreani se coordinan manualmente. No existen tarifas inventadas, integraciones nacionales contratadas ni creación automática de guías. Las tarifas propias requieren CP y provincia exactos, costo final, plazo, límites de peso/bultos/lado y vencimiento respaldados. Inicialmente no hay tarifas activas.

Peso volumétrico: volumen externo en cm³ dividido por aforo verificado del servicio. Se compara con peso real por bulto y aplica mínimo/redondeo contractual, luego suma. Sin divisor verificado el peso tarifable queda pendiente. Correo se filtra conservadoramente a 25 kg, suma de lados 250 cm y lado 150 cm: su página oficial presenta 25 y 30 kg y se debe confirmar servicio concreto. Vía requiere consulta para bultos mayores de 100 kg. Estas guardas no sustituyen confirmación de cobertura, seguro, límites ni tarifa.

## Fichas físicas

El contrato `docs/catalog-expansion-20261010/logistics-contract.json` todavía no contiene mediciones. El consumidor utiliza snapshots exactos `_ge_logistics_physical_v1` en ítems o líneas, nunca infiere variante a partir del nombre del producto. Campos y evidencia se validan en `GE_Logistics::product_contract`; integración extensible mediante `ge_logistics_product_specs`. Deben incluir mediciones, fecha, responsable y perfil de embalaje con tara y dimensiones externas. Peso estimado de papel = área neta × gramaje × cantidad, más accesorios y tara; sin desperdicio de producción. Se distribuye el remanente y redondea peso hacia arriba al gramo. Falta de evidencia mantiene «pendiente de medición». Aún debe completarse la toma de medidas y la persistencia de snapshots por el catálogo.

## Maps opcional

Desactivado por defecto. Requiere clave existente restringida a navegador en `GE_LOGISTICS_MAPS_BROWSER_KEY`, APIs y facturación ya autorizadas, y activación explícita en configuración. Places nuevo sólo se carga al pedir una sugerencia, restringido a Argentina, consultando componentes e ID. No geocodifica el origen privado. La sugerencia no confirma entregabilidad; la falla conserva ingreso manual. No se abrió cuenta, aceptaron términos ni activaron consumos pagos.

## Persistencia y privacidad

Configuración `ge_logistics_config_v1`; coordinación `_ge_logistics_v1`. Se conserva el snapshot original de entrega al actualizarlo. Documentos fiscales y versiones comerciales aceptadas se preservan. La corrección previa de privacidad en configuración es independiente del release de código; un rollback de código debe conservarla. No se afirma eliminación de documentos históricos enviados ni de fichas ajenas al sitio.

## Evidencia oficial consultada el 2026-10-10

| Servicio | Fuente | Acceso real y pendiente |
|---|---|---|
| Vía Cargo | https://formularios.viacargo.com.ar/ y https://viacargo.com.ar/ | Cotizador oficial manual; confirmar servicio, cobertura, valor declarado y pago. No API verificada. |
| Correo | https://www.correoargentino.com.ar/servicios/paqueteria/paqueteria-ecommerce-1 y https://www.correoargentino.com.ar/MiCorreo/public/faqs | API requiere registro y gestión de credenciales. No credenciales verificadas. PDF tarifario encontrado fechado febrero de 2023, excluido como precio actual. |
| Andreani | https://developers.andreani.com/ | Portal oficial; acceso directo restringido. Contrato y credenciales pendientes. |
| Uber Envíos | https://help.uber.com/es/riders/article/c%C3%B3mo-funcionan-los-env%C3%ADos-uber-flash-?nodeId=e11b7b9c-acf4-4099-8408-1b9819dc0f8e | Gestión manual en aplicación. Ningún pedido enviado. |
| Google | https://developers.google.com/maps/documentation/javascript/place-autocomplete-new y https://developers.google.com/maps/documentation/places/web-service/usage-and-billing | Requiere acceso y facturación autorizados; permanece apagado. |

## Verificación y publicación

85 controles de cálculo/validación y 40 de WordPress en PHP 7.4 y 8.1. Siete suites comerciales, 225 controles por runtime. Última suite 8.1 requirió 512 MB / 120 s por acumulación de datos sintéticos; pasó 33 controles. Ninguna prueba altera datos de producción. Vista real del renderer con datos ficticios en escritorio y móvil, sin contratación y sin formulario ejecutable. Release sólo desde commit canónico, manifiesto de 14 archivos, backup privado verificado, guardas por hash y rollback selectivo. El Result Pack externo registra estado y evidencia final.

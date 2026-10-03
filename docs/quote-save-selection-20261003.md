# Guardar selección y avisar a la gráfica

El botón al pie usa la elección actual del formulario, registra la aceptación comercial del cliente, conserva el snapshot original y descarga el PDF existente. El portal muestra confirmación y enlace de descarga de respaldo. En Gestión guarda una selección de trabajo sin atribuir aceptación al cliente. Vista previa deshabilita escrituras. Versiones vencidas, configuraciones inválidas y cambios sobre una selección ya aceptada se rechazan; reintentos iguales son idempotentes.

La campana privada avisa aceptación con archivos finales listos, sin archivos, con archivos finales pendientes de aprobación, preliminares o faltantes por ítem. Cuenta únicamente artes de los ítems/modelos elegidos, excluye miniaturas y archivos desvinculados. Listo exige final, checksum real vigente y aprobación del archivo/versión/ítem exactos; si falta aprobación del cliente, sigue pendiente. Avisos posteriores se generan cuando se cargan, asignan o aprueban archivos de presupuestos aceptados. Ningún aviso aprueba arte, cobra o libera producción.

QA aislado: 36 combinaciones/278 verificaciones, 32 pruebas específicas de guardado/avisos, 5 de vista previa y 67 regresiones fiscales/comerciales. Navegador: guardar sin previsualizar conserva modelo 5/laminado brillo/350 g mate, total 375000 y estado borrador; campana contiene aviso; descarga automática y enlace presentes. Sin mutación de presupuestos reales.

Despliegue selectivo de siete archivos desde master, manifest completo, tar de respaldo verificado y ensayo de rollback en copia privada. Presupuesto 1002 permanece borrador y su metadata debe conservarse; presupuesto 986 protegido.

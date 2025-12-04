# Checklist · Web-to-Print Graph Express

## Bloque 1 – Setup de proyecto y repo

- [x] Definir objetivo general del proyecto (web-to-print tipo Onprintshop / Graficadruck).
- [x] Crear estructura de repo local en `F:\GRAPH EXPRESS\REPO-GRAPHEXPRESS`.
- [x] Crear `docs/runbook_webtoprint.md` con el runbook inicial.
- [x] Inicializar Git y hacer primer commit.
- [x] Crear child theme `printing-services-child` (style.css + functions.php).
- [x] Crear plugin base `ge-webtoprint-calculator`.
- [x] Agregar `.gitignore` para WordPress.
- [x] Crear `README.md`.
- [ ] Vincular repo con GitHub y hacer primer `git push`.

## Bloque 2 – WordPress + WooCommerce (entorno local)

- [ ] Preparar entorno local (Ubuntu/WSL) con:
  - [ ] Apache/Nginx + PHP (7.4+).
  - [ ] MySQL/MariaDB.
- [ ] Descargar e instalar WordPress.
- [ ] Instalar y activar tema “Printing Services”.
- [ ] Copiar el child theme `printing-services-child` desde el repo.
- [ ] Instalar y activar WooCommerce.
- [ ] Instalar y activar plugin `GE Web-to-Print Calculator`.

## Bloque 3 – Estructura de navegación

- [ ] Crear páginas:
  - [ ] Inicio
  - [ ] Tienda
  - [ ] Guía de diseño
  - [ ] Quiénes somos
  - [ ] Contacto
  - [ ] Mi cuenta (WooCommerce)
- [ ] Definir menú principal al estilo graficadruck.com.ar, pero moderno.
- [ ] Crear categorías de producto:
  - [ ] Impresión láser
  - [ ] Impresión offset
  - [ ] Gran formato / cama plana UV
  - [ ] Tarjetas PVC
  - [ ] Gráfica editorial

## Bloque 4 – Productos y tienda

- [ ] Crear productos de ejemplo por categoría (solo para pruebas).
- [ ] Revisar plantilla de producto del tema y anotar qué queremos cambiar.
- [ ] Definir estructura futura de tablas de precios (por pasos de cantidad).

## Bloque 5 – Estados de pedido (flujo de trabajo interno)

Estados deseados:
- Ingresado
- Aprobado
- En producción
- Listo para entrega

Tareas:

- [ ] Analizar cómo maneja WooCommerce los estados de pedido.
- [ ] Implementar estados personalizados en el plugin `ge-webtoprint-calculator`.
- [ ] Ajustar el estado inicial del pedido a **Ingresado**.
- [ ] Probar cambio manual de estados en el admin.
- [ ] (Futuro) Agregar vistas/columnas extra en la lista de pedidos.

## Bloque 6 – Calculadora de costos (futuro)

- [ ] Definir fórmulas por tipo de impresión:
  - [ ] Láser full color.
  - [ ] Offset papel/cartulina.
  - [ ] Gran formato (cama plana UV + eco solvente).
  - [ ] Tarjetas PVC.
  - [ ] Gráfica editorial.
- [ ] Diseñar estructura del “motor de precios”:
  - [ ] Clases / funciones internas.
  - [ ] Parametrización (gramaje, tirada, tamaño, etc.).
- [ ] Diseñar UI básica de calculadora (integrada a productos WooCommerce o como formulario aparte).
- [ ] Conectar calculadora con precios visibles al cliente.

## Bloque 7 – Preparación para producción

- [ ] Repasar seguridad básica (usuarios admin, contraseñas, updates).
- [ ] Definir dominio / subdominio para staging.
- [ ] Plan de deploy al VPS (sin romper otros sitios).
- [ ] Pruebas de flujo completo:
  - [ ] Navegación.
  - [ ] Alta de pedido.
  - [ ] Cambio de estado.
  - [ ] Emails básicos de WooCommerce.

### 2025-12-04 – WordPress local + PrintSpace + Header GraphExpress

**Hecho hoy**

- [x] Descomprimir theme PrintSpace comprado y copiarlo a:
  - [x] `wp-content/themes/printspace` (repo).
  - [x] `/var/www/html/wp-content/themes/printspace` (WordPress local).
- [x] Activar PrintSpace como theme en el entorno local.
- [x] Instalar plugins incluidos con el theme (haru-printspace, revslider, etc.).
- [x] Instalar WooCommerce manualmente y ajustar permisos de `/var/www/html` para que WordPress pueda escribir sin FTP.
- [x] Añadir `define('FS_METHOD', 'direct');` a `wp-config.php` para usar el método de escritura `direct`.
- [x] Instalar Elementor y otros plugins necesarios desde el panel sin pedir FTP.
- [x] Crear child theme `graphexpress-child` en el repo (style.css, functions.php, front-page.php).
- [x] Commitear y pushear la rama `feature/graphexpress-child-home` con el child theme.
- [x] Crear header `Main Header GraphExpress` con Header Builder (Haru) y comprobar que se ve en su propia URL de preview.

**Pendiente / siguiente sesión**

- [ ] Asignar correctamente `Main Header GraphExpress` como header global:
  - [ ] Verificar opciones de `Header Builder Type` y selección de header en el Customizer.
  - [ ] Verificar metabox de asignación en el CPT `haru_header` (Display On: Entire Site / Front Page).
  - [ ] Revisar metabox de Header en la página de inicio para que no sobrescriba el global.
- [ ] Investigar por qué el widget de logo no persiste la imagen (SVG/PNG) en el header:
  - [ ] Probar con imagen PNG simple.
  - [ ] Revisar si el widget usa “Site Logo” o imagen fija.
  - [ ] Documentar la solución en el runbook.
- [ ] Integrar y probar el child theme `graphexpress-child` en el WP local:
  - [ ] Activar el child una vez estable el header.
  - [ ] Comprobar que `front-page.php` se renderiza correctamente como home.
- [ ] Decidir el uso de plugins “pro” (drag-and-drop-file-uploads-wc-pro, wc-designer-pro):
  - [ ] Mantenerlos desactivados si no son críticos en esta fase.
  - [ ] Documentar requisitos (simplexml, WooCommerce inicializado, etc.) si se van a usar.


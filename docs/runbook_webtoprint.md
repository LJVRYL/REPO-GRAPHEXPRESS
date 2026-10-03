# Runbook Web-to-Print Graph Express (v1)

## 1. Objetivo del proyecto

Crear una plataforma **web-to-print** para Graph Express usando **WordPress + WooCommerce + tema gratuito** y código propio (theme child + plugin), con estas funciones principales:

- Catálogo online de productos de impresión.
- Tienda con WooCommerce (carrito, checkout, “Mi cuenta”).
- Estados de pedido adaptados a nuestro flujo:
  - Ingresado
  - Aprobado
  - En producción
  - Listo para entrega
- Base de datos de clientes y pedidos (WooCommerce + extensiones propias).
- En una segunda etapa: **calculadora de costos** a medida (láser, offset, gran formato, PVC y editorial).

El sitio debe estar inspirado en la estructura de graficadruck.com.ar, pero con un diseño más moderno y adaptado a Graph Express.

---

## 2. Stack técnico

- **CMS:** WordPress (versión estable actual).
- **E-commerce:** WooCommerce.
- **Tema base:** tema gratuito “Printing Services” (WordPress.org).
- **Tema efectivo:** theme hijo `printing-services-child` con nuestras customizaciones.
- **Plugin propio:** `ge-webtoprint-calculator` (nombre tentativo) para:
  - Estados personalizados de pedido.
  - Lógica futura de calculadora de costos.
  - Campos extra en productos / pedidos.

- **Control de versiones:** Git + GitHub.
- **Entornos:**
  - Local (Ubuntu / WSL) para desarrollo y pruebas.
  - VPS (Ferozo / Dattaweb) para staging/prod (como Tickex).

---

## 3. Flujo de trabajo (metodología)

Siempre trabajamos así:

1. **Planificación en ChatGPT (este proyecto):**
   - Definimos qué feature o cambio queremos hacer.
   - El asistente genera:
     - Descripción funcional.
     - Estructura de archivos.
     - **Prompts para Codex**.

2. **Edición de código en Codex:**
   - Abrimos el repo `graph-webtoprint` (nombre tentativo) en Codex.
   - Pegamos el prompt preparado en ChatGPT.
   - Codex modifica:
     - Theme child (`/wp-content/themes/printing-services-child`).
     - Plugin propio (`/wp-content/plugins/ge-webtoprint-calculator`).
   - Confirmamos los cambios y hacemos commits **chicos y frecuentes**.

3. **Pruebas en entorno local (Ubuntu / WSL):**
   - `git pull` para traer los cambios al entorno local.
   - Levantamos WordPress local y probamos:
     - Front (home, productos, carrito, etc.).
     - Backoffice (pedidos, estados, etc.).
   - Si algo falla, se corrige en Codex y se repite el ciclo.

4. **Push a GitHub:**
   - Una vez verificado en local:
     - `git commit` con mensaje claro.
     - `git push` a la rama principal (o rama de feature si hace falta).

5. **Deploy al VPS:**
   - Siempre **BACKUP primero**:
     - Archivos del sitio.
     - Base de datos.
   - Subimos sólo lo necesario:
     - Theme child.
     - Plugin propio.
     - Eventuales ajustes de configuración.
   - Probamos en el VPS (idealmente en subdominio o staging antes de producción).

Regla de oro: **un cambio a la vez**, probar, luego seguir.

---

## 4. Alcance funcional v1

Versión inicial (MVP) del sitio debe lograr:

1. Estructura de páginas:
   - Inicio
   - Tienda
   - Categorías de productos:
     - Impresión Láser
     - Impresión Offset
     - Gran Formato / Cama Plana UV
     - Tarjetas PVC
     - Gráfica Editorial
   - Guía de diseño
   - Quiénes somos
   - Contacto
   - Mi cuenta (WooCommerce)

2. Tienda WooCommerce:
   - Productos ejemplo para cada categoría.
   - Flujo de pedido básico:
     - Cliente agrega producto al carrito.
     - Realiza el checkout.
     - Pedido se crea con estado inicial **Ingresado** (cuando se implemente la lógica de estados propios).

3. Diseño:
   - Basado en el tema “Printing Services”.
   - Inspiración en graficadruck.com.ar:
     - Home con bloques por tipo de producto.
     - Fichas de producto claras, con pestañas para:
       - Descripción
       - Tabla de precios (estática al principio)
       - Plantillas (descarga futura o texto informativo)

4. Estados de pedido (plan para v2):
   - Registrar estados personalizados:
     - Ingresado
     - Aprobado
     - En producción
     - Listo para entrega
   - Integrarlos al flujo de WooCommerce.

5. Calculadora de costos (plan para v2+):
   - Motor de costos separado:
     - Láser full color.
     - Offset (papel/cartulina).
     - Gran formato / cama plana UV + eco solvente.
     - Tarjetas PVC.
     - Gráfica editorial.
   - API interna tipo `ge_calcular_precio()`, usada por los productos WooCommerce.

---

## 5. Estructura del repositorio (prevista)

> Nota: el repo **NO** versiona el core de WordPress ni los uploads, sólo nuestro código y documentación.

- `/docs/`
  - `runbook_webtoprint.md`  ← este archivo
  - `checklist.md`           ← lista de tareas realizadas y pendientes (a crear)
- `/wp-content/themes/printing-services-child/`
  - `style.css`
  - `functions.php`
  - Otros templates que vayamos sobreescribiendo
- `/wp-content/plugins/ge-webtoprint-calculator/`
  - Código del plugin (php, assets, etc.)
- `.gitignore`
  - Excluir:
    - `/wp-admin/`
    - `/wp-includes/`
    - `/wp-content/uploads/`
    - archivos de config sensibles (`wp-config.php`, etc.)

---

## 6. Reglas de trabajo / buenas prácticas

- Siempre hacer cambios **chicos y atómicos**.
- Un feature a la vez:
  - definirlo en ChatGPT,
  - implementarlo en Codex,
  - probarlo local,
  - recién ahí deploy.
- Mensajes de commit claros (en español o inglés, pero descriptivos).
- **Nunca** tocar el panel Ferozo ni otros sitios (Tickex, AAJC, Graphexpress, etc.) sin backup previo.
- Documentar cualquier cambio relevante en este runbook y/o en `checklist.md`.

## 2025-12-04 – Entorno local WordPress (PrintSpace) y header GraphExpress

### Contexto general

- Se trabaja con un WordPress local en WSL/Ubuntu con DocumentRoot en `/var/www/html`.
- El repositorio `REPO-GRAPHEXPRESS` solo versiona `wp-content` (plugins, themes, etc.), no el core de WordPress ni `wp-config.php`.
- La rama activa para esta fase es `feature/graphexpress-child-home`.

### Theme PrintSpace y plugins

- Se descomprime el theme comprado `printspace-141` en `F:\GIT\theme\printspace-141` (`/mnt/f/GIT/theme/printspace-141`).
- El ZIP principal `printspace.zip` se extrae en `_printspace_theme/printspace` y desde ahí se copia la carpeta `printspace` a:
  - `wp-content/themes/printspace` dentro del repo.
  - `/var/www/html/wp-content/themes/printspace` en el WordPress local.
- Se activa el theme PrintSpace en `http://localhost/wp-admin/`.
- Se instalan plugins incluidos con el theme:
  - `haru-printspace` (plugin del theme).
  - `revslider`.
  - `drag-and-drop-file-uploads-wc-pro` (más tarde desactivado por errores).
  - `wc-designer-pro` (da error si falta `simplexml`).
- Se instala WooCommerce manualmente:
  - Descargar ZIP oficial a `/tmp`.
  - Descomprimir en `/var/www/html/wp-content/plugins/woocommerce`.
  - Ajustar permisos y propietario a `www-data:www-data`.
- Se corrige el pedido de FTP del instalador de plugins:
  - Propietario recursivo de `/var/www/html` → `www-data:www-data`.
  - Directorios: `chmod 755`, archivos: `chmod 644`.
  - Se añade `define('FS_METHOD', 'direct');` en `wp-config.php`.
- A partir de entonces, la instalación de plugins desde el panel de WordPress ya no solicita credenciales FTP.

### Child theme graphexpress-child y Git

- Codex crea un nuevo child theme `graphexpress-child` dentro de `wp-content/themes` con:
  - `style.css` apuntando al parent `printspace`.
  - `functions.php` encolando los estilos del parent + child.
  - `front-page.php` con un layout base para la home de GraphExpress.
- Se actualiza `.gitignore` para permitir versionar el child theme.
- En la rama `feature/graphexpress-child-home` se commitean:
  - `.gitignore` actualizado.
  - `wp-content/themes/graphexpress-child/{style.css,functions.php,front-page.php}`.
- Se hace push a remoto:
  - `git push -u origin feature/graphexpress-child-home`.

### Header Builder y logo GraphExpress

- Con el plugin `haru-printspace` activo, el theme ofrece gestionar el header vía Header & Footer Builder.
- Se crea un header nuevo tipo `Header`:
  - Título: `Main Header GraphExpress`.
  - Editable con Elementor.
- El header se visualiza correctamente en su URL interna:
  - `http://localhost/index.php/haru_header/main-header-graphexpress/`.
- Desde el Customizer se intenta:
  - Cambiar `Header Builder Type` a modo “Header Builder”.
  - Asignar el header creado, pero al experimentar con opciones el header del sitio llega a desaparecer.
  - Se usa “Reset selection” y vuelve el header por defecto del theme (PrintSpace), dejando el header custom creado pero sin asignar globalmente.
- Se sube el logo de GraphExpress (SVG + PNG) a la librería de medios:
  - Ambos se ven bien en Media Library.
  - En el Header Builder, al elegir el logo en el widget correspondiente, la imagen parece no persistir al guardar (se “borra” la selección).
- Pendiente:
  - Revisar por qué el widget de logo no guarda la imagen (posible conflicto de configuración del builder, uso del “Site Logo” vs imagen directa, o sobreescritura por opciones globales del theme).
  - Asegurar que el CPT `haru_header` tenga asignación “Display On: Entire Site” y que el Customizer apunte a `Main Header GraphExpress` como header global.

### Próximos pasos sugeridos

1. Desde Codex:
   - Revisar el child theme `graphexpress-child` y consolidar el layout de `front-page.php` según la estructura definida para GraphExpress (hero, servicios, proceso, CTA).
   - Asegurar que el child se integra correctamente con PrintSpace (estilos, hooks, templates).
2. En WordPress local:
   - Resolver la asignación de `Main Header GraphExpress` como header global (Header Builder + Customizer + metaboxes de páginas).
   - Investigar el comportamiento del widget de logo y documentar la solución (PNG vs SVG, uso de “Site Identity”, etc.).
3. Plugins opcionales/conflictivos:
   - Evaluar si `drag-and-drop-file-uploads-wc-pro` y `wc-designer-pro` son realmente necesarios en esta etapa.
   - Si no lo son, mantenerlos desactivados en el entorno de desarrollo y documentar esa decisión en el runbook.


# Graph Express · Web-to-Print

Repositorio de trabajo para la nueva plataforma **Web-to-Print** de Graph Express, basada en:

- **WordPress** (CMS)
- **WooCommerce** (tienda)
- **Tema gratuito:** Printing Services (WordPress.org)
- **Tema hijo:** `printing-services-child`
- **Plugin propio:** `ge-webtoprint-calculator`

El objetivo es construir una web similar en estructura a graficadruck.com.ar, pero más moderna y adaptada a los servicios de impresión de Graph Express.

---

## Estructura del repo

Este repo **no** contiene el core completo de WordPress ni los uploads. Solo versionamos:

- `docs/`
  - `runbook_webtoprint.md` → runbook detallado del proyecto.
  - `checklist.md` → (a crear) lista de tareas realizadas y pendientes.

- `wp-content/themes/printing-services-child/`
  - `style.css` → header del tema hijo + futuros estilos.
  - `functions.php` → hooks y filtros personalizados.

- `wp-content/plugins/ge-webtoprint-calculator/`
  - `ge-webtoprint-calculator.php` → archivo principal del plugin.

- `.gitignore`
  - Ignora core de WordPress, uploads, otros themes/plugins y archivos sensibles.

---

## Flujo de trabajo

1. **Planificación en ChatGPT**
   - Definimos features y cambios.
   - ChatGPT prepara prompts para Codex.

2. **Edición en Codex**
   - Se abre este repo.
   - Se aplican cambios en:
     - Tema hijo.
     - Plugin propio.
   - Commits chicos y descriptivos.

3. **Pruebas en entorno local (Ubuntu/WSL)**
   - `git pull`
   - Probar WordPress + WooCommerce + plugin.

4. **Push a GitHub**
   - `git commit` + `git push`.

5. **Deploy al VPS**
   - Backup siempre antes.
   - Subir solo tema hijo + plugin + ajustes.
   - Probar en staging / subdominio y luego en producción.

Detalles más completos: ver `docs/runbook_webtoprint.md`.

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

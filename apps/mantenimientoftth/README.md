# Mantenimiento FTTH

Mini app Express que sirve un formulario y un endpoint para generar un PDF bajo `BASE_PATH=/mantenimientoftth`.

## Requisitos

- Node.js 18+ (recomendado)

## Instalacion

```bash
cd apps/mantenimientoftth
npm install
```

## Scripts

- `npm run dev` inicia el servidor en modo simple (puerto por defecto 3010).
- `npm start` alias del comando anterior.

## Uso

1) Levanta la app:

```bash
npm run dev
```

2) Abre en el navegador:

```
http://localhost:3010/mantenimientoftth/
```

3) Completa el formulario (Nombre, Apellido, Cargo, DNI) y sube la foto y el QR.
4) Enviar realiza un `POST` multipart a `/mantenimientoftth/api/generate` y descarga un PDF.

### Panel admin (privado)

- Usa la URL: `http://localhost:3010/mantenimientoftth/admin?key=TU_KEY`.
- La key se toma de la query `?key=` (o header `x-admin-key`).
- `ADMIN_KEY` puede definirse en env; si no existe usa `change-me` y se loguea una advertencia.
- El panel lista submissions, muestra miniaturas de foto/QR y permite descargar el PDF guardado.

## Endpoints

- `GET /mantenimientoftth/` sirve el frontend estatico.
- `POST /mantenimientoftth/api/generate` recibe multipart form-data. Hoy devuelve un PDF placeholder generado con `pdf-lib`. El punto marcado como `TODO` en `server.js` es donde debe ir la logica real (ej: construir el PDF con los datos, foto y QR).
- `GET /mantenimientoftth/admin?key=...` sirve la UI admin (protegida por key).
- `GET /mantenimientoftth/api/admin/submissions?key=...` devuelve el JSON de submissions.
- `GET /mantenimientoftth/api/admin/submissions/:id/pdf|photo|qr?key=...` permite descargar assets guardados (protegido).

## Notas sobre el PDF real

- La ruta ya usa `multer` con `memoryStorage`, por lo que `req.files.photo` y `req.files.qr` quedan disponibles en memoria.
- Usa la constante `BASE_PATH` para mantener rutas coherentes al desplegar en subcarpetas.
- El helper `buildPlaceholderPdf` muestra como crear un PDF basico con `pdf-lib`; reemplazalo por la version final (por ejemplo, embebiendo la foto y el QR y aplicando el layout necesario).

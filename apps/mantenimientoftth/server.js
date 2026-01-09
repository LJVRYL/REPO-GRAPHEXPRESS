const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const express = require('express');
const multer = require('multer');

const BASE_PATH = '/mantenimientoftth';
const PORT = process.env.PORT || 3010;
const ADMIN_KEY = process.env.ADMIN_KEY || 'change-me';

if (!process.env.ADMIN_KEY) {
	console.warn('[mantenimientoftth] ADMIN_KEY no seteada, usando fallback "change-me"');
}

const STORAGE_ROOT = path.join(__dirname, 'storage');
const STORAGE = {
	photos: path.join(STORAGE_ROOT, 'photos'),
	qrs: path.join(STORAGE_ROOT, 'qrs'),
	// pdfs queda para compatibilidad pero ya no se escribe.
	pdfs: path.join(STORAGE_ROOT, 'pdfs'),
	data: path.join(STORAGE_ROOT, 'data'),
};
const SUBMISSIONS_FILE = path.join(STORAGE.data, 'submissions.json');
const MAX_FILE_BYTES = 5 * 1024 * 1024;

const app = express();

const upload = multer({
	storage: multer.memoryStorage(),
	limits: { fileSize: MAX_FILE_BYTES },
});

let writeQueue = Promise.resolve();
const runExclusive = (fn) => {
	const next = writeQueue.then(() => fn());
	writeQueue = next.catch((err) => {
		console.error('[mantenimientoftth] Error en cola de escritura', err);
	});
	return next;
};

async function ensureStorage() {
	await fsp.mkdir(STORAGE_ROOT, { recursive: true });
	await Promise.all([
		fsp.mkdir(STORAGE.photos, { recursive: true }),
		fsp.mkdir(STORAGE.qrs, { recursive: true }),
		fsp.mkdir(STORAGE.pdfs, { recursive: true }), // legacy/no-op ahora
		fsp.mkdir(STORAGE.data, { recursive: true }),
	]);

	try {
		await fsp.access(SUBMISSIONS_FILE, fs.constants.F_OK);
	} catch (_) {
		await fsp.writeFile(SUBMISSIONS_FILE, '[]', 'utf8');
	}
}

const mimeToExt = (mime, originalName = '') => {
	const known = {
		'image/png': '.png',
		'image/jpeg': '.jpg',
		'image/jpg': '.jpg',
		'image/webp': '.webp',
	};
	if (known[mime]) return known[mime];
	const fromName = path.extname(originalName || '').toLowerCase();
	return fromName || '.bin';
};

const buildFullName = (firstName = '', lastName = '') => `${firstName} ${lastName}`.trim();

async function loadSubmissions() {
	try {
		const content = await fsp.readFile(SUBMISSIONS_FILE, 'utf8');
		return JSON.parse(content || '[]');
	} catch (err) {
		console.error('[mantenimientoftth] No se pudo leer submissions.json', err);
		return [];
	}
}

async function saveSubmissions(submissions) {
	await fsp.writeFile(SUBMISSIONS_FILE, JSON.stringify(submissions, null, 2), 'utf8');
}

function validateSubmissionPayload(body, files) {
	const errors = [];
	const { firstName = '', lastName = '', role = '', dni = '' } = body || {};
	const dniClean = String(dni).trim();

	if (!firstName.trim()) errors.push('Nombre es obligatorio');
	if (!lastName.trim()) errors.push('Apellido es obligatorio');
	if (!role.trim()) errors.push('Cargo es obligatorio');
	if (!dniClean) errors.push('DNI es obligatorio');
	if (dniClean && !/^\d{7,9}$/.test(dniClean)) errors.push('DNI debe tener 7 a 9 digitos');

	const photo = files?.photo?.[0];
	const qr = files?.qr?.[0];
	if (!photo) errors.push('Archivo de foto es obligatorio');
	if (!qr) errors.push('Archivo de QR es obligatorio');

	[photo, qr].forEach((f) => {
		if (!f) return;
		if (!f.mimetype?.startsWith('image/')) errors.push('Los archivos deben ser imagenes');
		if (f.size > MAX_FILE_BYTES) errors.push('Archivo excede el tamaño maximo permitido');
	});

	return { ok: errors.length === 0, errors, firstName, lastName, role, dni: dniClean };
}

async function persistSubmission({ firstName, lastName, role, dni, files }) {
	return runExclusive(async () => {
		const submissions = await loadSubmissions();
		const nextId = submissions.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0) + 1;

		const fullName = buildFullName(firstName, lastName);
		const createdAt = new Date().toISOString();

		const photo = files.photo[0];
		const qr = files.qr[0];

		const photoExt = mimeToExt(photo.mimetype, photo.originalname);
		const qrExt = mimeToExt(qr.mimetype, qr.originalname);

		const photoName = `tarjeta_${nextId}_photo${photoExt}`;
		const qrName = `tarjeta_${nextId}_qr${qrExt}`;

		const photoDiskPath = path.join(STORAGE.photos, photoName);
		const qrDiskPath = path.join(STORAGE.qrs, qrName);

		await fsp.writeFile(photoDiskPath, photo.buffer);
		await fsp.writeFile(qrDiskPath, qr.buffer);

		const record = {
			id: nextId,
			fullName,
			role,
			dni,
			createdAt,
			photoPath: path.posix.join('photos', photoName),
			qrPath: path.posix.join('qrs', qrName),
		};

		submissions.push(record);
		await saveSubmissions(submissions);

		// Nota: PDF ya no se genera; devolvemos solo record.
		return { record };
	});
}

function adminKeyMatches(req) {
	const provided = (req.query.key || req.headers['x-admin-key'] || '').toString();
	return provided === ADMIN_KEY;
}

function requireAdmin(req, res, next) {
	if (!adminKeyMatches(req)) {
		return res.status(401).json({ message: 'No autorizado' });
	}
	return next();
}

app.get(`${BASE_PATH}/admin`, requireAdmin, (req, res) => {
	return res.sendFile(path.join(__dirname, 'public', 'admin.html'));
});

app.get(`${BASE_PATH}/api/admin/submissions`, requireAdmin, async (req, res) => {
	const submissions = await loadSubmissions();
	const ordered = submissions.sort((a, b) => (b.id || 0) - (a.id || 0));
	return res.json(ordered);
});

app.get(`${BASE_PATH}/api/admin/submissions/:id/pdf`, requireAdmin, async (req, res) => {
	const id = Number(req.params.id);
	const submissions = await loadSubmissions();
	const record = submissions.find((item) => Number(item.id) === id);
	if (!record) return res.status(404).json({ message: 'No encontrado' });

	// PDF ya no se genera ni se sirve.
	return res.status(410).json({ message: 'PDF no disponible (generacion pausada)' });
});

app.get(`${BASE_PATH}/api/admin/submissions/:id/photo`, requireAdmin, async (req, res) => {
	const id = Number(req.params.id);
	const submissions = await loadSubmissions();
	const record = submissions.find((item) => Number(item.id) === id);
	if (!record) return res.status(404).json({ message: 'No encontrado' });

	const photoDiskPath = path.join(STORAGE_ROOT, record.photoPath);
	if (!fs.existsSync(photoDiskPath)) return res.status(404).json({ message: 'Foto no disponible' });

	return res.sendFile(photoDiskPath);
});

app.get(`${BASE_PATH}/api/admin/submissions/:id/qr`, requireAdmin, async (req, res) => {
	const id = Number(req.params.id);
	const submissions = await loadSubmissions();
	const record = submissions.find((item) => Number(item.id) === id);
	if (!record) return res.status(404).json({ message: 'No encontrado' });

	const qrDiskPath = path.join(STORAGE_ROOT, record.qrPath);
	if (!fs.existsSync(qrDiskPath)) return res.status(404).json({ message: 'QR no disponible' });

	return res.sendFile(qrDiskPath);
});

app.use(BASE_PATH, express.static(path.join(__dirname, 'public')));

app.post(
	`${BASE_PATH}/api/generate`,
	upload.fields([
		{ name: 'photo', maxCount: 1 },
		{ name: 'qr', maxCount: 1 },
	]),
	async (req, res) => {
		try {
			const validation = validateSubmissionPayload(req.body, req.files);
			if (!validation.ok) {
				return res.status(400).json({ message: validation.errors.join('; ') });
			}

			const { record } = await persistSubmission({
				firstName: validation.firstName,
				lastName: validation.lastName,
				role: validation.role,
				dni: validation.dni,
				files: req.files,
			});

			return res.status(200).json({
				ok: true,
				id: record.id,
				createdAt: record.createdAt,
				fullName: record.fullName,
				role: record.role,
				dni: record.dni,
			});
		} catch (error) {
			console.error('Error en /api/generate', error);
			return res.status(500).json({ message: 'Error al guardar la tarjeta' });
		}
	}
);

app.use((req, res) => {
	res.status(404).json({ message: 'No encontrado' });
});

app.listen(PORT, async () => {
	await ensureStorage();
	console.log(`Mantenimiento FTTH listo en http://localhost:${PORT}${BASE_PATH}/`);
});

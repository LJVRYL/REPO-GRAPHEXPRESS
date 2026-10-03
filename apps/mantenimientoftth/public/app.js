const BASE_PATH = '/mantenimientoftth';

document.addEventListener('DOMContentLoaded', () => {
	const form = document.getElementById('ftth-form');
	const photoInput = document.getElementById('photo');
	const qrInput = document.getElementById('qr');
	const statusEl = document.getElementById('form-status');
	const submitBtn = document.getElementById('submit-btn');

	const previewCache = new Map();

	const setStatus = (message, type) => {
		statusEl.textContent = message || '';
		statusEl.className = type ? type : '';
	};

	const resetPreview = (previewEl, placeholder) => {
		if (previewCache.has(previewEl)) {
			URL.revokeObjectURL(previewCache.get(previewEl));
			previewCache.delete(previewEl);
		}
		previewEl.textContent = placeholder;
	};

	const handlePreview = (inputEl, previewId, placeholder) => {
		const previewEl = document.getElementById(previewId);
		const file = inputEl.files?.[0];

		if (!file) {
			resetPreview(previewEl, placeholder);
			return;
		}

		if (!file.type.startsWith('image/')) {
			resetPreview(previewEl, placeholder);
			setStatus('Solo se permiten imagenes para la foto y el QR.', 'error');
			inputEl.value = '';
			return;
		}

		const objectUrl = URL.createObjectURL(file);
		const img = document.createElement('img');
		img.src = objectUrl;
		img.alt = placeholder;

		previewEl.innerHTML = '';
		previewEl.appendChild(img);

		if (previewCache.has(previewEl)) {
			URL.revokeObjectURL(previewCache.get(previewEl));
		}
		previewCache.set(previewEl, objectUrl);
	};

	const validateForm = () => {
		const firstName = form.firstName.value.trim();
		const lastName = form.lastName.value.trim();
		const role = form.role.value.trim();
		const dni = form.dni.value.trim();

		if (!firstName || !lastName || !role || !dni) {
			setStatus('Completa los campos obligatorios.', 'error');
			return false;
		}

		if (!photoInput.files?.length || !qrInput.files?.length) {
			setStatus('Sube la foto y el QR antes de continuar.', 'error');
			return false;
		}

		return true;
	};

	form.addEventListener('submit', async (event) => {
		event.preventDefault();

		if (!validateForm()) {
			return;
		}

		setStatus('Enviando datos...', '');
		submitBtn.disabled = true;

		try {
			const formData = new FormData();
			formData.append('firstName', form.firstName.value.trim());
			formData.append('lastName', form.lastName.value.trim());
			formData.append('role', form.role.value.trim());
			formData.append('dni', form.dni.value.trim());
			formData.append('photo', photoInput.files[0]);
			formData.append('qr', qrInput.files[0]);

			const response = await fetch(`${BASE_PATH}/api/generate`, {
				method: 'POST',
				body: formData,
			});

			const result = await response.json().catch(() => null);
			if (!response.ok || !result?.ok) {
				const message = result?.message || 'No se pudo guardar la tarjeta.';
				throw new Error(message);
			}

			const idLabel = result.id ? `Tarjeta #${result.id}` : 'Tarjeta guardada';
			setStatus(`Carga OK — ${idLabel}`, 'success');

			// Opcional: limpiar el formulario tras exito
			form.reset();
			resetPreview(document.getElementById('photo-preview'), 'Sin imagen');
			resetPreview(document.getElementById('qr-preview'), 'Sin imagen');
		} catch (error) {
			console.error(error);
			const message = error?.message || 'Ocurrio un error inesperado.';
			setStatus(message, 'error');
		} finally {
			submitBtn.disabled = false;
		}
	});

	photoInput.addEventListener('change', () => handlePreview(photoInput, 'photo-preview', 'Sin imagen'));
	qrInput.addEventListener('change', () => handlePreview(qrInput, 'qr-preview', 'Sin imagen'));
});

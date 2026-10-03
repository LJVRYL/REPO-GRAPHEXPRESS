(function () {
    'use strict';
    var money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });
    document.querySelectorAll('[data-ge-storefront]').forEach(function (form) {
        var select = form.querySelector('[data-ge-option]');
        var selectors = Array.prototype.slice.call(form.querySelectorAll('[data-ge-selector]'));
        var optionMap = JSON.parse(form.dataset.optionMap || '{}');
        var mappedCombinations = Object.keys(optionMap).map(function (key) { return key.split('|'); });
        var submit = form.querySelector('.ge-storefront-submit');
        var quantity = form.querySelector('[data-ge-quantity]');
        var price = form.querySelector('[data-ge-price]');
        var base = form.querySelector('[data-ge-base]');
        var width = form.querySelector('[data-ge-width]');
        var height = form.querySelector('[data-ge-height]');
        var length = form.querySelector('[data-ge-length]');
        var rollWidths = JSON.parse(form.dataset.rollWidths || '[]');
        var rollHint = form.querySelector('[data-ge-roll-hint]');
        var r2Files = form.querySelector('[data-ge-r2-files]');
        var r2Claims = form.querySelector('[data-ge-r2-claims]');
        var r2Button = form.querySelector('[data-ge-r2-upload-button]');
        var r2Status = form.querySelector('[data-ge-r2-status]');
        var uploadProgress = form.querySelector('[data-ge-upload-progress]');
        var submitButton = form.querySelector('.ge-storefront-submit');
        function refreshSelectors() {
            if (!selectors.length) { return; }
            selectors.forEach(function (field, fieldIndex) {
                Array.prototype.slice.call(field.options).forEach(function (candidate) {
                    var available = mappedCombinations.some(function (parts) {
                        if (parts[fieldIndex] !== candidate.value) { return false; }
                        for (var previous = 0; previous < fieldIndex; previous += 1) {
                            if (parts[previous] !== selectors[previous].value) { return false; }
                        }
                        return true;
                    });
                    candidate.disabled = !available;
                    candidate.hidden = !available;
                });
                if (field.options[field.selectedIndex] && field.options[field.selectedIndex].disabled) {
                    var replacement = Array.prototype.slice.call(field.options).find(function (candidate) { return !candidate.disabled; });
                    if (replacement) { field.value = replacement.value; }
                }
            });
            var combination = selectors.map(function (field) { return field.value; }).join('|');
            if (optionMap[combination] && select.querySelector('option[value="' + optionMap[combination] + '"]')) {
                select.value = optionMap[combination];
                var selected = select.options[select.selectedIndex];
                if (selected.dataset.fixed) { quantity.value = selected.dataset.fixed; }
                if (submit) { submit.disabled = false; }
            } else if (submit) {
                submit.disabled = true;
            }
        }
        function applyQuantityRule(reset) {
            var option = select.options[select.selectedIndex];
            var minimum = Math.max(1, Number(option.dataset.min || 1));
            var step = Math.max(1, Number(option.dataset.step || 1));
            var current = Math.max(1, Number(quantity.value || minimum));
            quantity.min = String(minimum);
            quantity.step = String(step);
            if (reset || current < minimum || (current - minimum) % step !== 0) {
                quantity.value = String(minimum);
            }
        }
        function update() {
            var option = select.options[select.selectedIndex];
            var factor = 1;
            if (width && height) {
                var requestedWidth = Math.max(0.01, Number(width.value || 0));
                var billedWidth = rollWidths.length ? rollWidths.find(function (candidate) { return Number(candidate) >= requestedWidth; }) : requestedWidth;
                if (rollWidths.length && !billedWidth) {
                    if (rollHint) rollHint.textContent = 'Para este tamaño, consultanos por una cotización especial.';
                    price.textContent = 'Consultar';
                    if (base) base.textContent = '';
                    return;
                }
                factor = (billedWidth / 100) * Math.max(0.01, Number(height.value || 0) / 100);
                if (rollHint) rollHint.textContent = 'Tamaño final: ' + requestedWidth + ' × ' + height.value + ' cm. Precio actualizado.';
            }
            if (length) { factor = Math.max(0.01, Number(length.value || 0) / 100); }
            var total = Number(option.dataset.price || 0) * factor * Math.max(1, Number(quantity.value || 1));
            price.textContent = money.format(Math.round(total * 1.21));
            if (base) { base.textContent = 'Base sin IVA: ' + money.format(Math.round(total)); }
        }
        select.addEventListener('change', function () { applyQuantityRule(true); update(); });
        selectors.forEach(function (field) {
            field.addEventListener('change', function () { refreshSelectors(); applyQuantityRule(true); update(); });
        });
        quantity.addEventListener('input', update);
        [width, height, length].forEach(function (field) { if (field) { field.addEventListener('input', update); } });
        if (r2Files && r2Claims && r2Button && window.geR2Upload) {
            var uploading = false;
            function setUploadStatus(message, error) {
                r2Status.textContent = message;
                r2Status.classList.toggle('is-error', !!error);
            }
            function fingerprint(files) {
                return files.map(function (file) { return [file.name, file.size, file.lastModified].join(':'); }).join('|');
            }
            r2Files.addEventListener('change', function () {
                r2Claims.value = '[]';
                r2Files.dataset.uploadedFingerprint = '';
                setUploadStatus(r2Files.files.length ? r2Files.files.length + ' archivo(s) seleccionado(s). Falta subirlos.' : 'Todavía no hay archivos cargados.', false);
            });
            r2Button.addEventListener('click', function () {
                if (uploading) { return; }
                var files = Array.prototype.slice.call(r2Files.files || []);
                var total = files.reduce(function (sum, file) { return sum + file.size; }, 0);
                if (!files.length) { setUploadStatus('Elegí al menos un archivo.', true); return; }
                if (files.length > Number(geR2Upload.maxFiles) || files.some(function (file) { return file.size < 1 || file.size > Number(geR2Upload.maxFileBytes); }) || total > Number(geR2Upload.maxTotalBytes)) {
                    setUploadStatus('Revisá la cantidad y el tamaño de los archivos.', true); return;
                }
                var turnstile = form.querySelector('[name="cf-turnstile-response"]');
                if (geR2Upload.requiresTurnstile && (!turnstile || !turnstile.value)) { setUploadStatus('Completá primero la validación de seguridad.', true); return; }
                uploading = true; r2Button.disabled = true; if (submitButton) { submitButton.disabled = true; }
                if (uploadProgress) { uploadProgress.hidden = false; uploadProgress.value = 0; }
                setUploadStatus('Preparando la carga privada…', false);
                var body = new FormData();
                body.append('action', geR2Upload.action);
                body.append('nonce', geR2Upload.nonce);
                if (turnstile && turnstile.value) { body.append('cf-turnstile-response', turnstile.value); }
                body.append('files', JSON.stringify(files.map(function (file) { return { name: file.name, size: file.size, type: file.type || 'application/octet-stream' }; })));
                fetch(geR2Upload.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.success) { throw new Error(data.data && data.data.message ? data.data.message : 'No se pudo autorizar la carga.'); } return data.data.uploads; }); })
                    .then(function (uploads) {
                        var completed = [];
                        return uploads.reduce(function (chain, upload, index) {
                            return chain.then(function () {
                                setUploadStatus('Subiendo ' + (index + 1) + ' de ' + uploads.length + ': ' + upload.name, false);
                                return new Promise(function (resolve, reject) {
                                    var request = new XMLHttpRequest();
                                    request.open('PUT', upload.url, true);
                                    request.withCredentials = true;
                                    request.setRequestHeader('Content-Type', upload.mime || 'application/octet-stream');
                                    request.upload.onprogress = function (event) {
                                        if (!event.lengthComputable) { return; }
                                        var percent = Math.round(((index + event.loaded / event.total) / uploads.length) * 100);
                                        if (uploadProgress) { uploadProgress.value = percent; }
                                        setUploadStatus('Subiendo ' + (index + 1) + ' de ' + uploads.length + ' · ' + percent + '%', false);
                                    };
                                    request.onload = function () {
                                        if (request.status < 200 || request.status >= 300) { reject(new Error('El almacenamiento privado rechazó el archivo ' + upload.name + '.')); return; }
                                        completed.push({ token: upload.token });
                                        resolve();
                                    };
                                    request.onerror = function () { reject(new Error('Se interrumpió la carga de ' + upload.name + '.')); };
                                    request.send(files[index]);
                                });
                            });
                        }, Promise.resolve()).then(function () { return completed; });
                    })
                    .then(function (claims) {
                        r2Claims.value = JSON.stringify(claims);
                        r2Files.dataset.uploadedFingerprint = fingerprint(files);
                        if (uploadProgress) { uploadProgress.value = 100; }
                        setUploadStatus(claims.length + ' archivo(s) cargado(s) y listo(s) para vincular al pedido.', false);
                    })
                    .catch(function (error) {
                        r2Claims.value = '[]';
                        if (uploadProgress) { uploadProgress.hidden = true; }
                        setUploadStatus(error.message || 'La carga no pudo completarse.', true);
                    })
                    .then(function () {
                        uploading = false; r2Button.disabled = false; if (submitButton) { submitButton.disabled = false; }
                        if (geR2Upload.requiresTurnstile && window.turnstile && typeof window.turnstile.reset === 'function') { window.turnstile.reset(); }
                    });
            });
            form.addEventListener('submit', function (event) {
                var files = Array.prototype.slice.call(r2Files.files || []);
                if (uploading || (files.length && r2Files.dataset.uploadedFingerprint !== fingerprint(files))) {
                    event.preventDefault();
                    setUploadStatus(uploading ? 'Esperá a que termine la carga.' : 'Subí los archivos antes de agregar el producto al carrito.', true);
                }
            });
        }
        refreshSelectors();
        applyQuantityRule(false);
        update();
    });
}());

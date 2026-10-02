(function () {
    'use strict';

    function money(value) {
        return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 }).format(value);
    }

    document.querySelectorAll('[data-ge-digital-calculator]').forEach(function (calculator) {
        var config = JSON.parse(calculator.dataset.config || '{}');
        var controls = Array.prototype.slice.call(calculator.querySelectorAll('[data-ge-field]'));
        var total = calculator.querySelector('[data-ge-total]');
        var unit = calculator.querySelector('[data-ge-unit]');
        var tax = calculator.querySelector('[data-ge-tax]');
        var state = calculator.querySelector('[data-ge-price-state]');
        var warning = calculator.querySelector('[data-ge-warning]');
        var submit = calculator.querySelector('[data-ge-submit]');
        var whatsapp = calculator.querySelector('[data-ge-whatsapp]');
        var uploading = false;
        var priceReady = false;

        function values() {
            var result = {};
            controls.forEach(function (control) { result[control.dataset.geField] = control.type === 'checkbox' ? control.checked : control.value; });
            return result;
        }

        function applyDependencies(current) {
            calculator.querySelectorAll('option[data-when]').forEach(function (option) {
                var rule = JSON.parse(option.dataset.when || '{}');
                var available = Object.keys(rule).every(function (key) {
                    var expected = rule[key];
                    return Array.isArray(expected) ? expected.map(String).indexOf(String(current[key])) !== -1 : String(current[key]) === String(expected);
                });
                option.hidden = !available;
                option.disabled = !available;
                if (!available && option.selected) { option.parentElement.value = option.parentElement.querySelector('option:not([disabled])').value; }
            });
        }

        function tieredPrice(current, quantity) {
            var model = config.pricing_model || {};
            if (model.type !== 'tiered-unit') { return null; }
            var key = (model.dimension_keys || []).map(function (dimension) { return current[dimension]; }).join('|');
            var rates = (model.public_unit_rates || {})[key];
            if (!Array.isArray(rates) || !rates.length) { return 0; }
            var tier = 0;
            (model.breaks || []).forEach(function (minimum, index) { if (quantity >= Number(minimum)) { tier = index; } });
            var printTotal = Number(rates[Math.min(tier, rates.length - 1)] || 0) * quantity;
            var laminationTotal = 0;
            var lamination = model.lamination || {};
            var selectedLamination = current[lamination.field || 'laminado'];
            var laminablePapers = Array.isArray(lamination.papers) ? lamination.papers : [lamination.paper];
            if (laminablePapers.indexOf(current.papel) !== -1 && selectedLamination && selectedLamination !== 'sin-laminar') {
                var sides = Number((lamination.sides || {})[selectedLamination] || 0);
                var publicUnit = Number((lamination.public_unit_per_side || {})[current.tamano] || 0);
                laminationTotal = publicUnit * sides * quantity;
            }
            return { print: printTotal, lamination: laminationTotal, total: printTotal + laminationTotal, unitLabel: model.unit_label || 'pliego' };
        }

        function priceKey(current) {
            return (config.fields || []).filter(function (field) { return field.type !== 'checkbox'; }).map(function (field) { return field.key + '=' + current[field.key]; }).join('|');
        }

        function selectedOptionNeedsQuote() {
            return controls.some(function (control) {
                return control.options && control.selectedIndex >= 0 && control.options[control.selectedIndex].dataset.quoteRequired === 'yes';
            });
        }

        function update() {
            var current = values();
            var quantityControl = controls.find(function (control) { return control.dataset.geField === 'cantidad'; });
            var minimumQuantity = quantityControl ? Math.max(1, Number(quantityControl.min || 1)) : 1;
            if (quantityControl && Number(current.cantidad || 0) < minimumQuantity) {
                quantityControl.value = String(minimumQuantity);
                current = values();
            }
            applyDependencies(current);
            current = values();
            var base = Number((config.prices || {})[priceKey(current)] || 0);
            var surcharge = controls.reduce(function (sum, control) { return sum + (control.type === 'checkbox' && control.checked ? Number(control.dataset.surcharge || 0) : 0); }, 0);
            var quantity = Math.max(minimumQuantity, Number(current.cantidad || minimumQuantity));
            var tiered = tieredPrice(current, quantity);
            var labels = controls.filter(function (control) { return control.type !== 'checkbox' || control.checked; }).map(function (control) {
                var labelNode = control.type === 'checkbox' ? control.closest('label').querySelector('strong') : control.closest('label').querySelector('span');
                var label = labelNode.textContent.trim();
                var selected = control.type === 'checkbox' ? 'Sí' : (control.options ? control.options[control.selectedIndex].text : control.value);
                return label + ': ' + selected;
            });

            var needsQuote = selectedOptionNeedsQuote();
            if (needsQuote) {
                total.textContent = 'Terminación a cotizar';
                unit.textContent = 'Confirmamos el valor según cantidad de juegos y páginas.';
                tax.textContent = 'Pendiente';
                state.textContent = 'Cotización personalizada';
                warning.textContent = 'La impresión quedó configurada. Abrochado y anillado se calculan según el armado final.';
            } else if (tiered && tiered.total > 0) {
                var calculated = Math.round(tiered.print * (1 + surcharge) + tiered.lamination);
                total.textContent = money(calculated) + ' + IVA';
                unit.textContent = 'Precio por ' + tiered.unitLabel + ': ' + money(calculated / quantity);
                tax.textContent = money(calculated * 0.21);
                state.textContent = 'Precio final sin IVA';
                warning.textContent = 'Incluye el margen comercial por cantidad' + (tiered.lamination > 0 ? ' y el laminado seleccionado.' : '.');
            } else if (base > 0) {
                var calculated = Math.round(base * (1 + surcharge));
                total.textContent = money(calculated) + ' + IVA';
                unit.textContent = 'Precio unitario: ' + money(calculated / quantity);
                tax.textContent = money(calculated * 0.21);
                state.textContent = config.price_status === 'estimated' ? 'Precio estimado sin IVA' : (config.prices_are_final ? 'Precio final sin IVA' : (surcharge > 0 ? 'Referencia con recargo aplicado' : 'Referencia provisoria'));
                warning.textContent = config.price_status === 'estimated' ? 'Estimación Graph Express sujeta a disponibilidad, archivo y plazo.' : (config.prices_are_final ? 'Valor final de Graph Express para la combinación seleccionada.' : 'Este valor todavía no incluye el margen comercial de Graph Express.');
            } else {
                total.textContent = config.quote_only ? 'Solicitar cotización' : 'Precio a confirmar';
                unit.textContent = config.quote_only ? 'Confirmamos el valor según la configuración elegida.' : 'La combinación ya quedó preparada para cargar su valor.';
                tax.textContent = config.quote_only ? 'Se calcula al cotizar' : 'Pendiente';
                state.textContent = config.quote_only ? 'Cotización personalizada' : 'Matriz incompleta';
                warning.textContent = config.quote_only ? 'Enviá la configuración y te responderemos con el precio final.' : 'No inventamos un precio para esta combinación: se completará al actualizar la lista.';
            }

            var message = 'Hola Graph Express, quiero cotizar ' + config.name + '.\n' + labels.join('\n');
            whatsapp.href = 'https://wa.me/5491151393899?text=' + encodeURIComponent(message);
            priceReady = !needsQuote && !config.quote_only && config.price_status !== 'estimated' &&
                (Boolean(config.prices_are_final) || Boolean(config.pricing_model)) &&
                ((tiered && tiered.total > 0) || base > 0);
            submit.disabled = !priceReady || uploading;
            submit.textContent = priceReady ? 'Agregar al carrito' : 'Pendiente de cotización';
        }

        controls.forEach(function (control) { control.addEventListener('change', update); control.addEventListener('input', update); });
        var filesInput = calculator.querySelector('[data-ge-digital-files]');
        var claimsInput = calculator.querySelector('[data-ge-digital-claims]');
        var uploadButton = calculator.querySelector('[data-ge-digital-upload]');
        var uploadStatus = calculator.querySelector('[data-ge-digital-status]');
        var progress = calculator.querySelector('[data-ge-digital-progress]');
        if (filesInput && claimsInput && uploadButton && window.geDigitalUpload) {
            function fingerprint(files) {
                return files.map(function (file) { return [file.name, file.size, file.lastModified].join(':'); }).join('|');
            }
            function status(message) { uploadStatus.textContent = message; }
            filesInput.addEventListener('change', function () {
                claimsInput.value = '[]';
                filesInput.dataset.uploadedFingerprint = '';
                status(filesInput.files.length ? 'Archivos seleccionados; falta subirlos.' : 'Los archivos quedarán vinculados al producto y al pedido.');
            });
            uploadButton.addEventListener('click', function () {
                if (uploading) { return; }
                var files = Array.prototype.slice.call(filesInput.files || []);
                var totalBytes = files.reduce(function (sum, file) { return sum + file.size; }, 0);
                if (!files.length) { status('Elegí al menos un archivo.'); return; }
                if (files.length > Number(geDigitalUpload.maxFiles) || files.some(function (file) { return file.size < 1 || file.size > Number(geDigitalUpload.maxFileBytes); }) || totalBytes > Number(geDigitalUpload.maxTotalBytes)) {
                    status('Revisá la cantidad o el tamaño de los archivos.'); return;
                }
                uploading = true; uploadButton.disabled = true; submit.disabled = true; progress.hidden = false; progress.value = 0;
                status('Preparando carga privada…');
                var body = new FormData();
                body.append('action', geDigitalUpload.action);
                body.append('nonce', geDigitalUpload.nonce);
                body.append('files', JSON.stringify(files.map(function (file) { return {name: file.name, size: file.size, type: file.type || 'application/octet-stream'}; })));
                fetch(geDigitalUpload.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body})
                    .then(function (response) { return response.json().then(function (data) { if (!response.ok || !data.success) { throw new Error(data.data && data.data.message ? data.data.message : 'No se pudo autorizar la carga.'); } return data.data.uploads; }); })
                    .then(function (uploads) {
                        var completed = [];
                        return uploads.reduce(function (chain, upload, index) {
                            return chain.then(function () {
                                return new Promise(function (resolve, reject) {
                                    var request = new XMLHttpRequest();
                                    request.open('PUT', upload.url, true);
                                    request.withCredentials = true;
                                    request.setRequestHeader('Content-Type', upload.mime || 'application/octet-stream');
                                    request.upload.onprogress = function (event) {
                                        if (event.lengthComputable) { progress.value = Math.round(((index + event.loaded / event.total) / uploads.length) * 100); }
                                    };
                                    request.onload = function () { if (request.status < 200 || request.status >= 300) { reject(new Error('No se pudo guardar ' + upload.name)); return; } completed.push({token: upload.token}); resolve(); };
                                    request.onerror = function () { reject(new Error('Se interrumpió la carga de ' + upload.name)); };
                                    request.send(files[index]);
                                });
                            });
                        }, Promise.resolve()).then(function () { return completed; });
                    })
                    .then(function (claims) { claimsInput.value = JSON.stringify(claims); filesInput.dataset.uploadedFingerprint = fingerprint(files); progress.value = 100; status(claims.length + ' archivo(s) cargado(s) para este producto.'); })
                    .catch(function (error) { claimsInput.value = '[]'; progress.hidden = true; status(error.message || 'No se pudo completar la carga.'); })
                    .then(function () { uploading = false; uploadButton.disabled = false; update(); });
            });
            calculator.addEventListener('submit', function (event) {
                var files = Array.prototype.slice.call(filesInput.files || []);
                if (uploading || (files.length && filesInput.dataset.uploadedFingerprint !== fingerprint(files))) {
                    event.preventDefault();
                    status(uploading ? 'Esperá a que termine la carga.' : 'Subí los archivos antes de agregar al carrito.');
                }
            });
        }
        update();
    });
}());

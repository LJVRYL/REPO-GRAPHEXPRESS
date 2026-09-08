(function () {
    'use strict';
    function request(action, data) {
        var body = new URLSearchParams(Object.assign({ action: action, nonce: geCanva.nonce }, data || {}));
        return fetch(geCanva.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() }).then(function (response) { return response.json(); }).then(function (json) { if (!json.success) { throw new Error(json.data && json.data.message ? json.data.message : 'No se pudo completar la operación.'); } return json.data; });
    }
    function field(panel, name) { return panel.closest('form').querySelector('input[name="' + name + '"]'); }
    function setStatus(panel, message, error) { var node = panel.querySelector('[data-canva-status]'); if (!node) return; node.textContent = message; node.classList.toggle('is-error', !!error); }
    document.addEventListener('click', function (event) {
        var create = event.target.closest('[data-canva-create]');
        if (create) {
            var panel = create.closest('.ge-canva-library'), width = panel.querySelector('[data-canva-width]').value, height = panel.querySelector('[data-canva-height]').value, unit = panel.querySelector('[data-canva-unit]').value, titleField = panel.closest('form').querySelector('input[name="artwork_name"]');
            if (!width || !height) { setStatus(panel, 'Ingresá el ancho y el alto.', true); return; }
            create.disabled = true; setStatus(panel, 'Creando el documento en Canva…');
            request('ge_canva_create_design', { width: width, height: height, unit: unit, title: titleField && titleField.value ? titleField.value : 'Diseño Graph Express' }).then(function (data) {
                ['id', 'title', 'edit_url', 'view_url', 'physical_width', 'physical_height', 'unit', 'pixel_width', 'pixel_height'].forEach(function (key) { var input = field(panel, 'canva_' + (key === 'id' ? 'design_id' : key)); if (input) input.value = data[key] || ''; });
                var current = panel.querySelector('[data-canva-current]'); current.classList.remove('is-empty'); current.innerHTML = '<span><strong></strong><small></small></span><a target="_blank" rel="noopener">Editar en Canva ↗</a>';
                current.querySelector('strong').textContent = data.title; current.querySelector('small').textContent = 'ID: ' + data.id; current.querySelector('a').href = data.edit_url;
                setStatus(panel, 'Diseño creado. Guardá la ficha para conservar el vínculo y abrí Canva para editarlo.');
                if (data.edit_url) window.open(data.edit_url, '_blank', 'noopener');
            }).catch(function (error) { setStatus(panel, error.message, true); }).finally(function () { create.disabled = false; });
            return;
        }
        var exportButton = event.target.closest('[data-canva-export]');
        if (exportButton) {
            var exportPanel = exportButton.closest('.ge-canva-library'), artworkId = exportPanel.dataset.artworkId; exportButton.disabled = true; setStatus(exportPanel, 'Preparando el PDF en Canva…');
            request('ge_canva_export_start', { artwork_id: artworkId }).then(function (data) {
                var attempts = 0; function poll() { attempts++; request('ge_canva_export_status', { artwork_id: artworkId, job_id: data.job_id }).then(function (result) { if (result.status === 'success') { setStatus(exportPanel, result.message); setTimeout(function () { window.location.reload(); }, 900); } else if (attempts < 30) { setStatus(exportPanel, 'Canva está generando el PDF…'); setTimeout(poll, 1500); } else { throw new Error('La exportación está demorando. Intentá nuevamente en unos minutos.'); } }).catch(function (error) { setStatus(exportPanel, error.message, true); exportButton.disabled = false; }); } poll();
            }).catch(function (error) { setStatus(exportPanel, error.message, true); exportButton.disabled = false; });
        }
    });
}());

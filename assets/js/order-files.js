(function () {
    'use strict';
    if (!window.geOrderUpload) { return; }
    document.querySelectorAll('[data-ge-order-upload="1"]').forEach(function (form) {
        var input = form.querySelector('input[type="file"]');
        var claims = form.querySelector('[name="ge_vps_uploads"]');
        var button = form.querySelector('button[type="submit"]');
        var progress = form.querySelector('progress');
        var status = form.querySelector('[role="status"]');
        var busy = false;
        form.addEventListener('submit', function (event) {
            if (busy || !input.files.length) { event.preventDefault(); return; }
            event.preventDefault();
            var file = input.files[0];
            if (file.size < 1 || file.size > Number(geOrderUpload.maxFileBytes)) {
                status.textContent = 'El archivo debe tener hasta 250 MB.';
                return;
            }
            busy = true; button.disabled = true; progress.hidden = false; progress.value = 0;
            status.textContent = 'Preparando la carga privada…';
            var body = new FormData();
            body.append('action', geOrderUpload.action);
            body.append('nonce', geOrderUpload.nonce);
            body.append('files', JSON.stringify([{ name: file.name, size: file.size, type: file.type || 'application/octet-stream' }]));
            fetch(geOrderUpload.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (response) { return response.json().then(function (data) {
                    if (!response.ok || !data.success || !data.data.uploads.length) { throw new Error(data.data && data.data.message ? data.data.message : 'No se pudo preparar la carga.'); }
                    return data.data.uploads[0];
                }); })
                .then(function (upload) { return new Promise(function (resolve, reject) {
                    var request = new XMLHttpRequest();
                    request.open('PUT', upload.url, true);
                    request.withCredentials = true;
                    request.setRequestHeader('Content-Type', upload.mime || 'application/octet-stream');
                    request.upload.onprogress = function (e) {
                        if (e.lengthComputable) { progress.value = Math.round(100 * e.loaded / e.total); status.textContent = 'Subiendo… ' + progress.value + '%'; }
                    };
                    request.onload = function () { request.status >= 200 && request.status < 300 ? resolve(upload) : reject(new Error('No se pudo guardar el archivo.')); };
                    request.onerror = function () { reject(new Error('Se interrumpió la carga.')); };
                    request.send(file);
                }); })
                .then(function (upload) {
                    claims.value = JSON.stringify([{ token: upload.token }]);
                    input.disabled = true;
                    status.textContent = 'Archivo cargado. Vinculando al pedido…';
                    form.submit();
                })
                .catch(function (error) { status.textContent = error.message || 'No se pudo cargar el archivo.'; busy = false; button.disabled = false; progress.hidden = true; });
        });
    });
}());

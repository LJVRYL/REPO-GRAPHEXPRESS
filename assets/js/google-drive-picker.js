(function () {
    'use strict';

    var accessToken = null;
    var pickerReady = false;
    var tokenClient = null;
    var pendingPickerButton = null;

    function status(button, message, isError) {
        var form = button.closest('form');
        var box = form ? form.querySelector('[data-ge-drive-status]') : button.parentNode.querySelector('[data-ge-drive-status]');
        if (!box) {
            return;
        }
        box.textContent = message;
        box.classList.toggle('is-error', !!isError);
    }

    function field(form, name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function setField(form, name, value) {
        var input = field(form, name);
        if (input) {
            input.value = value || '';
        }
    }

    function shareWithGraphExpress(fileId) {
        if (!geGoogleDrive.shareEmail || !accessToken) {
            return Promise.resolve(false);
        }
        return fetch('https://www.googleapis.com/drive/v3/files/' + encodeURIComponent(fileId) + '/permissions?sendNotificationEmail=false', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + accessToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ role: 'reader', type: 'user', emailAddress: geGoogleDrive.shareEmail })
        }).then(function (response) { return response.ok; }).catch(function () { return false; });
    }

    function fillSelection(button, documentData) {
        var form = button.closest('form');
        var fileId = documentData[google.picker.Document.ID] || '';
        var name = documentData[google.picker.Document.NAME] || '';
        var mime = documentData[google.picker.Document.MIME_TYPE] || '';
        var url = documentData[google.picker.Document.URL] || ('https://drive.google.com/open?id=' + encodeURIComponent(fileId));
        var size = documentData[google.picker.Document.SIZE_BYTES] || '';

        setField(form, 'drive_file_id', fileId);
        setField(form, 'drive_file_name', name);
        setField(form, 'drive_mime_type', mime);
        setField(form, 'drive_file_url', url);
        setField(form, 'drive_file_size', size);
        setField(form, 'drive_file_source', 'picker');
        setField(form, 'original_name', name);
        setField(form, 'external_reference', url);
        setField(form, 'storage_provider', 'drive');
        if (field(form, 'artwork_name') && !field(form, 'artwork_name').value) { field(form, 'artwork_name').value = name; }
        var save = form.querySelector('[data-ge-drive-save]');
        if (save) { save.disabled = false; }
        status(button, 'Compartiendo “' + name + '” con Graph Express…', false);
        shareWithGraphExpress(fileId).then(function (shared) {
            status(button, shared ? 'Seleccionado y compartido: ' + name + '. Ya podés guardarlo en tu portal.' : 'Seleccionado: ' + name + '. No pudimos compartirlo automáticamente; revisá que Graph Express tenga acceso antes de producir.', !shared);
        });
    }

    function showPicker(button) {
        var view = new google.picker.DocsView()
            .setIncludeFolders(false)
            .setMode(google.picker.DocsViewMode.LIST);
        var picker = new google.picker.PickerBuilder()
            .addView(view)
            .setOAuthToken(accessToken)
            .setDeveloperKey(geGoogleDrive.apiKey)
            .setAppId(geGoogleDrive.appId)
            .setOrigin(window.location.protocol + '//' + window.location.host)
            .setTitle('Elegí el original de producción')
            .setCallback(function (data) {
                if (data.action === google.picker.Action.PICKED && data.docs && data.docs[0]) {
                    fillSelection(button, data.docs[0]);
                } else if (data.action === google.picker.Action.CANCEL) {
                    status(button, 'No se seleccionó ningún archivo.', false);
                }
            })
            .build();
        picker.setVisible(true);
    }

    function requestToken(button) {
        if (!window.google || !google.accounts || !google.accounts.oauth2) {
            status(button, 'Google todavía no terminó de cargar. Intentá nuevamente.', true);
            return;
        }
        pendingPickerButton = button;
        if (!tokenClient) {
            tokenClient = google.accounts.oauth2.initTokenClient({
                client_id: geGoogleDrive.clientId,
                scope: geGoogleDrive.scope,
                include_granted_scopes: true,
                callback: function (response) {
                    if (!response || response.error || !response.access_token) {
                        status(pendingPickerButton, 'No se pudo autorizar el acceso a Google Drive.', true);
                        return;
                    }
                    accessToken = response.access_token;
                    showPicker(pendingPickerButton);
                }
            });
        }
        tokenClient.requestAccessToken({ prompt: accessToken ? '' : 'consent' });
    }

    function open(button) {
        status(button, 'Conectando con Google Drive…', false);
        if (pickerReady) {
            requestToken(button);
            return;
        }
        if (!window.gapi || !gapi.load) {
            status(button, 'Google Picker todavía no terminó de cargar. Intentá nuevamente.', true);
            return;
        }
        gapi.load('picker', {
            callback: function () {
                pickerReady = true;
                requestToken(button);
            },
            onerror: function () {
                status(button, 'No se pudo cargar el selector de Google Drive.', true);
            }
        });
    }

    function mount() {
        document.querySelectorAll('.ge-library-form').forEach(function (form) {
            if (form.querySelector('[data-ge-drive-picker]')) {
                return;
            }
            var upload = form.querySelector('.ge-original-upload');
            if (!upload) {
                return;
            }
            var panel = document.createElement('div');
            panel.className = 'ge-drive-picker-panel is-wide';
            panel.innerHTML = '<div><b>DR</b><span><strong>¿El original ya está en Google Drive?</strong><small>Guardamos el vínculo seguro y evitamos duplicar un archivo pesado en el VPS.</small></span></div>' +
                '<button type="button" data-ge-drive-picker>Elegir desde Google Drive</button>' +
                '<p data-ge-drive-status>No se seleccionó ningún archivo de Drive.</p>' +
                '<input type="hidden" name="drive_file_id" value="">' +
                '<input type="hidden" name="drive_file_name" value="">' +
                '<input type="hidden" name="drive_mime_type" value="">' +
                '<input type="hidden" name="drive_file_url" value="">' +
                '<input type="hidden" name="drive_file_size" value="">';
            upload.insertAdjacentElement('beforebegin', panel);
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ge-drive-picker]');
        if (!button) {
            return;
        }
        event.preventDefault();
        open(button);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
}());

(function () {
    'use strict';
    var tokenClient = null;
    var pendingButton = null;

    function formField(form, name) { return form.querySelector('[name="' + name + '"]'); }
    function setFormField(form, name, value) { var input = formField(form, name); if (input) { input.value = value || ''; } }
    function setStatus(button, message, error) { var box = button.closest('form').querySelector('[data-ge-drive-status]'); if (box) { box.textContent = message; box.classList.toggle('is-error', !!error); } }
    function authorized(button, callback) {
        pendingButton = button;
        if (!window.google || !google.accounts || !google.accounts.oauth2) { setStatus(button, 'Google todavía no terminó de cargar. Intentá nuevamente.', true); return; }
        if (!tokenClient) {
            tokenClient = google.accounts.oauth2.initTokenClient({
                client_id: geGoogleDrive.clientId,
                scope: geGoogleDrive.scope,
                include_granted_scopes: true,
                callback: function (response) {
                    if (!response || response.error || !response.access_token) { setStatus(pendingButton, 'No se pudo autorizar Google Drive.', true); return; }
                    var action = pendingButton._geDriveAction;
                    if (action) { action(response.access_token); }
                }
            });
        }
        button._geDriveAction = callback;
        tokenClient.requestAccessToken({ prompt: '' });
    }
    function api(token, url, options) {
        options = options || {}; options.headers = options.headers || {}; options.headers.Authorization = 'Bearer ' + token;
        return fetch(url, options).then(function (response) {
            if (!response.ok) { throw new Error('Google Drive respondió ' + response.status); }
            return response.status === 204 ? {} : response.json();
        });
    }
    function ensureFolder(token) {
        var query = encodeURIComponent("name='Graph Express' and mimeType='application/vnd.google-apps.folder' and trashed=false");
        return api(token, 'https://www.googleapis.com/drive/v3/files?q=' + query + '&spaces=drive&fields=files(id,name)&pageSize=1').then(function (result) {
            if (result.files && result.files[0]) { return result.files[0].id; }
            return api(token, 'https://www.googleapis.com/drive/v3/files?fields=id', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: 'Graph Express', mimeType: 'application/vnd.google-apps.folder' }) }).then(function (folder) { return folder.id; });
        });
    }
    function share(token, fileId) {
        if (!geGoogleDrive.shareEmail) { return Promise.resolve(false); }
        return fetch('https://www.googleapis.com/drive/v3/files/' + encodeURIComponent(fileId) + '/permissions?sendNotificationEmail=false', { method: 'POST', headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' }, body: JSON.stringify({ role: 'reader', type: 'user', emailAddress: geGoogleDrive.shareEmail }) }).then(function (response) { return response.ok; }).catch(function () { return false; });
    }
    function upload(button, token, file) {
        var form = button.closest('form'); var progress = form.querySelector('[data-ge-drive-progress]');
        setStatus(button, 'Preparando la carpeta Graph Express en tu Drive…', false);
        ensureFolder(token).then(function (folderId) {
            return fetch('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,mimeType,size,webViewLink', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json; charset=UTF-8', 'X-Upload-Content-Type': file.type || 'application/octet-stream', 'X-Upload-Content-Length': String(file.size) },
                body: JSON.stringify({ name: file.name, parents: [folderId] })
            });
        }).then(function (response) {
            if (!response.ok || !response.headers.get('Location')) { throw new Error('No se pudo iniciar la carga.'); }
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest(); xhr.open('PUT', response.headers.get('Location')); xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                xhr.upload.onprogress = function (event) { if (event.lengthComputable && progress) { progress.hidden = false; progress.value = Math.round(event.loaded * 100 / event.total); setStatus(button, 'Subiendo a tu Drive: ' + progress.value + '%', false); } };
                xhr.onload = function () { if (xhr.status >= 200 && xhr.status < 300) { try { resolve(JSON.parse(xhr.responseText)); } catch (error) { reject(error); } } else { reject(new Error('La carga falló.')); } };
                xhr.onerror = function () { reject(new Error('Se interrumpió la carga.')); }; xhr.send(file);
            });
        }).then(function (uploaded) {
            return share(token, uploaded.id).then(function (shared) { return { file: uploaded, shared: shared }; });
        }).then(function (result) {
            var uploaded = result.file; setFormField(form, 'drive_file_id', uploaded.id); setFormField(form, 'drive_file_name', uploaded.name || file.name); setFormField(form, 'drive_mime_type', uploaded.mimeType || file.type); setFormField(form, 'drive_file_url', uploaded.webViewLink || 'https://drive.google.com/open?id=' + encodeURIComponent(uploaded.id)); setFormField(form, 'drive_file_size', uploaded.size || file.size); setFormField(form, 'drive_file_source', 'direct-upload');
            if (formField(form, 'artwork_name') && !formField(form, 'artwork_name').value) { formField(form, 'artwork_name').value = file.name; }
            var save = form.querySelector('[data-ge-drive-save]'); if (save) { save.disabled = false; }
            if (progress) { progress.value = 100; }
            setStatus(button, result.shared ? 'Archivo subido y compartido con Graph Express. Ya podés guardarlo en tu portal.' : 'Archivo subido. No pudimos compartirlo automáticamente; revisá el acceso antes de producir.', !result.shared);
        }).catch(function (error) { setStatus(button, error.message || 'No se pudo subir el archivo.', true); });
    }
    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-ge-drive-upload]')) { return; }
        var label = event.target.closest('label'); if (label) { label.classList.toggle('has-file', !!event.target.files.length); var span = label.querySelector('span'); if (span && event.target.files[0]) { span.textContent = event.target.files[0].name; } }
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ge-drive-upload-button]'); if (!button) { return; } event.preventDefault();
        var input = button.closest('form').querySelector('[data-ge-drive-upload]'); var file = input && input.files ? input.files[0] : null;
        if (!file) { setStatus(button, 'Elegí primero un archivo de tu computadora.', true); return; }
        authorized(button, function (token) { upload(button, token, file); });
    });
}());

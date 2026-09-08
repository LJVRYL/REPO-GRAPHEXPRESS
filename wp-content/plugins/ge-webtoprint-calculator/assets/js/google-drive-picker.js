(function () {
    'use strict';

    var accessToken = null;
    var pickerReady = false;
    var tokenClient = null;

    function status(button, message, isError) {
        var box = button.parentNode.querySelector('[data-ge-drive-status]');
        if (!box) {
            return;
        }
        box.textContent = message;
        box.classList.toggle('is-error', !!isError);
    }

    function field(form, name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function fillSelection(button, documentData) {
        var form = button.closest('form');
        var fileId = documentData[google.picker.Document.ID] || '';
        var name = documentData[google.picker.Document.NAME] || '';
        var mime = documentData[google.picker.Document.MIME_TYPE] || '';
        var url = documentData[google.picker.Document.URL] || ('https://drive.google.com/open?id=' + encodeURIComponent(fileId));
        var size = documentData[google.picker.Document.SIZE_BYTES] || '';

        field(form, 'drive_file_id').value = fileId;
        field(form, 'drive_file_name').value = name;
        field(form, 'drive_mime_type').value = mime;
        field(form, 'drive_file_url').value = url;
        field(form, 'drive_file_size').value = size;
        field(form, 'original_name').value = name;
        field(form, 'external_reference').value = url;
        field(form, 'storage_provider').value = 'drive';
        status(button, 'Seleccionado: ' + name + '. La ficha guardará el vínculo, no una copia en el VPS.', false);
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
        if (!tokenClient) {
            tokenClient = google.accounts.oauth2.initTokenClient({
                client_id: geGoogleDrive.clientId,
                scope: geGoogleDrive.scope,
                include_granted_scopes: true,
                callback: function (response) {
                    if (!response || response.error || !response.access_token) {
                        status(button, 'No se pudo autorizar el acceso a Google Drive.', true);
                        return;
                    }
                    accessToken = response.access_token;
                    showPicker(button);
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

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-ge-addresses]').forEach(function (section) {
        var list = section.querySelector('[data-ge-address-list]');
        var template = section.querySelector('[data-ge-address-template]');
        var add = section.querySelector('[data-ge-add-address]');

        function reindex() {
            var items = list.querySelectorAll('[data-ge-address-item]');
            items.forEach(function (item, index) {
                var number = item.querySelector('[data-ge-address-number]');
                if (number) { number.textContent = String(index + 1); }
                item.querySelectorAll('[name^="addresses["]').forEach(function (field) {
                    field.name = field.name.replace(/addresses\[[^\]]+\]/, 'addresses[' + index + ']');
                });
            });
            add.disabled = items.length >= 4;
            add.hidden = items.length >= 4;
        }

        add.addEventListener('click', function () {
            if (list.querySelectorAll('[data-ge-address-item]').length >= 4) { return; }
            list.appendChild(template.content.cloneNode(true));
            reindex();
            var items = list.querySelectorAll('[data-ge-address-item]');
            var last = items[items.length - 1];
            if (last) { last.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });

        list.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-ge-remove-address]');
            if (!remove) { return; }
            var item = remove.closest('[data-ge-address-item]');
            if (item && list.querySelectorAll('[data-ge-address-item]').length > 1) { item.remove(); reindex(); }
        });
        reindex();
    });

    document.querySelectorAll('input[name="profile_avatar"]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) { return; }
            var preview = input.closest('.ge-profile-photo').querySelector('.ge-profile-avatar');
            var image = preview.querySelector('img') || document.createElement('img');
            image.src = URL.createObjectURL(input.files[0]);
            image.alt = 'Vista previa de la foto';
            preview.replaceChildren(image);
        });
    });
});

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var control = document.querySelector('[data-ge-artwork-control]');
        if (!control) return;

        var dispatch = document.querySelector('.ge-dispatch-card');
        var sheet = document.querySelector('.ge-sheet-form');
        var anchor = dispatch || sheet;
        if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(control, anchor);

        control.querySelectorAll('input[type="checkbox"][name*="[sources]"]').forEach(function (source) {
            source.addEventListener('change', function () {
                var card = source.closest('article');
                if (!card) return;
                card.classList.add('has-unsaved-artwork-change');
                card.querySelectorAll('input[name*="[customer_approved]"], input[name*="[staff_approved]"]').forEach(function (approval) {
                    approval.checked = false;
                });
            });
        });
    });
}());

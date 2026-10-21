(function () {
    'use strict';
    function normalize(lamination, finish) {
        if (!lamination || !finish) return false;
        var none = lamination.value === 'sin-laminar';
        var options = Array.prototype.slice.call(finish.options);
        if (!options.some(function (option) { return option.value === 'no-aplica'; })) return false;
        options.forEach(function (option) {
            var available = option.value === 'no-aplica' ? none : !none;
            option.disabled = !available; option.hidden = !available;
        });
        var current = options.find(function (option) { return option.value === finish.value; });
        if (current && !current.disabled) return false;
        var next = options.find(function (option) { return !option.disabled; });
        if (!next) return false;
        finish.value = next.value;
        return true;
    }
    if (typeof module === 'object' && module.exports) module.exports = { normalize: normalize };
    if (typeof document === 'undefined') return;

    function pair(input) {
        if (!input || typeof input.closest !== 'function') return null;
        var form = input.closest('form'); if (!form) return null;
        if (input.dataset.geField === 'laminado_caras') {
            var product = form.querySelector('[name="product_id"]');
            if (!product || product.value !== '83') return null;
            return { lamination: input, finish: form.querySelector('[data-ge-field="laminado_acabado"]'), form: form, public: true };
        }
        var match = (input.name || '').match(/^lines\[(\d+)\]\[configuration\]\[laminado_caras\]$/);
        if (!match) return null;
        var prefix = 'lines[' + match[1] + ']';
        var id = form.elements.namedItem(prefix + '[product_id]');
        if (!id || id.value !== '83') return null;
        return { lamination: input, finish: form.elements.namedItem(prefix + '[configuration][laminado_acabado]'), form: form, public: false };
    }
    function pendingMessage(value) {
        if (!value.public || value.lamination.value !== 'sin-laminar') return;
        var config;
        try { config = JSON.parse(value.form.dataset.config || '{}'); } catch (error) { return; }
        if (config.pricing_model) return;
        var key = (config.fields || []).filter(function (field) { return field.type !== 'checkbox'; }).map(function (field) {
            var control = value.form.querySelector('[data-ge-field="' + field.key + '"]');
            return field.key + '=' + (control ? control.value : '');
        }).join('|');
        if (Number((config.prices || {})[key] || 0) > 0) return;
        var messages = [['[data-ge-total]', 'A cotizar'], ['[data-ge-unit]', 'Te confirmamos el importe para esta configuración.'], ['[data-ge-price-state]', 'Impresión sin laminar'], ['[data-ge-warning]', 'La opción sin laminar está disponible. Consultanos el precio final.']];
        messages.forEach(function (entry) { var node = value.form.querySelector(entry[0]); if (node && node.textContent !== entry[1]) node.textContent = entry[1]; });
    }
    function apply(input, notify) {
        var value = pair(input); if (!value) return;
        var changed = normalize(value.lamination, value.finish);
        if (changed && notify) value.finish.dispatchEvent(new Event('change', { bubbles: true }));
        // Existing calculators run on the original change event. Update copy only after those synchronous handlers.
        Promise.resolve().then(function () { pendingMessage(value); });
    }
    document.addEventListener('change', function (event) { apply(event.target, false); }, true);
    document.addEventListener('input', function (event) { apply(event.target, false); }, true);
    function refresh() {
        document.querySelectorAll('[data-ge-field="laminado_caras"], select[name$="[configuration][laminado_caras]"]').forEach(function (input) { apply(input, true); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refresh); else refresh();
    var observer = new MutationObserver(function (records) {
        if (records.some(function (record) { return record.addedNodes.length > 0; })) refresh();
    });
    if (document.body) observer.observe(document.body, { childList: true, subtree: true });
}());

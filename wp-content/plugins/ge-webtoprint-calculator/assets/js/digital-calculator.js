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
        var file = calculator.querySelector('[data-ge-file]');
        var fileName = calculator.querySelector('[data-ge-file-name]');

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
            var rates = (model.supplier_unit_rates || {})[key];
            if (!Array.isArray(rates) || !rates.length) { return 0; }
            var tier = 0;
            (model.breaks || []).forEach(function (minimum, index) { if (quantity >= Number(minimum)) { tier = index; } });
            var supplierPrint = Number(rates[Math.min(tier, rates.length - 1)] || 0) * quantity;
            var commercialRate = 0;
            (model.commercial_markups || []).some(function (range) {
                if (quantity >= Number(range.min) && quantity <= Number(range.max)) { commercialRate = Number(range.rate || 0); return true; }
                return false;
            });
            var printTotal = supplierPrint * (1 + commercialRate);
            var laminationTotal = 0;
            var lamination = model.lamination || {};
            var selectedLamination = current[lamination.field || 'laminado'];
            var laminablePapers = Array.isArray(lamination.papers) ? lamination.papers : [lamination.paper];
            if (laminablePapers.indexOf(current.papel) !== -1 && selectedLamination && selectedLamination !== 'sin-laminar') {
                var sides = Number((lamination.sides || {})[selectedLamination] || 0);
                var supplierUnit = Number((lamination.supplier_unit_per_side || {})[current.tamano] || 0);
                laminationTotal = supplierUnit * sides * quantity * (1 + Number(lamination.markup || 0));
            }
            return { print: printTotal, lamination: laminationTotal, total: printTotal + laminationTotal, commercialRate: commercialRate };
        }

        function priceKey(current) {
            return (config.fields || []).filter(function (field) { return field.type !== 'checkbox'; }).map(function (field) { return field.key + '=' + current[field.key]; }).join('|');
        }

        function update() {
            var current = values();
            applyDependencies(current);
            current = values();
            var base = Number((config.prices || {})[priceKey(current)] || 0);
            var surcharge = controls.reduce(function (sum, control) { return sum + (control.type === 'checkbox' && control.checked ? Number(control.dataset.surcharge || 0) : 0); }, 0);
            var quantity = Math.max(1, Number(current.cantidad || 1));
            var tiered = tieredPrice(current, quantity);
            var labels = controls.filter(function (control) { return control.type !== 'checkbox' || control.checked; }).map(function (control) {
                var labelNode = control.type === 'checkbox' ? control.closest('label').querySelector('strong') : control.closest('label').querySelector('span');
                var label = labelNode.textContent.trim();
                var selected = control.type === 'checkbox' ? 'Sí' : (control.options ? control.options[control.selectedIndex].text : control.value);
                return label + ': ' + selected;
            });

            if (tiered && tiered.total > 0) {
                var calculated = Math.round(tiered.print * (1 + surcharge) + tiered.lamination);
                total.textContent = money(calculated) + ' + IVA';
                unit.textContent = 'Precio por pliego: ' + money(calculated / quantity);
                tax.textContent = money(calculated * 0.21);
                state.textContent = 'Precio final sin IVA';
                warning.textContent = 'Incluye el margen comercial por cantidad' + (tiered.lamination > 0 ? ' y el laminado seleccionado.' : '.');
            } else if (base > 0) {
                var calculated = Math.round(base * (1 + surcharge));
                total.textContent = money(calculated) + ' + IVA';
                unit.textContent = 'Precio unitario: ' + money(calculated / quantity);
                tax.textContent = money(calculated * 0.21);
                state.textContent = surcharge > 0 ? 'Referencia con recargo aplicado' : 'Referencia provisoria';
                warning.textContent = 'Este valor todavía no incluye el margen comercial de Graph Express.';
            } else {
                total.textContent = 'Precio a confirmar';
                unit.textContent = 'La combinación ya quedó preparada para cargar su valor.';
                tax.textContent = 'Pendiente';
                state.textContent = 'Matriz incompleta';
                warning.textContent = 'No inventamos un precio para esta combinación: se completará al actualizar la lista.';
            }

            var message = 'Hola Graph Express, quiero cotizar ' + config.name + '.\n' + labels.join('\n');
            if (file && file.files.length) { message += '\nArchivo seleccionado: ' + file.files[0].name; }
            submit.href = 'https://wa.me/5491151393899?text=' + encodeURIComponent(message);
        }

        controls.forEach(function (control) { control.addEventListener('change', update); control.addEventListener('input', update); });
        if (file) { file.addEventListener('change', function () { fileName.textContent = file.files.length ? 'Archivo seleccionado: ' + file.files[0].name : 'Ningún archivo seleccionado.'; update(); }); }
        update();
    });
}());

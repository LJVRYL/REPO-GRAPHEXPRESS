import assert from 'node:assert/strict';
import test from 'node:test';
import { compare, inspectProduct, scanCatalog } from '../ops/elementi-catalog-check.mjs';

const black = { attribute_pa_color: 'negro', attribute_pa_personalizacion: 'sin-personalizacion' };
const white = { ...black, attribute_pa_color: 'blanco' };
const product = { key: 'vaso-de-prueba', name: 'Vaso de prueba', options: [{ attributes: black }, { attributes: white }] };
const row = { attributes: black, sku: 'VASO-PRUEBA-N', is_purchasable: true, is_in_stock: true, display_price: 1200, max_qty: 999 };

function page(variations) {
    const encoded = JSON.stringify(variations).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll("'", '&#39;');
    return `<form class="variations_form cart" data-product_variations="${encoded}"></form>`;
}

test('takes explicit stock flags and public prices only for the corresponding option', () => {
    const result = inspectProduct(product, page([row, { ...row, attributes: white, is_in_stock: false, display_price: 1400 }]));
    assert.deepEqual(result.map(({ availability }) => availability), ['orderable', 'out_of_stock']);
    assert.deepEqual(result.map(({ publicUnitPrice }) => publicUnitPrice), [1200, 1400]);
    assert.equal(Object.hasOwn(result[0], 'quantity'), false);
});

test('keeps missing and ambiguous matches unresolved', () => {
    const unresolved = inspectProduct(product, page(false));
    assert.deepEqual(unresolved.map(({ availability }) => availability), ['unknown', 'unknown']);
    const unspecifiedColor = { ...row, attributes: { ...black, attribute_pa_color: '' }, backorders_allowed: true };
    const ambiguous = inspectProduct(product, page([row, unspecifiedColor]));
    assert.equal(ambiguous[0].availability, 'unknown');
    assert.equal(ambiguous[1].availability, 'orderable_backorders_allowed');
});

test('reports observed changes and keeps fetch failures distinct from stock', async () => {
    const pause = async () => {};
    const previous = await scanCatalog([product], { read: async () => page([row]), pause });
    const current = await scanCatalog([product], {
        read: async () => page([{ ...row, is_in_stock: false, display_price: 1250 }]), pause,
    });
    assert.deepEqual(compare(previous, current), [{
        key: product.key, attributes: black, availability: ['orderable', 'out_of_stock'], publicUnitPrice: [1200, 1250],
    }]);

    const failed = await scanCatalog([product], { read: async () => { throw new Error('http-503'); }, pause });
    assert.deepEqual(compare(current, failed), [{ key: product.key, observation: ['ok', 'failed'] }]);
    assert.deepEqual(failed.products[0].variants, []);
    assert.deepEqual(compare(failed, failed), []);
});

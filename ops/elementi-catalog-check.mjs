import { readFile } from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';
import { pathToFileURL } from 'node:url';

const catalogFile = new URL('../wp-content/plugins/ge-webtoprint-calculator/data/marketing-catalog.json', import.meta.url);
const source = 'elementi-public-storefront';
const maxPageBytes = 4_000_000;

function decodeAttribute(value) {
    const entities = { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>' };
    return value.replace(/&(#x[\da-f]+|#\d+|amp|quot|apos|lt|gt);/gi, (entity, code) => {
        const key = code.toLowerCase();
        if (key[0] !== '#') return entities[key];
        const point = key.startsWith('#x') ? parseInt(key.slice(2), 16) : Number(key.slice(1));
        return point <= 0x10ffff ? String.fromCodePoint(point) : entity;
    });
}

function publicVariants(html) {
    const data = html.match(/<form\b[^>]*?\sdata-product_variations\s*=\s*(["'])(.*?)\1/is);
    if (!data) return [];
    const rows = JSON.parse(decodeAttribute(data[2]));
    if (rows === false) return [];
    if (!Array.isArray(rows)) throw new Error('unexpected-variation-data');
    return rows.filter(row => row && row.attributes && typeof row.attributes === 'object');
}

function attributesKey(attributes) {
    return JSON.stringify(Object.entries(attributes).sort(([a], [b]) => a.localeCompare(b)));
}

function matchVariant(attributes, variants) {
    const entries = Object.entries(attributes);
    if (!entries.length) return null;
    const matches = variants.filter(({ attributes: candidate }) =>
        Object.keys(candidate).length === entries.length && entries.every(([key, value]) =>
            Object.hasOwn(candidate, key) && (candidate[key] === value || candidate[key] === '')
        )
    );
    return matches.length === 1 ? matches[0] : null;
}

export function inspectProduct(product, html) {
    const variants = publicVariants(html);
    return product.options.map(({ attributes = {} }) => {
        const row = matchVariant(attributes, variants);
        let availability = 'unknown';
        if (row?.is_in_stock === false) {
            availability = 'out_of_stock';
        } else if (row?.is_in_stock === true && row.is_purchasable === true && row.variation_is_active !== false) {
            availability = row.backorders_allowed === true ? 'orderable_backorders_allowed' : 'orderable';
        }
        const amount = row?.display_price;
        return {
            attributes,
            supplierSku: typeof row?.sku === 'string' && row.sku ? row.sku : null,
            availability,
            publicUnitPrice: Number.isFinite(amount) && amount > 0 ? amount : null,
        };
    });
}

export function compare(previous, current) {
    if (previous.source !== source || current.source !== source || !Array.isArray(previous.products)) {
        throw new Error('invalid-previous-report');
    }
    const priorProducts = new Map(previous.products.map(product => [product.key, product]));
    const changes = [];
    for (const product of current.products) {
        const prior = priorProducts.get(product.key);
        if (!prior) continue;
        if (product.observation !== prior.observation) {
            changes.push({ key: product.key, observation: [prior.observation, product.observation] });
        }
        const older = new Map(prior.variants.map(variant => [attributesKey(variant.attributes), variant]));
        for (const variant of product.variants) {
            const last = older.get(attributesKey(variant.attributes));
            if (!last) continue;
            const entry = { key: product.key, attributes: variant.attributes };
            if (last.availability !== variant.availability) {
                entry.availability = [last.availability, variant.availability];
            }
            if (last.publicUnitPrice !== null && variant.publicUnitPrice !== null && last.publicUnitPrice !== variant.publicUnitPrice) {
                entry.publicUnitPrice = [last.publicUnitPrice, variant.publicUnitPrice];
            }
            if (entry.availability || entry.publicUnitPrice) changes.push(entry);
        }
    }
    return changes;
}

async function readPage(url) {
    const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(15_000) });
    if (!response.ok) throw new Error(`http-${response.status}`);
    if (!response.headers.get('content-type')?.includes('text/html')) throw new Error('unexpected-content-type');

    const decoder = new TextDecoder();
    let bytes = 0;
    let html = '';
    for await (const chunk of response.body) {
        bytes += chunk.byteLength;
        if (bytes > maxPageBytes) throw new Error('page-too-large');
        html += decoder.decode(chunk, { stream: true });
    }
    return html + decoder.decode();
}

export async function scanCatalog(catalog, { read = readPage, pause = () => delay(1_000) } = {}) {
    const products = [];
    for (const product of catalog) {
        const { key, name } = product;
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(key) || !Array.isArray(product.options)) {
            throw new Error('invalid-catalog');
        }
        const url = `https://www.elementi.com.ar/producto/${key}/`;
        const result = { key, name, url, observation: 'ok', variants: [] };
        try {
            result.variants = inspectProduct(product, await read(url));
            if (result.variants.every(row => row.availability === 'unknown' && row.publicUnitPrice === null)) {
                result.observation = 'unresolved';
            }
        } catch (error) {
            result.observation = 'failed';
            result.error = error.message;
        }
        products.push(result);
        if (products.length < catalog.length) await pause();
    }
    return { source, checkedAt: new Date().toISOString(), products };
}

async function main(args) {
    const { values } = (await import('node:util')).parseArgs({ args, options: {
        previous: { type: 'string' }, product: { type: 'string', multiple: true },
    } });
    const catalog = JSON.parse(await readFile(catalogFile, 'utf8'));
    const selected = values.product ? catalog.filter(product => values.product.includes(product.key)) : catalog;
    if (!selected.length || (values.product && selected.length !== new Set(values.product).size)) {
        throw new Error('unknown-product');
    }
    const previous = values.previous && JSON.parse(await readFile(values.previous, 'utf8'));
    const report = await scanCatalog(selected);
    if (previous) report.changes = compare(previous, report);
    console.log(JSON.stringify(report, null, 2));
    if (report.products.every(product => product.observation !== 'ok')) process.exitCode = 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main(process.argv.slice(2)).catch(error => { console.error(error.message); process.exitCode = 1; });
}

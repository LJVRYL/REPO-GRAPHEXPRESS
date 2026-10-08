# Graphex Meta catalog feed

Public CSV: `https://graphex.ar/wp-json/ge/v1/commerce-feed.csv`.

The MU plugin reads visible, published WooCommerce products on each fetch. SKU is the stable content ID. It publishes the default configured commercial presentation with its full minimum quantity, final price including 21% VAT, public description, image, category and storefront URL. Digital prices use the existing commercial quote calculation. Vinyl bills the full roll width through the existing pricing function. Estimated or quote-only products and products without images are excluded rather than priced at zero.

WebP attachments receive separate JPEG derivatives under uploads/graphex-meta, keyed by source hash. Originals are preserved. No customers, orders, private originals, costs or margins are exported. Feed GET does not create carts, quotes, orders or payments. JPEG derivatives are the only generated files.

Meta scheduled fetch: hourly, ARS. The selected WhatsApp catalog must be 1125151343414484, owned by Graphex 1811159106704793. Page connection alone does not prove catalog connection.

Validation: `php tests/meta-commerce-feed.php`; preview against live catalog and validate CSV fields, positive ARS prices, IDs, image formats and exclusions. Deploy only wp-content/mu-plugins/ge-meta-commerce-feed.php from the canonical commit.

Rollback: disable scheduled fetching in Meta and move the new MU plugin to the private release backup (do not restore the whole WordPress tree). Keep generated public derivatives for existing catalog image links. Original site products and prices are not modified. Backup: /root/ge-backups/meta-catalog-20261008; tar read/list/gzip and SHA-256 checked.

<?php
/**
 * Tarjeta pública de producto para el catálogo Graph Express.
 */

defined('ABSPATH') || exit;

global $product;

if (! is_a($product, 'WC_Product') || ! $product->is_visible()) {
    return;
}

$terms = get_the_terms($product->get_id(), 'product_cat');
$group = null;
if ($terms && ! is_wp_error($terms)) {
    foreach ($terms as $term) {
        if ($term->parent) {
            $group = $term;
            break;
        }
    }
}

$group_slug = $group ? $group->slug : 'general';
$group_name = $group ? $group->name : 'Gran formato';
$symbols = array(
    'lonas-gigantografias' => '↗',
    'vinilos'               => '◇',
    'soportes-especiales'   => '✦',
    'portabanners'          => '▥',
    'placas-rigidas'        => '□',
);
$symbol = isset($symbols[$group_slug]) ? $symbols[$group_slug] : 'GE';
$attributes = array_slice($product->get_attributes(), 0, 2);
$reference_price = $product->get_meta('_ge_reference_price_min');
$show_reference_price = 'yes' === $product->get_meta('_ge_show_reference_price') && is_numeric($reference_price);
$action_label = $product->get_meta('_ge_digital_config') ? 'Configurar producto' : 'Ver formatos';
?>
<li <?php wc_product_class('gx-product-tile gx-product-group-' . sanitize_html_class($group_slug), $product); ?>>
    <a class="gx-product-card-link" href="<?php the_permalink(); ?>">
        <div class="gx-product-card-visual">
            <?php if ($product->get_image_id()) : ?>
                <?php echo wp_kses_post($product->get_image('woocommerce_thumbnail')); ?>
            <?php else : ?>
                <span><?php echo esc_html($symbol); ?></span>
                <small><?php echo esc_html($group_name); ?></small>
            <?php endif; ?>
        </div>
        <div class="gx-product-card-body">
            <span class="gx-product-card-group"><?php echo esc_html($group_name); ?></span>
            <h2><?php the_title(); ?></h2>
            <p><?php echo esc_html(wp_trim_words($product->get_short_description(), 16)); ?></p>
            <?php if ($show_reference_price) : ?>
                <p class="gx-product-reference-price">
                    <span>Desde</span>
                    <strong><?php echo wp_kses_post(wc_price((float) $reference_price, array('decimals' => 0))); ?></strong>
                    <small>+ IVA</small>
                </p>
            <?php endif; ?>
            <?php if ($attributes) : ?>
                <div class="gx-product-card-specs">
                    <?php foreach ($attributes as $attribute) : ?>
                        <span><?php echo esc_html($attribute->get_name()); ?>: <b><?php echo esc_html(implode(' · ', array_slice($attribute->get_options(), 0, 3))); ?></b></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </a>
    <a class="gx-product-card-action" href="<?php the_permalink(); ?>"><?php echo esc_html($action_label); ?> <span>→</span></a>
</li>

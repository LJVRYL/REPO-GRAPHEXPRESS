<?php

defined('ABSPATH') || exit;

/**
 * Convierte las listas internas del catálogo en configuraciones comprables.
 * El navegador nunca define el precio: cada alta al carrito se recalcula aquí.
 */
final class GE_WTP_Storefront {
    const ACTION = 'ge_add_configured_product';

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'render'), 29);
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'add_to_cart'));
        add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'add_to_cart'));
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'purchasable'), 9999, 2);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'admin_preview_validation'), 9999, 5);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_cart_prices'));
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item_data'), 10, 4);
        add_action('pre_get_posts', array(__CLASS__, 'order_catalog'));
    }

    public static function assets() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        global $post;
        $config = $post ? self::config((int) $post->ID) : array();
        if (!$config) {
            return;
        }
        wp_enqueue_style('ge-storefront', GE_WTP_PLUGIN_URL . 'assets/css/storefront.css', array(), GE_WTP_VERSION);
        wp_enqueue_script('ge-storefront', GE_WTP_PLUGIN_URL . 'assets/js/storefront.js', array(), GE_WTP_VERSION, true);
    }

    public static function purchasable($purchasable, $product) {
        return self::config($product->get_id()) ? true : $purchasable;
    }

    public static function admin_preview_validation($valid, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        if (current_user_can('manage_woocommerce') && self::config($product_id)) {
            return true;
        }
        return $valid;
    }

    public static function render() {
        global $product;
        if (!$product) {
            return;
        }
        $config = self::config($product->get_id());
        if (!$config) {
            return;
        }
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        remove_action('woocommerce_single_product_summary', 'graphexpress_quote_only_product_cta', 31);
        $first = reset($config['options']);
        $mode = isset($config['mode']) ? $config['mode'] : '';
        $is_measure = !empty($mode);
        $saved_artworks = is_user_logged_in() && class_exists('GE_WTP_Artwork_Library')
            ? GE_WTP_Artwork_Library::get_items(get_current_user_id())
            : array();
        ?>
        <form class="ge-storefront-config" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ge-storefront data-options="<?php echo esc_attr(wp_json_encode($config['options'])); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
            <?php wp_nonce_field(self::ACTION . '_' . $product->get_id(), 'ge_store_nonce'); ?>
            <div class="ge-storefront-heading"><span>Compra online</span><h2>Configurá tu pedido</h2><p>Elegí una variante. El precio se actualiza automáticamente y queda guardado en tu orden.</p></div>
            <label class="ge-storefront-field" <?php echo $is_measure ? 'hidden' : ''; ?>>
                <span><?php echo esc_html($config['label']); ?></span>
                <select name="option_key" data-ge-option required>
                    <?php foreach ($config['options'] as $key => $option) : ?>
                        <option value="<?php echo esc_attr($key); ?>" data-price="<?php echo esc_attr($option['price']); ?>"><?php echo esc_html($option['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ('m2' === $mode) : ?>
                <div class="ge-storefront-measures">
                    <label><span>Ancho (cm)</span><input type="number" name="width" min="1" step="0.1" value="100" data-ge-width required></label>
                    <label><span>Alto (cm)</span><input type="number" name="height" min="1" step="0.1" value="100" data-ge-height required></label>
                </div>
            <?php elseif ('ml' === $mode) : ?>
                <div class="ge-storefront-measures"><label><span>Largo (cm)</span><input type="number" name="length" min="1" step="0.1" value="100" data-ge-length required></label></div>
            <?php endif; ?>
            <div class="ge-storefront-buy-row">
                <label><span>Cantidad</span><input type="number" name="quantity" min="1" step="1" value="1" data-ge-quantity></label>
                <div class="ge-storefront-price"><small>Total final con IVA</small><strong data-ge-price><?php echo wp_kses_post(wc_price($first['price'] * 1.21, array('decimals' => 0))); ?></strong><span data-ge-base>Base sin IVA: <?php echo wp_kses_post(wc_price($first['price'], array('decimals' => 0))); ?></span></div>
            </div>
            <?php if ($saved_artworks) : ?>
                <fieldset class="ge-storefront-artworks">
                    <legend>Archivo de impresión</legend>
                    <p>Si ya guardaste el original con Graph Express, vinculalo al producto para evitar confusiones.</p>
                    <?php foreach ($saved_artworks as $artwork) : ?>
                        <label><input type="checkbox" name="artwork_ids[]" value="<?php echo esc_attr($artwork->ID); ?>"><span><strong><?php echo esc_html(get_post_meta($artwork->ID, '_ge_artwork_code', true) ?: 'GE-ART-' . $artwork->ID); ?></strong><?php echo esc_html($artwork->post_title); ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php elseif (!is_user_logged_in()) : ?>
                <p class="ge-storefront-account-note"><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">Ingresá o creá una cuenta</a> para reutilizar archivos guardados y consultar tu historial.</p>
            <?php endif; ?>
            <button class="ge-storefront-submit button alt" type="submit">Agregar al carrito</button>
            <p class="ge-storefront-secure">Precio validado por Graph Express · Podés revisar todo antes de finalizar.</p>
        </form>
        <?php
    }

    public static function add_to_cart() {
        if (function_exists('wc_load_cart') && (!function_exists('WC') || !WC()->cart)) {
            wc_load_cart();
        }
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id || !isset($_POST['ge_store_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ge_store_nonce'])), self::ACTION . '_' . $product_id)) {
            wp_die('No pudimos validar el pedido. Volvé al producto e intentá nuevamente.', 403);
        }
        $config = self::config($product_id);
        $key = isset($_POST['option_key']) ? sanitize_text_field(wp_unslash($_POST['option_key'])) : '';
        if (!$config || !isset($config['options'][$key])) {
            wc_add_notice('La opción elegida ya no está disponible.', 'error');
            wp_safe_redirect(get_permalink($product_id));
            exit;
        }
        $quantity = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
        $option = $config['options'][$key];
        $unit_price = (float) $option['price'];
        $configuration = $option['label'];
        $mode = isset($config['mode']) ? $config['mode'] : '';
        if ('m2' === $mode) {
            $width = isset($_POST['width']) ? max(1, (float) str_replace(',', '.', wp_unslash($_POST['width']))) : 100;
            $height = isset($_POST['height']) ? max(1, (float) str_replace(',', '.', wp_unslash($_POST['height']))) : 100;
            $unit_price = round($unit_price * ($width / 100) * ($height / 100));
            $configuration = self::decimal($width) . ' × ' . self::decimal($height) . ' cm · ' . $option['label'];
        } elseif ('ml' === $mode) {
            $length = isset($_POST['length']) ? max(1, (float) str_replace(',', '.', wp_unslash($_POST['length']))) : 100;
            $unit_price = round($unit_price * ($length / 100));
            $configuration = self::decimal($length) . ' cm de largo · ' . $option['label'];
        }
        $cart_data = array(
            'ge_configuration_key' => $key,
            'ge_configuration'     => $configuration,
            'ge_calculated_price'  => round($unit_price * 1.21),
            'ge_base_price'        => $unit_price,
            'ge_unique'            => wp_generate_uuid4(),
        );
        if (is_user_logged_in() && class_exists('GE_WTP_Artwork_Library')) {
            $requested_artworks = isset($_POST['artwork_ids']) ? array_map('absint', (array) wp_unslash($_POST['artwork_ids'])) : array();
            $allowed_artworks = wp_list_pluck(GE_WTP_Artwork_Library::get_items(get_current_user_id()), 'ID');
            $cart_data['ge_artwork_ids'] = array_values(array_intersect($requested_artworks, array_map('absint', $allowed_artworks)));
        }
        if (!WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_data)) {
            wc_add_notice('No pudimos agregar el producto al carrito.', 'error');
            wp_safe_redirect(get_permalink($product_id));
            exit;
        }
        wc_add_notice('Producto agregado al carrito.', 'success');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public static function apply_cart_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (isset($item['ge_calculated_price']) && is_numeric($item['ge_calculated_price'])) {
                $item['data']->set_price((float) $item['ge_calculated_price']);
            }
        }
    }

    public static function order_catalog($query) {
        if (is_admin() || !$query->is_main_query() || (!is_post_type_archive('product') && !is_tax('product_cat'))) {
            return;
        }
        // WooCommerce parses this query var as a scalar before building the
        // final "menu_order title" clause. An associative array triggers an
        // undefined index warning in WC_Query on shop archives.
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
        $query->set('posts_per_page', 24);
    }

    public static function cart_item_data($data, $item) {
        if (!empty($item['ge_configuration'])) {
            $data[] = array('key' => 'Configuración', 'value' => wc_clean($item['ge_configuration']));
        }
        if (!empty($item['ge_artwork_ids'])) {
            foreach ((array) $item['ge_artwork_ids'] as $artwork_id) {
                $title = get_the_title(absint($artwork_id));
                $code = get_post_meta(absint($artwork_id), '_ge_artwork_code', true) ?: 'GE-ART-' . absint($artwork_id);
                if ($title) {
                    $data[] = array('key' => 'Archivo', 'value' => wc_clean($code . ' · ' . $title));
                }
            }
        }
        return $data;
    }

    public static function order_item_data($item, $cart_key, $values, $order) {
        if (!empty($values['ge_configuration'])) {
            $item->add_meta_data('Configuración', wc_clean($values['ge_configuration']), true);
        }
        if (!empty($values['ge_artwork_ids'])) {
            $order_artworks = (array) $order->get_meta(GE_WTP_Artwork_Library::ORDER_META, true);
            foreach ((array) $values['ge_artwork_ids'] as $artwork_id) {
                $artwork_id = absint($artwork_id);
                $title = get_the_title($artwork_id);
                $code = get_post_meta($artwork_id, '_ge_artwork_code', true) ?: 'GE-ART-' . $artwork_id;
                if ($title) {
                    $item->add_meta_data('Archivo asociado', wc_clean($code . ' · ' . $title), false);
                    $order_artworks[] = $artwork_id;
                }
            }
            $order->update_meta_data(GE_WTP_Artwork_Library::ORDER_META, array_values(array_unique(array_map('absint', $order_artworks))));
        }
    }

    public static function config($product_id) {
        $digital = get_post_meta($product_id, '_ge_digital_config', true);
        if (is_array($digital) && !empty($digital['prices']) && 'yes' === get_option('ge_wtp_enable_provisional_digital_sales', 'no')) {
            return self::digital_config($digital);
        }

        $sections = get_post_meta($product_id, '_ge_public_price_sections', true);
        if (is_array($sections) && $sections) {
            $options = self::section_options($sections);
            return $options ? array('label' => 'Formato y cantidad', 'options' => $options) : array();
        }

        $costs = get_post_meta($product_id, '_ge_supplier_costs', true);
        $catalog_key = (string) get_post_meta($product_id, '_ge_public_catalog_key', true);
        if (is_array($costs) && $costs && 0 !== strpos($catalog_key, 'windbanners-')) {
            return self::supplier_config($costs, (string) get_post_meta($product_id, '_ge_supplier_cost_unit', true));
        }
        return array();
    }

    public static function minimum_price($product_id) {
        $config = self::config($product_id);
        if (!$config || empty($config['options'])) {
            return 0;
        }
        return min(array_map(function ($option) { return (float) $option['price']; }, $config['options']));
    }

    private static function supplier_config($costs, $unit) {
        $options = array();
        $margin = max(0, (float) get_option('ge_wtp_bandurria_margin', 30));
        foreach ($costs as $key => $cost) {
            if (!is_numeric($cost) || (float) $cost <= 0) {
                continue;
            }
            $label = in_array($key, array('m2', 'ml'), true) ? '1 ' . ($unit ?: $key) : self::humanize($key);
            $options[sanitize_title($key)] = array('label' => $label, 'price' => round((float) $cost * (1 + $margin / 100)));
        }
        $mode = 1 === count($costs) && isset($costs['m2']) ? 'm2' : (1 === count($costs) && isset($costs['ml']) ? 'ml' : '');
        return $options ? array('label' => count($options) > 1 ? 'Modelo / terminación' : 'Unidad de venta', 'options' => $options, 'mode' => $mode) : array();
    }

    private static function section_options($sections) {
        $options = array();
        foreach ($sections as $section_index => $section) {
            $columns = isset($section['columns']) ? array_values($section['columns']) : array();
            $rows = isset($section['rows']) ? $section['rows'] : array();
            foreach ($rows as $row_index => $row) {
                $row = array_values($row);
                for ($column = 1; $column < count($row); $column++) {
                    $price = self::number($row[$column]);
                    if ($price <= 0) {
                        continue;
                    }
                    $parts = array_filter(array(
                        isset($section['title']) ? $section['title'] : '',
                        isset($row[0]) ? $row[0] : '',
                        isset($columns[$column]) ? $columns[$column] : '',
                    ));
                    $key = 's' . $section_index . '-r' . $row_index . '-c' . $column;
                    $options[$key] = array('label' => implode(' · ', array_unique($parts)), 'price' => $price);
                }
            }
        }
        return $options;
    }

    private static function digital_config($config) {
        $field_labels = array();
        foreach ($config['fields'] as $field) {
            if ('checkbox' === $field['type']) {
                continue;
            }
            $field_labels[$field['key']] = array('label' => $field['label'], 'options' => array());
            foreach ((array) $field['options'] as $option) {
                $field_labels[$field['key']]['options'][(string) $option['value']] = $option['label'];
            }
        }
        $options = array();
        foreach ($config['prices'] as $key => $price) {
            if (!is_numeric($price) || $price <= 0) {
                continue;
            }
            $parts = array();
            foreach (explode('|', $key) as $pair) {
                $bits = explode('=', $pair, 2);
                if (2 !== count($bits) || !isset($field_labels[$bits[0]])) {
                    continue;
                }
                $value = isset($field_labels[$bits[0]]['options'][$bits[1]]) ? $field_labels[$bits[0]]['options'][$bits[1]] : $bits[1];
                $parts[] = $field_labels[$bits[0]]['label'] . ': ' . $value;
            }
            $options['digital-' . md5($key)] = array('label' => implode(' · ', $parts), 'price' => (float) $price);
        }
        return $options ? array('label' => 'Combinación disponible', 'options' => $options) : array();
    }

    private static function number($value) {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $clean = preg_replace('/[^0-9,.-]/', '', (string) $value);
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
        return is_numeric($clean) ? (float) $clean : 0;
    }

    private static function humanize($value) {
        $value = str_replace(array('×', 'x'), ' × ', (string) $value);
        $value = str_replace(array('-', '_'), ' ', $value);
        return mb_convert_case(trim(preg_replace('/\s+/', ' ', $value)), MB_CASE_TITLE, 'UTF-8');
    }

    private static function decimal($value) {
        return number_format((float) $value, ((float) $value === floor((float) $value)) ? 0 : 1, ',', '.');
    }
}

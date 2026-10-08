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
        add_filter('woocommerce_display_product_attributes', array(__CLASS__, 'display_roll_unit'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item_data'), 10, 4);
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'finalize_order_uploads'));
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'finalize_order_uploads'));
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
        $css_file = GE_WTP_PLUGIN_DIR . 'assets/css/storefront.css';
        $js_file = GE_WTP_PLUGIN_DIR . 'assets/js/storefront.js';
        wp_enqueue_style('ge-storefront', GE_WTP_PLUGIN_URL . 'assets/css/storefront.css', array(), is_file($css_file) ? (string) filemtime($css_file) : GE_WTP_VERSION);
        wp_enqueue_script('ge-storefront', GE_WTP_PLUGIN_URL . 'assets/js/storefront.js', array(), is_file($js_file) ? (string) filemtime($js_file) : GE_WTP_VERSION, true);
        $storage = self::upload_storage_class();
        if ($storage) {
            $limits = $storage::limits();
            wp_localize_script('ge-storefront', 'geR2Upload', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => $storage::AJAX_ACTION,
                'nonce' => wp_create_nonce($storage::AJAX_ACTION),
                'requiresTurnstile' => 'GE_WTP_R2_Storage' === $storage,
                'ready' => $storage::ready(),
                'loggedIn' => is_user_logged_in(),
                'maxFiles' => (int) $limits['max_files'],
                'maxFileBytes' => (int) $limits['max_file_bytes'],
                'maxTotalBytes' => (int) $limits['max_total_bytes'],
            ));
        }
    }

    public static function purchasable($purchasable, $product) {
        return self::config($product->get_id()) ? true : $purchasable;
    }

    public static function display_roll_unit( $attributes, $product ) {
        $config = self::config( $product->get_id() );
        if ( empty( $config['roll_widths_cm'] ) ) { return $attributes; }
        foreach ( $attributes as &$attribute ) {
            if ( ( $attribute['label'] ?? '' ) === 'Unidad de cálculo' ) { $attribute['value'] = 'Por medida'; }
        }
        unset( $attribute );
        return $attributes;
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
        $minimum_quantity = max(1, isset($first['min_qty']) ? (int) $first['min_qty'] : (isset($config['min_qty']) ? (int) $config['min_qty'] : 1));
        $quantity_step = max(1, isset($first['step']) ? (int) $first['step'] : (isset($config['step']) ? (int) $config['step'] : 1));
        $mode = isset($config['mode']) ? $config['mode'] : '';
        $is_measure = !empty($mode);
        $saved_artworks = is_user_logged_in() && class_exists('GE_WTP_Artwork_Library')
            ? GE_WTP_Artwork_Library::get_items(get_current_user_id())
            : array();
        $upload_title = !empty($config['upload_title']) ? $config['upload_title'] : 'Archivos de producción';
        $upload_description = !empty($config['upload_description']) ? $config['upload_description'] : 'Subí los originales al almacenamiento privado del VPS. Quedarán vinculados a este producto y a tu pedido.';
        $upload_hint = !empty($config['upload_hint']) ? $config['upload_hint'] : '';
        ?>
        <form class="ge-storefront-config" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ge-storefront data-options="<?php echo esc_attr(wp_json_encode($config['options'])); ?>" data-option-map="<?php echo esc_attr(wp_json_encode(isset($config['option_map']) ? $config['option_map'] : array())); ?>" data-roll-widths="<?php echo esc_attr(wp_json_encode($config['roll_widths_cm'] ?? array())); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
            <?php wp_nonce_field(self::ACTION . '_' . $product->get_id(), 'ge_store_nonce'); ?>
            <div class="ge-storefront-heading"><span>Compra online</span><h2>Configurá tu pedido</h2><p>Elegí las opciones. El precio se actualiza automáticamente y queda guardado en tu orden.</p></div>
            <?php if (!empty($config['selectors'])) : ?>
                <div class="ge-storefront-selectors">
                    <?php foreach ($config['selectors'] as $selector) : ?>
                        <label class="ge-storefront-field">
                            <span><?php echo esc_html($selector['label']); ?></span>
                            <select data-ge-selector="<?php echo esc_attr($selector['key']); ?>" required>
                                <?php foreach ($selector['options'] as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                </div>
                <select name="option_key" data-ge-option hidden required>
                    <?php foreach ($config['options'] as $key => $option) : ?>
                        <option value="<?php echo esc_attr($key); ?>" data-price="<?php echo esc_attr($option['price']); ?>" data-min="<?php echo esc_attr($option['min_qty'] ?? $minimum_quantity); ?>" data-step="<?php echo esc_attr($option['step'] ?? $quantity_step); ?>" data-fixed="<?php echo esc_attr($option['fixed_qty'] ?? ''); ?>"><?php echo esc_html($option['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <label class="ge-storefront-field" <?php echo $is_measure ? 'hidden' : ''; ?>>
                    <span><?php echo esc_html($config['label']); ?></span>
                    <select name="option_key" data-ge-option required>
                        <?php foreach ($config['options'] as $key => $option) : ?>
                            <option value="<?php echo esc_attr($key); ?>" data-price="<?php echo esc_attr($option['price']); ?>" data-min="<?php echo esc_attr($option['min_qty'] ?? $minimum_quantity); ?>" data-step="<?php echo esc_attr($option['step'] ?? $quantity_step); ?>" data-fixed="<?php echo esc_attr($option['fixed_qty'] ?? ''); ?>"><?php echo esc_html($option['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ('m2' === $mode) : ?>
                <div class="ge-storefront-measures">
                    <label><span><?php echo ! empty( $config['roll_widths_cm'] ) ? 'Ancho final (cm)' : 'Ancho (cm)'; ?></span><input type="number" name="width" min="1" step="0.1" value="100" data-ge-width required></label>
                    <label><span><?php echo ! empty( $config['roll_widths_cm'] ) ? 'Largo final (cm)' : 'Alto (cm)'; ?></span><input type="number" name="height" min="1" step="0.1" value="100" data-ge-height required></label>
                </div>
                <?php if ( ! empty( $config['roll_widths_cm'] ) ) : ?><p data-ge-roll-hint>Indicá el tamaño final para ver el precio.</p><?php endif; ?>
            <?php elseif ('ml' === $mode) : ?>
                <div class="ge-storefront-measures"><label><span>Largo (cm)</span><input type="number" name="length" min="1" step="0.1" value="100" data-ge-length required></label></div>
            <?php endif; ?>
            <div class="ge-storefront-buy-row<?php echo !empty($config['fixed_quantity_selector']) ? ' has-fixed-quantity' : ''; ?>">
                <?php if (!empty($config['fixed_quantity_selector'])) : ?><input type="hidden" name="quantity" value="<?php echo esc_attr($minimum_quantity); ?>" data-ge-quantity><?php else : ?><label><span>Cantidad</span><input type="number" name="quantity" min="<?php echo esc_attr($minimum_quantity); ?>" step="<?php echo esc_attr($quantity_step); ?>" value="<?php echo esc_attr($minimum_quantity); ?>" data-ge-quantity></label><?php endif; ?>
                <div class="ge-storefront-price"><small>Total final con IVA</small><strong data-ge-price><?php echo wp_kses_post(wc_price($first['price'] * $minimum_quantity * 1.21, array('decimals' => 0))); ?></strong><span data-ge-base>Base sin IVA: <?php echo wp_kses_post(wc_price($first['price'] * $minimum_quantity, array('decimals' => 0))); ?></span></div>
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
            <fieldset class="ge-storefront-upload">
                <legend><?php echo esc_html($upload_title); ?></legend>
                <?php if ($upload_hint) : ?><p class="ge-storefront-upload-hint"><?php echo esc_html($upload_hint); ?></p><?php endif; ?>
                <?php $storage = self::upload_storage_class(); ?>
                <?php if (!is_user_logged_in()) : ?>
                    <p><?php echo esc_html($upload_description); ?> <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">Ingresá o creá una cuenta</a> antes de cargar los archivos para mantenerlos privados y vincularlos al pedido correcto.</p>
                <?php elseif (!$storage || !$storage::ready()) : ?>
                    <p><?php echo esc_html($upload_description); ?> En este momento no podemos recibir archivos. Podés enviarnos un enlace compartido desde Mi Cuenta y lo vincularemos al pedido.</p>
                <?php else : $limits = $storage::limits(); ?>
                    <p><?php echo esc_html($upload_description); ?></p>
                    <input type="file" data-ge-r2-files accept=".pdf,.ai,.eps,.psd,.tif,.tiff,.svg,.cdr,.zip,.jpg,.jpeg,.png" multiple>
                    <input type="hidden" name="ge_r2_uploads" value="[]" data-ge-r2-claims>
                    <?php if ('GE_WTP_R2_Storage' === $storage) { GE_WTP_Turnstile::render_widget('r2_upload'); } ?>
                    <button type="button" class="button" data-ge-r2-upload-button>Subir archivos de forma segura</button>
                    <small>Hasta <?php echo esc_html((int) $limits['max_files']); ?> archivos por producto y <?php echo esc_html(size_format((int) $limits['max_file_bytes'])); ?> por archivo. La carga debe terminar antes de agregar al carrito.</small>
                    <progress data-ge-upload-progress max="100" value="0" hidden aria-label="Progreso de carga"></progress>
                    <p data-ge-r2-status aria-live="polite">Todavía no hay archivos cargados.</p>
                <?php endif; ?>
            </fieldset>
            <?php if (!empty($config['comments_label'])) : ?>
                <label class="ge-storefront-comments">
                    <span><?php echo esc_html($config['comments_label']); ?></span>
                    <textarea name="ge_order_comments" maxlength="2000" rows="4" placeholder="<?php echo esc_attr($config['comments_placeholder'] ?? ''); ?>"></textarea>
                </label>
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
        // admin-post runs after wp_loaded; force WooCommerce to hydrate the
        // existing session before add_to_cart, or the next item replaces it.
        WC()->cart->get_cart();
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
        $selected_option = $config['options'][$key];
        $minimum_quantity = max(1, isset($selected_option['min_qty']) ? (int) $selected_option['min_qty'] : (isset($config['min_qty']) ? (int) $config['min_qty'] : 1));
        $quantity_step = max(1, isset($selected_option['step']) ? (int) $selected_option['step'] : (isset($config['step']) ? (int) $config['step'] : 1));
        $quantity = !empty($selected_option['fixed_qty']) ? absint($selected_option['fixed_qty']) : (isset($_POST['quantity']) ? absint($_POST['quantity']) : $minimum_quantity);
        if ($quantity < $minimum_quantity || 0 !== (($quantity - $minimum_quantity) % $quantity_step)) {
            wc_add_notice(sprintf('La cantidad debe comenzar en %1$d y avanzar de %2$d en %2$d unidades.', $minimum_quantity, $quantity_step), 'error');
            wp_safe_redirect(get_permalink($product_id));
            exit;
        }
        $option = $selected_option;
        $unit_price = (float) $option['price'];
        $configuration = $option['label'];
        $mode = isset($config['mode']) ? $config['mode'] : '';
        if ('m2' === $mode) {
            $width = isset($_POST['width']) ? max(1, (float) str_replace(',', '.', wp_unslash($_POST['width']))) : 100;
            $height = isset($_POST['height']) ? max(1, (float) str_replace(',', '.', wp_unslash($_POST['height']))) : 100;
            $billable_width = $width;
            if ( ! empty( $config['roll_widths_cm'] ) ) {
                $billable_width = GE_WTP_Roll_Pricing::billable_width( $width, $config['roll_widths_cm'] );
                if ( ! $billable_width ) {
                    wc_add_notice( 'El ancho excede los rollos disponibles. Consultanos por una cotización en paños.', 'error' );
                    wp_safe_redirect( get_permalink( $product_id ) );
                    exit;
                }
            }
            $unit_price = round($unit_price * ($billable_width / 100) * ($height / 100));
            $configuration = self::decimal($width) . ' × ' . self::decimal($height) . ' cm';
        } elseif ('ml' === $mode) {
            $length = isset($_POST['length']) ? max(1, (float) str_replace(',', '.', wp_unslash($_POST['length']))) : 100;
            $unit_price = round($unit_price * ($length / 100));
            $configuration = self::decimal($length) . ' cm de largo · ' . $option['label'];
        }
        $cart_data = array(
            'ge_configuration_key' => $key,
            'ge_configuration'     => $configuration,
            // Preserve centavos at unit level so a large minimum quantity does
            // not accumulate a rounding difference against the displayed total.
            'ge_calculated_price'  => round($unit_price * 1.21, 4),
            'ge_base_price'        => $unit_price,
            'ge_unique'            => wp_generate_uuid4(),
        );
        if ( ! empty( $config['roll_widths_cm'] ) ) { $cart_data['ge_internal_roll_width_cm'] = $billable_width; }
        $comments = isset($_POST['ge_order_comments']) ? sanitize_textarea_field(wp_unslash($_POST['ge_order_comments'])) : '';
        if ('' !== $comments) {
            $cart_data['ge_order_comments'] = function_exists('mb_substr') ? mb_substr($comments, 0, 2000) : substr($comments, 0, 2000);
        }
        if (is_user_logged_in() && class_exists('GE_WTP_Artwork_Library')) {
            $requested_artworks = isset($_POST['artwork_ids']) ? array_map('absint', (array) wp_unslash($_POST['artwork_ids'])) : array();
            $allowed_artworks = wp_list_pluck(GE_WTP_Artwork_Library::get_items(get_current_user_id()), 'ID');
            $cart_data['ge_artwork_ids'] = array_values(array_intersect($requested_artworks, array_map('absint', $allowed_artworks)));
        }
        if (!empty($_FILES['ge_product_artworks']['name'])) {
            $uploads = new WP_Error('ge_legacy_upload', 'La carga directa al servidor fue deshabilitada. Iniciá sesión y usá la carga privada.');
        } else {
            $raw_claims = isset($_POST['ge_r2_uploads']) ? json_decode(wp_unslash($_POST['ge_r2_uploads']), true) : array();
            $tokens = is_array($raw_claims) ? wp_list_pluck($raw_claims, 'token') : array();
            $storage = self::upload_storage_class();
            $uploads = $storage ? $storage::validate_uploaded_claims($tokens, get_current_user_id()) : array();
        }
        if (is_wp_error($uploads)) {
            wc_add_notice($uploads->get_error_message(), 'error');
            wp_safe_redirect(get_permalink($product_id));
            exit;
        }
        if ($uploads) {
            $cart_data['ge_r2_artworks'] = $uploads;
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
        if (!empty($item['ge_order_comments'])) {
            $data[] = array('key' => 'Datos adicionales', 'value' => wc_clean($item['ge_order_comments']));
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
        if (!empty($item['ge_r2_artworks'])) {
            foreach ((array) $item['ge_r2_artworks'] as $upload) {
                if (!empty($upload['name'])) { $data[] = array('key' => 'Archivo nuevo', 'value' => wc_clean($upload['name'])); }
            }
        }
        return $data;
    }

    public static function order_item_data($item, $cart_key, $values, $order) {
        if (!empty($values['ge_configuration'])) {
            $item->add_meta_data('Configuración', wc_clean($values['ge_configuration']), true);
        }
        if ( ! empty( $values['ge_internal_roll_width_cm'] ) ) { $item->update_meta_data( '_ge_internal_roll_width_cm', $values['ge_internal_roll_width_cm'] ); }
        if (!empty($values['ge_order_comments'])) {
            $item->add_meta_data('Datos adicionales', wc_clean($values['ge_order_comments']), true);
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
        if (!empty($values['ge_staged_artworks'])) {
            $item->add_meta_data('_ge_staged_artworks', $values['ge_staged_artworks'], true);
        }
        if (!empty($values['ge_r2_artworks'])) {
            $item->add_meta_data('_ge_r2_artworks', $values['ge_r2_artworks'], true);
        }
    }

    public static function finalize_order_uploads($order) {
        if (!$order instanceof WC_Order || !class_exists('GE_WTP_Documents')) {
            return;
        }
        $documents = GE_WTP_Documents::get_documents($order->get_id());
        $changed = false;
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $item_changed = false;
            $r2_uploads = $item->get_meta('_ge_r2_artworks', true);
            if (is_array($r2_uploads)) {
                foreach ($r2_uploads as $upload) {
                    $provider = $upload['provider'] ?? '';
                    if ('vps' === $provider && class_exists('GE_WTP_VPS_Storage')) {
                        $upload = GE_WTP_VPS_Storage::finalize_descriptor($upload, $order->get_id(), $item_id);
                        if (is_wp_error($upload)) { continue; }
                    }
                    if (!in_array($provider, array('r2', 'vps'), true) || empty($upload['name'])) { continue; }
                    if ('r2' === $provider && empty($upload['object_key'])) { continue; }
                    if ('vps' === $provider && empty($upload['relative_path'])) { continue; }
                    $document = array(
                        'id' => wp_generate_uuid4(),
                        'provider' => $provider,
                        'name' => sanitize_file_name($upload['name']),
                        'mime' => sanitize_text_field($upload['mime'] ?? 'application/octet-stream'),
                        'size' => (int) ($upload['size'] ?? 0),
                        'category' => 'arte',
                        'uploaded_by' => absint($upload['uploaded_by'] ?? $order->get_customer_id()),
                        'uploaded_at' => sanitize_text_field($upload['uploaded_at'] ?? current_time('mysql')),
                        'order_item_id' => absint($item_id),
                        'analysis' => array('confidence' => 'pending', 'warning' => 'Pendiente de control de preprensa.'),
                    );
                    if ('r2' === $provider) {
                        $document['bucket'] = sanitize_text_field($upload['bucket'] ?? 'graphexpress-pedidos-eu');
                        $document['object_key'] = sanitize_text_field($upload['object_key']);
                    } else {
                        $document['relative_path'] = sanitize_text_field($upload['relative_path']);
                    }
                    $documents[] = $document;
                    $changed = true;
                }
                $item->delete_meta_data('_ge_r2_artworks');
                $item_changed = true;
            }
            $uploads = $item->get_meta('_ge_staged_artworks', true);
            if (!is_array($uploads)) {
                if ($item_changed) { $item->save(); }
                continue;
            }
            foreach ($uploads as $upload) {
                $source = self::staging_directory() . '/' . sanitize_file_name($upload['stored_name'] ?? '');
                if (!is_file($source)) {
                    continue;
                }
                $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
                $stored = wp_generate_uuid4() . '.' . $extension;
                $destination = trailingslashit(GE_WTP_Documents::private_directory()) . $stored;
                if (!rename($source, $destination)) {
                    continue;
                }
                $mime = sanitize_text_field($upload['mime'] ?? 'application/octet-stream');
                $documents[] = array(
                    'id' => wp_generate_uuid4(),
                    'stored_name' => $stored,
                    'name' => sanitize_file_name($upload['name'] ?? 'archivo'),
                    'mime' => $mime,
                    'size' => (int) filesize($destination),
                    'category' => 'arte',
                    'uploaded_by' => get_current_user_id(),
                    'uploaded_at' => current_time('mysql'),
                    'order_item_id' => absint($item_id),
                    'analysis' => GE_WTP_Documents::analyze_file($destination, $mime),
                );
                $changed = true;
            }
            $item->delete_meta_data('_ge_staged_artworks');
            $item_changed = true;
            $item->save();
        }
        if ($changed) {
            $order->update_meta_data(GE_WTP_Documents::META_KEY, $documents);
            $order->save();
        }
    }

    public static function config($product_id) {
        $custom = get_post_meta($product_id, '_ge_storefront_config', true);
        if (is_array($custom) && !empty($custom['options'])) {
            return $custom;
        }
        $digital = get_post_meta($product_id, '_ge_digital_config', true);
        if (is_array($digital) && !empty($digital['prices']) && 'yes' === get_option('ge_wtp_enable_provisional_digital_sales', 'no')) {
            return self::digital_config($digital);
        }

        $sections = get_post_meta($product_id, '_ge_public_price_sections', true);
        if (is_array($sections) && $sections) {
            $options = self::section_options($sections);
            $rules = get_post_meta($product_id, '_ge_option_rules', true);
            if (is_array($rules)) {
                foreach ($options as $key => &$option) {
                    if (!empty($rules[$key]['min_qty'])) {
                        $option['min_qty'] = max(1, (int) $rules[$key]['min_qty']);
                    }
                    if (!empty($rules[$key]['step'])) {
                        $option['step'] = max(1, (int) $rules[$key]['step']);
                    }
                }
                unset($option);
            }
            return $options ? array(
                'label' => get_post_meta($product_id, '_ge_option_label', true) ?: 'Formato y cantidad',
                'options' => $options,
                'min_qty' => max(1, (int) get_post_meta($product_id, '_ge_minimum_quantity', true)),
                'step' => max(1, (int) get_post_meta($product_id, '_ge_quantity_step', true)),
            ) : array();
        }

        $costs = get_post_meta($product_id, '_ge_supplier_costs', true);
        $catalog_key = (string) get_post_meta($product_id, '_ge_public_catalog_key', true);
        if (is_array($costs) && $costs && 0 !== strpos($catalog_key, 'windbanners-')) {
            $config = self::supplier_config($costs, (string) get_post_meta($product_id, '_ge_supplier_cost_unit', true));
            $roll_widths = GE_WTP_Roll_Pricing::widths_for_catalog_key( $catalog_key );
            if ( $roll_widths && 'm2' === ( $config['mode'] ?? '' ) ) {
                $config['roll_widths_cm'] = $roll_widths;
                foreach ( $config['options'] as &$option ) { $option['label'] = 'A medida'; }
                unset( $option );
            }
            $selector_config = get_post_meta($product_id, '_ge_storefront_selectors', true);
            if (is_array($selector_config) && !empty($selector_config['fields']) && !empty($selector_config['map'])) {
                $config['selectors'] = $selector_config['fields'];
                $config['option_map'] = $selector_config['map'];
            }
            return $config;
        }
        return array();
    }

    public static function minimum_price($product_id) {
        $config = self::config($product_id);
        if (!$config || empty($config['options'])) {
            return 0;
        }
        $priced_options = array_filter($config['options'], function ($option) {
            return empty($option['manual_quote']);
        });
        if (!$priced_options) {
            return 0;
        }
        return min(array_map(function ($option) {
            $quantity = !empty($option['fixed_qty']) ? max(1, (int) $option['fixed_qty']) : 1;
            return (float) $option['price'] * $quantity;
        }, $priced_options));
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

    private static function stage_product_uploads($field) {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            return array();
        }
        $names = (array) $_FILES[$field]['name'];
        $allowed = array('pdf', 'ai', 'eps', 'psd', 'tif', 'tiff', 'svg', 'cdr', 'zip', 'jpg', 'jpeg', 'png');
        if (!self::ensure_staging_directory()) {
            return new WP_Error('ge_upload_storage', 'No se pudo preparar el almacenamiento privado para los archivos.');
        }
        $saved = array();
        foreach (array_slice(array_keys($names), 0, 20) as $index) {
            $error = (int) ($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if (UPLOAD_ERR_NO_FILE === $error) {
                continue;
            }
            if (UPLOAD_ERR_OK !== $error) {
                return new WP_Error('ge_upload_error', 'Uno de los archivos no pudo cargarse. Revisá su tamaño e intentá nuevamente.');
            }
            $name = sanitize_file_name(wp_basename($names[$index]));
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $size = (int) ($_FILES[$field]['size'][$index] ?? 0);
            $temp = $_FILES[$field]['tmp_name'][$index] ?? '';
            if (!in_array($extension, $allowed, true) || !$size || $size > 250 * MB_IN_BYTES || !is_uploaded_file($temp)) {
                return new WP_Error('ge_upload_invalid', 'Revisá el formato y tamaño de los archivos adjuntos.');
            }
            $stored = wp_generate_uuid4() . '.' . $extension;
            $destination = self::staging_directory() . '/' . $stored;
            if (!move_uploaded_file($temp, $destination)) {
                return new WP_Error('ge_upload_move', 'No se pudo guardar uno de los archivos.');
            }
            GE_WTP_File_Analysis::ingest( $destination, function_exists('mime_content_type') ? mime_content_type($destination) : 'application/octet-stream' );
            $mime = function_exists('mime_content_type') ? (string) mime_content_type($destination) : 'application/octet-stream';
            $saved[] = array('stored_name' => $stored, 'name' => $name, 'mime' => $mime, 'size' => $size);
        }
        return $saved;
    }

    private static function staging_directory() {
        return WP_CONTENT_DIR . '/ge-private/cart-uploads';
    }

    private static function upload_storage_class() {
        $provider = defined('GE_WTP_UPLOAD_PROVIDER') ? (string) GE_WTP_UPLOAD_PROVIDER : (string) getenv('GE_WTP_UPLOAD_PROVIDER');
        $provider = strtolower(trim($provider));
        if ('vps' === $provider) {
            return class_exists('GE_WTP_VPS_Storage') && GE_WTP_VPS_Storage::configured() ? 'GE_WTP_VPS_Storage' : '';
        }
        if ('r2' === $provider) {
            return class_exists('GE_WTP_R2_Storage') && GE_WTP_R2_Storage::configured() ? 'GE_WTP_R2_Storage' : '';
        }
        return '';
    }

    private static function ensure_staging_directory() {
        $directory = self::staging_directory();
        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }
        if (is_dir($directory) && !file_exists($directory . '/.htaccess')) {
            file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (is_dir($directory) && !file_exists($directory . '/index.php')) {
            file_put_contents($directory . '/index.php', "<?php\nhttp_response_code(404); exit;\n");
        }
        return is_dir($directory) && is_writable($directory);
    }
}

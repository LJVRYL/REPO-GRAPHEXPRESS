<?php
/** Plugin Name: Graphex public Meta commerce feed */
defined('ABSPATH') || exit;

/** Only published storefront information leaves this endpoint. No costs or customer records. */
final class GE_Meta_Commerce_Feed {
    const ROUTE = '/ge/v1/commerce-feed.csv';

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('ge/v1', '/commerce-feed.csv', array(
                'methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => array(__CLASS__, 'response'),
            ));
        });
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serve'), 10, 4);
    }

    public static function digital_defaults($config) {
        $values = array();
        foreach ($config['fields'] ?? array() as $field) {
            $key = $field['key'];
            if ('checkbox' === $field['type']) { $values[$key] = false; continue; }
            if ('number' === $field['type']) { $values[$key] = $field['default'] ?? $field['min'] ?? 1; continue; }
            $eligible = array();
            foreach ($field['options'] ?? array() as $option) {
                $valid = true;
                foreach ($option['when'] ?? array() as $dependency => $allowed) {
                    if (!in_array((string) ($values[$dependency] ?? ''), array_map('strval', (array) $allowed), true)) { $valid = false; break; }
                }
                if ($valid) { $eligible[] = $option; }
            }
            if (!$eligible) { return array(); }
            $selected = $eligible[0]['value'];
            foreach ($eligible as $option) {
                if (isset($field['default']) && (string) $option['value'] === (string) $field['default']) { $selected = $option['value']; break; }
            }
            $values[$key] = $selected;
        }
        return $values;
    }

    public static function storefront_offer($config) {
        $options = $config['options'] ?? array();
        if (!$options) { return null; }
        $key = key($options); $option = reset($options);
        // Match the default combination selected by the storefront selectors.
        if (!empty($config['selectors']) && !empty($config['option_map'])) {
            $parts = array();
            foreach ($config['selectors'] as $field) {
                $first = reset($field['options']);
                $parts[] = is_array($first) ? ($first['value'] ?? '') : key($field['options']);
            }
            $mapped = $config['option_map'][implode('|', $parts)] ?? null;
            if ($mapped && isset($options[$mapped])) { $key = $mapped; $option = $options[$mapped]; }
        }
        $quantity = max(1, (int) ($option['fixed_qty'] ?? $option['min_qty'] ?? $config['min_qty'] ?? 1));
        $unit = (float) ($option['price'] ?? 0);
        $label = $option['label'] ?? '';
        $mode = $config['mode'] ?? '';
        if ('m2' === $mode) {
            $width = 100;
            if (!empty($config['roll_widths_cm'])) {
                $width = GE_WTP_Roll_Pricing::billable_width(100, $config['roll_widths_cm']);
                if (!$width) { return null; }
            }
            $unit = round($unit * $width / 100);
            $label = '100 × 100 cm' . ($width !== 100 ? ' · ancho facturado ' . $width . ' cm' : '');
        } elseif ('ml' === $mode) { $unit = round($unit); $label = '100 cm de largo · ' . $label; }
        $amount = round(round($unit * 1.21, 4) * $quantity, 2);
        if (!is_finite($amount) || $amount <= 0) { return null; }
        return array('amount' => $amount, 'label' => $label . ' · Cantidad: ' . $quantity, 'key' => (string) $key);
    }

    public static function product_offer($product) {
        $digital = $product->get_meta('_ge_digital_config');
        if (is_array($digital) && !empty($digital['fields'])) {
            $values = self::digital_defaults($digital);
            if (!$values) { return null; }
            $quote = GE_WTP_Digital_Catalog::commercial_quote_price($product->get_id(), $values);
            if (is_wp_error($quote) || empty($quote['price'])) { return null; }
            return array('amount' => round($quote['price'] * 1.21, 2), 'label' => implode(' · ', $quote['labels']), 'key' => 'digital-default');
        }
        return self::storefront_offer(GE_WTP_Storefront::config($product->get_id()));
    }

    public static function rows(&$excluded = array()) {
        $rows = array();
        foreach (get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC')) as $id) {
            $product = wc_get_product($id);
            if (!$product || !$product->is_visible() || get_post_field('post_password', $id)) { continue; }
            $offer = self::product_offer($product);
            $image = self::image_url($product->get_image_id());
            if (!$offer || !$image) { $excluded[] = array('sku' => $product->get_sku(), 'reason' => !$offer ? 'requires_quote' : 'missing_image'); continue; }
            $categories = wp_get_post_terms($id, 'product_cat', array('fields' => 'names'));
            $name = self::text($product->get_name());
            $configuration = self::text($offer['label']);
            $rows[] = array(
                'id' => $product->get_sku() ?: 'GE-' . $id,
                'title' => mb_substr($name . ' · ' . $configuration, 0, 150),
                'description' => mb_substr(self::text($product->get_short_description()) . "\nPresentación publicada: " . $configuration . '. Precio final con IVA para esta configuración. Consultá otras medidas, cantidades, disponibilidad y plazo en graphex.ar.', 0, 5000),
                'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
                'condition' => 'new',
                'price' => number_format($offer['amount'], 2, '.', '') . ' ARS',
                'link' => $product->get_permalink(), 'image_link' => $image, 'brand' => 'GRAPHEX',
                'product_type' => is_wp_error($categories) ? '' : implode(' > ', $categories),
                'custom_label_0' => 'graphex.ar', 'custom_label_1' => $offer['key'],
            );
        }
        return $rows;
    }

    private static function text($value) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8'))); }

    /** Meta-compatible derivative; original attachment and site image remain intact. */
    public static function image_url($attachment_id) {
        $url = wp_get_attachment_image_url($attachment_id, 'full');
        if (!$url || 'image/webp' !== get_post_mime_type($attachment_id)) { return $url; }
        $source = realpath(get_attached_file($attachment_id));
        $uploads = wp_upload_dir(); $root = realpath($uploads['basedir']);
        if (!$source || !$root || strpos($source, $root . DIRECTORY_SEPARATOR) !== 0) { return false; }
        $directory = $root . '/graphex-meta';
        $filename = $attachment_id . '-' . substr(hash_file('sha256', $source), 0, 16) . '.jpg';
        $destination = $directory . '/' . $filename;
        if (!is_file($destination)) {
            if (!wp_mkdir_p($directory)) { return false; }
            $editor = wp_get_image_editor($source);
            if (is_wp_error($editor)) { return false; }
            $editor->set_quality(90);
            $saved = $editor->save($destination, 'image/jpeg');
            if (is_wp_error($saved)) { return false; }
        }
        return trailingslashit($uploads['baseurl']) . 'graphex-meta/' . $filename;
    }

    public static function response() {
        if (!function_exists('wc_get_product') || !class_exists('GE_WTP_Storefront') || !class_exists('GE_WTP_Digital_Catalog')) { return new WP_Error('ge_feed_unavailable', 'El catálogo no está disponible.', array('status' => 503)); }
        $rows = self::rows();
        if (!$rows) { return new WP_Error('ge_feed_empty', 'No hay productos publicables.', array('status' => 503)); }
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_keys($rows[0]));
        foreach ($rows as $row) { fputcsv($stream, array_values($row)); }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        return new WP_REST_Response($csv, 200, array('Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'X-Graphex-Product-Count' => (string) count($rows)));
    }

    public static function serve($served, $result, $request, $server) {
        if (self::ROUTE !== $request->get_route() || 200 !== $result->get_status()) { return $served; }
        $server->send_header('Content-Type', 'text/csv; charset=UTF-8');
        echo $result->get_data();
        return true;
    }
}
GE_Meta_Commerce_Feed::init();

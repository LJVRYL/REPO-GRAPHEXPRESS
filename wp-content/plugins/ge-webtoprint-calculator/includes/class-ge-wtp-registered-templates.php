<?php
defined('ABSPATH') || exit;

/** Graphex reference templates. Authentication is enforced at the byte-serving boundary. */
final class GE_WTP_Registered_Templates {
    // Design preparation belongs to the existing read-only order-file module policy.
    const ACTION = 'ge_order_design_template_download';
    const VERSION = '20261028.2';
    private static $authenticated_now = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'wire'), 110);
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'download'));
        add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'download'));
        add_action('template_redirect', array(__CLASS__, 'prevent_cache'), 1);
        add_action('template_redirect', array(__CLASS__, 'item_download'), -90);
        add_action('template_redirect', array(__CLASS__, 'remember_registration_item'), 2);
        add_action('wp_login', array(__CLASS__, 'registration_authenticated'), 20, 2);
        add_filter('wp_redirect', array(__CLASS__, 'registration_return'), 20, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 140);
    }

    public static function catalog() {
        static $catalog;
        if (null === $catalog) {
            $catalog = require __DIR__ . '/gx-template-catalog.php';
        }
        return is_array($catalog) ? $catalog : array('templates' => array(), 'products' => array());
    }

    public static function wire() {
        if (class_exists('GE_Design_Guides')) {
            remove_action('woocommerce_single_product_summary', array('GE_Design_Guides', 'wind_panel'), 28);
            add_shortcode('graphex_plantillas', array(__CLASS__, 'index'));
        }
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'product_panel'), 28);
        add_action('woocommerce_after_shop_loop_item', array(__CLASS__, 'item_button'), 20);
    }

    public static function product_rows($product) {
        if (!$product || !method_exists($product, 'get_slug')) {
            return null;
        }
        $catalog = self::catalog();
        $slug = $product->get_slug();
        return isset($catalog['products'][$slug]) ? $catalog['products'][$slug] : null;
    }

    public static function is_surface() {
        if (is_page('plantillas-de-diseno')) {
            return true;
        }
        global $product;
        if (function_exists('is_product') && is_product()) {
            if (!$product && function_exists('wc_get_product')) {
                $product = wc_get_product(get_queried_object_id());
            }
            return null !== self::product_rows($product);
        }
        return false;
    }

    public static function prevent_cache() {
        if (self::is_surface()) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0, must-revalidate');
            header('Vary: Cookie', false);
        }
    }

    public static function assets() {
        $catalog = (function_exists('is_shop') && is_shop()) || (function_exists('is_product_taxonomy') && is_product_taxonomy());
        if (!self::is_surface() && !$catalog) {
            return;
        }
        wp_enqueue_style('gx-registered-templates', GE_WTP_PLUGIN_URL . 'assets/css/registered-templates.css', array(), self::VERSION);
        if (!self::is_surface()) { return; }
        wp_enqueue_script('gx-registered-templates', GE_WTP_PLUGIN_URL . 'assets/js/registered-templates.js', array(), self::VERSION, true);
    }

    public static function item_has_template($rows) {
        $catalog = self::catalog();
        foreach ((array) $rows as $row) {
            if (!empty($row['ref']) && isset($catalog['templates'][$row['ref']])) { return true; }
        }
        return false;
    }

    public static function item_url($id) {
        return add_query_arg(array('gx_template_item' => (int) $id), home_url('/'));
    }

    public static function registration_url($id = 0) {
        $url = class_exists('GE_WTP_Portal') ? GE_WTP_Portal::portal_url('', array('modo' => 'registro')) : add_query_arg(array('modo' => 'registro'), home_url('/cliente-markcom/'));
        return $id ? add_query_arg(array('gx_template_product' => (int) $id), $url) : $url;
    }

    public static function valid_item($id) {
        if (!function_exists('wc_get_product')) { return null; }
        $item = wc_get_product((int) $id);
        return $item && $item->get_status() === 'publish' && self::item_has_template(self::product_rows($item)) ? $item : null;
    }

    public static function item_button() {
        global $product;
        $rows = self::product_rows($product);
        if (null === $rows) { return; }
        if (!self::item_has_template($rows)) {
            echo '<div class="gx-template-item-action"><span class="gx-template-item-pending">Plantilla a solicitud</span></div>';
            return;
        }
        // A stable gate URL keeps catalog pages cacheable without exposing another user's nonce.
        echo '<div class="gx-template-item-action"><a class="gx-template-item-button" href="' . esc_url(self::item_url($product->get_id())) . '">Descargar plantilla<span class="screen-reader-text"> de ' . esc_html($product->get_name()) . '</span></a></div>';
    }

    public static function item_download() {
        if (!isset($_GET['gx_template_item'])) { return; }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        $raw = $_GET['gx_template_item'];
        $item = is_string($raw) && ctype_digit($raw) ? self::valid_item((int) $raw) : null;
        if (!$item) { wp_die('Esta plantilla no está disponible.', '', array('response' => 404)); }
        if (!is_user_logged_in()) {
            wp_safe_redirect(self::registration_url($item->get_id()), 303);
            exit;
        }
        $rows = self::product_rows($item);
        $refs = array_unique(array_map(static function ($row) { return $row['ref'] ?? ''; }, $rows));
        $target = count($refs) === 1 && reset($refs) ? self::download_url(reset($refs)) : get_permalink($item->get_id()) . '#gx-template-heading';
        wp_safe_redirect($target, 303);
        exit;
    }

    public static function registration_cookie($value, $expires) {
        setcookie('gx_template_return', $value, array('expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
    }

    public static function remember_registration_item() {
        $id = $_GET['gx_template_product'] ?? null;
        if (($_GET['modo'] ?? '') !== 'registro' || !is_string($id) || !ctype_digit($id) || !self::valid_item((int) $id)) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        $payload = (int) $id . '.' . time();
        self::registration_cookie($payload . '.' . wp_hash($payload), time() + 3600);
    }

    public static function registration_authenticated($login, $user) {
        self::$authenticated_now = true;
    }

    public static function registration_return($location, $status) {
        $action = $_REQUEST['action'] ?? '';
        if (!self::$authenticated_now || !is_string($action) || !in_array($action, array('ge_customer_register', 'ge_markcom_login'), true)) { return $location; }
        $cookie = $_COOKIE['gx_template_return'] ?? '';
        if (!is_string($cookie) || !preg_match('/^([0-9]+)\.([0-9]+)\.([a-f0-9]+)$/D', $cookie, $match)) { return $location; }
        $payload = $match[1] . '.' . $match[2];
        if (!hash_equals(wp_hash($payload), $match[3]) || (int) $match[2] > time() || time() - (int) $match[2] > 3600 || !self::valid_item((int) $match[1])) { return $location; }
        self::registration_cookie('', time() - 3600);
        unset($_COOKIE['gx_template_return']);
        return self::item_url((int) $match[1]);
    }

    public static function library_url() {
        return home_url('/plantillas-de-diseno/');
    }

    public static function login_panel($return_url) {
        return '<div class="gx-template-login"><p>Ingresá con tu cuenta para descargar las plantillas de Graphex.</p><a class="gx-template-button" href="' . esc_url(wp_login_url($return_url)) . '">Ingresar y ver plantillas</a></div>';
    }

    public static function download_url($id) {
        if (!is_user_logged_in()) {
            return '';
        }
        return add_query_arg(array('action' => self::ACTION, 'ref' => $id, '_wpnonce' => wp_create_nonce('gx_template_' . $id)), admin_url('admin-post.php'));
    }

    public static function product_panel() {
        global $product;
        $rows = self::product_rows($product);
        if (null === $rows) {
            return;
        }
        echo '<section class="gx-template-panel" aria-labelledby="gx-template-heading"><p class="gx-template-kicker">ARCHIVOS PARA DISEÑAR</p><h2 id="gx-template-heading">Plantillas Graphex</h2><p>Elegí el modelo, la medida y la faz de tu pedido. La referencia conserva su escala; confirmamos las medidas y terminaciones antes de producir.</p>';
        if (!is_user_logged_in()) {
            if (self::item_has_template($rows)) {
                echo '<div class="gx-template-login"><a class="gx-template-button" href="' . esc_url(self::item_url($product->get_id())) . '">Descargar plantilla</a><p>Registrate o ingresá con tu cuenta para descargarla.</p></div>';
            } else {
                echo '<p>Plantilla a solicitud para esta opción.</p>';
            }
            echo '</section>';
            return;
        }
        $catalog = self::catalog();
        $map = array();
        echo '<label for="gx-template-option">Modelo y medida de la plantilla</label><select id="gx-template-option" data-gx-template-option><option value="">Elegí una opción</option>';
        foreach ($rows as $row) {
            $id = isset($row['ref']) ? $row['ref'] : '';
            $entry = $id && isset($catalog['templates'][$id]) ? $catalog['templates'][$id] : null;
            $map[$row['key']] = $entry ? array('url' => self::download_url($id), 'title' => $entry['title']) : array('url' => '', 'title' => '');
            echo '<option value="' . esc_attr($row['key']) . '">' . esc_html($row['label']) . '</option>';
        }
        echo '</select><div data-gx-template-picker data-template-map="' . esc_attr(wp_json_encode($map)) . '"><p class="gx-template-status" data-gx-template-message role="status" aria-live="polite">Seleccioná una opción para ver su plantilla.</p><a class="gx-template-button" data-gx-template-download hidden>Descargar plantilla PDF</a></div><noscript><p>Podés descargar cada modelo desde la <a href="' . esc_url(self::library_url()) . '">biblioteca de plantillas</a>.</p></noscript><p class="gx-template-note">Las líneas de referencia no deben salir en el arte final. Si tu opción está en revisión, solicitá su plantilla por medida.</p><a href="' . esc_url(self::library_url()) . '">Ver todas las plantillas</a></section>';
    }

    public static function index() {
        $previous = class_exists('GE_Design_Guides') ? GE_Design_Guides::index() : '';
        $html = '<section class="gx-template-library" aria-labelledby="gx-textile-library"><p class="gx-template-kicker">GRAPHEX / EXHIBIDORES Y TEXTILES</p><h2 id="gx-textile-library">Plantillas por modelo</h2><p>Descargá la referencia del modelo y la faz exactos. No la reemplaces por una medida parecida. La preparación del archivo y la escala física requieren revisión antes de producir.</p>';
        if (!is_user_logged_in()) {
            return $previous . $html . self::login_panel(self::library_url()) . '</section>';
        }
        $catalog = self::catalog();
        $html .= '<label for="gx-template-search">Buscar un modelo</label><input id="gx-template-search" type="search" data-gx-template-search placeholder="Por ejemplo: Gazebo 3x3" autocomplete="off"><p data-gx-template-count role="status" aria-live="polite"></p><div class="gx-template-grid" data-gx-template-grid>';
        foreach ($catalog['templates'] as $id => $entry) {
            $html .= '<article class="gx-template-card" data-gx-template-card><h3>' . esc_html($entry['title']) . '</h3><p>' . esc_html($entry['pages']) . ' ' . (1 === (int) $entry['pages'] ? 'página' : 'páginas') . ' / referencia de diseño</p><a class="gx-template-button" href="' . esc_url(self::download_url($id)) . '">Descargar PDF<span class="screen-reader-text"> de ' . esc_html($entry['title']) . '</span></a></article>';
        }
        return $previous . $html . '</div><p class="gx-template-note">¿No aparece tu opción? Solicitá la plantilla de la medida y terminación elegidas desde tu portal.</p></section>';
    }

    /** Return a complete, verified PDF or a closed failure. Never accept a path from the request. */
    public static function pdf_bytes($id) {
        $catalog = self::catalog();
        if (!is_string($id) || !isset($catalog['templates'][$id]) || !preg_match('/^gx-ref-[0-9]{3}$/D', $id)) {
            return new WP_Error('gx_template_missing', 'Esta plantilla no está disponible.');
        }
        $entry = $catalog['templates'][$id];
        $file = __DIR__ . '/gx-template-data-' . $id . '.php';
        if (!is_file($file)) {
            return new WP_Error('gx_template_missing', 'Esta plantilla no está disponible.');
        }
        $encoded = require $file;
        if (!is_string($encoded)) {
            return new WP_Error('gx_template_invalid', 'No se pudo preparar la descarga.');
        }
        $compressed = base64_decode($encoded, true);
        $pdf = false === $compressed ? false : @gzinflate($compressed, 8000000);
        if (!is_string($pdf) || substr($pdf, 0, 5) !== '%PDF-' || !hash_equals($entry['sha256'], hash('sha256', $pdf))) {
            return new WP_Error('gx_template_invalid', 'No se pudo verificar la descarga.');
        }
        return $pdf;
    }

    public static function download() {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        header('Vary: Cookie', false);
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::library_url()), 303);
            exit;
        }
        $id = isset($_GET['ref']) && is_string($_GET['ref']) ? wp_unslash($_GET['ref']) : '';
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';
        if (!preg_match('/^gx-ref-[0-9]{3}$/D', $id) || !wp_verify_nonce($nonce, 'gx_template_' . $id)) {
            wp_die('El enlace venció o no es válido. Volvé a abrir la biblioteca de plantillas.', '', array('response' => 403));
        }
        $pdf = self::pdf_bytes($id);
        if (is_wp_error($pdf)) {
            wp_die($pdf->get_error_message(), '', array('response' => 404));
        }
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="graphex-plantilla-' . substr($id, -3) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        if ('HEAD' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) {
            echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified binary PDF.
        }
        exit;
    }
}

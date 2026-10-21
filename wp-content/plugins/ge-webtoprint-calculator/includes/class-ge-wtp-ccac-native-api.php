<?php
defined('ABSPATH') || exit;

/** Restricted native access for the explicitly requested CCAC quotation. Disabled until provisioned. */
final class GE_WTP_CCAC_Native_API {
    const OPTION = 'ge_ccac_native_access_v1';
    const SOURCE = 'ccac-1a15f0c4501d34bd-leonardo-c';
    const STATE = 'ge_ccac_quote_1a15f0c4501d34bd';
    private static $authenticated = array();

    public static function init() {
        add_action('wp_authenticate_application_password_errors', array(__CLASS__, 'scope_password'), 10, 4);
        add_action('application_password_did_authenticate', array(__CLASS__, 'authenticated'), 10, 2);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_filter('rest_post_dispatch', array(__CLASS__, 'private_response'), 10, 3);
    }
    private static function error($code, $message, $status = 403) {
        return new WP_Error('ge_ccac_' . $code, $message, array('status' => $status));
    }
    private static function access() { return (array) get_option(self::OPTION, array()); }
    private static function allowed_route($route) {
        return in_array(rtrim((string) $route, '/'), array('/ge/v1/ccac-quote/context', '/ge/v1/ccac-quote/draft', '/ge/v1/ccac-quote/pdf'), true);
    }
    /** Deny this dedicated credential on unrelated REST routes and on XML-RPC. */
    public static function scope_password($errors, $user, $item, $password) {
        $config = self::access();
        if (empty($config['app_uuid']) || !hash_equals((string) $config['app_uuid'], (string) ($item['uuid'] ?? ''))) return;
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
        if (empty($config['enabled']) || (int) ($config['actor_id'] ?? 0) !== (int) $user->ID ||
            !defined('REST_REQUEST') || !REST_REQUEST || !self::allowed_route($route) || !is_ssl()) {
            $errors->add('ge_ccac_credential_scope', 'La credencial comercial no admite esta operación.');
        }
    }
    public static function authenticated($user, $item) {
        self::$authenticated = array('actor_id' => (int) $user->ID, 'app_uuid' => (string) ($item['uuid'] ?? ''));
    }
    public static function permission($request) {
        $config = self::access(); $actor = get_current_user_id();
        if (!self::allowed_route($request->get_route()) || !is_ssl() || empty($config['enabled']) ||
            !$actor || $actor !== (int) ($config['actor_id'] ?? 0) ||
            $actor !== (int) (self::$authenticated['actor_id'] ?? 0) ||
            empty($config['app_uuid']) || !hash_equals((string) $config['app_uuid'], (string) (self::$authenticated['app_uuid'] ?? ''))) {
            return self::error('authentication', 'Se requiere la credencial comercial autorizada.');
        }
        if (!user_can($actor, 'ge_manage_operations') && !user_can($actor, 'manage_woocommerce')) return self::error('role', 'Rol comercial sin permiso.');
        if (!class_exists('GE_Organization_Runtime') || !GE_Organization_Runtime::allowed('quotes', 'POST' === $request->get_method(), $actor)) return self::error('module', 'Módulo presupuestos no autorizado.');
        if (class_exists('GE_WTP_Portal') && GE_WTP_Portal::is_staff_preview()) return self::error('preview', 'La vista previa no opera presupuestos.');
        return true;
    }
    public static function routes() {
        foreach (array('context' => array('GET', 'context'), 'draft' => array('GET,POST', 'draft'), 'pdf' => array('GET', 'pdf')) as $path => $spec) {
            register_rest_route('ge/v1', '/ccac-quote/' . $path, array('methods' => $spec[0], 'callback' => array(__CLASS__, $spec[1]), 'permission_callback' => array(__CLASS__, 'permission')));
        }
    }
    public static function private_response($response, $server, $request) {
        if (self::allowed_route($request->get_route()) && is_object($response) && method_exists($response, 'header')) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Pragma', 'no-cache');
        }
        return $response;
    }
    private static function customer() {
        $user = get_userdata(11);
        if (!$user || !is_email($user->user_email)) return self::error('customer', 'La ficha del cliente necesita un correo válido.', 409);
        $scope = get_user_meta(11, '_ge_organization_id', true);
        if ($scope && $scope !== GE_Organization::PRIMARY) return self::error('tenant', 'Cliente fuera de la organización.');
        return $user;
    }
    private static function definitions() {
        return array(array('Personalización 1', 200), array('Personalización 2', 100), array('Personalización 3', 100), array('Personalización 4', 100));
    }
    private static function labels($input) {
        if (!is_array($input) || array_keys($input) !== array(0, 1, 2, 3)) return self::error('labels', 'Se requieren cuatro personalizaciones ordenadas.', 400);
        $labels = array();
        foreach ($input as $label) {
            if (!is_string($label) || !$label || strlen($label) > 160 || preg_match('/[\x00-\x1f\x7f]/', $label) || sanitize_text_field($label) !== $label) return self::error('labels', 'Personalización inválida.', 400);
            $labels[] = $label;
        }
        if (count(array_unique($labels)) !== 4) return self::error('labels', 'Las cuatro personalizaciones deben distinguirse.', 400);
        return $labels;
    }
    private static function lines($labels = null) {
        $lines = array();
        foreach (self::definitions() as $index => $definition) {
            if ($labels !== null) $definition[0] = $labels[$index];
            $configuration = array('tamano' => '5x9', 'cantidad' => (string) $definition[1], 'papel' => '300', 'impresion' => 'doble', 'laminado_caras' => 'sin-laminar', 'laminado_acabado' => 'no-aplica');
            $priced = GE_WTP_Commercial_Quote_Catalog::price(83, $configuration, 1);
            if (is_wp_error($priced)) return $priced;
            if (!empty($priced['manual']) || !isset($priced['price']) || !is_numeric($priced['price']) || (float) $priced['price'] <= 0) return self::error('tariff', 'La configuración exacta necesita una tarifa de venta verificada en Graphex.', 422);
            if ((int) ($priced['quantity'] ?? 0) !== 1 || ($priced['configuration'] ?? array()) !== $configuration) return self::error('configuration', 'El configurador modificó el alcance solicitado.', 409);
            $lines[] = array('source_type' => 'catalog_product', 'product_id' => 83, 'name' => 'Tarjetas · ' . $definition[0] . ' · ' . $definition[1] . ' unidades', 'quantity' => 1, 'unit' => 'lote', 'unit_net' => wc_format_decimal($priced['price'], 2), 'configuration' => $configuration, 'description' => $definition[1] . ' tarjetas de 9 × 5 cm, doble faz, ilustración mate 300 g, sin laminar. ' . $priced['description']);
        }
        return $lines;
    }
    private static function summaries() {
        $posts = get_posts(array('post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 51, 'fields' => 'ids', 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => 11, 'orderby' => 'ID', 'order' => 'DESC'));
        $rows = array();
        foreach ($posts as $id) {
            $q = GE_WTP_Commercial_Quotes::get($id, get_current_user_id());
            if (is_wp_error($q)) return $q;
            $rows[] = array('id' => $q['id'], 'number' => $q['number'], 'version' => $q['version'], 'status' => $q['status'], 'source' => get_post_meta($id, '_ge_commercial_source', true), 'issuer_profile_id' => $q['snapshot']['issuer_profile_id'] ?? '');
        }
        return $rows;
    }
    private static function cost_rule() {
        if (!class_exists('GE_WTP_Cost_Products') || !class_exists('GE_WTP_Cost_Engine')) return array('status' => 'native_cost_reader_unavailable');
        try {
            // Native getter enforces cost-engine permissions. detail=false avoids recipe enumeration or recalculation.
            $product = GE_WTP_Cost_Products::get(83, false);
            $link = is_array($product['cost_link'] ?? null) ? $product['cost_link'] : array();
            return array('status' => $link ? 'linked' : 'not_linked', 'product_id' => 83, 'source' => GE_WTP_Cost_Products::META, 'link' => array_intersect_key($link, array_flip(array('recipe_id', 'price_rule', 'option_key', 'input', 'linked_at'))));
        } catch (Throwable $error) {
            return array('status' => 'native_cost_permission_or_reader_unavailable');
        }
    }
    private static function current_context() {
        $user = self::customer(); if (is_wp_error($user)) return $user;
        $product = wc_get_product(83);
        if (!$product || $product->get_sku() !== 'ID-EXP-001' || $product->get_status() !== 'publish') return self::error('product', 'Producto de tarjetas no disponible.', 409);
        $issuer = GE_WTP_Billing_Issuers::get('leonardo-c');
        if (!$issuer || empty($issuer['active']) || empty($issuer['relationship_confirmed'])) return self::error('issuer', 'El perfil vigente de Leonardo Ayala requiere revisión.', 409);
        $issuer = array_intersect_key($issuer, array_flip(array('id', 'active', 'legal_name', 'cuit', 'vat_status', 'verification_status', 'relationship_confirmed', 'fiscal_address', 'postal_code', 'locality', 'province', 'country', 'contact_email', 'contact_phone', 'iibb', 'revision', 'common_price_policy', 'invoice_a_price_policy', 'tax_rate_basis_points', 'invoice_types_allowed')));
        $organization = GE_Organization_Runtime::snapshot();
        unset($organization['captured_at'], $organization['hash']);
        $existing = self::summaries(); if (is_wp_error($existing)) return $existing;
        $priced = self::lines();
        $context = array('customer' => array('id' => 11, 'name' => $user->display_name, 'email' => $user->user_email), 'billing_profiles' => GE_WTP_Customer_Branches::profiles(11), 'issuer' => $issuer, 'organization' => $organization, 'product' => array('id' => 83, 'name' => $product->get_name(), 'config' => $product->get_meta('_ge_digital_config')), 'requested' => self::definitions(), 'existing_quotes' => $existing, 'existing_complete' => count($existing) < 51, 'lines' => is_wp_error($priced) ? null : $priced, 'pricing_error' => is_wp_error($priced) ? $priced->get_error_message() : null);
        $context['linked_cost_rule'] = self::cost_rule();
        $context['context_hash'] = hash('sha256', wp_json_encode($context));
        return $context;
    }
    public static function context($request) {
        $allowed = self::permission($request); if (is_wp_error($allowed)) return $allowed;
        return self::current_context();
    }
    private static function scoped_quote($id) {
        $state = get_option(self::STATE, array());
        if (empty($state['quote_id']) || (int) $state['quote_id'] !== absint($id) || empty($state['order_hash'])) return self::error('quote_scope', 'Presupuesto fuera del registro autorizado.');
        $q = GE_WTP_Commercial_Quotes::get(absint($id), get_current_user_id());
        if (is_wp_error($q)) return $q;
        if ((int) $q['customer_id'] !== 11 || ($q['snapshot']['issuer_profile_id'] ?? '') !== 'leonardo-c') return self::error('quote_scope', 'Presupuesto fuera del alcance autorizado.');
        if (!hash_equals((string) $state['order_hash'], self::order_hash($q))) return self::error('quote_changed', 'El alcance del presupuesto guardado cambió; requiere revisión.', 409);
        return $q;
    }
    private static function order_hash($quote) {
        $items = array();
        foreach (($quote['snapshot']['items'] ?? array()) as $item) $items[] = array('name' => $item['name'] ?? '', 'product_id' => (int) ($item['product_id'] ?? 0), 'quantity' => (int) ($item['quantity'] ?? 0), 'configuration' => $item['configuration'] ?? array());
        return hash('sha256', wp_json_encode(array('customer_id' => (int) ($quote['customer_id'] ?? 0), 'issuer' => $quote['snapshot']['issuer_profile_id'] ?? '', 'items' => $items)));
    }
    private static function same_order($quote, $labels) {
        if (($quote['snapshot']['issuer_profile_id'] ?? '') !== 'leonardo-c' || count($quote['snapshot']['items'] ?? array()) !== 4) return false;
        $remaining = array_values($quote['snapshot']['items']);
        foreach (self::definitions() as $position => $definition) {
            $definition[0] = $labels[$position];
            $found = false;
            foreach ($remaining as $index => $item) {
                $config = $item['configuration'] ?? array();
                if (strpos((string) ($item['name'] ?? ''), $definition[0]) !== false &&
                    (int) ($item['product_id'] ?? 0) === 83 && (int) ($item['quantity'] ?? 0) === 1 &&
                    ($config['cantidad'] ?? '') === (string) $definition[1] && ($config['tamano'] ?? '') === '5x9' &&
                    ($config['papel'] ?? '') === '300' && ($config['impresion'] ?? '') === 'doble' &&
                    ($config['laminado_caras'] ?? '') === 'sin-laminar' && ($config['laminado_acabado'] ?? '') === 'no-aplica') {
                    unset($remaining[$index]); $found = true; break;
                }
            }
            if (!$found) return false;
        }
        return !$remaining;
    }
    public static function draft($request) {
        $allowed = self::permission($request); if (is_wp_error($allowed)) return $allowed;
        $customer = self::customer(); if (is_wp_error($customer)) return $customer;
        if ('GET' === $request->get_method()) return $request->get_param('quote_id') ? self::scoped_quote($request->get_param('quote_id')) : self::summaries();
        $input = $request->get_json_params();
        if (!is_array($input) || array_diff(array_keys($input), array('expected_context_hash', 'billing_profile_id', 'customer_tax_review_confirmed', 'existing_quotes_reviewed', 'artwork_labels'))) return self::error('input', 'Solicitud comercial inválida.', 400);
        $labels = self::labels($input['artwork_labels'] ?? null); if (is_wp_error($labels)) return $labels;
        if (!isset($input['billing_profile_id']) || !is_string($input['billing_profile_id'])) return self::error('input', 'Perfil receptor inválido.', 400);
        $intent_hash = hash('sha256', wp_json_encode(array('source' => self::SOURCE, 'customer_id' => 11, 'issuer' => 'leonardo-c', 'billing_profile_id' => $input['billing_profile_id'], 'labels' => $labels, 'quantities' => array(200, 100, 100, 100))));
        // Durable reservation is never auto-expired: an interrupted write requires inspection, not a duplicate.
        $state = get_option(self::STATE, array());
        if (!empty($state)) {
            if (empty($state['intent_hash']) || !hash_equals((string) $state['intent_hash'], $intent_hash)) return self::error('intent_conflict', 'La misma operación no admite un pedido diferente.', 409);
            if (($state['status'] ?? '') === 'complete') return self::scoped_quote($state['quote_id']);
            return self::error('reserved', 'Operación previa pendiente de conciliación; no se creó un duplicado.', 409);
        }
        $context = self::current_context(); if (is_wp_error($context)) return $context;
        if (empty($input['expected_context_hash']) || !hash_equals($context['context_hash'], (string) $input['expected_context_hash'])) return self::error('changed', 'El contexto cambió; revisá nuevamente los datos de Graphex.', 409);
        if (empty($context['existing_complete']) || ($input['existing_quotes_reviewed'] ?? false) !== true) return self::error('review_existing', 'Revisá los presupuestos existentes antes de crear otro.', 409);
        foreach ($context['existing_quotes'] as $q) {
            $candidate = GE_WTP_Commercial_Quotes::get($q['id'], get_current_user_id());
            if (is_wp_error($candidate)) return $candidate;
            if (self::same_order($candidate, $labels)) {
                if ($q['status'] === 'draft') {
                    if (!add_option(self::STATE, array('status' => 'complete', 'quote_id' => $q['id'], 'intent_hash' => $intent_hash, 'order_hash' => self::order_hash($candidate), 'reused' => true), '', false)) return self::error('busy', 'La operación ya está en curso.', 409);
                    return self::scoped_quote($q['id']);
                }
                return self::error('existing_order', 'Ya existe una propuesta con este alcance; revisala antes de crear otra.', 409);
            }
            if ($q['source'] === self::SOURCE) return self::error('existing_intent', 'Existe una operación previa distinta para esta solicitud.', 409);
        }
        if ($context['pricing_error'] || !$context['lines']) return self::error('tariff', $context['pricing_error'] ?: 'Falta tarifa de venta.', 422);
        $profile = GE_WTP_Customer_Branches::find(11, (string) ($input['billing_profile_id'] ?? ''));
        if (!$profile || ($input['customer_tax_review_confirmed'] ?? false) !== true) return self::error('profile_review', 'Falta revisar el receptor y emisor vigentes.', 422);
        $args = array('issuer_profile_id' => 'leonardo-c', 'issuer_change_reason' => 'Emisor Leonardo Ayala solicitado explícitamente para este presupuesto CCAC', 'billing_profile_id' => $profile['id'], 'customer_tax_confirm' => true, 'source' => self::SOURCE);
        $lines = self::lines($labels); if (is_wp_error($lines)) return $lines;
        if (!add_option(self::STATE, array('status' => 'reserved', 'actor_id' => get_current_user_id(), 'context_hash' => $context['context_hash'], 'intent_hash' => $intent_hash, 'at' => gmdate('c')), '', false)) return self::error('busy', 'La operación ya está en curso.', 409);
        try {
            $q = GE_WTP_Commercial_Quotes::create_draft(11, $lines, $args, get_current_user_id());
            if (is_wp_error($q)) { update_option(self::STATE, array('status' => 'review_required', 'reason' => 'native_create_error', 'intent_hash' => $intent_hash), false); return $q; }
            $record = array('status' => 'created_pending_readback', 'quote_id' => $q['id'], 'intent_hash' => $intent_hash, 'order_hash' => self::order_hash($q));
            update_option(self::STATE, $record, false);
            $q = self::scoped_quote($q['id']);
            if (is_wp_error($q)) return $q;
            if ($q['status'] !== 'draft' || empty($q['number']) || (int) $q['version'] < 1 || !self::same_order($q, $labels) || empty($q['snapshot']['total_cents']) || empty($q['snapshot']['organization_snapshot'])) return self::error('readback', 'El borrador requiere conciliación antes de continuar.', 409);
            $record['status'] = 'complete'; $record['version'] = $q['version']; $record['at'] = gmdate('c');
            update_option(self::STATE, $record, false);
            return $q;
        } catch (Throwable $error) {
            // Preserve reservation and any saved ID. Do not expose exception internals or re-run the mutation.
            return self::error('interrupted', 'La operación requiere revisar su estado guardado antes de reintentar.', 409);
        }
    }
    public static function pdf($request) {
        $allowed = self::permission($request); if (is_wp_error($allowed)) return $allowed;
        $q = self::scoped_quote($request->get_param('quote_id')); if (is_wp_error($q)) return $q;
        if ((int) $request->get_param('expected_version') !== (int) $q['version']) return self::error('version', 'La versión del presupuesto cambió.', 409);
        $bytes = GE_WTP_Commercial_Quote_PDF::build($q); if (is_wp_error($bytes)) return $bytes;
        if (!is_string($bytes) || substr($bytes, 0, 5) !== '%PDF-') return self::error('pdf', 'El generador nativo no devolvió un PDF válido.', 500);
        return array('quote_id' => $q['id'], 'number' => $q['number'], 'version' => $q['version'], 'filename' => sanitize_file_name('presupuesto-' . $q['number'] . '-v' . $q['version'] . '.pdf'), 'mime_type' => 'application/pdf', 'sha256' => hash('sha256', $bytes), 'data_base64' => base64_encode($bytes));
    }
}

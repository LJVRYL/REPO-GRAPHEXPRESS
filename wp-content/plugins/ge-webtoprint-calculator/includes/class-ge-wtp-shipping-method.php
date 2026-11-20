<?php
defined('ABSPATH') || exit;

final class GE_Shipping_Method extends WC_Shipping_Method {
    public function __construct($instance_id = 0) {
        $this->id = 'ge_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = 'Graphex Envíos';
        $this->method_description = 'Tarifario a domicilio por destino, peso y dimensiones del bulto.';
        $this->supports = array('shipping-zones', 'instance-settings', 'instance-settings-modal');
        $this->instance_form_fields = array(
            'title' => array('title' => 'Nombre en la tienda', 'type' => 'text', 'default' => 'Envío a domicilio'),
            'tax_status' => array('title' => 'Impuestos', 'type' => 'select', 'default' => 'none',
                'options' => array('none' => 'Importes finales', 'taxable' => 'WooCommerce agrega impuestos')),
            'source' => array('title' => 'Operador y referencia del tarifario', 'type' => 'text'),
            'valid_through' => array('title' => 'Vigente hasta (AAAA-MM-DD)', 'type' => 'text'),
            'divisor' => array('title' => 'Divisor volumétrico (cm³/kg)', 'type' => 'number', 'default' => '',
                'description' => 'Ingresá 0 si el servicio cobra por peso real.'),
            'maximum_sum' => array('title' => 'Máximo largo + ancho + alto (cm)', 'type' => 'number'),
            'rates' => array('title' => 'Tarifario por bulto (CSV)', 'type' => 'textarea',
                'description' => 'Una fila por tramo. Columnas: provincia (* para todas), CP desde, CP hasta, gramos máximos, lado mayor cm, lado medio cm, lado menor cm, ARS. Ordená primero las filas más específicas y los tramos menores.'),
            'paper' => array('title' => 'Perfiles de papel (CSV)', 'type' => 'textarea',
                'description' => 'Gramaje, espesor mm, laminado g/m² por cara, laminado mm por cara, holgura total por eje cm, tara g, máximo g por bulto. Usá estimaciones medidas o fichas técnicas.'),
        );
        $this->title = $this->get_option('title');
        $this->tax_status = $this->get_option('tax_status', 'none');
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    private function parse_rows($name, $columns) {
        $rows = array();
        foreach (preg_split('/\R/', trim((string) $this->get_option($name))) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = array_map('trim', explode(',', $line));
            if (count($row) !== $columns) {
                return array();
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function rates() {
        $rows = $this->parse_rows('rates', 8);
        foreach ($rows as $row) {
            if (!preg_match('/^(?:[A-Z]|\*)$/D', $row[0])
                || !preg_match('/^\d{4}$/D', $row[1]) || !preg_match('/^\d{4}$/D', $row[2])
                || $row[1] < 1000 || $row[2] < $row[1]
                || !$this->numbers(array_slice($row, 3), false)
                || $row[4] < $row[5] || $row[5] < $row[6]) {
                return array();
            }
        }
        return $rows;
    }

    private function numbers(array $values, $zero_allowed) {
        foreach ($values as $value) {
            if (!preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D', $value) || (!$zero_allowed && (float) $value <= 0)) {
                return false;
            }
        }
        return true;
    }

    private function paper_profiles() {
        $profiles = array();
        foreach ($this->parse_rows('paper', 7) as $row) {
            $gsm = array_shift($row);
            if (!ctype_digit($gsm) || !$this->numbers($row, true)
                || $row[0] <= 0 || $row[5] <= $row[4] || isset($profiles[(int) $gsm])) {
                return array();
            }
            $profiles[(int) $gsm] = $row;
        }
        return $profiles;
    }

    private function paper_selection($line) {
        $config = $line['data']->get_meta('_ge_digital_config');
        $key = $line['ge_configuration_key'] ?? '';
        $labels = explode(' · ', $line['ge_configuration'] ?? '');
        if ($key !== '') {
            foreach (array_keys($config['prices'] ?? array()) as $candidate) {
                if ('digital-' . md5($candidate) === $key) {
                    parse_str(str_replace('|', '&', $candidate), $selection);
                    return $selection;
                }
            }
            return array();
        }
        // Older digital-cart rows store the validated selection as display labels.
        $selection = array();
        foreach (($config['fields'] ?? array()) as $field) {
            foreach (($field['options'] ?? array()) as $option) {
                if (in_array($field['label'] . ': ' . $option['label'], $labels, true)) {
                    $selection[$field['key']] = $option['value'];
                    break;
                }
            }
        }
        return $selection;
    }

    private function parcels($line, $profiles) {
        $product = $line['data'];
        $quantity = (int) $line['quantity'];
        $sku = $product->get_sku();
        if (in_array($sku, array('ID-EXP-001', 'ID-EXP-002'), true)) {
            $selection = $this->paper_selection($line);
            $sizes = $sku === 'ID-EXP-001' ? array('5x9' => array(5, 9))
                : array('a3' => array(29.7, 42), 'a4' => array(21, 29.7), 'a5' => array(14.8, 21), 'a6' => array(10.5, 14.8));
            $size = $sizes[$selection['tamano'] ?? ''] ?? null;
            $gsm = (int) ($selection['papel'] ?? 0);
            $pieces = (int) ($selection['cantidad'] ?? 0);
            $faces = $sku === 'ID-EXP-001' ? array('simple' => 1, 'doble' => 2) : array('' => 0);
            $film = $faces[$selection['laminado_caras'] ?? ''] ?? null;
            if (!$size || !$pieces || !isset($profiles[$gsm]) || $film === null
                || ($film && ($profiles[$gsm][1] <= 0 || $profiles[$gsm][2] <= 0))) {
                return array();
            }
            return GE_Shipping_Calculator::paper_parcels(
                array($size[0], $size[1], $gsm, $film), $profiles[$gsm], $pieces * $quantity
            );
        }
        if (!empty($line['ge_configuration']) || $product->get_meta('_ge_digital_config')
            || $product->get_meta('_ge_storefront_config')) {
            return array();
        }
        // A configured WooCommerce weight/dimension set describes one packed unit.
        $parcel = array(wc_get_weight((float) $product->get_weight(), 'g'));
        foreach (array($product->get_length(), $product->get_width(), $product->get_height()) as $dimension) {
            $parcel[] = wc_get_dimension((float) $dimension, 'cm');
        }
        return min($parcel) > 0 && $quantity > 0 ? array(array_merge($parcel, array($quantity))) : array();
    }

    public function calculate_shipping($package = array()) {
        $valid_through = (string) $this->get_option('valid_through');
        $maximum_sum = (string) $this->get_option('maximum_sum');
        $parts = explode('-', $valid_through);
        $divisor = (string) $this->get_option('divisor');
        if (get_woocommerce_currency() !== 'ARS' || !$this->get_option('source')
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $valid_through)
            || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])
            || $valid_through < wp_date('Y-m-d') || !$this->numbers(array($divisor), true)
            || !$this->numbers(array($maximum_sum), false)) {
            return;
        }
        $rates = $this->rates();
        if (!$rates || empty($package['contents'])) {
            return;
        }
        $parcels = array();
        $profiles = $this->paper_profiles();
        foreach ($package['contents'] as $line) {
            $boxes = $this->parcels($line, $profiles);
            if (!$boxes) {
                return;
            }
            $parcels = array_merge($parcels, $boxes);
        }
        $quote = GE_Shipping_Calculator::quote($parcels, $rates, $package['destination'], (float) $divisor, (float) $maximum_sum);
        if ($quote !== null) {
            $this->add_rate(array(
                'id' => $this->get_rate_id(),
                'label' => $this->title,
                'cost' => $quote['cost'],
                'package' => $package,
                'meta_data' => array(
                    '_ge_shipping_source' => $this->get_option('source'),
                    '_ge_actual_grams' => $quote['actual_g'],
                    '_ge_billable_grams' => $quote['billable_g'],
                    '_ge_parcels' => $parcels,
                ),
            ));
        }
    }
}



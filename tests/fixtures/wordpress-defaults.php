<?php
// Standalone legacy-domain fixtures: resolver stage 1, no database or registered filters.
// Resolver stage 3 is verified separately against real WordPress/WooCommerce fixtures.
if (!function_exists('get_option')) { function get_option($name, $default = false) { return $name === 'ge_customer_tax_stage_v1' ? 1 : $default; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); } }
if (!function_exists('__')) { function __($text, $domain = 'default') { return $text; } }
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}

if (!function_exists('apply_filters')) { function apply_filters($hook, $value, ...$args) { return $value; } }

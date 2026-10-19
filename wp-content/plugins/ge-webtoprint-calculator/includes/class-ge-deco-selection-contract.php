<?php
/** Read-only candidate contract. No hooks, writes, products or sales activation. PHP 7.4+. */
require_once __DIR__ . '/class-ge-deco-print-quality.php';
final class GE_Deco_Selection_Contract {
    public static function resolve(array $manifest, array $storefront, $key, $requested_revision) {
        if (!is_string($key) || !is_string($requested_revision) || empty($manifest['revision']) || !is_string($manifest['revision']) || !hash_equals($manifest['revision'], $requested_revision)) {
            throw new InvalidArgumentException('stale_revision');
        }
        if (($manifest['rights_status'] ?? '') !== 'approved' || empty($manifest['rights_evidence_ref']) || empty($manifest['artwork_id']) || empty($manifest['production_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['production_sha256'])) {
            throw new InvalidArgumentException('unapproved_artwork');
        }
        $entry = $manifest['options'][$key] ?? null;
        $option = $storefront['options'][$key] ?? null;
        if (!is_array($entry) || !is_array($option) || ($entry['enabled'] ?? false) !== true || ($entry['geometry_verified'] ?? false) !== true || empty($entry['cost_version_ref']) || empty($entry['price_approval_ref']) || empty($entry['supplier_variant_ref'])) {
            throw new InvalidArgumentException('unapproved_option');
        }
        foreach (array('print_mm', 'outer_mm') as $field) {
            // Bought frames need a verified print format; external size is optional
            // metadata, never a guessed cutting/manufacturing dimension.
            if ('outer_mm' === $field && !isset($entry[$field])) { continue; }
            if (!isset($entry[$field]) || !is_array($entry[$field]) || count($entry[$field]) !== 2) { throw new InvalidArgumentException('invalid_geometry'); }
            foreach ($entry[$field] as $value) { if (!is_int($value) || $value <= 0) { throw new InvalidArgumentException('invalid_geometry'); } }
        }
        if (isset($entry['outer_mm']) && ($entry['outer_mm'][0] < $entry['print_mm'][0] || $entry['outer_mm'][1] < $entry['print_mm'][1])) { throw new InvalidArgumentException('invalid_geometry'); }
        // Each allowed crop has a separate immutable server option, never arbitrary browser coordinates.
        if (!in_array($entry['fit'] ?? '', array('contain', 'cover'), true) || !isset($entry['crop_anchor']) || $entry['crop_anchor'] !== array(0.5, 0.5)) { throw new InvalidArgumentException('invalid_composition'); }
        $quality = GE_Deco_Print_Quality::enforce($manifest['production_pixels'] ?? null, $entry['print_mm'], $entry['fit']);
        $price = $option['price'] ?? null;
        if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0 || empty($option['label']) || !isset($storefront['tax_context']['multiplier']) || !is_numeric($storefront['tax_context']['multiplier']) || !is_finite((float) $storefront['tax_context']['multiplier']) || (float) $storefront['tax_context']['multiplier'] <= 0) { throw new InvalidArgumentException('missing_approved_price'); }
        // Price belongs to GE_WTP_Storefront; this contract creates no pricing formula.
        return array('schema' => 'graphex-deco-order/v1', 'manifest_revision' => $manifest['revision'], 'artwork_id' => $manifest['artwork_id'], 'production_sha256' => $manifest['production_sha256'], 'option_key' => $key, 'configuration' => $option['label'], 'composition' => $entry, 'quality' => $quality, 'cost_version_ref' => $entry['cost_version_ref'], 'price_approval_ref' => $entry['price_approval_ref']);
    }
}

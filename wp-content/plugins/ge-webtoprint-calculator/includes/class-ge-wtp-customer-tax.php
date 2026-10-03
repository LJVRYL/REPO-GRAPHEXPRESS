<?php
defined( 'ABSPATH' ) || exit;

/** Pure fiscal guidance. Never performs a lookup or issues a document. */
final class GE_WTP_Customer_Tax {
    const RULE_VERSION = 'arca-domestic-v1-2026-10-02';
    const SOURCE = 'https://www.arca.gob.ar/facturacion/regimen-general/comprobantes.asp';
    const CUSTOMER_EVIDENCE_TTL = 86400;
    const ISSUER_EVIDENCE_TTL = 2592000;

    /** Trusted server-side policy only. Historical verification fields remain unchanged. */
    private static function evidence_fresh( $profile, $kind ) {
        $ttl = 'customer' === $kind ? self::CUSTOMER_EVIDENCE_TTL : self::ISSUER_EVIDENCE_TTL;
        $ttl = apply_filters( 'ge_wtp_tax_evidence_ttl', $ttl, $kind );
        if ( ! is_numeric( $ttl ) || (int) $ttl <= 0 ) { return false; }
        $date = 'customer' === $kind && ! empty( $profile['checked_at'] ) ? $profile['checked_at'] : ( $profile['verified_at'] ?? '' );
        if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $date ) ) { return false; }
        $parsed = DateTimeImmutable::createFromFormat( DateTimeInterface::ATOM, $date );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $parsed || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) { return false; }
        $timestamp = $parsed->getTimestamp(); $now = time();
        // A future timestamp cannot prove current evidence, even within a small clock skew.
        return false !== $timestamp && $timestamp <= $now && $timestamp >= $now - (int) $ttl;
    }

    public static function vat_status( $profile ) {
        $value = (string) ( $profile['vat_status'] ?? $profile['tax_status'] ?? 'unknown' );
        return in_array( $value, array( 'registered', 'monotributo', 'exempt', 'final_consumer' ), true ) ? $value : 'unknown';
    }

    public static function resolve( $issuer, $profile, $operation = array() ) {
        $issuer = (array) $issuer; $profile = (array) $profile; $operation = (array) $operation;
        $result = array(
            'suggested_document_class' => 'unknown', 'tax_treatment' => 'unknown',
            'suggested_issuer_id' => (string) ( $issuer['id'] ?? 'unknown' ),
            'issuer_profile_id' => (string) ( $issuer['id'] ?? 'unknown' ),
            'requires_review' => true, 'reason' => '', 'warnings' => array(),
            'source' => self::SOURCE, 'profile_source' => (string) ( $profile['source'] ?? 'manual' ),
            'rule_version' => self::RULE_VERSION,
            'customer_verified' => 'verified' === ( $profile['verification_status'] ?? '' ),
            'required_document_class' => 'unknown', 'compatible' => false, 'issuer_ready' => false, 'ready' => false,
            'vat_display_requirement' => 'review', 'tax_rate_basis_points' => null,
            'customer_evidence_fresh' => self::evidence_fresh( $profile, 'customer' ),
            'issuer_evidence_fresh' => self::evidence_fresh( $issuer, 'issuer' ),
        );
        if ( ! $result['customer_evidence_fresh'] ) { $result['warnings'][] = 'La evidencia fiscal del receptor está vencida, no tiene fecha válida o tiene una fecha futura; requiere una nueva verificación.'; }
        if ( ! $result['issuer_evidence_fresh'] ) { $result['warnings'][] = 'La evidencia fiscal del emisor está vencida, no tiene fecha válida o tiene una fecha futura; requiere una nueva verificación.'; }
        $export = ! empty( $operation['export'] ) || ! empty( $operation['is_export'] ) || 'export' === ( $operation['type'] ?? '' ) || 'export' === ( $operation['operation_type'] ?? '' );
        $country = strtoupper( (string) ( $operation['country'] ?? $profile['country'] ?? 'AR' ) );
        if ( $export || ( $country && 'AR' !== $country ) ) {
            $result['reason'] = 'export_out_of_scope';
            $result['warnings'][] = 'La exportación requiere revisar el comprobante E; está fuera del alcance de estas reglas domésticas.';
            return $result;
        }
        $iv = self::vat_status( $issuer ); $cv = self::vat_status( $profile );
        if ( 'registered' === $iv && in_array( $cv, array( 'registered', 'monotributo' ), true ) ) {
            $result['suggested_document_class'] = 'A'; $result['tax_treatment'] = 'vat_applies'; $result['vat_display_requirement'] = 'itemized';
            $result['reason'] = 'registered_to_registered_or_monotributo';
            if ( 'monotributo' === $cv ) { $result['warnings'][] = 'Comprobante A a monotributista: corresponde la leyenda prevista por la Ley 27.618.'; }
        } elseif ( 'registered' === $iv && in_array( $cv, array( 'final_consumer', 'exempt' ), true ) ) {
            $result['suggested_document_class'] = 'B'; $result['tax_treatment'] = 'vat_applies';
            if ( 'final_consumer' === $cv ) { $result['vat_display_requirement'] = 'itemized_transparency'; }
            $result['reason'] = 'registered_to_final_consumer_or_exempt';
        } elseif ( in_array( $iv, array( 'monotributo', 'exempt' ), true ) ) {
            $result['suggested_document_class'] = 'C'; $result['tax_treatment'] = 'no_vat'; $result['vat_display_requirement'] = 'not_applicable';
            $result['reason'] = 'monotributo_or_exempt_issuer';
        } else {
            $result['reason'] = 'unknown_tax_condition';
            $result['warnings'][] = 'Falta una condición fiscal explícita. La ausencia de datos no implica consumidor final.';
        }
        if ( 'unknown' === $cv ) { $result['warnings'][] = 'La condición fiscal del receptor requiere revisión.'; }
        if ( 'registered' === $iv ) { $result['warnings'][] = 'Verificá el tratamiento de la operación, exenciones y alícuotas: la clase de comprobante no determina la tasa de IVA ni la política de precio.'; }
        if ( 'itemized_transparency' === $result['vat_display_requirement'] ) { $result['warnings'][] = 'Factura B a consumidor final: revisar la discriminación del IVA exigida por el régimen de transparencia fiscal vigente.'; }
        if ( ! $result['customer_verified'] ) { $result['warnings'][] = 'Datos del receptor declarados manualmente o pendientes de verificación oficial.'; }
        if ( empty( $issuer['active'] ) ) { $result['warnings'][] = 'El emisor no está activo para selección.'; }
        if ( 'verified' !== ( $issuer['verification_status'] ?? '' ) ) { $result['warnings'][] = 'La condición y habilitación del emisor deben verificarse.'; }
        if ( empty( $issuer['relationship_confirmed'] ) ) { $result['warnings'][] = 'Debe confirmarse la relación comercial y contable real con el emisor.'; }
        $capabilities = (array) ( $issuer['invoice_types_allowed'] ?? $issuer['document_capabilities'] ?? array() );
        $result['required_document_class'] = $result['suggested_document_class'];
        $result['compatible'] = 'unknown' !== $result['required_document_class'] && in_array( $result['required_document_class'], $capabilities, true );
        if ( 'unknown' !== $result['suggested_document_class'] && ! in_array( $result['suggested_document_class'], $capabilities, true ) ) {
            $result['warnings'][] = 'El emisor no tiene declarada la capacidad para el comprobante sugerido.';
            $result['suggested_document_class'] = 'unknown';
        }
        $result['issuer_ready'] = $result['compatible'] && ! empty( $issuer['active'] ) && 'verified' === ( $issuer['verification_status'] ?? '' ) && ! empty( $issuer['relationship_confirmed'] ) && $result['issuer_evidence_fresh'];
        $result['ready'] = $result['issuer_ready'] && $result['customer_verified'] && $result['customer_evidence_fresh'] && 'unknown' !== $cv;
        // Guidance always requires a human decision; never enables automatic invoicing.
        return $result;
    }

    public static function suggestion( $profile, $operation = array() ) {
        $profile = (array) $profile; $operation = (array) $operation;
        $scenario = in_array( self::vat_status( $profile ), array( 'registered', 'monotributo' ), true ) ? 'invoice_a' : 'common';
        $all = class_exists( 'GE_WTP_Billing_Issuers' ) ? GE_WTP_Billing_Issuers::all() : array();
        $candidates = array(); $best = -1;
        foreach ( $all as $id => $issuer ) {
            if ( empty( $issuer['active'] ) ) { continue; }
            $issuer['id'] = $issuer['id'] ?? $id;
            $resolution = self::resolve( $issuer, $profile, $operation );
            $default = in_array( $scenario, (array) ( $issuer['default_for_scenarios'] ?? array() ), true );
            if ( ! $resolution['issuer_ready'] && ! $default ) { continue; }
            $score = ( $resolution['issuer_ready'] ? 100 : 0 ) + ( $default ? 10 : 0 );
            if ( $score > $best ) { $best = $score; $candidates = array( $issuer ); }
            elseif ( $score === $best ) { $candidates[] = $issuer; }
        }
        // Reuse registered IDs. Never create tax-specific duplicates or infer identity from names.
        $selected = count( $candidates ) === 1 ? $candidates[0] : array();
        $result = self::resolve( $selected, $profile, $operation );
        $result['scenario'] = $scenario;
        if ( ! $selected ) { $result['warnings'][] = count( $candidates ) > 1 ? 'Hay varios emisores predeterminados: seleccioná el emisor real.' : 'No hay un emisor predeterminado activo para este escenario.'; }
        return $result;
    }
}

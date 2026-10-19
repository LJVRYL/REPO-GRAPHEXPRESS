<?php
/** Amounts are integer ARS cents. No network, hooks or activation side effects. */
final class GE_Deco_Marketplace_Settlement {
    private static function amount($value) {
        if (!is_int($value) || $value < 0 || $value > 2000000000) {
            throw new InvalidArgumentException('invalid_settlement_amount');
        }
        return $value;
    }

    /** Free artwork uses the native first-party checkout, which owns its processing tariff. */
    public static function unit_quote($physical, $artist, array $fee, $now) {
        $quote=self::quote($physical,$artist,0,$fee,$now);
        $quote['checkout_route']=$artist>0?'marketplace':'graphex';
        if($artist===0){
            $quote['total_cents']=$physical;$quote['graphex_cents']=$physical;
            $quote['processing_cents']=0;$quote['processing_payer']='native_checkout';
        }
        return $quote;
    }

    /** A fee policy must come from a verified seller/method-specific server quote. */
    public static function quote($physical, $artist, $freight, array $fee, $now, $charged_total = null) {
        foreach (array($physical, $artist, $freight) as $amount) { self::amount($amount); }
        if ($physical === 0 || !is_int($now) || $now < 0) {
            throw new InvalidArgumentException('invalid_settlement_amount');
        }
        if (!is_int($fee['rate_bps'] ?? null) || $fee['rate_bps'] < 0 || $fee['rate_bps'] >= 5000
            || !is_int($fee['fixed_cents'] ?? null) || $fee['fixed_cents'] < 0
            || !is_int($fee['valid_until'] ?? null) || $fee['valid_until'] <= $now
            || !is_int($fee['verified_at'] ?? null) || $fee['verified_at'] > $now || $fee['verified_at'] < $now - 30 * 86400
            || empty($fee['evidence_ref']) || empty($fee['revision'])
            || !preg_match('/^[1-9][0-9]{2,30}$/D', (string)($fee['collector_id'] ?? ''))
            || ($fee['payment_method_id'] ?? '') !== 'account_money') {
            throw new InvalidArgumentException('verified_processing_fee_required');
        }
        self::amount($fee['fixed_cents']);
        $net = $physical + $artist + $freight;
        self::amount($net);
        $denominator = 10000 - $fee['rate_bps'];
        // Processing is inside Graphex's selling price; no separate buyer surcharge.
        $total = intdiv(($net + $fee['fixed_cents']) * 10000 + $denominator - 1, $denominator);
        if($charged_total!==null){self::amount($charged_total);if($charged_total<$total)throw new InvalidArgumentException('graphex_floor_not_covered');$total=$charged_total;}
        self::amount($total);
        $processing = intdiv($total * $fee['rate_bps'] + 9999, 10000) + $fee['fixed_cents'];
        $platform = $total - $artist - $processing;
        if ($platform < $physical + $freight || ($artist > 0 && ($platform <= 0 || $platform >= $total))) {
            throw new InvalidArgumentException('graphex_floor_not_covered');
        }
        return array('schema'=>'graphex-marketplace-settlement/v1', 'currency'=>'ARS',
            'total_cents'=>$total, 'artist_cents'=>$artist, 'graphex_cents'=>$platform,
            'physical_floor_cents'=>$physical, 'freight_cents'=>$freight,
            'processing_cents'=>$processing, 'processing_payer'=>'graphex',
            'collector_id'=>(string)$fee['collector_id'], 'payment_method_id'=>'account_money',
            'fee_revision'=>(string)$fee['revision'], 'fee_evidence_ref'=>(string)$fee['evidence_ref'],
            'valid_until'=>$fee['valid_until'], 'split_required'=>$artist > 0);
    }

    /** Confirm the provider result, never trust return-URL query parameters. */
    public static function reconcile(array $expected, array $actual) {
        foreach (array('total_cents','artist_cents','graphex_cents','physical_floor_cents','freight_cents','processing_cents') as $key) {
            self::amount($expected[$key] ?? null);
        }
        foreach (array('total_cents','processing_cents','marketplace_cents','refunded_cents') as $key) {
            self::amount($actual[$key] ?? null);
        }
        if (($expected['schema'] ?? '') !== 'graphex-marketplace-settlement/v1'
            || ($expected['currency'] ?? '') !== 'ARS' || ($actual['currency'] ?? '') !== 'ARS'
            || ($actual['collector_id'] ?? '') !== ($expected['collector_id'] ?? '')
            || ($actual['payment_method_id'] ?? '') !== ($expected['payment_method_id'] ?? '')
            || $actual['total_cents'] !== $expected['total_cents']
            || $actual['marketplace_cents'] !== $expected['graphex_cents']
            || $actual['processing_cents'] !== $expected['processing_cents']
            || $actual['total_cents'] - $actual['processing_cents'] - $actual['marketplace_cents'] !== $expected['artist_cents']
            || $expected['graphex_cents'] < $expected['physical_floor_cents'] + $expected['freight_cents']) {
            throw new InvalidArgumentException('settlement_review_required');
        }
        if ($actual['refunded_cents'] > 0 || in_array($actual['status'] ?? '', array('refunded','charged_back'), true)) {
            return 'reversed';
        }
        if (($actual['status'] ?? '') === 'approved' && !empty($actual['accredited_at'])) { return 'paid'; }
        if (in_array($actual['status'] ?? '', array('pending','in_process','authorized'), true)) { return 'pending'; }
        return 'unpaid';
    }
}

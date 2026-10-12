<?php
defined('ABSPATH') || exit;

/** Explicit owner-only Meta test assets; never an alternative route for a real number. */
final class GE_Meta_Test_Assets {
    const WABA='1588731999397867';
    const PHONE='1338419139362975';
    const NUMBER='15556505576';
    const RECIPIENT='5491151393899';
    public static function account() { return 'wa:'.self::WABA.':'.self::PHONE; }
    public static function inbound($cfg) {
        return ($cfg['mode'] ?? '')==='meta-test' && ($cfg['waba_id'] ?? '')===self::WABA
            && ($cfg['phone_number_id'] ?? '')===self::PHONE && ($cfg['number'] ?? '')===self::NUMBER;
    }
    public static function outbound($cfg) {
        return ($cfg['mode'] ?? '')==='meta-test' && ($cfg['waba_id'] ?? '')===self::WABA
            && ($cfg['asset_id'] ?? '')===self::PHONE;
    }
}

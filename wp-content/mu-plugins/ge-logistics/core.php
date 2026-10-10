<?php
defined( 'ABSPATH' ) || exit;

/** Pure calculations. No carrier calls, labels, charges or synthetic tariff defaults. */
final class GE_Logistics {
    const META = '_ge_logistics_v1';
    const CONFIG = 'ge_logistics_config_v1';
    public static function carriers() {
        return array( 'pickup'=>'Retiro en Graphex', 'local'=>'Entrega local coordinada', 'uber'=>'Uber Envíos · gestión manual', 'via_cargo'=>'Vía Cargo', 'correo'=>'Correo Argentino', 'andreani'=>'Andreani' );
    }
    public static function provinces() {
        return array( 'C'=>'Ciudad Autónoma de Buenos Aires', 'B'=>'Buenos Aires', 'K'=>'Catamarca', 'H'=>'Chaco', 'U'=>'Chubut', 'X'=>'Córdoba', 'W'=>'Corrientes', 'E'=>'Entre Ríos', 'P'=>'Formosa', 'Y'=>'Jujuy', 'L'=>'La Pampa', 'F'=>'La Rioja', 'M'=>'Mendoza', 'N'=>'Misiones', 'Q'=>'Neuquén', 'R'=>'Río Negro', 'A'=>'Salta', 'J'=>'San Juan', 'D'=>'San Luis', 'Z'=>'Santa Cruz', 'S'=>'Santa Fe', 'G'=>'Santiago del Estero', 'V'=>'Tierra del Fuego', 'T'=>'Tucumán' );
    }
    public static function province( $value ) {
        $value = trim( (string) $value );
        if ( isset( self::provinces()[ strtoupper( $value ) ] ) ) { return strtoupper( $value ); }
        $slug = sanitize_title( $value );
        if ( in_array( $slug, array( 'caba', 'capital-federal', 'buenos-aires-ciudad', 'ciudad-autonoma-de-buenos-aires' ), true ) ) { return 'C'; }
        foreach ( self::provinces() as $code=>$name ) { if ( sanitize_title( $name ) === $slug ) { return $code; } }
        return '';
    }
    public static function address( $input ) {
        $input = is_array($input) ? $input : array();
        $out = array();
        foreach ( array( 'id', 'label', 'recipient', 'street', 'city', 'province', 'postal_code', 'phone', 'hours', 'notes', 'country', 'place_id' ) as $key ) {
            $raw=$input[$key]??'';
            $out[$key] = mb_substr( sanitize_text_field( is_scalar($raw)?$raw:'' ), 0, 'notes' === $key ? 500 : 220 );
        }
        $out['country'] = strtoupper( $out['country'] ?: 'AR' );
        $out['province'] = self::province( $out['province'] );
        $out['postal_code'] = strtoupper( preg_replace( '/\s+/', '', $out['postal_code'] ) );
        // A place ID is a suggestion, never proof of deliverability or staff review.
        $out['review_status'] = 'pending';
        return $out;
    }
    public static function address_check( $a ) {
        if ( 'AR' !== ( $a['country'] ?? '' ) ) { return new WP_Error( 'country', 'Esta modalidad se cotiza sólo dentro de Argentina.' ); }
        foreach ( array( 'street'=>'dirección', 'city'=>'localidad', 'province'=>'provincia', 'recipient'=>'quién recibe', 'phone'=>'teléfono de recepción' ) as $field=>$label ) {
            if ( empty( $a[$field] ) ) { return new WP_Error( 'address', 'Completá ' . $label . ' para cotizar el envío.' ); }
        }
        if ( ! self::province( $a['province'] ) || ! preg_match( '/^(?:\d{4}|[A-Z]\d{4}[A-Z]{3})$/', $a['postal_code'] ?? '' ) ) { return new WP_Error( 'postal', 'Revisá la provincia y el CP argentino (4 números o CPA completo).' ); }
        if ( strlen( preg_replace( '/\D/', '', $a['phone'] ) ) < 8 ) { return new WP_Error( 'phone', 'Revisá el teléfono de recepción.' ); }
        return true;
    }
    public static function packages( $rows ) {
        if ( ! is_array( $rows ) || count( $rows ) > 20 ) { return new WP_Error( 'packages', 'Usá hasta 20 tipos de bulto por despacho.' ); }
        $out = array(); $count = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) { return new WP_Error( 'packages', 'Bulto inválido.' ); }
            if ( ! array_filter( $row ) ) { continue; }
            $p = array();
            foreach ( array( 'count', 'weight_kg', 'length_cm', 'width_cm', 'height_cm' ) as $key ) {
                $raw = $row[$key] ?? '';
                if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) || ! is_finite( (float) $raw ) || (float) $raw <= 0 ) { return new WP_Error( 'packages', 'Cada bulto necesita cantidad, peso y tres medidas mayores a cero.' ); }
                $p[$key] = (float) $raw;
            }
            if ( floor( $p['count'] ) !== $p['count'] || $p['count'] > 100 || $p['weight_kg'] > 1000 || max( $p['length_cm'], $p['width_cm'], $p['height_cm'] ) > 1000 ) { return new WP_Error( 'packages', 'Cantidad o medida de bulto fuera de rango.' ); }
            $p['count'] = (int) $p['count']; $count += $p['count']; $out[] = $p;
        }
        return $count <= 100 ? $out : new WP_Error( 'packages', 'Máximo 100 bultos por despacho.' );
    }
    public static function weight( $packages, $divisor = null, $step = null, $minimum = null ) {
        $real = 0; $chargeable = 0; $volume = 0; $units = 0;
        foreach ( $packages as $p ) {
            $v = $p['length_cm'] * $p['width_cm'] * $p['height_cm'];
            $real += $p['weight_kg'] * $p['count']; $volume += $v * $p['count']; $units += $p['count'];
            if ( $divisor > 0 ) {
                $w = max( $p['weight_kg'], $v / $divisor, (float) $minimum );
                if ( $step > 0 ) { $w = ceil( ( $w - 0.00000001 ) / $step ) * $step; }
                $chargeable += $w * $p['count'];
            }
        }
        return array( 'real_kg'=>round( $real, 3 ), 'volume_cm3'=>round( $volume, 2 ), 'count'=>$units, 'chargeable_kg'=>$divisor > 0 ? round( $chargeable, 3 ) : null );
    }
    /** Product worker supplies measured/declared specs through this contract. */
    public static function estimate( $spec ) {
        foreach ( array( 'quantity', 'width_cm', 'height_cm', 'gsm', 'sheets_per_unit', 'thickness_mm', 'packaging_kg', 'extra_kg', 'max_units_per_box', 'padding_cm' ) as $key ) {
            if ( ! isset( $spec[$key] ) || ! is_numeric( $spec[$key] ) || ! is_finite( (float) $spec[$key] ) || (float) $spec[$key] < 0 ) { return new WP_Error( 'spec', 'Falta una ficha física completa; medir y pesar el pedido embalado.' ); }
        }
        foreach ( array( 'quantity', 'width_cm', 'height_cm', 'gsm', 'sheets_per_unit', 'thickness_mm', 'max_units_per_box' ) as $key ) { if ( $spec[$key] <= 0 ) { return new WP_Error( 'spec', 'La ficha física debe tener valores positivos.' ); } }
        if ( floor( $spec['quantity'] ) != $spec['quantity'] || floor( $spec['max_units_per_box'] ) != $spec['max_units_per_box'] ) { return new WP_Error( 'spec', 'Cantidad y capacidad por caja deben ser enteras.' ); }
        $rows = array(); $remaining = (int) $spec['quantity']; $boxes = (int) ceil( $remaining / $spec['max_units_per_box'] );
        if ( $boxes > 100 ) { return new WP_Error( 'spec', 'Más de 100 bultos: dividir el despacho.' ); }
        while ( $remaining > 0 ) {
            $n = min( $remaining, (int) $spec['max_units_per_box'] ); $remaining -= $n;
            $unit_mass = isset($spec['unit_net_weight_kg']) ? (float)$spec['unit_net_weight_kg'] : (( $spec['net_area_m2'] ?? ($spec['width_cm']*$spec['height_cm']/10000) ) * $spec['gsm']/1000 * $spec['sheets_per_unit'] + $spec['extra_kg']);
            if(!is_finite($unit_mass)||$unit_mass<=0)return new WP_Error('spec','Peso neto de producto inválido.');
            $rows[] = array( 'count'=>1, 'weight_kg'=>ceil( ( $unit_mass * $n + $spec['packaging_kg'] ) * 1000 ) / 1000, 'length_cm'=>$spec['width_cm'] + 2 * $spec['padding_cm'], 'width_cm'=>$spec['height_cm'] + 2 * $spec['padding_cm'], 'height_cm'=>round( $n * $spec['sheets_per_unit'] * $spec['thickness_mm'] / 10 + 2 * $spec['padding_cm'], 2 ) );
        }
        return array( 'status'=>'estimated', 'packages'=>$rows, 'basis'=>$spec['basis'] ?? 'Ficha productiva; confirmar con muestra embalada' );
    }
    /** Adapter for product-catalog's contract. Null measurements never become zero. */
    public static function product_contract($p,$quantity){
        if(!is_array($p)||empty($p['validated'])||empty($p['measurement_date'])||empty($p['responsible'])||empty($p['evidence_ref'])||empty($p['packaging_profile_id']))return new WP_Error('measurement','La ficha productiva requiere medición validada y evidencia.');
        $keys=array('finished_size_mm','unfolded_net_area_m2','grammage_g_m2','folded_thickness_mm','unit_net_weight_kg','units_per_package','packaging_tare_kg','padding_cm');
        foreach($keys as $key)if(!isset($p[$key]))return new WP_Error('measurement','Falta '.$key.' en la ficha productiva.');
        if(!is_array($p['finished_size_mm'])||!isset($p['finished_size_mm']['width'],$p['finished_size_mm']['height']))return new WP_Error('measurement','Falta tamaño final validado.');
        foreach(array('unfolded_net_area_m2','grammage_g_m2','folded_thickness_mm','unit_net_weight_kg','units_per_package','packaging_tare_kg','padding_cm') as $key)if(!is_numeric($p[$key])||!is_finite((float)$p[$key])||$p[$key]<0)return new WP_Error('measurement','Dato físico inválido: '.$key);
        foreach(array('width','height') as $key)if(!is_numeric($p['finished_size_mm'][$key])||$p['finished_size_mm'][$key]<=0)return new WP_Error('measurement','Tamaño final inválido.');
        return array('quantity'=>$quantity,'width_cm'=>$p['finished_size_mm']['width']/10,'height_cm'=>$p['finished_size_mm']['height']/10,'gsm'=>$p['grammage_g_m2'],'sheets_per_unit'=>1,'thickness_mm'=>$p['folded_thickness_mm'],'packaging_kg'=>$p['packaging_tare_kg'],'extra_kg'=>0,'max_units_per_box'=>$p['units_per_package'],'padding_cm'=>$p['padding_cm'],'net_area_m2'=>$p['unfolded_net_area_m2'],'unit_net_weight_kg'=>$p['unit_net_weight_kg'],'basis'=>$p['evidence_ref'].' · '.$p['measurement_date'].' · '.$p['responsible']);
    }
    public static function carrier_check( $carrier, $packages ) {
        if ( ! isset( self::carriers()[$carrier] ) ) { return new WP_Error( 'carrier', 'Transportista inválido.' ); }
        if ( 'pickup' === $carrier ) { return true; }
        if ( ! $packages ) { return new WP_Error( 'packages', 'Completá los bultos antes de ofrecer este envío.' ); }
        foreach ( $packages as $p ) {
            // Conservative screening: official PAQ.AR page currently states both 25 and 30 kg.
            if ( 'correo' === $carrier && ( $p['weight_kg'] > 25 || array_sum( array( $p['length_cm'], $p['width_cm'], $p['height_cm'] ) ) > 250 || max( $p['length_cm'], $p['width_cm'], $p['height_cm'] ) > 150 ) ) { return new WP_Error( 'carrier_limit', 'Supera el control conservador PAQ.AR (25 kg, suma 250 cm, lado 150 cm). Confirmá otro servicio con Correo.' ); }
            if ( 'via_cargo' === $carrier && $p['weight_kg'] > 100 ) { return new WP_Error( 'carrier_limit', 'Más de 100 kg por bulto requiere consulta directa con la agencia Vía Cargo.' ); }
        }
        return true;
    }
    public static function fingerprint( $address, $packages, $items, $origin ) {
        return hash( 'sha256', wp_json_encode( array( $address, $packages, $items, $origin ) ) );
    }
    public static function cents( $value ) {
        return is_scalar( $value ) && preg_match( '/^\d{1,8}(?:\.\d{1,2})?$/', (string) $value ) ? (int) round( (float) $value * 100 ) : null;
    }
    public static function offer_check( $offer, $fingerprint, $now = null ) {
        $now = $now ?? time();
        if ( empty( $offer['id'] ) || empty( $offer['coverage_verified'] ) || ( $offer['fingerprint'] ?? '' ) !== $fingerprint || (int) ( $offer['expires_at'] ?? 0 ) <= $now || ! isset( $offer['price_cents'] ) || $offer['price_cents'] < 0 ) { return new WP_Error( 'stale_offer', 'La cotización venció o cambió el destino, los productos, el origen o los bultos. Pedí una nueva.' ); }
        return true;
    }
    public static function tracking_url( $url, $carrier ) {
        if ( '' === $url ) { return ''; }
        $p = wp_parse_url( $url );
        $domains = array( 'via_cargo'=>array('viacargo.com.ar'), 'correo'=>array('correoargentino.com.ar'), 'andreani'=>array('andreani.com','andreani.com.ar'), 'uber'=>array('uber.com') );
        if ( empty( $p['host'] ) || 'https' !== ( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== $p['port'] ) ) { return new WP_Error( 'tracking', 'Usá un enlace HTTPS oficial del transportista.' ); }
        foreach ( $domains[$carrier] ?? array() as $domain ) { $host = strtolower( $p['host'] ); if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) { return esc_url_raw( $url ); } }
        return new WP_Error( 'tracking', 'El enlace de seguimiento no pertenece al transportista seleccionado.' );
    }
}

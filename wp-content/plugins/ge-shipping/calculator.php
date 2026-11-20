<?php
// Pure package and tariff calculations, with grams, centimetres and ARS.
final class GE_Shipping_Calculator {
    public static function paper_grams($width, $length, $gsm, $quantity = 1) {
        return $width * $length * $gsm * $quantity / 10000;
    }

    public static function postcode($value) {
        return preg_match('/^(?:([A-Z])(\d{4})[A-Z]{3}|(\d{4}))$/D', strtoupper(trim((string) $value)), $matches)
            && (int) ($matches[3] ?? $matches[2]) >= 1000 ? (int) ($matches[3] ?? $matches[2]) : 0;
    }

    public static function quote(array $parcels, array $rows, array $destination, $divisor, $maximum_sum) {
        $cp = self::postcode($destination['postcode'] ?? '');
        $state = $destination['state'] ?? '';
        if (!$parcels || !$cp || !$state || ($destination['country'] ?? '') !== 'AR') {
            return null;
        }
        $postal = strtoupper(trim((string) $destination['postcode']));
        if (strlen($postal) === 8 && $postal[0] !== $state) {
            return null;
        }
        $cost = $actual = $billable = 0;
        foreach ($parcels as $parcel) {
            list($grams, $length, $width, $height, $count) = $parcel;
            if ($length + $width + $height > $maximum_sum) {
                return null;
            }
            $weight = (int) ceil(max($grams, $divisor > 0 ? 1000 * $length * $width * $height / $divisor : 0));
            $dimensions = array($length, $width, $height);
            rsort($dimensions, SORT_NUMERIC);
            $rate = null;
            // The first eligible row wins. Put narrower destinations and lower weight tiers first.
            foreach ($rows as $row) {
                list($province, $from, $to, $limit, $long, $medium, $short, $price) = $row;
                if (($province === '*' || $province === $state) && $cp >= $from && $cp <= $to
                    && $weight <= $limit && $dimensions[0] <= $long && $dimensions[1] <= $medium
                    && $dimensions[2] <= $short) {
                    $rate = (int) round($price * 100);
                    break;
                }
            }
            if ($rate === null) {
                return null;
            }
            $cost += $rate * $count;
            $actual += (int) ceil($grams) * $count;
            $billable += $weight * $count;
        }
        return array('cost' => $cost / 100, 'actual_g' => $actual, 'billable_g' => $billable);
    }

    public static function paper_parcels(array $spec, array $profile, $quantity) {
        list($width, $length, $gsm, $faces) = $spec;
        list($caliper, $laminateGsm, $laminateMm, $padding, $tare, $maximum) = $profile;
        $unit = self::paper_grams($width, $length, $gsm + $faces * $laminateGsm);
        $capacity = (int) floor(($maximum - $tare) / $unit);
        if ($capacity < 1 || $quantity < 1) {
            return array();
        }
        $groups = array($capacity => intdiv($quantity, $capacity));
        if ($quantity % $capacity) {
            $groups[$quantity % $capacity] = 1;
        }
        $parcels = array();
        foreach ($groups as $pieces => $count) {
            if ($count) {
                $parcels[] = array(
                    $unit * $pieces + $tare, $length + $padding, $width + $padding,
                    $pieces * ($caliper + $faces * $laminateMm) / 10 + $padding, $count,
                );
            }
        }
        return $parcels;
    }
}


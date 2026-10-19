<?php
/** Server-side candidate policy. Original analysis pixels only; never preview dimensions. */
final class GE_Deco_Print_Quality {
    const MINIMUM_PPI = 200;
    public static function metrics($pixels, $print_mm, $fit) {
        foreach (array($pixels, $print_mm) as $dimensions) {
            if (!is_array($dimensions) || count($dimensions) !== 2) { throw new InvalidArgumentException('missing_analysis_dimensions'); }
            foreach ($dimensions as $dimension) { if (!is_int($dimension) || $dimension <= 0) { throw new InvalidArgumentException('missing_analysis_dimensions'); } }
        }
        if (!in_array($fit, array('contain', 'cover'), true)) { throw new InvalidArgumentException('invalid_composition'); }
        $scales = array($print_mm[0] / $pixels[0], $print_mm[1] / $pixels[1]);
        $scale = 'contain' === $fit ? min($scales) : max($scales);
        return array('effective_ppi' => 25.4 / $scale, 'minimum_ppi' => self::MINIMUM_PPI);
    }
    public static function enforce($pixels, $print_mm, $fit) {
        $metrics = self::metrics($pixels, $print_mm, $fit);
        if ($metrics['effective_ppi'] < self::MINIMUM_PPI) { throw new InvalidArgumentException('insufficient_resolution'); }
        return $metrics;
    }
}

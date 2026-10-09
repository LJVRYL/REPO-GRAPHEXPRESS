<?php
/** Deterministic card profile over facts from the existing isolated CLI analyzer. */
defined('ABSPATH') || exit;
final class GE_Cards_Canva_Preflight {
    public static function evaluate($row, $pages) {
        $result = array('status'=>'pending','blockers'=>array(),'warnings'=>array(),'unverified'=>array('safe_area','cut_geometry','color_conversion'),'production_approved'=>false);
        if (!is_array($row) || !in_array($pages,array(1,2),true)) { $result['status']='blocked'; $result['blockers'][]='invalid_context'; return $result; }
        if (in_array($row['status'] ?? '',array('failed','blocker'),true)) { $result['status']='blocked'; $result['blockers'][]='analysis_failed'; return $result; }
        $facts = $row['facts'] ?? array();
        if (!$facts) { return $result; }
        if (!empty($facts['blockers']) || ($facts['status'] ?? '') === 'failed') { $result['blockers'][]='analysis_blocker'; }
        if (($facts['page_count'] ?? null) !== $pages) { $result['blockers'][]='page_count'; }
        $sizes = $facts['page_sizes'] ?? array(); $seen=array();
        foreach ($sizes as $size) {
            $page=$size['page'] ?? 0; $mm=$size['mm'] ?? array();
            if (!is_int($page) || $page<1 || $page>$pages || isset($seen[$page]) || count($mm)!==2 || !is_numeric($mm[0]) || !is_numeric($mm[1])) { $result['blockers'][]='page_geometry'; continue; }
            $seen[$page]=true;
            // Landscape media required: do not rotate, resize, or alter the original.
            if (abs((float)$mm[0]-95)>0.2 || abs((float)$mm[1]-65)>0.2) { $result['blockers'][]='page_dimensions'; }
        }
        if (count($seen)!==$pages) { $result['blockers'][]='page_geometry_unverified'; }
        if (($facts['encrypted'] ?? null)!==false) { $result['blockers'][]='encryption_unverified'; }
        if (($facts['fonts_embedded'] ?? null)===false) { $result['blockers'][]='fonts_not_embedded'; }
        elseif (($facts['fonts_embedded'] ?? null)!==true) { $result['unverified'][]='fonts_embedded'; }
        if (!array_key_exists('images',$facts) || !is_array($facts['images'])) { $result['unverified'][]='image_resolution'; }
        else foreach ($facts['images'] as $image) {
            if (($image['type'] ?? '')!=='image') { continue; }
            $dpi=$image['effective_dpi'] ?? array();
            if (count($dpi)!==2 || !is_numeric($dpi[0]) || !is_numeric($dpi[1])) { $result['unverified'][]='image_resolution'; }
            elseif (min((float)$dpi[0],(float)$dpi[1])<300) { $result['blockers'][]='image_resolution_below_300'; }
        }
        foreach (array('blockers','warnings','unverified') as $key) { $result[$key]=array_values(array_unique($result[$key])); }
        $result['status']=$result['blockers'] ? 'blocked' : 'review_required';
        return $result;
    }
}
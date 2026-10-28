<?php
defined('ABSPATH') || exit;

/** Fresh read-only observations may renew unchanged costs; never rewrite approved prices. */
final class GE_WTP_Deco_Observations {
    private static function full($policy) {
        static $cache=array();$revision=$policy['revision']??'';
        if(array_key_exists($revision,$cache))return $cache[$revision];
        if(!defined('GE_DECO_PRIVATE_ROOT'))return null;
        $file=rtrim(GE_DECO_PRIVATE_ROOT,'/\\').'/latest-pricing-observation.json';
        if(!is_file($file))return $cache[$revision]=null;
        if(is_link($file)||filesize($file)>4*MB_IN_BYTES)throw new InvalidArgumentException('invalid_price_observation');
        $data=json_decode(file_get_contents($file),true);
        if(!is_array($data)||($data['complete']??false)!==true)throw new InvalidArgumentException('invalid_price_observation');
        if(($data['approved_revision']??'')!==$revision)return $cache[$revision]=null;
        if(!is_int($data['observed_at']??null)||!is_string($data['source_digest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$data['source_digest']))throw new InvalidArgumentException('invalid_price_observation');
        foreach(array('frame_observed_at','print_observed_at') as $field){if(!is_int($data[$field]??null)||$data[$field]>time()+300||$data[$field]<time()-GE_WTP_Deco_Prints::PRICE_MAX_AGE)throw new InvalidArgumentException('stale_price_snapshot');}
        return $cache[$revision]=$data;
    }
    private static function current($policy) {
        static $cache=array();$revision=$policy['revision']??'';
        if(!is_string($revision))throw new InvalidArgumentException('invalid_partial_price_observation');
        if(array_key_exists($revision,$cache))return $cache[$revision];
        if(!defined('GE_DECO_PRIVATE_ROOT'))return self::full($policy);
        $file=rtrim(GE_DECO_PRIVATE_ROOT,'/\\').'/latest-pricing-partial-observation.json';
        if(!file_exists($file)&&!is_link($file))return $cache[$revision]=self::full($policy);
        if(!is_file($file)||is_link($file)||filesize($file)>4*MB_IN_BYTES)throw new InvalidArgumentException('invalid_partial_price_observation');
        $data=json_decode(file_get_contents($file),true);
        if(!is_array($data)||($data['schema_version']??null)!==1||($data['kind']??'')!=='graphex-price-evidence-per-option'||($data['complete']??null)!==false||!is_bool($data['source_complete']??null)||($data['approved_revision']??null)!==$revision)throw new InvalidArgumentException('invalid_partial_price_observation');
        if(!is_int($data['observed_at']??null)||$data['observed_at']>time()+300)throw new InvalidArgumentException('invalid_partial_price_observation');
        foreach(array('source_digest','printing_source_digest')as $field)if(!is_string($data[$field]??null)||!preg_match('/^[a-f0-9]{64}$/D',$data[$field]))throw new InvalidArgumentException('invalid_partial_price_observation');
        $rows=$data['options']??null;$approved=$policy['options']??array();
        if(!is_array($approved)||!is_array($rows)||count($rows)!==count($approved)||array_diff_key($rows,$approved)||array_diff_key($approved,$rows)||!is_array($policy['frame_source_map']??null))throw new InvalidArgumentException('invalid_partial_price_observation');
        $mapping=array();foreach($policy['frame_source_map']as $item){if(!is_array($item)||!is_string($item['public_id']??null)||!is_string($item['source']??null)||strpos($item['source'],'https://')!==0||!is_array($item['nominal_variant']??null))throw new InvalidArgumentException('invalid_partial_price_observation');$id=$item['public_id'];if(isset($mapping[$id]))throw new InvalidArgumentException('invalid_partial_price_observation');$mapping[$id]=$item;}
        foreach($rows as $id=>$row){
            $item=$mapping[$id]??array();$variant=(string)($item['supplier_variant_id']??'');
            if(!preg_match('/^opcion-[0-9]+$/D',$id)||!preg_match('/^[1-9][0-9]*$/D',$variant)||($approved[$id]['variant_ref']??'')!=='variant:'.$variant||!is_array($row)||($row['variant_id']??null)!==$variant||($row['source']??null)!==($item['source']??null)||!is_array($row['nominal_variant']??null)||!in_array($row['status']??'',array('verified','changed','unknown'),true)||!is_bool($row['unchanged']??null))throw new InvalidArgumentException('invalid_partial_price_observation');
            if(($row['status']==='verified')!==$row['unchanged'])throw new InvalidArgumentException('invalid_partial_price_observation');
            if($row['status']==='unknown'&&(isset($row['frame_observed_at'])||isset($row['print_observed_at'])))throw new InvalidArgumentException('invalid_partial_price_observation');
        }
        $data['_mapping']=$mapping;return $cache[$revision]=$data;
    }
    private static function partial($data){return ($data['kind']??'')==='graphex-price-evidence-per-option';}
    private static function partial_times($id,$policy,$data){
        $row=$data['options'][$id]??array();$approved=$policy['options'][$id]??array();$mapping=$data['_mapping'][$id]??array();
        if(($row['status']??'')!=='verified'||($row['unchanged']??false)!==true||($row['available']??false)!==true||($approved['available']??false)!==true||($approved['print_dimensions_verified']??false)!==true||($row['nominal_variant']??null)!==($mapping['nominal_variant']??null))throw new InvalidArgumentException('price_review_required');
        foreach(array('frame_cost_cents','printing_cents')as $field)if(!is_int($row[$field]??null)||$row[$field]<=0||$row[$field]!==($approved[$field]??null))throw new InvalidArgumentException('price_review_required');
        if(!is_string($row['source_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$row['source_sha256']))throw new InvalidArgumentException('price_review_required');
        $needs_reference=($approved['route']??'')==='gran-formato'&&!empty($policy['large_format_cost_rule']);
        if(($needs_reference||isset($row['reference_catalog_cents']))&&(!is_int($row['reference_catalog_cents']??null)||$row['reference_catalog_cents']!==($policy['large_format_reference_cents']??null)))throw new InvalidArgumentException('price_review_required');
        $now=time();foreach(array($data['observed_at'],$row['frame_observed_at']??null,$row['print_observed_at']??null)as $date)if(!is_int($date)||$date>$now+300||$date<$now-GE_WTP_Deco_Prints::PRICE_MAX_AGE||$date>$data['observed_at']+300)throw new InvalidArgumentException('stale_price_snapshot');
        return array($row['frame_observed_at'],$row['print_observed_at']);
    }
    public static function times($policy) {
        $data=self::current($policy);
        // Partial evidence has no global frame timestamp. Every option goes through
        // allowed() when listed/materialized and verify() on selection/cart/checkout.
        if(self::partial($data))return array();
        return $data?array($data['frame_observed_at'],$data['print_observed_at']):array($policy['frame_observed_at'],$policy['print_observed_at']);
    }
    public static function allowed($id,$policy) {
        $data=self::current($policy);
        if(self::partial($data)){try{self::partial_times($id,$policy,$data);return true;}catch(InvalidArgumentException $error){return false;}}
        return !$data||($data['options'][$id]['unchanged']??false)===true;
    }
    public static function verify($id,$entry) {
        $policy=get_option('ge_deco_commercial_release',array());$data=self::current($policy);
        if(self::partial($data)){
            if(($entry['cost_version_ref']??null)!==($policy['revision']??null)||($entry['supplier_variant_ref']??null)!==($policy['options'][$id]['variant_ref']??null))throw new InvalidArgumentException('price_review_required');
            return self::partial_times($id,$policy,$data);
        }
        if(!$data)return array($entry['frame_observed_at'],$entry['print_observed_at']);
        if(($data['options'][$id]['unchanged']??false)!==true)throw new InvalidArgumentException('price_review_required');
        return array($data['frame_observed_at'],$data['print_observed_at']);
    }
    public static function evidence() {
        $policy=get_option('ge_deco_commercial_release',array());$data=self::current($policy);
        if(self::partial($data))return array('observed_at'=>$data['observed_at'],'approved_revision'=>$data['approved_revision'],'source_digest'=>$data['source_digest'],'per_option'=>true,'source_complete'=>$data['source_complete']);
        return $data?array('observed_at'=>$data['observed_at'],'approved_revision'=>$data['approved_revision'],'source_digest'=>$data['source_digest']):array('approved_revision'=>$policy['revision']??'','source'=>'approved_snapshot');
    }
}

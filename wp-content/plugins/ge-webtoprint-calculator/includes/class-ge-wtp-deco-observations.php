<?php
defined('ABSPATH') || exit;

/** Fresh read-only observations may renew unchanged costs; never rewrite approved prices. */
final class GE_WTP_Deco_Observations {
    private static function current($policy) {
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
    public static function times($policy) {
        $data=self::current($policy);return $data?array($data['frame_observed_at'],$data['print_observed_at']):array($policy['frame_observed_at'],$policy['print_observed_at']);
    }
    public static function allowed($id,$policy) {
        $data=self::current($policy);return !$data||($data['options'][$id]['unchanged']??false)===true;
    }
    public static function verify($id,$entry) {
        $policy=get_option('ge_deco_commercial_release',array());$data=self::current($policy);
        if(!$data)return array($entry['frame_observed_at'],$entry['print_observed_at']);
        if(($data['options'][$id]['unchanged']??false)!==true)throw new InvalidArgumentException('price_review_required');
        return array($data['frame_observed_at'],$data['print_observed_at']);
    }
    public static function evidence() {
        $policy=get_option('ge_deco_commercial_release',array());$data=self::current($policy);
        return $data?array('observed_at'=>$data['observed_at'],'approved_revision'=>$data['approved_revision'],'source_digest'=>$data['source_digest']):array('approved_revision'=>$policy['revision']??'','source'=>'approved_snapshot');
    }
}

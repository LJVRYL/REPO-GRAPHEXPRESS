<?php
/** Retention applies only to integrity-checked Canva imports registered by this module. */
defined('ABSPATH') || exit;
final class GE_Cards_Canva_Retention {
    const PREFIX = 'ge_cc_retention_';
    const DUE = 'ge_cc_retention_due_';
    const ENABLED = 'ge_cards_canva_retention_enabled_v1';
    public static function init() {
        add_filter('ge_vps_pending_file_expired',array(__CLASS__,'pending_expired'),10,2);
        add_action('ge_vps_upload_finalized',array(__CLASS__,'finalized'),10,4);
        add_action('ge_cards_canva_cleanup',array(__CLASS__,'cleanup'),20);
    }
    private static function root() {
        $root=defined('GE_WTP_PRIVATE_UPLOAD_DIR') ? GE_WTP_PRIVATE_UPLOAD_DIR : getenv('GE_WTP_PRIVATE_UPLOAD_DIR');
        $base=$root ? realpath($root) : false;
        $public=realpath(ABSPATH);
        return $base && $public && $base!==$public && strpos($base,$public.DIRECTORY_SEPARATOR)!==0 && !is_link($root) ? $base : '';
    }
    private static function relative($path) {
        $base=self::root(); $real=is_string($path) && !is_link($path) ? realpath($path) : false;
        return $base && $real && strpos($real,$base.DIRECTORY_SEPARATOR)===0 ? str_replace(DIRECTORY_SEPARATOR,'/',substr($real,strlen($base)+1)) : '';
    }
    private static function valid($r) {
        return is_array($r) && ($r['version']??0)===1 && preg_match('/^[a-f0-9]{64}$/D',$r['sha256']??'') && ($r['user_id']??0)>0 && ($r['created_at']??0)>0 && ($r['expires_at']??0)>$r['created_at'] && !empty($r['organization']) && !empty($r['relative_path']);
    }
    private static function save($id,$r) {
        update_option(self::PREFIX.$id,$r,false);
        update_option(self::DUE.$id,$r['expires_at'],false);
    }
    public static function track($path,$sha,$user,$organization) {
        $relative=self::relative($path);
        if (!preg_match('~^pending/([1-9][0-9]*)/[0-9]{4}/[0-9]{2}/[a-f0-9-]{36}/[^/]+\.pdf$~iD',$relative,$m) || (int)$m[1]!== (int)$user || !preg_match('/^[a-f0-9]{64}$/D',$sha) || !$organization || !is_file($path) || !hash_equals($sha,hash_file('sha256',$path))) { return false; }
        $id=hash('sha256',$relative); $existing=get_option(self::PREFIX.$id);
        if ($existing) { return self::valid($existing) && $existing['relative_path']===$relative && hash_equals($existing['sha256'],$sha) && (int)$existing['user_id']===(int)$user; }
        $created=min(time(),filemtime($path));
        self::save($id,array('version'=>1,'relative_path'=>$relative,'sha256'=>$sha,'user_id'=>(int)$user,'organization'=>$organization,'created_at'=>$created,'expires_at'=>$created+30*86400,'order_id'=>0));
        return true;
    }
    public static function pending_expired($expired,$path) {
        $relative=self::relative($path); if (!$relative) { return $expired; }
        $r=get_option(self::PREFIX.hash('sha256',$relative));
        // Registered imports are removed only by our hash-checked bounded cleanup.
        return self::valid($r) && $r['relative_path']===$relative ? false : $expired;
    }
    public static function finalized($descriptor,$source_relative,$order_id,$item_id) {
        $id=hash('sha256',$source_relative); $r=get_option(self::PREFIX.$id);
        if (!self::valid($r) || $r['relative_path']!==$source_relative || ($descriptor['provider']??'')!=='vps' || (int)$order_id<1) { return; }
        $relative=$descriptor['relative_path']??'';
        if (strpos($relative,'orders/'.(int)$order_id.'/'.(int)$item_id.'/')!==0) { return; }
        $path=self::root().DIRECTORY_SEPARATOR.$relative;
        if (self::relative($path)!==$relative || !is_file($path) || !hash_equals($r['sha256'],hash_file('sha256',$path))) { return; }
        $order=function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order || (int)$order->get_customer_id()!==$r['user_id']) { return; }
        $date=$order->get_date_created(); $start=time();
        $deadline=(new DateTimeImmutable('@'.$start))->modify('+12 months')->getTimestamp();
        $r['relative_path']=$relative; $r['order_id']=(int)$order_id; $r['order_item_id']=(int)$item_id; $r['expires_at']=$deadline; $r['order_created_at']=$date ? $date->getTimestamp() : $start; $r['associated_at']=$start;
        self::save($id,$r);
        // Path lookup remains available for integrity checks after consolidation.
        update_option(self::PREFIX.hash('sha256',$relative),array('alias'=>$id),false);
    }
    private static function defer($name,$dry_run) { if (!$dry_run) { update_option($name,time()+86400,false); } }
    public static function cleanup($dry_run=false) {
        $summary=array('examined'=>0,'eligible'=>0,'deleted'=>0,'held'=>0,'missing'=>0,'failed'=>0);
        if (!$dry_run && get_option(self::ENABLED,'no')!=='yes') { return $summary; }
        $base=self::root(); if (!$base) { return $summary; }
        global $wpdb;
        $names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP '^ge_cc_retention_due_[a-f0-9]{64}$' AND option_value REGEXP '^[0-9]+$' AND CAST(option_value AS UNSIGNED)<=%d ORDER BY CAST(option_value AS UNSIGNED), option_id LIMIT 100",time()));
        foreach ($names as $name) {
            $summary['examined']++; $id=substr($name,strlen(self::DUE)); $r=get_option(self::PREFIX.$id);
            if (!self::valid($r) || $r['expires_at']>time()) { $summary['held']++; self::defer($name,$dry_run); continue; }
            if (!empty($r['order_id'])) {
                $order=function_exists('wc_get_order') ? wc_get_order($r['order_id']) : false;
                if (!$order || (int)$order->get_customer_id()!==$r['user_id'] || !in_array($order->get_status(),array('completed','cancelled','refunded','failed'),true)) { $summary['held']++; self::defer($name,$dry_run); continue; }
                if ($order->get_meta('_ge_canva_retention_hold')==='yes') { $summary['held']++; self::defer($name,$dry_run); continue; }
                if (strpos($r['relative_path'],'orders/'.$r['order_id'].'/'.(int)$r['order_item_id'].'/')!==0) { $summary['held']++; self::defer($name,$dry_run); continue; }
            } elseif (strpos($r['relative_path'],'pending/'.$r['user_id'].'/')!==0) { $summary['held']++; self::defer($name,$dry_run); continue; }
            $path=$base.DIRECTORY_SEPARATOR.$r['relative_path'];
            // Fail closed on traversal, parent symlinks, changed bytes and path substitution.
            if (!file_exists($path)) { $summary['missing']++; self::defer($name,$dry_run); continue; }
            if (strpos($r['relative_path'],'\\')!==false || strpos($r['relative_path'],"\0")!==false || self::relative($path)!==$r['relative_path'] || !is_file($path) || !hash_equals($r['sha256'],hash_file('sha256',$path))) { $summary['held']++; self::defer($name,$dry_run); continue; }
            $summary['eligible']++;
            if ($dry_run) { continue; }
            $before=lstat($path); clearstatcache(true,$path); $after=lstat($path);
            if (!$before || !$after || $before['ino']!==$after['ino'] || $before['size']!==$after['size'] || !unlink($path)) { $summary['failed']++; self::defer($name,$dry_run); continue; }
            $summary['deleted']++; $r['deleted_at']=time(); unset($r['relative_path']);
            update_option(self::PREFIX.$id,array('version'=>1,'deleted_at'=>$r['deleted_at'],'order_id'=>$r['order_id'],'sha256'=>$r['sha256']),false);
            delete_option($name);
            if (!empty($r['order_id'])) { delete_option(self::PREFIX.hash('sha256',str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($base)+1)))); }
        }
        if (!$dry_run) { update_option('ge_cards_canva_retention_last_run_v1',array('at'=>time(),'summary'=>$summary),false); }
        return $summary;
    }
}
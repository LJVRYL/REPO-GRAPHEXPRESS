<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/class-ge-meta-test-assets.php';

/** Reviewer access is a private, expiring list of exact owned test conversations. */
final class GE_Meta_Review_Policy {
    /** Restriction remains in force when review access is disabled or expired. */
    public static function restricted_actor($cfg,$actor) {
        return is_array($cfg) && is_int($cfg['reviewer_user_id'] ?? null) && $actor>0 && $cfg['reviewer_user_id']===$actor;
    }
    public static function allowed_path($uri,$action='') {
        $path=rtrim((string)parse_url($uri,PHP_URL_PATH),'/');
        $query=array();parse_str((string)parse_url($uri,PHP_URL_QUERY),$query);
        if ($path==='/meta-review') return !array_diff(array_keys($query),array('record_id'));
        return $path==='/wp-login.php' && $action==='logout' && !array_diff(array_keys($query),array('action','redirect_to','_wpnonce'));
    }
    public static function authorize($cfg,$actor,$now) {
        if (!is_array($cfg) || ($cfg['organization_id'] ?? '')!=='graph-express' || ($cfg['enabled'] ?? false)!==true
            || !is_int($cfg['reviewer_user_id'] ?? null) || $actor!==$cfg['reviewer_user_id'] || $actor<1
            || !is_int($cfg['sender_principal_id'] ?? null) || $cfg['sender_principal_id']<1 || $cfg['sender_principal_id']===$actor
            || !is_int($cfg['created_at'] ?? null) || !is_int($cfg['expires_at'] ?? null)
            || $cfg['created_at']<1 || $cfg['created_at']>$now || $cfg['expires_at']<=$now || $cfg['expires_at']>$cfg['created_at']+30*86400
            || !is_array($cfg['bindings'] ?? null) || !$cfg['bindings'] || count($cfg['bindings'])>3) throw new RuntimeException('Review access unavailable or expired.',403);
        $assets=array('instagram'=>'instagram:17841407285480956','messenger'=>'messenger:1377222212141366','whatsapp'=>GE_Meta_Test_Assets::account());
        $seen=array();
        foreach ($cfg['bindings'] as $id=>$b) {
            if (!ctype_digit((string)$id) || (int)$id<1 || !is_array($b) || !is_string($b['channel'] ?? null) || !isset($assets[$b['channel']])
                || isset($seen[$b['channel']]) || !is_string($b['peer'] ?? null) || !is_string($b['conversation_key'] ?? null)
                || ($b['account_ref'] ?? '')!==$assets[$b['channel']] || !preg_match('/^[0-9]{5,40}$/D',$b['peer'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/D',$b['conversation_key'] ?? '')
                || $b['conversation_key']!==hash('sha256',$b['channel'].':'.$b['account_ref'].':'.$b['peer'])
                || ($b['channel']==='whatsapp' && $b['peer']!==GE_Meta_Test_Assets::RECIPIENT)) throw new RuntimeException('Invalid review conversation scope.',403);
            $seen[$b['channel']]=true;
        }
        return $cfg;
    }
    public static function binding($cfg,$id) {
        if (!is_int($id) || $id<1 || !isset($cfg['bindings'][(string)$id])) throw new RuntimeException('Conversation outside review scope.',403);
        return $cfg['bindings'][(string)$id];
    }
    public static function conversation($r,$b) {
        $e=$r['attention_event'] ?? $r['meta_event'] ?? array();
        return ($r['organization_id'] ?? '')==='graph-express' && ($r['kind'] ?? '')==='thread'
            && ($r['channel'] ?? '')===$b['channel'] && ($r['conversation_key'] ?? '')===$b['conversation_key']
            && ($e['account_ref'] ?? '')===$b['account_ref'] && (string)($e['peer'] ?? $e['from'] ?? '')===$b['peer'];
    }
    public static function record($r,$b,$id) {
        if ((int)($r['id'] ?? 0)!==$id || !self::conversation($r,$b)) throw new RuntimeException('Conversation identity changed; review access denied.',403);
        return $r;
    }
}

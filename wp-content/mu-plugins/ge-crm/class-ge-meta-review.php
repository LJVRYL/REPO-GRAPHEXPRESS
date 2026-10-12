<?php
defined('ABSPATH') || exit;

/** Dedicated test-only surface. Reviewer never becomes a CRM/organization member. */
final class GE_Meta_Review {
    const CONFIG='/home/graphexpress/meta-review/config.json';
    public static function init() { add_action('template_redirect',array(__CLASS__,'route'),0); }
    public static function config() {
        $p=self::CONFIG;
        if (PHP_SAPI==='cli') {
            $qa=getenv('GE_META_REVIEW_CONFIG');
            if ($qa && strpos($qa,'/job-flow-qa-')!==false && strpos($qa,'/site/')===false) $p=$qa;
        }
        $c=is_readable($p) ? json_decode(file_get_contents($p),true) : array();
        return is_array($c) ? $c : array();
    }
    private static function access() {
        return GE_Meta_Review_Policy::authorize(self::config(),(int)get_current_user_id(),time());
    }
    private static function principal($cfg,$fn) {
        $actor=get_current_user_id(); wp_set_current_user($cfg['sender_principal_id']);
        try {
            if (GE_CRM::org()!=='graph-express' || !GE_CRM::can(true) || !in_array(GE_CRM::role(get_current_user_id()),array('owner','admin'),true)) throw new RuntimeException('Review sender principal unavailable.',403);
            return $fn();
        } finally { wp_set_current_user($actor); }
    }
    public static function view($id) {
        $cfg=self::access();$b=GE_Meta_Review_Policy::binding($cfg,$id);
        return self::principal($cfg,function()use($id,$b) {
            $r=GE_Meta_Review_Policy::record(GE_CRM::get($id,'thread'),$b,$id);$items=array();
            foreach (GE_CRM_Outbound::incoming($r) as $other) {
                if (!GE_Meta_Review_Policy::conversation($other,$b)) continue;
                $e=$other['attention_event'] ?? $other['meta_event'] ?? array();
                $items[]=array('out'=>false,'text'=>$e['body'] ?? '', 'time'=>$e['timestamp'] ?? $e['received_at'] ?? '', 'state'=>'');
            }
            foreach (GE_CRM_Outbound::history($r) as $row) {
                $d=$row['details'];$channel=$d['channel'] ?? '';
                if ($channel==='whatsapp_test' && $b['channel']==='whatsapp') $channel='whatsapp';
                if (($d['account_ref'] ?? '')!==$b['account_ref'] || $channel!==$b['channel'] || (string)($d['recipient'] ?? '')!==$b['peer']) continue;
                $items[]=array('out'=>true,'text'=>$d['text'] ?? '', 'time'=>$row['created_at'].'Z','state'=>$d['state'] ?? 'unknown');
            }
            usort($items,function($a,$b){return strtotime($a['time'])<=>strtotime($b['time']);});
            return array('id'=>$id,'channel'=>$b['channel'],'messages'=>$items);
        });
    }
    public static function send($request) {
        $cfg=self::access();$actor=(int)get_current_user_id();$id=$request['record_id'] ?? null;
        $b=GE_Meta_Review_Policy::binding($cfg,$id);
        if (!is_string($request['nonce'] ?? null) || !wp_verify_nonce($request['nonce'],'ge_meta_review_reply')) throw new RuntimeException('Session verification failed. Reload and try again.',403);
        if (!is_string($request['text'] ?? null) || !is_string($request['request_id'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D',$request['request_id'])) throw new RuntimeException('Invalid manual reply.',422);
        return self::principal($cfg,function()use($cfg,$id,$b,$request,$actor) {
            $r=GE_Meta_Review_Policy::record(GE_CRM::get($id,'thread'),$b,$id);
            GE_CRM::event($id,0,'meta_review_reply_requested',array('reviewer_user_id'=>$actor,'request_id'=>$request['request_id'],'conversation_key'=>$b['conversation_key'],'text_sha256'=>hash('sha256',$request['text'])));
            return GE_CRM_Outbound::send(array('record_id'=>$id,'request_id'=>$request['request_id'],'text'=>$request['text']));
        });
    }
    public static function route() {
        if (rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH),'/')!=='/meta-review') return;
        $raw=self::config();
        if (empty($raw['enabled'])) { status_header(404);nocache_headers();exit('Review access is not enabled.'); }
        if (!is_user_logged_in()) { wp_safe_redirect(wp_login_url(home_url('/meta-review/')));exit; }
        nocache_headers();header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
        $error='';$feedback='';$id=0;
        try {
            $cfg=self::access();$candidate=$_GET['record_id'] ?? (string)array_key_first($cfg['bindings']);
            if (!is_string($candidate) || !ctype_digit($candidate)) throw new RuntimeException('Invalid conversation selection.',403);
            $id=(int)$candidate;
            $data=self::view($id);
            if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
                $result=self::send(array('record_id'=>$id,'nonce'=>wp_unslash($_POST['_wpnonce'] ?? ''),'text'=>wp_unslash($_POST['text'] ?? ''),'request_id'=>wp_unslash($_POST['request_id'] ?? '')));
                set_transient('ge_meta_review_feedback_'.get_current_user_id(),$result,120);
                wp_safe_redirect(add_query_arg('record_id',$id,home_url('/meta-review/')));exit;
            }
            $feedback=get_transient('ge_meta_review_feedback_'.get_current_user_id());delete_transient('ge_meta_review_feedback_'.get_current_user_id());
        } catch (RuntimeException $e) { status_header($e->getCode()===403?403:422);$error=$e->getMessage(); }
        if ($error && empty($data)) { self::page_header();echo '<main class="ge-crm ge-meta-review"><h1>Review access unavailable</h1><p role="alert">'.esc_html($error).'</p></main></body></html>';exit; }
        self::page_header();
        $org=GE_Organization::get(GE_Organization::PRIMARY);$logo=$org['branding']['logo_url'] ?? '';
        if (!$logo && defined('GE_WTP_PLUGIN_URL')) $logo=GE_WTP_PLUGIN_URL.'assets/images/graphex-simbolo.svg';
        echo '<main class="ge-crm ge-meta-review"><header class="ge-crm-heading"><div>'.($logo?'<img class="ge-meta-review-logo" src="'.esc_url($logo).'" alt="Graphex" width="64" height="64">':'').'<span>GRAPHEX · META REVIEW</span><h1>Test conversations</h1><p>Messages received through Meta and manual replies from Graphex CRM.</p></div><a href="'.esc_url(wp_logout_url(home_url('/meta-review/'))).'">Sign out</a></header><nav class="ge-crm-tabs" aria-label="Test channels">';
        $labels=array('instagram'=>'Instagram @graphex.ar','messenger'=>'Messenger · Graphex','whatsapp'=>'WhatsApp · official Meta test');
        foreach ($cfg['bindings'] as $record=>$binding) echo '<a '.((int)$record===$id?'aria-current="page"':'').' href="'.esc_url(add_query_arg('record_id',(int)$record,home_url('/meta-review/'))).'">'.esc_html($labels[$binding['channel']]).'</a>';
        echo '</nav><section class="ge-crm-panel ge-crm-chat"><h2>'.esc_html($labels[$data['channel']]).'</h2><p>This access covers the owner’s test conversations only. Customer records and administration are unavailable.</p>';
        if ($error) echo '<p role="alert">'.esc_html($error).'</p>';
        if (is_array($feedback)) echo '<p role="status">'.esc_html($feedback['state']==='accepted'?'Meta accepted the reply; delivery confirmation follows.':($feedback['error'] ?: self::state_label($feedback['state']))).'</p>';
        echo '<ol class="ge-crm-chat-messages" aria-label="Conversation messages">';
        foreach ($data['messages'] as $m) echo '<li class="'.($m['out']?'is-outgoing':'is-incoming').'"><strong>'.($m['out']?'Graphex CRM':'Test user').'</strong><p>'.nl2br(esc_html($m['text'])).'</p><small>'.esc_html($m['time']).($m['state']?' · '.esc_html(self::state_label($m['state'])):'').'</small></li>';
        echo '</ol><form method="post"><label for="review-reply">Manual reply</label><textarea id="review-reply" name="text" rows="4" maxlength="1000" required></textarea><input type="hidden" name="request_id" value="'.esc_attr(wp_generate_uuid4()).'">';wp_nonce_field('ge_meta_review_reply');
        echo '<p>Sending replies to the original test user. Meta’s 24-hour reply window applies; a fresh incoming message is required after it expires.</p><button>Send manual reply</button></form></section><p>Refresh this page to see new messages and delivery/read confirmations. No automatic replies, publishing or AI calls are enabled on this review page.</p></main></body></html>';exit;
    }
    public static function state_label($state) {
        $labels=array('accepted'=>'Accepted by Meta · delivery pending','sending'=>'Sending · confirmation pending','delivered'=>'Delivered','read'=>'Read','failed'=>'Send failed','unknown'=>'Unconfirmed · inspect before retrying','sent'=>'Accepted by Meta · delivery pending','simulated'=>'Simulated QA result');
        return $labels[$state] ?? 'Unconfirmed';
    }
    private static function page_header() {
        status_header(http_response_code()>=400?http_response_code():200);
        $base=content_url('/mu-plugins/ge-crm/');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Graphex CRM · Meta review</title><link rel="stylesheet" href="'.esc_url($base.'crm.css').'"><link rel="stylesheet" href="'.esc_url($base.'reply.css').'"><link rel="stylesheet" href="'.esc_url($base.'meta-review.css').'"></head><body>';
    }
}

<?php
defined('ABSPATH') || exit;

/** Inbound social adapters share the encrypted queue and private worker with WhatsApp. */
final class GE_Meta_Social {
    public static function ready($cfg) {
        if (empty($cfg['social_enabled']) || ($cfg['organization_id'] ?? '') !== 'graph-express') return false;
        foreach (array('app_secret','verify_token','storage_key') as $k) if (!is_string($cfg[$k] ?? null)) return false;
        if (strlen($cfg['app_secret']) < 32 || strlen($cfg['verify_token']) < 32 || strlen(base64_decode($cfg['storage_key'],true) ?: '') !== 32) return false;
        foreach (array('instagram_ids','page_ids') as $k) {
            if (!is_array($cfg[$k] ?? null)) return false;
            foreach ($cfg[$k] as $id) if (!is_string($id) || !preg_match('/^[0-9]{5,30}$/D',$id)) return false;
        }
        return (bool)($cfg['instagram_ids'] || $cfg['page_ids']);
    }
    private static function id($id) {
        if (!is_string($id) || $id === '' || strlen($id)>190 || preg_match('/[\x00-\x1f]/',$id)) throw new InvalidArgumentException('Invalid scoped ID');
        return $id;
    }
    public static function events($data,$cfg) {
        if (!self::ready($cfg)) throw new InvalidArgumentException('Social assets not enabled');
        $channel=$data['object'] === 'instagram' ? 'instagram' : 'messenger';
        $assets=$cfg[$channel === 'instagram' ? 'instagram_ids' : 'page_ids'];
        if (empty($data['entry']) || !is_array($data['entry'])) throw new InvalidArgumentException('Empty envelope');
        $out=array();
        foreach ($data['entry'] as $entry) {
            if (!is_array($entry) || !in_array((string)($entry['id'] ?? ''),$assets,true)) throw new InvalidArgumentException('Foreign social asset');
            $asset=(string)$entry['id'];
            $base=array('channel'=>$channel,'account_ref'=>$channel . ':' . $asset,'asset_id'=>$asset);
            if (!isset($entry['messaging'])) {
                // Changes/comments are conserved for review, never claimed as supported DMs.
                $out[]=array_merge($base,array('kind'=>'review','data'=>$entry)); continue;
            }
            if (!is_array($entry['messaging']) || !$entry['messaging']) throw new InvalidArgumentException('Invalid messaging list');
            foreach ($entry['messaging'] as $m) {
                if (!is_array($m)) throw new InvalidArgumentException('Invalid event');
                $sender=self::id($m['sender']['id'] ?? ''); $recipient=self::id($m['recipient']['id'] ?? '');
                if ($sender !== $asset && $recipient !== $asset) throw new InvalidArgumentException('Foreign recipient');
                $peer=$sender === $asset ? $recipient : $sender;
                $ts=$m['timestamp'] ?? null;
                if (!is_numeric($ts) || (float)$ts<1 || (float)$ts > (time()+300)*1000) throw new InvalidArgumentException('Invalid timestamp');
                $baseEvent=array_merge($base,array('peer'=>$peer,'timestamp'=>gmdate('Y-m-d\TH:i:s\Z',(int)floor($ts/1000))));
                if (isset($m['message'])) {
                    $msg=$m['message']; if (!is_array($msg)) throw new InvalidArgumentException('Invalid message');
                    $body=(string)($msg['text'] ?? ''); $attachments=$msg['attachments'] ?? array();
                    if (!is_array($attachments)) throw new InvalidArgumentException('Invalid attachments');
                    $kind=(!empty($msg['is_echo']) || $sender === $asset) ? 'echo' : 'message';
                    if (!empty($msg['is_deleted'])) $kind='revoke';
                    $out[]=array_merge($baseEvent,array('kind'=>$kind,'message_id'=>self::id($msg['mid'] ?? ''),'body'=>mb_strcut($body,0,12000,'UTF-8'),'truncated'=>strlen($body)>12000,'attachments'=>$attachments,'media_pending'=>(bool)$attachments,'data'=>$m));
                } elseif (isset($m['delivery']['mids'])) {
                    if (!is_array($m['delivery']['mids'])) throw new InvalidArgumentException('Invalid delivery');
                    foreach ($m['delivery']['mids'] as $mid) $out[]=array_merge($baseEvent,array('kind'=>'status','message_id'=>self::id($mid),'status'=>'delivered'));
                } elseif (isset($m['read'])) {
                    // Watermark is conversation-wide; do not invent a particular read message ID.
                    $out[]=array_merge($baseEvent,array('kind'=>'watermark','message_id'=>'','watermark'=>$m['read']['watermark'] ?? null,'data'=>$m));
                } else $out[]=array_merge($baseEvent,array('kind'=>'review','data'=>$m));
                if (count($out)>10000) throw new InvalidArgumentException('Batch too large');
            }
        }
        if (!$out) throw new InvalidArgumentException('Empty batch');
        return $out;
    }
    public static function apply($e,$receipt) {
        if ($e['kind'] === 'message') {
            $r=GE_CRM::meta_ingest($e,$receipt);
            GE_CRM::whatsapp_transport_event($r['record_id'],$e,$receipt);
            $channels=get_option('ge_crm_attention_channels',array());
            $channels[$e['channel']]['transport_verified_at']=gmdate('c');
            update_option('ge_crm_attention_channels',$channels,false);
            return;
        }
        $id=GE_CRM::whatsapp_find_thread($e['account_ref'],$e['message_id'] ?? '',$e['peer'] ?? '',$e['channel']);
        if ($id) { GE_CRM::whatsapp_transport_event($id,$e,$receipt); return; }
        GE_CRM::require_access(true);
        GE_CRM::locked(function() use ($e,$receipt) {
            GE_CRM::automation_task('meta-review:' . hash('sha256',wp_json_encode($e)),array('title'=>'Revisar evento ' . ucfirst($e['channel']),'notes'=>'Evento conservado sin conversación vinculada. Referencia privada meta-receipt:' . (int)$receipt,'owner_id'=>get_current_user_id(),'status'=>'open','priority'=>'high','source'=>'meta-official'));
        });
    }
    public static function render_message($r) {
        if (empty($r['meta_event'])) return;
        $e=$r['meta_event'];
        echo '<section class="ge-crm-panel"><h2>Mensaje original de ' . esc_html(ucfirst($e['channel'])) . '</h2><p>Recibido: ' . esc_html($e['timestamp']) . '</p><pre style="white-space:pre-wrap">' . esc_html($e['body']) . '</pre><p>ID de origen: ' . esc_html($e['message_id']) . '</p>';
        if (!empty($e['media_pending'])) echo '<p><strong>Adjuntos pendientes de descarga segura y revisión.</strong></p>';
        echo '<p>Asociar al cliente sólo después de verificar su identidad. Las respuestas requieren revisión humana y no se envían desde este receptor.</p></section>';
    }
}

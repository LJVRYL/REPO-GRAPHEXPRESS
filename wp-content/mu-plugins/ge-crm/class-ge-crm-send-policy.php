<?php
defined('ABSPATH') || exit;

/** Pure request planner. Recipients come only from the original scoped event. */
final class GE_CRM_Send_Policy {
    public static function plan($r, $config, $text, $now) {
        if (($r['kind'] ?? '') !== 'thread' || ($r['organization_id'] ?? '') !== 'graph-express') throw new RuntimeException('Conversación fuera de Graphex.',403);
        if (($r['status'] ?? '') === 'closed') throw new RuntimeException('Reabrí la conversación antes de responder.',409);
        if (!is_string($text) || trim($text) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)) throw new RuntimeException('Escribí una respuesta válida.',422);
        $channel=$r['channel'] ?? ''; $e=$r['meta_event'] ?? $r['attention_event'] ?? array();
        $limit=$channel === 'instagram' ? 1000 : ($channel === 'messenger' ? 2000 : 4096);
        if (mb_strlen($text,'UTF-8') > $limit) throw new RuntimeException('La respuesta supera el límite de este canal: '.$limit.' caracteres.',422);
        if ($channel === 'email') {
            if (empty($config['email']['enabled'])) throw new RuntimeException('Las respuestas por correo todavía no están habilitadas.',409);
            $from=$e['from'] ?? ''; $to=$e['to'] ?? '';
            if (!filter_var($from,FILTER_VALIDATE_EMAIL) || !in_array(strtolower($to),array_map('strtolower',$config['email']['recipients'] ?? array()),true) || !empty($e['sender_is_internal'])) throw new RuntimeException('No hay un destinatario de correo verificado.',422);
            return array('channel'=>'email','recipient'=>$from,'asset_id'=>$to,'account_ref'=>'email:'.strtolower($to),'text'=>$text,'subject'=>'Re: '.preg_replace('/^(Re:\s*)+/i','',(string)($e['subject'] ?? 'Consulta a Graphex')),'headers'=>$e['headers'] ?? array());
        }
        if (!in_array($channel,array('whatsapp','instagram','messenger'),true)) throw new RuntimeException('Este canal no permite enviar desde el CRM.',409);
        $c=$config[$channel] ?? array();
        if (empty($c['enabled']) || empty($c['access_token']) || empty($c['asset_id'])) throw new RuntimeException('Falta conectar el envío de '.ucfirst($channel).'.',409);
        $asset=(string)$c['asset_id']; $peer=(string)($e['peer'] ?? $e['from'] ?? '');
        if (!preg_match('/^[0-9]{5,30}$/D',$asset) || !preg_match('/^[0-9]{5,40}$/D',$peer)) throw new RuntimeException('Falta la identidad verificada del canal.',422);
        $account=$channel === 'whatsapp' ? 'wa:'.($c['waba_id'] ?? '').':'.$asset : $channel.':'.$asset;
        if (($e['account_ref'] ?? '') !== $account || ($e['channel'] ?? $channel) !== $channel) throw new RuntimeException('La cuenta configurada no coincide con la conversación.',403);
        $stamp=strtotime($e['timestamp'] ?? $e['received_at'] ?? '');
        if (!$stamp || $stamp>$now+300 || $now-$stamp>=86400) throw new RuntimeException('Pasaron las 24 horas para responder por este canal. Esperá un nuevo mensaje del cliente.',409);
        $message=$channel === 'whatsapp' ? array('messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$peer,'type'=>'text','text'=>array('preview_url'=>false,'body'=>$text)) : array('recipient'=>array('id'=>$peer),'message'=>array('text'=>$text));
        if ($channel === 'messenger') $message['messaging_type']='RESPONSE';
        return array('channel'=>$channel,'recipient'=>$peer,'asset_id'=>$asset,'account_ref'=>$account,'text'=>$text,'url'=>'https://graph.facebook.com/v26.0/'.$asset.'/messages','payload'=>$message);
    }
    /** Ambiguous responses never become sent, and must never trigger automatic retry. */
    public static function result($status,$body) {
        $p=json_decode($body,true);
        $id=is_array($p) ? ($p['messages'][0]['id'] ?? $p['message_id'] ?? '') : '';
        if ($status>=200 && $status<300 && is_string($id) && strlen($id)<=190 && $id!=='' && !preg_match('/[\x00-\x1f]/',$id)) return array('state'=>'accepted','provider_id'=>$id,'error'=>'');
        if (is_array($p) && isset($p['error']['code'])) return array('state'=>'failed','provider_id'=>'','error'=>'Meta rechazó el envío (código '.(int)$p['error']['code'].'). Revisá la conexión y los permisos.');
        return array('state'=>'unknown','provider_id'=>'','error'=>'No se pudo confirmar el resultado. Revisá el canal antes de volver a enviar.');
    }
}

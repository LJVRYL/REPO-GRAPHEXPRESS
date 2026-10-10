<?php
defined('ABSPATH') || exit;

/** Official signed Meta input only. This module deliberately has no send API. */
final class GE_WhatsApp_Inbound {
    const VERSION = 1;
    const CONFIG = '/home/graphexpress/whatsapp-crm/config.json';
    const MAX_BYTES = 2097152;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('ge_crm_inbox_channel_details', array(__CLASS__, 'render_status'));
    }
    public static function table() { global $wpdb; return $wpdb->prefix . 'ge_whatsapp_inbound'; }
    public static function config() {
        $path = self::CONFIG;
        // QA override is CLI only and must point outside every public root.
        if (PHP_SAPI === 'cli' || defined('GE_WHATSAPP_PRIVATE_CLI')) {
            $candidate = getenv('GE_WHATSAPP_CONFIG');
            if ($candidate && strpos($candidate, '/job-flow-qa-') !== false && strpos($candidate, '/site/') === false) $path = $candidate;
        }
        if (!is_file($path) || !is_readable($path)) return array();
        $cfg = json_decode(file_get_contents($path), true);
        return is_array($cfg) ? $cfg : array();
    }
    public static function ready($cfg) {
        foreach (array('waba_id','phone_number_id','number','app_secret','verify_token','storage_key') as $k) if (!is_string($cfg[$k] ?? null)) return false;
        return !empty($cfg['enabled']) && ($cfg['organization_id'] ?? '') === 'graph-express'
            && ($cfg['waba_id'] ?? '') === '735912107316791'
            && preg_match('/^[0-9]{5,30}$/D', $cfg['phone_number_id'] ?? '')
            && ($cfg['number'] ?? '') === '5491151393899'
            && strlen($cfg['app_secret'] ?? '') >= 32 && strlen($cfg['verify_token'] ?? '') >= 32
            && strlen(base64_decode($cfg['storage_key'] ?? '', true) ?: '') === 32;
    }
    public static function verify_signature($body, $header, $secret) {
        return is_string($body) && strlen($body) <= self::MAX_BYTES && is_string($header)
            && preg_match('/^sha256=[a-f0-9]{64}$/D', $header)
            && is_string($secret) && strlen($secret) >= 32 && hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header);
    }
    public static function encrypt($body, $key) {
        $key = base64_decode($key, true); if (strlen($key ?: '') !== 32) throw new RuntimeException('Storage key unavailable');
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'graphex-whatsapp-v1');
        if (false === $cipher) throw new RuntimeException('Storage encryption failed');
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt($body, $key) {
        $raw = base64_decode($body, true); $key = base64_decode($key, true);
        if (strlen($raw ?: '') < 28 || strlen($key ?: '') !== 32) throw new RuntimeException('Storage invalid');
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'graphex-whatsapp-v1');
        if (false === $out) throw new RuntimeException('Storage authentication failed');
        return $out;
    }
    public static function routes() {
        register_rest_route('ge/v1', '/crm/whatsapp-webhook', array(
            array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'challenge')),
            array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'receive')),
        ));
        register_rest_route('ge/v1', '/crm/meta-webhook', array(
            array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'social_challenge')),
            array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'social_receive')),
        ));
    }
    public static function social_challenge($request) { return self::challenge($request, true); }
    public static function social_receive($request) { return self::receive($request, true); }
    public static function challenge($request, $social = false) {
        $cfg = self::config();
        if (!($social ? GE_Meta_Social::ready($cfg) : self::ready($cfg))) return new WP_Error('wa_not_configured', 'Integración pendiente de configurar.', array('status' => 503));
        $mode = $request->get_param('hub_mode') ?? $request->get_param('hub.mode');
        $token = $request->get_param('hub_verify_token') ?? $request->get_param('hub.verify_token');
        $value = $request->get_param('hub_challenge') ?? $request->get_param('hub.challenge');
        if ($mode !== 'subscribe' || !is_string($token) || !hash_equals($cfg['verify_token'], $token) || !is_string($value) || !preg_match('/^[0-9]{1,100}$/D', $value)) return new WP_Error('wa_verify', 'Verificación rechazada.', array('status' => 403));
        // Meta needs the literal challenge, not a JSON string with quotes.
        $route=$social ? '/ge/v1/crm/meta-webhook' : '/ge/v1/crm/whatsapp-webhook';
        add_filter('rest_pre_serve_request', function ($served, $result, $req) use ($value,$route) {
            if ($req->get_route() !== $route || $req->get_method() !== 'GET') return $served;
            header('Content-Type: text/plain; charset=utf-8'); echo $value; return true;
        }, 10, 3);
        return new WP_REST_Response($value, 200);
    }
    public static function receive($request, $social = false) {
        $cfg = self::config();
        if (!($social ? GE_Meta_Social::ready($cfg) : self::ready($cfg)) || (int)get_option('ge_whatsapp_inbound_schema', 0) !== self::VERSION) return new WP_Error('wa_not_configured', 'Integración pendiente de configurar.', array('status' => 503));
        $raw = $request->get_body();
        if (!self::verify_signature($raw, $request->get_header('x-hub-signature-256'), $cfg['app_secret'])) return new WP_Error('wa_signature', 'Firma rechazada.', array('status' => strlen($raw) > self::MAX_BYTES ? 413 : 403));
        try {
            // Validate every asset in the batch before acknowledging any of it.
            $events=self::events($raw, $cfg);
            if (($events[0]['channel'] ?? 'whatsapp') === 'whatsapp' ? $social : !$social) throw new InvalidArgumentException('Wrong endpoint');
            global $wpdb; $key = hash('sha256', $raw); $table = self::table();
            $encrypted = self::encrypt($raw, $cfg['storage_key']);
            $ok = $wpdb->query($wpdb->prepare("INSERT INTO $table (payload_hash,payload,state,created_at,next_at) VALUES (%s,%s,'queued',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", $key, $encrypted));
            if (false === $ok) throw new RuntimeException('Durable receipt unavailable');
            return new WP_REST_Response(array('received' => true), 200);
        } catch (InvalidArgumentException $ex) {
            return new WP_Error('wa_asset', 'Evento o activo no admitido.', array('status' => 403));
        } catch (Throwable $ex) {
            // Never log bodies, contact numbers, signatures or credential values.
            error_log('Graphex WhatsApp durable receipt requires review');
            return new WP_Error('wa_storage', 'Recepción temporalmente no disponible.', array('status' => 503));
        }
    }
    public static function install() {
        if (!defined('GE_WHATSAPP_PRIVATE_CLI')) throw new RuntimeException('Private CLI required');
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $t = self::table(); $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payload_hash char(64) NOT NULL,
            payload longtext NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'queued',
            attempts int unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            next_at datetime NOT NULL,
            lease_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            error_code varchar(60) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY receipt (payload_hash),
            KEY pending (state,next_at)
        ) ENGINE=InnoDB $c;");
        $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($t)), ARRAY_A);
        if (!$row || $row['Engine'] !== 'InnoDB') throw new RuntimeException('Durable InnoDB queue required');
        update_option('ge_whatsapp_inbound_schema', self::VERSION, false);
    }
    private static function id($v) {
        if (!is_string($v) || $v === '' || strlen($v) > 190 || preg_match('/[\x00-\x1f]/', $v)) throw new InvalidArgumentException('Invalid identifier'); return $v;
    }
    private static function stamp($v) {
        if (!is_string($v) && !is_int($v)) throw new InvalidArgumentException('Invalid time');
        if (!preg_match('/^[0-9]{1,12}$/D', (string)$v) || (int)$v > time() + 300 || (int)$v < 1) throw new InvalidArgumentException('Invalid time');
        return gmdate('Y-m-d\TH:i:s\Z', (int)$v);
    }
    public static function events($raw, $cfg) {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) throw new InvalidArgumentException('Oversized payload');
        $data = json_decode($raw, true, 64);
        if (is_array($data) && in_array($data['object'] ?? '',array('page','instagram'),true)) return GE_Meta_Social::events($data,$cfg);
        if (!self::ready($cfg)) throw new InvalidArgumentException('WhatsApp not configured');
        if (!is_array($data) || ($data['object'] ?? '') !== 'whatsapp_business_account' || empty($data['entry']) || !is_array($data['entry'])) throw new InvalidArgumentException('Invalid envelope');
        $out = array();
        foreach ($data['entry'] as $entry) {
            if (!is_array($entry) || (string)($entry['id'] ?? '') !== $cfg['waba_id'] || empty($entry['changes']) || !is_array($entry['changes'])) throw new InvalidArgumentException('Foreign account');
            foreach ($entry['changes'] as $change) {
                if (!is_array($change)) throw new InvalidArgumentException('Invalid change');
                $value = $change['value'] ?? null; $field = $change['field'] ?? '';
                if (!is_array($value)) throw new InvalidArgumentException('Invalid change');
                foreach (array('messages','statuses','message_echoes','history','state_sync') as $list) if (isset($value[$list]) && !is_array($value[$list])) throw new InvalidArgumentException('Invalid event list');
                $phone = $value['metadata']['phone_number_id'] ?? null;
                if ($phone !== null && (string)$phone !== $cfg['phone_number_id']) throw new InvalidArgumentException('Foreign phone');
                if (in_array($field, array('messages','smb_message_echoes','history','smb_app_state_sync'), true) && $phone === null) throw new InvalidArgumentException('Missing phone');
                $base = array('field' => $field, 'account_ref' => 'wa:' . $cfg['waba_id'] . ':' . $cfg['phone_number_id'], 'waba_id' => $cfg['waba_id'], 'phone_number_id' => $cfg['phone_number_id']);
                if ($field === 'messages') {
                    foreach (($value['messages'] ?? array()) as $message) $out[] = self::message($message, $base, false, false, $cfg);
                    foreach (($value['statuses'] ?? array()) as $status) {
                        $out[] = array_merge($base, array('kind' => 'status', 'message_id' => self::id($status['id'] ?? ''), 'timestamp' => self::stamp($status['timestamp'] ?? ''), 'status' => self::id($status['status'] ?? ''), 'errors' => array_map(function($e) { return (int)($e['code'] ?? 0); }, $status['errors'] ?? array())));
                    }
                    if (empty($value['messages']) && empty($value['statuses'])) $out[] = array_merge($base, array('kind'=>'review', 'code'=>'unsupported_messages', 'data'=>$value));
                } elseif ($field === 'smb_message_echoes') {
                    foreach (($value['message_echoes'] ?? array()) as $message) $out[] = self::message($message, $base, true, false, $cfg);
                } elseif ($field === 'history') {
                    foreach (($value['history'] ?? array()) as $chunk) {
                        foreach (($chunk['threads'] ?? array()) as $thread) foreach (($thread['messages'] ?? array()) as $message) $out[] = self::message($message, $base, preg_replace('/\D/', '', $message['from'] ?? '') === $cfg['number'], true, $cfg, $thread['id'] ?? '');
                        $out[] = array_merge($base, array('kind'=>'history_progress', 'metadata'=>$chunk['metadata'] ?? array(), 'errors'=>array_map(function($e) { return (int)($e['code'] ?? 0); }, $chunk['errors'] ?? array())));
                    }
                } elseif ($field === 'account_update') {
                    $out[] = array_merge($base, array('kind'=>'account', 'event'=>self::id($value['event'] ?? 'unknown'), 'timestamp'=>(string)($entry['time'] ?? ''), 'reason'=>(string)($value['disconnection_info']['reason'] ?? '')));
                } elseif ($field === 'smb_app_state_sync') {
                    // Contacts are not consent or permission to create/merge customer identities.
                    $out[] = array_merge($base, array('kind'=>'contacts', 'count'=>count($value['state_sync'] ?? array())));
                } else $out[] = array_merge($base, array('kind'=>'review', 'code'=>'unknown_field', 'data'=>$value));
                if (count($out) > 10000) throw new InvalidArgumentException('Batch too large');
            }
        }
        if (!$out) throw new InvalidArgumentException('Empty event batch');
        return $out;
    }
    private static function message($m, $base, $outbound, $history, $cfg, $thread = '') {
        if (!is_array($m)) throw new InvalidArgumentException('Invalid message');
        $id = self::id($m['id'] ?? ''); $type = self::id($m['type'] ?? 'unknown');
        $peer = $outbound ? ($m['to'] ?? $thread) : ($m['from'] ?? '');
        if (!preg_match('/^[0-9]{5,20}$/D', (string)$peer)) throw new InvalidArgumentException('Invalid peer');
        if ($outbound && preg_replace('/\D/', '', $m['from'] ?? '') !== $cfg['number']) throw new InvalidArgumentException('Foreign outbound sender');
        $kind = in_array($type, array('edit','revoke'), true) ? $type : ($outbound ? 'echo' : 'message');
        $body = $type === 'text' ? (string)($m['text']['body'] ?? '') : '[' . $type . '] ' . (string)($m[$type]['caption'] ?? '');
        if ($type === 'interactive') $body='[interactive] ' . (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '');
        if ($type === 'button') $body='[button] ' . (string)($m['button']['text'] ?? '');
        $attachment = isset($m[$type]['id']) ? 'wa-media:' . self::id((string)$m[$type]['id']) : '';
        return array_merge($base, array('kind'=>$kind, 'message_id'=>$id, 'original_message_id'=>$m[$type]['original_message_id'] ?? '', 'timestamp'=>self::stamp($m['timestamp'] ?? ''), 'peer'=>(string)$peer, 'outbound'=>$outbound, 'history'=>$history, 'type'=>$type, 'body'=>mb_strcut($body, 0, 12000, 'UTF-8'), 'truncated'=>strlen($body)>12000, 'attachment'=>$attachment, 'media_pending'=>!in_array($type, array('text','interactive','button','edit','revoke'), true), 'data'=>$m));
    }
    public static function drain($limit = 20) {
        if (!defined('GE_WHATSAPP_PRIVATE_CLI') || !GE_CRM::can(true) || GE_CRM::org() !== 'graph-express') throw new RuntimeException('Private authorized CRM principal required');
        global $wpdb; $table = self::table(); $cfg = self::config();
        if (!self::ready($cfg) && !GE_Meta_Social::ready($cfg)) { self::health(false, 0, 'configuration_pending'); return array('configured'=>false,'processed'=>0); }
        $lock = 'gewa:' . substr(hash('sha256', DB_NAME), 0, 35);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) return array('busy'=>true);
        $processed = 0;
        try {
            $rows = $wpdb->get_results("SELECT * FROM $table WHERE (state='queued' AND next_at<=UTC_TIMESTAMP()) OR (state='processing' AND lease_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)) ORDER BY id LIMIT " . min(100, max(1, (int)$limit)), ARRAY_A);
            foreach ($rows as $row) {
                if (false === $wpdb->query($wpdb->prepare("UPDATE $table SET state='processing',lease_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=%d", $row['id']))) throw new RuntimeException('Claim failed');
                try {
                    $events = self::events(self::decrypt($row['payload'], $cfg['storage_key']), $cfg);
                    foreach ($events as $event) self::apply($event, (int)$row['id']);
                    if (false === $wpdb->query($wpdb->prepare("UPDATE $table SET state='done',completed_at=UTC_TIMESTAMP(),error_code='' WHERE id=%d", $row['id']))) throw new RuntimeException('Completion failed');
                    $processed++;
                } catch (Throwable $ex) {
                    $attempt = (int)$row['attempts'] + 1; $state = $attempt >= 8 ? 'review' : 'queued';
                    $delay = min(3600, 30 * (2 ** min($attempt, 7))) + random_int(0, 20);
                    if (false === $wpdb->query($wpdb->prepare("UPDATE $table SET state=%s,error_code='processing_review',next_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND) WHERE id=%d", $state, $delay, $row['id']))) throw new RuntimeException('Retry storage failed');
                    if ($state === 'review') self::review_task('queue:' . $row['id'], 'Evento WhatsApp pendiente de revisión', 'Reintentos agotados. Original privado: wa-receipt:' . $row['id']);
                }
            }
            self::health(true, $processed, '');
            return array('configured'=>true,'processed'=>$processed);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    private static function apply($e, $receipt) {
        if (isset($e['channel']) && $e['channel'] !== 'whatsapp') { GE_Meta_Social::apply($e,$receipt); return; }
        if ($e['kind'] === 'message') {
            $key = 'message:' . hash('sha256', 'whatsapp:' . $e['account_ref'] . ':' . $e['message_id']);
            global $wpdb; $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . GE_CRM::table() . ' WHERE organization_id=%s AND dedupe_key=%s', GE_CRM::org(), $key));
            if (!$id) {
                $r = GE_CRM::attention_ingest(array('organization_id'=>GE_CRM::org(), 'channel'=>'whatsapp', 'external_id'=>$e['message_id'], 'conversation_id'=>$e['peer'], 'account_ref'=>$e['account_ref'], 'source_ref'=>'wa-message:' . $e['message_id'], 'received_at'=>$e['timestamp'], 'from'=>$e['peer'], 'to'=>'5491151393899', 'subject'=>'WhatsApp · ' . $e['type'], 'body'=>$e['body'], 'headers'=>array(), 'historical_backfill'=>$e['history'], 'body_truncated'=>$e['truncated'], 'reply_ambiguous'=>$e['media_pending'], 'attachment_refs'=>$e['attachment'] ? array($e['attachment']) : array()));
                $id = $r['record_id'];
            } else {
                $existing = GE_CRM::get($id, 'thread');
                // Same-ID differing data is an incident, never overwrite the exact original.
                if (($existing['attention_event']['body'] ?? '') !== wp_strip_all_tags($e['body'])) self::review_task('conflict:' . hash('sha256', $e['message_id'] . $e['body']), 'Mensaje WhatsApp con datos distintos', 'Revisar original sin sustituirlo: wa-receipt:' . $receipt, $id);
            }
            GE_CRM::attention_process($id); // WhatsApp classifier always suppresses acknowledgements.
            GE_CRM::whatsapp_transport_event($id, $e, $receipt);
            if ($e['media_pending']) self::review_task('media:' . $id, 'Revisar adjunto WhatsApp', 'El mensaje multimedia requiere descarga segura y revisión. La referencia sola no es un archivo recibido/aprobado.', $id);
            if (!$e['history']) {
                $channels = get_option('ge_crm_attention_channels', array());
                $channels['whatsapp']['transport_verified_at'] = gmdate('c');
                update_option('ge_crm_attention_channels', $channels, false);
            }
        } elseif (in_array($e['kind'], array('status','edit','revoke','echo'), true)) {
            $id = GE_CRM::whatsapp_find_thread($e['account_ref'], ($e['original_message_id'] ?? '') ?: $e['message_id'], $e['kind']==='echo' ? $e['peer'] : '');
            if ($id) GE_CRM::whatsapp_transport_event($id, $e, $receipt);
            else self::review_task('orphan:' . hash('sha256', json_encode($e)), 'Evento WhatsApp sin conversación vinculada', 'Estado/eco/edición conservado. Revisar enlace al mensaje; fuente wa-receipt:' . $receipt);
        } elseif ($e['kind'] === 'account') {
            if (in_array($e['event'], array('PARTNER_REMOVED','ACCOUNT_OFFBOARDED'), true)) {
                $channels = get_option('ge_crm_attention_channels', array());
                $channels['whatsapp']['account_disconnected'] = true; update_option('ge_crm_attention_channels', $channels, false);
                self::review_task('disconnect:' . hash('sha256', json_encode($e)), 'WhatsApp desconectado de la API', 'Revisar conexión sin migrar/desregistrar. wa-receipt:' . $receipt);
            } elseif ($e['event'] === 'ACCOUNT_RECONNECTED') {
                $channels = get_option('ge_crm_attention_channels', array()); $channels['whatsapp']['account_disconnected'] = false; update_option('ge_crm_attention_channels', $channels, false);
            } else self::review_task('account:' . hash('sha256', json_encode($e)), 'Cambio de cuenta WhatsApp', 'Revisar cambio de cuenta. wa-receipt:' . $receipt);
        } elseif ($e['kind'] === 'history_progress') {
            $channels = get_option('ge_crm_attention_channels', array());
            $channels['whatsapp']['history_sync'] = array('receipt_ref'=>'wa-receipt:' . $receipt,'metadata'=>$e['metadata'],'errors'=>$e['errors'],'checked_at'=>gmdate('c'));
            update_option('ge_crm_attention_channels', $channels, false);
            if ($e['errors']) self::review_task('history:' . $receipt, 'Historial WhatsApp no disponible', 'Sincronización con errores o no autorizada; no ocultar cobertura. wa-receipt:' . $receipt);
        } elseif ($e['kind'] === 'review') self::review_task('review:' . $receipt, 'Evento WhatsApp no interpretado', 'Conservado para revisión: wa-receipt:' . $receipt);
        // Contact changes remain in the encrypted ledger; never automatically merge identities.
    }
    private static function review_task($key, $title, $notes, $thread = 0) {
        GE_CRM::require_access(true);
        GE_CRM::locked(function () use ($key,$title,$notes,$thread) {
            $record = $thread ? GE_CRM::get($thread,'thread') : array();
            GE_CRM::automation_task('wa-review:' . hash('sha256',$key),array('title'=>$title,'notes'=>$notes,'thread_id'=>$thread,'customer_id'=>$record['customer_id'] ?? 0,'owner_id'=>$record['owner_id'] ?? get_current_user_id(),'status'=>'open','priority'=>'high','source'=>'whatsapp-official'));
        });
    }
    private static function health($running, $processed, $error) {
        global $wpdb; $t = self::table();
        $pending = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE state IN ('queued','processing')");
        $review = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE state='review'");
        $oldest = $wpdb->get_var("SELECT MIN(created_at) FROM $t WHERE state IN ('queued','processing')");
        $channels = get_option('ge_crm_attention_channels',array());
        $channels['whatsapp']['consumer_health'] = array('checked_at'=>gmdate('c'),'receiver_running'=>$running,'pending_events'=>$pending,'review_events'=>$review,'oldest_pending_age_seconds'=>$oldest ? max(0,time()-strtotime($oldest . ' UTC')) : 0,'poll_failed'=>(bool)$error,'error_code'=>$error,'runtime'=>'VPS','last_batch_processed'=>$processed);
        foreach (array('instagram','messenger') as $channel) $channels[$channel]['consumer_health']=$channels['whatsapp']['consumer_health'];
        update_option('ge_crm_attention_channels',$channels,false);
        if ($oldest && time()-strtotime($oldest . ' UTC') > 900) self::review_task('queue-age:' . gmdate('Y-m-d-H'), 'Cola WhatsApp atrasada', 'Hay recepción durable pendiente por más de 15 minutos; revisar proceso VPS.');
    }
    public static function render_status() {
        if (!GE_CRM::can()) return;
        $cfg = self::config(); $channels = get_option('ge_crm_attention_channels',array()); $wa = $channels['whatsapp'] ?? array(); $h = $wa['consumer_health'] ?? array();
        echo '<section class="ge-crm-panel"><h2>Recepción oficial de WhatsApp, Instagram y Messenger</h2>';
        echo '<p>' . esc_html(self::ready($cfg) ? 'Receptor oficial configurado; verificar mensajes reales en esta bandeja.' : 'Receptor preparado en el servidor. Falta habilitar Meta y conectar el número existente mediante coexistencia.') . '</p>';
        if (!empty($wa['account_disconnected'])) echo '<p><strong>Meta informó una desconexión. Requiere revisión.</strong></p>';
        if ($h) echo '<p>Control del servidor: ' . esc_html($h['checked_at']) . ' · Pendientes: ' . (int)$h['pending_events'] . ' · Eventos para revisar: ' . (int)$h['review_events'] . '.</p>';
        foreach (array('instagram','messenger') as $channel) echo '<p><strong>' . esc_html(ucfirst($channel)) . ':</strong> ' . esc_html(!empty($channels[$channel]['transport_verified_at']) ? 'Evento entrante recibido en CRM: ' . $channels[$channel]['transport_verified_at'] : 'Sin recepción real verificada. Falta habilitación y suscripción de la aplicación Meta.') . '</p>';
        echo '<p>Proceso preparado para el VPS. Las respuestas automáticas de los tres canales están desactivadas. Los adjuntos requieren descarga segura antes de aprobar un archivo.</p></section>';
    }
}

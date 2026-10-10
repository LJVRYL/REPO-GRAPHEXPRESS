<?php
defined('ABSPATH') || exit;

/** Commercial attribution is evidence on real records, never an anonymous contact. */
final class GE_CRM_Origin {
    const META = '_ge_crm_origin_v1';
    const RECEIVED = '_ge_crm_origin_received_at';
    public static function init() {
        foreach (array('added_post_meta','updated_post_meta') as $hook) add_action($hook,array(__CLASS__,'post_meta'),20,4);
        add_action('user_register',array(__CLASS__,'registered'),30);
        add_action('woocommerce_after_order_object_save',array(__CLASS__,'order_saved'),30);
    }
    public static function enabled() { return (bool)get_option('ge_crm_origin_enabled',false); }
    public static function labels() { return array('google_maps'=>'Google Maps','google_organic'=>'Google orgánico','paid_campaign'=>'Campañas pagas','organic_social'=>'Redes orgánicas','referral'=>'Referencias y otros enlaces','direct_unknown'=>'Directo / desconocido'); }
    public static function unknown($reason='not_observed') {
        $touch=array('bucket'=>'direct_unknown','source'=>'','medium'=>'','campaign'=>'','content'=>'','landing'=>'','referrer_provider'=>'','captured_at'=>'','evidence'=>$reason,'confidence'=>'unknown');
        return array('schema_version'=>1,'organization_id'=>GE_CRM::org(),'first_touch'=>$touch,'latest_touch'=>$touch,'recorded_at'=>gmdate('c'),'consent'=>'not_granted');
    }
    private static function identifier($v) {
        if(!is_string($v) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.~-]{0,79}$/D',$v) || preg_match('/(?:email|phone|telefono|cuit|customer|order|pedido|token|secret|password)/i',$v) || preg_match('/\d{8,}/',$v)) return '';
        return strtolower($v);
    }
    public static function touch($raw,$at) {
        if(!is_array($raw))return null;
        $stamp=is_string($at)?strtotime($at):false;
        if(!$stamp || $stamp<time()-30*DAY_IN_SECONDS || $stamp>time()+300)return null;
        $t=array('source'=>self::identifier($raw['utm_source']??''),'medium'=>self::identifier($raw['utm_medium']??''),'campaign'=>self::identifier($raw['utm_campaign']??''),'content'=>self::identifier($raw['utm_content']??''),'landing'=>'','referrer_provider'=>'','captured_at'=>gmdate('c',$stamp),'evidence'=>'not_observed','confidence'=>'unknown','bucket'=>'direct_unknown');
        $path=$raw['landing']??'';
        // Only public catalogue paths: no query strings, private routes or artwork URLs.
        if(is_string($path) && strlen($path)<=160 && preg_match('~^/(?:$|(?:producto|product|categoria-producto|product-category|servicios|contacto|presupuesto|impresion|stickers|volantes)(?:/[a-z0-9-]{1,80})?/?$)~D',$path))$t['landing']=$path;
        $host=strtolower((string)($raw['referrer_host']??''));
        if(preg_match('/^(?:www\.)?google\.(?:com|com\.ar|es|cl|com\.uy|com\.br)$/D',$host))$t['referrer_provider']='google';
        elseif(in_array($host,array('facebook.com','www.facebook.com','l.facebook.com','lm.facebook.com','instagram.com','www.instagram.com','l.instagram.com'),true))$t['referrer_provider']='meta';
        if($t['source']) {
            $t['evidence']='consented_browser_utm';$t['confidence']='browser_indicator';
            if($t['source']==='google' && $t['medium']==='organic' && $t['campaign']==='graphex_maps')$t['bucket']='google_maps';
            elseif(in_array($t['medium'],array('cpc','ppc','paid','paid_social','display','cpm'),true))$t['bucket']='paid_campaign';
            elseif($t['source']==='google' && $t['medium']==='organic')$t['bucket']='google_organic';
            elseif($t['medium']==='organic_social')$t['bucket']='organic_social';
            else $t['bucket']='referral';
        } elseif($t['referrer_provider']) {
            $t['evidence']='consented_browser_referrer';$t['confidence']='browser_indicator';$t['bucket']=$t['referrer_provider']==='google'?'google_organic':'organic_social';
        }
        return $t;
    }
    public static function browser($cookies) {
        $u=self::unknown();
        if(($cookies['ge_growth_consent']??'')!=='granted')return $u;
        $str=$cookies['ge_growth_touch']??'';
        if(!is_string($str) || strlen($str)>8192)return self::unknown('invalid_capture');
        $raw=json_decode(stripslashes($str),true);
        if(!is_array($raw))$raw=json_decode(rawurldecode($str),true);
        if(!is_array($raw) || !is_numeric($raw['expires_at']??null) || (float)$raw['expires_at']<time() || (float)$raw['expires_at']>time()+30*DAY_IN_SECONDS+300)return self::unknown('expired_or_missing_capture');
        $first=self::touch($raw['first']??null,$raw['first_at']??'');$last=self::touch($raw['last']??null,$raw['last_at']??'');
        if(!$first || !$last)return self::unknown('invalid_capture');
        $u['first_touch']=$first;$u['latest_touch']=$last;$u['consent']='granted';return $u;
    }
    public static function valid($v) {
        return is_array($v) && ($v['schema_version']??0)===1 && ($v['organization_id']??'')===GE_CRM::org() && isset(self::labels()[$v['first_touch']['bucket']??''],self::labels()[$v['latest_touch']['bucket']??'']);
    }
    public static function read($kind,$id) {
        if(!$id)return self::unknown();
        if($kind==='customer') { if(!GE_CRM::scope('customer',$id))return self::unknown();$v=get_user_meta($id,self::META,true); }
        elseif($kind==='request') { if(!GE_CRM::request_scope($id))return self::unknown();$v=get_post_meta($id,self::META,true); }
        elseif($kind==='order') { if(!GE_CRM::scope('order',$id))return self::unknown();$v=wc_get_order($id)->get_meta(self::META,true); }
        else { if(!GE_CRM::scope('quote',$id))return self::unknown();$v=get_post_meta($id,self::META,true); }
        return self::valid($v)?$v:self::unknown();
    }
    private static function real_browser() {
        return strtolower($_SERVER['HTTP_HOST']??'')==='graphex.ar' && !current_user_can('manage_woocommerce') && !current_user_can('ge_manage_operations') && !current_user_can('manage_options');
    }
    public static function observe_customer($id,$origin) {
        if(!$id || !GE_CRM::scope('customer',$id) || !self::valid($origin))return;
        for($n=0;$n<3;$n++) {
            $old=get_user_meta($id,self::META,true);
            if(!self::valid($old)) {
                $u=get_userdata($id);$active=(int)get_option('ge_crm_origin_active_since',0);
                $next=$u && strtotime($u->user_registered.' UTC') >= $active && $active ? $origin : self::unknown('predates_capture');
                $next['latest_touch']=$origin['latest_touch'];$next['recorded_at']=$origin['recorded_at'];
                if(add_user_meta($id,self::META,$next,true))return;
                if($old && update_user_meta($id,self::META,$next,$old))return;
            } else {
                if(strtotime($old['recorded_at'])>strtotime($origin['recorded_at']) || $old['latest_touch']===$origin['latest_touch'])return;
                $next=$old;$next['latest_touch']=$origin['latest_touch'];$next['recorded_at']=$origin['recorded_at'];$next['consent']=$origin['consent'];
                if(update_user_meta($id,self::META,$next,$old))return;
            }
        }
    }
    public static function registered($id) {
        if(self::enabled() && self::real_browser() && GE_CRM::scope('customer',$id))self::observe_customer($id,self::browser($_COOKIE));
    }
    public static function post_meta($mid,$id,$key,$value) {
        if(!self::enabled())return;
        if($key==='_ge_quote_request' && is_array($value) && ($value['status']??'')==='new' && (int)($value['customer_id']??0)===get_current_user_id() && self::real_browser() && GE_CRM::request_scope($id)) {
            if(get_post_meta($id,self::RECEIVED,true))return;
            $origin=self::browser($_COOKIE);$origin['provenance']=array('kind'=>'submitted_request','id'=>(int)$id);
            if(add_post_meta($id,self::META,$origin,true)) {
                add_post_meta($id,self::RECEIVED,gmdate('Y-m-d H:i:s'),true);self::observe_customer((int)$value['customer_id'],$origin);
            }
        }
        if($key==='_ge_quote_request_source' && (int)$value && GE_CRM::scope('quote',$id) && GE_CRM::request_scope((int)$value)) {
            $request=get_post_meta((int)$value,'_ge_quote_request',true);
            if((int)($request['customer_id']??0)!==(int)get_post_meta($id,'_ge_commercial_customer_id',true))return;
            $o=self::read('request',(int)$value);$o['provenance']=array('kind'=>'request','id'=>(int)$value);add_post_meta($id,self::META,$o,true);
        }
    }
    public static function prepare_record($data,$old) {
        if(isset($old['origin']) && self::valid($old['origin'])) {$data['origin']=$old['origin'];return $data;}
        unset($data['origin']);
        if(!self::enabled())return $data;
        $o=null;
        foreach(array('quote_request_id'=>'request','quote_id'=>'quote','order_id'=>'order') as $key=>$kind)if(!empty($data[$key])) {$o=self::read($kind,$data[$key]);break;}
        if(!$o)foreach(array('thread_id','lead_id') as $key)if(!empty($data[$key])) {$r=GE_CRM::get($data[$key]);if(self::valid($r['origin']??null)){$o=$r['origin'];break;}}
        // Transport body, names and the operator's own cookies are never source evidence.
        $data['origin']=$o?:self::unknown();return $data;
    }
    public static function order_saved($order) {
        if(!self::enabled() || !($order instanceof WC_Order) || !GE_CRM::scope('order',$order->get_id()) || self::valid($order->get_meta(self::META,true)))return;
        $qid=(int)$order->get_meta('_ge_commercial_quote_id',true);
        if($qid && GE_CRM::scope('quote',$qid)) {
            if((int)get_post_meta($qid,'_ge_commercial_customer_id',true)!==(int)$order->get_customer_id())return;
            $o=self::read('quote',$qid);$o['provenance']=array('kind'=>'quote','id'=>$qid);
        } else {
            $created=$order->get_date_created();$active=(int)get_option('ge_crm_origin_active_since',0);
            if(!self::real_browser() || !$active || !$created || $created->getTimestamp()<$active || $created->getTimestamp()<time()-300)return;
            $o=self::browser($_COOKIE);$o['provenance']=array('kind'=>'web_order','id'=>$order->get_id());
            self::observe_customer((int)$order->get_customer_id(),$o);
        }
        $order->update_meta_data(self::META,$o);$order->save_meta_data();
    }
    public static function paid_cents($o) {
        if(!($o instanceof WC_Order) || in_array($o->get_status(),array('cancelled','failed'),true) || !$o->get_date_paid())return 0;
        $total=GE_WTP_Quote_Balance::cents($o->get_total());if($total<=0)return 0;
        try {
            if($o->get_meta('_ge_commercial_quote_id',true)) {
                if((int)$o->get_meta('_ge_final_total_cents',true)!==$total)return 0;
                $r=GE_WTP_Quote_Balance::reconcile($total,(array)$o->get_meta('_ge_commercial_credited_attempts',true));
                return $r['amount_paid_cents']===$total && (int)$o->get_meta('_ge_amount_paid_cents',true)===$total ? $total : 0;
            }
            if($o->get_meta('_ge_payment_confirmed_by',true) && $o->get_meta('_ge_payment_confirmed_at',true) && $o->get_meta('_ge_payment_state',true)==='paid' && GE_WTP_Quote_Balance::cents($o->get_meta('_ge_payment_confirmed_total',true))===$total)return $total;
            return $o->is_paid() && $o->get_transaction_id() ? $total : 0;
        } catch(Throwable $ex) { return 0; }
    }
    private static function bucket($v,$basis) { return self::valid($v)?$v[$basis.'_touch']['bucket']:'direct_unknown'; }
    private static function primary_order($o) { return !$o->get_meta('_ge_commercial_payment_order',true) && !$o->get_meta('_ge_work_order',true) && GE_CRM::scope('order',$o->get_id()); }
    public static function report($from,$to,$basis='first') {
        GE_CRM::require_access();
        $zone=new DateTimeZone('America/Argentina/Buenos_Aires');
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from,$zone);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to,$zone);
        if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$b<$a||$b->diff($a)->days>365||!in_array($basis,array('first','latest'),true))throw new RuntimeException('Elegí un período válido de hasta un año.');
        $start=$a->getTimestamp();$end=$b->modify('+1 day')->getTimestamp();$utc=new DateTimeZone('UTC');
        $startsql=$a->setTimezone($utc)->format('Y-m-d H:i:s');$endsql=$b->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $rows=array();foreach(self::labels() as $k=>$label)$rows[$k]=array('label'=>$label,'inquiries'=>0,'customers'=>0,'quotes'=>0,'orders'=>0,'paid_sales'=>0,'money'=>array());
        global $wpdb;
        foreach(array('ge_quote_request'=>'inquiries','ge_commercial_quote'=>'quotes') as $type=>$metric) {
            $last=0;
            do {
                // Submitted requests use receipt time; historical objects have no invented attribution.
                if($type==='ge_quote_request')$ids=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s WHERE p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft') AND p.ID>%d AND ((m.meta_value >= %s AND m.meta_value < %s) OR (m.meta_id IS NULL AND p.post_date_gmt >= %s AND p.post_date_gmt < %s)) GROUP BY p.ID ORDER BY p.ID LIMIT 100",self::RECEIVED,$type,$last,$startsql,$endsql,$startsql,$endsql));
                else $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status NOT IN ('trash','auto-draft') AND ID>%d AND post_date_gmt >= %s AND post_date_gmt < %s ORDER BY ID LIMIT 100",$type,$last,$startsql,$endsql));
                foreach($ids as $id) {
                    $last=(int)$id;$kind=$type==='ge_quote_request'?'request':'quote';
                    if($kind==='request') {$d=get_post_meta($id,'_ge_quote_request',true);if(!GE_CRM::request_scope($id)||!is_array($d)||($d['status']??'draft')==='draft')continue;}
                    elseif(!GE_CRM::scope('quote',$id))continue;
                    $rows[self::bucket(self::read($kind,$id),$basis)][$metric]++;
                }
            }while(count($ids)===100);
        }
        $last=0;$seen=array();
        do {
            $raw=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.GE_CRM::table()." WHERE organization_id=%s AND kind IN ('thread','lead') AND id>%d AND created_at >= %s AND created_at < %s ORDER BY id LIMIT 100",GE_CRM::org(),$last,$startsql,$endsql),ARRAY_A);
            foreach($raw as $record) {
                $r=GE_CRM::decode($record);$last=$r['id'];if(!empty($r['quote_request_id']))continue;
                if($r['kind']==='thread') {
                    if(!empty($r['attention_event']['historical_backfill']))continue;
                    $classification=$r['attention_classification']??$r['meta_classification']??(isset($r['attention_event'])?GE_CRM_Attention::classify($r['attention_event']):array());
                    if(in_array($classification['category']??'',array('automated','non_useful','delivery_failure'),true))continue;
                    $key=$r['conversation_key']??('thread:'.$r['id']);
                } else {if(!empty($r['thread_id']))continue;$key='lead:'.$r['id'];}
                if(isset($seen[$key]))continue;$seen[$key]=true;
                $rows[self::bucket($r['origin']??null,$basis)]['inquiries']++;
            }
        }while(count($raw)===100);
        $last=0;
        do {
            $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID>%d AND user_registered >= %s AND user_registered < %s ORDER BY ID LIMIT 100",$last,$startsql,$endsql));
            foreach($ids as $id){$last=(int)$id;if(GE_CRM::scope('customer',$id))$rows[self::bucket(self::read('customer',$id),$basis)]['customers']++;}
        }while(count($ids)===100);
        foreach(array('date_created'=>'orders','date_paid'=>'paid_sales') as $date=>$metric) {
            $page=1;
            do {
                $batch=wc_get_orders(array('type'=>'shop_order','limit'=>100,'page'=>$page++,$date=>$start.'...'.($end-1),'orderby'=>'ID','order'=>'ASC','return'=>'objects'));
                foreach($batch as $o) {
                    if(!self::primary_order($o))continue;$key=self::bucket(self::read('order',$o->get_id()),$basis);
                    if($metric==='orders') {$rows[$key]['orders']++;continue;}
                    $paid=self::paid_cents($o);if(!$paid)continue;
                    foreach($o->get_refunds() as $refund){$dt=$refund->get_date_created();if($dt && $dt->getTimestamp()<$end)$paid-=GE_WTP_Quote_Balance::cents($refund->get_amount());}
                    $paid=max(0,$paid);$rows[$key]['paid_sales']++;$currency=$o->get_currency();$rows[$key]['money'][$currency]=($rows[$key]['money'][$currency]??0)+$paid;
                }
            }while(count($batch)===100);
        }
        return $rows;
    }
    public static function render_record($record) {
        $v=self::valid($record['origin']??null)?$record['origin']:self::unknown();
        echo '<details><summary>Origen: '.esc_html(self::labels()[$v['first_touch']['bucket']]).'</summary>';
        foreach(array('first_touch'=>'Primer origen','latest_touch'=>'Último origen observado') as $key=>$label){
            $t=$v[$key];echo '<p><strong>'.esc_html($label).':</strong> '.esc_html(self::labels()[$t['bucket']]);
            if(!empty($t['captured_at']))echo ' · '.esc_html(wp_date('d/m/Y H:i',strtotime($t['captured_at']),new DateTimeZone('America/Argentina/Buenos_Aires')));
            echo '</p>';
            foreach(array('source'=>'Fuente','medium'=>'Medio','campaign'=>'Campaña','content'=>'Contenido','landing'=>'Página de entrada') as $field=>$name)if(!empty($t[$field]))echo '<p>'.esc_html($name).': '.esc_html($t[$field]).'</p>';
        }
        echo '<p>Indicios de navegación con consentimiento. Sin evidencia se conserva Directo / desconocido. El primer origen no se reemplaza.</p></details>';
    }
    public static function render() {
        $from=sanitize_text_field(wp_unslash($_GET['origin_from']??wp_date('Y-m-01')));$to=sanitize_text_field(wp_unslash($_GET['origin_to']??wp_date('Y-m-d')));$basis=sanitize_key($_GET['origin_basis']??'first');
        echo '<h2>De dónde llegan los trabajos</h2><p>El origen acompaña a cada consulta, presupuesto y pedido. Las visitas anónimas permanecen en Analytics y no crean clientes.</p><form method="get" class="ge-crm-toolbar"><input type="hidden" name="section" value="crm"><input type="hidden" name="view" value="origin"><label>Desde <input type="date" name="origin_from" value="'.esc_attr($from).'"></label><label>Hasta <input type="date" name="origin_to" value="'.esc_attr($to).'"></label><label>Origen <select name="origin_basis"><option value="first"'.selected($basis,'first',false).'>Primer origen</option><option value="latest"'.selected($basis,'latest',false).'>Último origen observado</option></select></label><button>Ver reporte</button></form>';
        if(!self::enabled()){echo '<p role="status">La captura de origen todavía no está activada.</p>';return;}
        try{$rows=self::report($from,$to,$basis);}catch(Throwable $ex){echo '<p role="alert">'.esc_html($ex->getMessage()).'</p>';return;}
        echo '<div role="region" aria-label="Resultados por origen" tabindex="0" style="overflow-x:auto"><table class="widefat"><caption>Eventos del período · horario de Buenos Aires</caption><thead><tr>';foreach(array('Origen','Consultas','Clientes nuevos','Presupuestos','Pedidos','Ventas cobradas','Importe cobrado') as $label)echo '<th scope="col">'.esc_html($label).'</th>';echo '</tr></thead><tbody>';
        foreach($rows as $r){echo '<tr><th scope="row">'.esc_html($r['label']).'</th>';foreach(array('inquiries','customers','quotes','orders','paid_sales') as $k)echo '<td>'.(int)$r[$k].'</td>';echo '<td>';if(!$r['money'])echo '—';foreach($r['money'] as $currency=>$cents)echo '<div>'.esc_html($currency.' '.number_format($cents/100,2,',','.')).'</div>';echo '</td></tr>';}
        echo '</tbody></table></div><details><summary>Qué cuenta este reporte</summary><p>Consultas: solicitudes enviadas y conversaciones comerciales reales, sin borradores ni visitas. Clientes nuevos: fichas registradas en el período. Presupuestos: cada presupuesto una vez, sin sumar versiones. Pedidos: pedidos principales, sin duplicar órdenes de pago ni trabajos internos.</p><p>Ventas cobradas: pedidos con pago total acreditado en el período. Incluye IVA y envío, y descuenta devoluciones registradas hasta la fecha final. Una seña parcial o un crédito aprobado no cuentan como venta cobrada. Las monedas se muestran por separado.</p><p>Los primeros y últimos orígenes son indicios de navegación consentida, no una identificación de personas. El primer origen no se reemplaza. Cada trabajo conserva el origen de su consulta; no toma el de otra compra del cliente. Sin datos verificables, se muestra Directo / desconocido. Los correos y mensajes sin un marcador de origen verificado quedan desconocidos.</p><p>Los registros históricos no reciben un origen supuesto. Las solicitudes antiguas sin fecha de recepción registrada se ubican por su fecha de creación. Google orgánico requiere un referente capturado con consentimiento; si no está disponible no se adivina.</p></details>';
    }
}

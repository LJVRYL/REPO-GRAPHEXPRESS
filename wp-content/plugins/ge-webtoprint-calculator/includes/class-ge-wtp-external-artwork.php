<?php
defined('ABSPATH') || exit;

/** External references share the Artwork v2 association model. Fetching is explicit. */
final class GE_WTP_External_Artwork {
    const ACTION = 'ge_external_artwork';
    public static function init() { add_action('wp_ajax_'.self::ACTION,array(__CLASS__,'ajax')); }
    public static function is_link($r) { return 'external_link' === ($r['source_type'] ?? ''); }
    public static function provider($url) {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        foreach(array('google_drive'=>array('drive.google.com','docs.google.com'),'dropbox'=>array('dropbox.com','dropboxusercontent.com'),'wetransfer'=>array('wetransfer.com','we.tl'),'onedrive'=>array('onedrive.live.com','1drv.ms','sharepoint.com')) as $p=>$domains) {
            foreach($domains as $d){if($host===$d || substr($host,-strlen('.'.$d))==='.'. $d){return $p;}}
        }
        return 'generic';
    }
    public static function provider_label($p) { return array('google_drive'=>'Google Drive','dropbox'=>'Dropbox','wetransfer'=>'WeTransfer','onedrive'=>'OneDrive','generic'=>'Enlace externo')[$p] ?? 'Enlace externo'; }
    public static function validate_url($url) {
        if(!is_string($url)||strlen($url)>4096||preg_match('/[\x00-\x20\x7f\\\\]/',$url)){return new WP_Error('url','URL inválida.');}
        $p=parse_url($url);
        if(!$p||!in_array(strtolower($p['scheme']??''),array('http','https'),true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&!in_array($p['port'],array(80,443),true)){return new WP_Error('url','Usá un enlace http o https sin credenciales.');}
        $host=strtolower($p['host']);
        if(!preg_match('/^[a-z0-9.-]+$/D',$host)||strpos($host,'.')===false||preg_match('/(^|\.)(localhost|local|internal|test|invalid)$/D',$host)){return new WP_Error('url','No se admiten direcciones internas.');}
        if(preg_match('/^[0-9.]+$/D',$host)&&!self::public_ip($host)){return new WP_Error('url','No se admiten direcciones internas.');}
        // Canonical dotted decimal only; exclude alternative numeric hosts handled by curl.
        if(preg_match('/(^|\.)(0x[0-9a-f]+|0[0-9]+)(\.|$)/i',$host)){return new WP_Error('url','Dirección no admitida.');}
        return $url;
    }
    public static function public_ip($ip) {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) && !preg_match('/^(0\.|127\.|169\.254\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|192\.0\.0\.|192\.0\.2\.|198\.(18|19|51)\.|203\.0\.113\.|22[4-9]\.|23[0-9]\.|24[0-9]\.|25[0-5]\.)/',$ip);
    }
    public static function public_record($r) {
        return array_intersect_key($r,array_flip(array('id','artifact_id','version_id','name','display_name','notes','source_type','provider','url','size','access_status','imported_file_id','file_analysis_ref','analysis_status','source_external_ref','checksum_sha256','quote_id','quote_item_id','line_uuid','order_item_id')));
    }
    public static function enqueue() {
        wp_enqueue_script('ge-external-artwork',GE_WTP_PLUGIN_URL.'assets/js/external-artwork.js',array(),filemtime(GE_WTP_PLUGIN_DIR.'assets/js/external-artwork.js'),true);
        wp_localize_script('ge-external-artwork','geExternalArtwork',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(self::ACTION)));
        wp_enqueue_style('ge-quote-artwork-v2',GE_WTP_PLUGIN_URL.'assets/css/quote-artwork-v2.css',array(),filemtime(GE_WTP_PLUGIN_DIR.'assets/css/quote-artwork-v2.css'));
    }
    public static function render($r,$quote_id=0,$order_id=0,$field='',$staff=true) {
        self::enqueue();
        $tag=$field?'li':'article';
        echo '<'.$tag.' class="ge-external-entry" data-ge-external-id="'.esc_attr($r['id']).'" data-quote-id="'.esc_attr($quote_id).'" data-order-id="'.esc_attr($order_id).'"><a href="'.esc_url($r['url']).'" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">↗</span> '.esc_html($r['name']).'</a><small>'.esc_html(self::provider_label($r['provider']).' · '.self::state($r)).'</small>';
        if(!empty($r['notes'])){echo '<small>'.esc_html($r['notes']).'</small>';}
        echo '<div class="ge-external-actions"><a href="'.esc_url($r['url']).'" target="_blank" rel="noopener noreferrer">Abrir archivo externo</a><button type="button" data-ge-copy-link="'.esc_attr($r['url']).'">Copiar link</button>';
        if($staff && empty($r['imported_file_id']) && 'detached'!==($r['association_status']??'')){echo '<button type="button" data-ge-import-link>Importar a Graphex</button>';}
        if($field){echo '<button type="button" data-ge-artwork-remove>Quitar vínculo</button><input type="hidden" name="'.esc_attr($field).'" value="'.esc_attr($r['id']).'">';}
        echo '</div><small data-ge-external-notice role="status" aria-live="polite"></small></'.$tag.'>';
    }
    public static function state($r) {
        if(!empty($r['imported_file_id'])){return 'Importado · origen conservado';}
        return ('requires_access'===($r['access_status']??'')?'Requiere acceso':('unavailable'===($r['access_status']??'')?'No disponible':'Acceso no verificado')).' · No analizado / archivo externo';
    }
    private static function fail($message,$code=422){wp_send_json_error(array('message'=>$message),$code);}
    private static function stage_key($actor,$id){return GE_WTP_Quote_Artwork_V2::PREFIX.$actor.'_'.$id;}
    public static function ajax() {
        if(!is_user_logged_in()||(!GE_WTP_Staff_Portal::can_access()&&!(GE_WTP_Quote_Requests::customer_can_stage()&&'add'===($_POST['op']??'')))||GE_WTP_Portal::is_staff_preview()){self::fail('Acceso denegado.',403);}
        if(!check_ajax_referer(self::ACTION,'nonce',false)){self::fail('La sesión venció. Volvé a abrir el trabajo.',403);}
        foreach(array('op','quote_id','order_id','ref_id','session_id','url','name','notes') as $f){if(isset($_POST[$f])&&!is_scalar($_POST[$f])){self::fail('Solicitud inválida.');}}
        $actor=get_current_user_id();$quote_id=absint($_POST['quote_id']??0);$order_id=absint($_POST['order_id']??0);$order=null;$quote=null;
        if($order_id){$order=wc_get_order($order_id);if(!$order||!GE_WTP_Documents::can_access_order($order)){self::fail('Pedido no disponible.',403);} $quote_id=(int)$order->get_meta('_ge_commercial_quote_id',true);}
        if($quote_id){$quote=GE_WTP_Commercial_Quotes::get($quote_id,$actor);if(is_wp_error($quote)){self::fail('Presupuesto no disponible.',403);}}
        $op=sanitize_key($_POST['op']??'');$id=sanitize_text_field(wp_unslash($_POST['ref_id']??''));$session=sanitize_text_field(wp_unslash($_POST['session_id']??''));
        if(!GE_WTP_Quote_Artwork_V2::uuid($id)){self::fail('Referencia inválida.');}
        if('add'===$op && ($order || ($quote && (!in_array($quote['status'],array('draft','sent','viewed'),true)||$quote['converted_order_id']||get_post_meta($quote_id,'_ge_commercial_initial_payment_order',true))))){self::fail('Abrí un presupuesto editable.',409);}
        if(!GE_WTP_Documents::ensure_private_directory()){self::fail('Almacenamiento no disponible.',503);}
        $root=trailingslashit(GE_WTP_Documents::private_directory());
        $scope=$quote_id?'quote-'.$quote_id:('stage-'.$actor.'-'.hash('sha256',$session));
        $scope_lock=fopen($root.'external-scope-'.$scope.'.lock','c');
        if(!$scope_lock||!flock($scope_lock,LOCK_EX|LOCK_NB)){self::fail('Este trabajo tiene una operación en curso. Reintentá en unos segundos.',409);}@chmod($root.'external-scope-'.$scope.'.lock',0600);
        $lock=fopen($root.'external-'.$id.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){self::fail('Importación en curso. Reintentá en unos segundos.',409);}@chmod($root.'external-'.$id.'.lock',0600);
        try {
            $key=self::stage_key($actor,$id);$row=get_option($key);
            if('add'===$op){
                if(!GE_WTP_Quote_Artwork_V2::uuid($session)){self::fail('Sesión inválida.');}
                $url=self::validate_url(wp_unslash($_POST['url']??''));if(is_wp_error($url)){self::fail($url->get_error_message());}
                $name=mb_substr(sanitize_text_field(wp_unslash($_POST['name']??'')),0,160);$notes=mb_substr(sanitize_textarea_field(wp_unslash($_POST['notes']??'')),0,1000);
                if($row){if($row['session_id']!==$session||$row['quote_id']!==$quote_id||$row['record']['url']!==$url){self::fail('Reintento diferente al original.',409);}wp_send_json_success(array('record'=>self::public_record($row['record'])));}
                $rate_key='ge_external_rate_'.$actor;$rate=(int)get_transient($rate_key);if($rate>=60){self::fail('Límite de enlaces por hora alcanzado.',429);}set_transient($rate_key,$rate+1,HOUR_IN_SECONDS);
                $provider=self::provider($url);$record=array('id'=>$id,'artifact_ref_id'=>$id,'artifact_id'=>$id,'version_id'=>$id,'name'=>$name?:self::provider_label($provider),'display_name'=>$name?:self::provider_label($provider),'notes'=>$notes,'url'=>$url,'provider'=>$provider,'source_type'=>'external_link','category'=>'arte','role'=>'artwork','size'=>0,'mime'=>'','access_status'=>'unverified','analysis_status'=>'unverified','imported_file_id'=>null,'checksum_sha256'=>null,'file_analysis_ref'=>null,'created_by'=>$actor,'created_at'=>gmdate('c'),'uploaded_by'=>$actor,'uploaded_at'=>gmdate('c'),'audit'=>array(array('event'=>'external_link_added','actor'=>$actor,'at'=>gmdate('c'))));
                $row=array('id'=>$id,'session_id'=>$session,'quote_id'=>$quote_id,'actor_id'=>$actor,'created_at'=>time(),'size'=>0,'status'=>'ready','record'=>$record);
                if(!add_option($key,$row,'',false)){self::fail('Reintentá guardar el enlace.',409);}wp_send_json_success(array('record'=>self::public_record($record)));
            }
            if('import'!==$op){self::fail('Operación inválida.');}
            $files=$order?GE_WTP_Documents::get_documents($order_id):($quote?GE_WTP_Commercial_Quote_Files::all($quote_id):array());$index=null;
            foreach($files as $i=>$r){if(($r['id']??'')===$id && self::is_link($r) && 'detached'!==($r['association_status']??'')){$index=$i;break;}}
            $staged=null;
            if(null!==$index){$record=$files[$index];}
            elseif($row && $row['session_id']===$session && $row['quote_id']===$quote_id && empty($row['claimed_quote_id']) && $row['created_at']>time()-DAY_IN_SECONDS && self::is_link($row['record'])){$record=$row['record'];$staged=$row;}
            else{self::fail('Enlace no disponible en este trabajo.',403);}
            if(!empty($record['imported_file_id'])){
                $local=null;foreach($files as $r){if(($r['id']??'')===$record['imported_file_id']){$local=$r;break;}}
                if(!$local){$saved=get_option('ge_external_import_'.$id);$local=$saved['local']??null;}
                wp_send_json_success(array('record'=>self::public_record($record),'local'=>$local?self::public_record($local):null));
            }
            if(count($files)>=30){self::fail('Máximo 30 referencias por presupuesto. Quitá un vínculo antes de importar.');}
            $result=self::import_record($record,$actor,$root);
            if(is_wp_error($result)){
                $record['access_status']=in_array($result->get_error_code(),array('access','html'),true)?'requires_access':'unavailable';
                self::persist($record,null,$files,$index,$staged,$key,$quote_id,$order);
                wp_send_json_error(array('message'=>$result->get_error_message(),'record'=>self::public_record($record)),422);
            }
            $local=$result['local'];$record['imported_file_id']=$local['id'];$record['access_status']='verified';$record['audit'][]=array('event'=>'imported','actor'=>$actor,'at'=>gmdate('c'),'file_id'=>$local['id']);
            self::persist($record,$local,$files,$index,$staged,$key,$quote_id,$order);
            wp_send_json_success(array('record'=>self::public_record($record),'local'=>self::public_record($local)));
        }finally{flock($lock,LOCK_UN);fclose($lock);flock($scope_lock,LOCK_UN);fclose($scope_lock);}
    }
    private static function persist($record,$local,$files,$index,$staged,$key,$quote_id,$order){
        if($staged){$staged['record']=$record;update_option($key,$staged,false);if($local){$s=$staged;$s['id']=$local['id'];$s['size']=$local['size'];$s['record']=$local;update_option(self::stage_key($s['actor_id'],$s['id']),$s,false);}return;}
        $files[$index]=$record;
        if($local && !in_array($local['id'],array_column($files,'id'),true)){$files[]=$local;}
        if($order){$order->update_meta_data(GE_WTP_Documents::META_KEY,$files);$order->add_order_note('Actualización de enlace externo de arte; no implica aprobación de producción.');$order->save();if($local&&!empty($local['order_item_id'])&&$order->get_meta('_ge_commercial_quote_id',true)){GE_WTP_Commercial_Quote_Files::attach_to_item($order->get_id(),$local['order_item_id'],$local['id'],get_current_user_id());}}
        if($quote_id){$quote_files=GE_WTP_Commercial_Quote_Files::all($quote_id);foreach($quote_files as &$r){if($r['id']===$record['id']){$r=$record;}}unset($r);if($local&&!in_array($local['id'],array_column($quote_files,'id'),true)){$quote_files[]=$local;}update_post_meta($quote_id,GE_WTP_Commercial_Quote_Files::META,$quote_files);GE_WTP_Commercial_Quotes::record_event($quote_id,'external_artwork_updated',get_current_user_id(),array('artifact_ref_id'=>$record['id'],'imported_file_id'=>$record['imported_file_id']));}
        if(!$order && $quote_id){$q=GE_WTP_Commercial_Quotes::get($quote_id);if(!empty($q['converted_order_id'])){$linked=wc_get_order($q['converted_order_id']);if($linked){$docs=GE_WTP_Documents::get_documents($linked->get_id());foreach($docs as &$doc){if(($doc['id']??'')===$record['id']){$doc=array_merge($doc,$record);}}unset($doc);$linked->update_meta_data(GE_WTP_Documents::META_KEY,$docs);$linked->save();GE_WTP_Commercial_Quote_Files::inherit($quote_id,$linked);}}}
    }
    public static function fetch_url($url){
        if('google_drive'===self::provider($url)&&preg_match('#/file/d/([a-zA-Z0-9_-]+)#',$url,$m)){
            parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);return 'https://drive.google.com/uc?export=download&id='.$m[1].(!empty($query['resourcekey'])?'&resourcekey='.rawurlencode($query['resourcekey']):'');
        }
        if('dropbox'===self::provider($url)){return add_query_arg('dl','1',$url);}
        return $url;
    }
    public static function import_record($record,$actor,$root){
        $saved=get_option('ge_external_import_'.$record['id']);if($saved && is_file($root.$saved['local']['stored_name']) && hash_equals($saved['local']['checksum_sha256'],hash_file('sha256',$root.$saved['local']['stored_name']))){return $saved;}
        if(!function_exists('curl_init')){return new WP_Error('unavailable','La importación no está disponible. El enlace sigue guardado.');}
        if(disk_free_space($root)<GE_WTP_Quote_Artwork_V2::MAX_FILE+2*1073741824){return new WP_Error('capacity','No hay capacidad para importar este archivo.');}
        global $wpdb;$rows=$wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(GE_WTP_Quote_Artwork_V2::PREFIX.$actor.'_').'%'));$reserved=0;foreach($rows as $v){$r=maybe_unserialize($v);if(is_array($r)&&($r['created_at']??0)>time()-DAY_IN_SECONDS){$reserved+=(int)($r['size']??0);}}
        if($reserved+GE_WTP_Quote_Artwork_V2::MAX_FILE>2*1073741824){return new WP_Error('capacity','Llegaste al límite diario de importaciones y cargas.');}
        $rate_key='ge_external_import_rate_'.$actor;$rate=(int)get_transient($rate_key);if($rate>=30){return new WP_Error('rate','Límite de importaciones por hora alcanzado.');}set_transient($rate_key,$rate+1,HOUR_IN_SECONDS);
        $id=wp_generate_uuid4();$part=$root.'external-'.$id.'.part';$url=self::fetch_url($record['url']);$deadline=microtime(true)+40;$download=null;
        try {
            for($hop=0;$hop<=3;$hop++){
                $valid=self::validate_url($url);if(is_wp_error($valid)){return $valid;}
                $p=parse_url($url);$host=$p['host'];$ips=filter_var($host,FILTER_VALIDATE_IP)?array($host):gethostbynamel($host);
                if(!$ips){return new WP_Error('dns','No se pudo verificar el destino. El enlace sigue guardado.');}
                foreach($ips as $ip){if(!self::public_ip($ip)){return new WP_Error('ssrf','El destino no es una dirección pública permitida.');}}
                $left=(int)floor($deadline-microtime(true));if($left<1){return new WP_Error('timeout','La descarga excedió el tiempo permitido.');}
                $out=fopen($part,'wb');if(!$out){return new WP_Error('storage','Almacenamiento no disponible.');}@chmod($part,0600);$bytes=0;$too_big=false;$headers=array();$header_bytes=0;
                $ch=curl_init($url);$port=$p['port']??('https'===$p['scheme']?443:80);
                curl_setopt_array($ch,array(CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>min(8,$left),CURLOPT_TIMEOUT=>$left,CURLOPT_LOW_SPEED_LIMIT=>1024,CURLOPT_LOW_SPEED_TIME=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>array($host.':'.$port.':'.$ips[0]),CURLOPT_USERAGENT=>'GraphexArtworkImport/1.0',CURLOPT_HTTPHEADER=>array('Accept-Encoding: identity'),CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$headers,&$header_bytes){$header_bytes+=strlen($line);if($header_bytes>65536){return 0;}if(strpos($line,':')!==false){list($k,$v)=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},CURLOPT_WRITEFUNCTION=>function($c,$chunk)use($out,&$bytes,&$too_big){$n=strlen($chunk);if($bytes+$n>GE_WTP_Quote_Artwork_V2::MAX_FILE){$too_big=true;return 0;}$written=fwrite($out,$chunk);$bytes+=$written;return $written;}));
                $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);fclose($out);
                if($too_big){return new WP_Error('size','El archivo supera el límite de importación de 250 MiB.');}
                if(in_array($status,array(301,302,303,307,308),true)){
                    if($hop===3||empty($headers['location'])){return new WP_Error('redirect','Demasiadas redirecciones.');}
                    $url=WP_Http::make_absolute_url($headers['location'],$url);continue;
                }
                if(in_array($status,array(401,403),true)){return new WP_Error('access','Requiere acceso. Habilitá la descarga o usá Subir archivo.');}
                if(false===$ok||$status!==200||!$bytes){return new WP_Error('download','No se pudo descargar el archivo. Revisá el acceso o reintentá.');}
                $download=array('headers'=>$headers,'bytes'=>$bytes,'url'=>$url);break;
            }
            if(!$download){return new WP_Error('download','Descarga no disponible.');}
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($part);$exts=array('application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/tiff'=>'tif','application/postscript'=>'eps','image/vnd.adobe.photoshop'=>'psd','image/x-photoshop'=>'psd','application/zip'=>'zip','application/x-zip'=>'zip','application/x-zip-compressed'=>'zip');
            if(!isset($exts[$mime])){return new WP_Error('html','El enlace no entrega un archivo compatible. Puede requerir acceso o una URL de descarga directa.');}
            $name='';if(preg_match('/filename="?([^";]+)"?/i',$download['headers']['content-disposition']??'',$m)){$name=sanitize_file_name(wp_basename(rawurldecode($m[1])));}
            if(!$name){$name=sanitize_file_name(wp_basename(rawurldecode(parse_url($record['url'],PHP_URL_PATH)??'')));}
            $ext=$exts[$mime];$name=substr(pathinfo($name?:'arte',PATHINFO_FILENAME),0,120).'.'.$ext;$stored='external-'.$id.'.'.$ext;$sha=hash_file('sha256',$part);
            if(!rename($part,$root.$stored)){return new WP_Error('storage','No se pudo guardar el archivo privado.');}@chmod($root.$stored,0600);
            $local=array('id'=>$id,'artifact_ref_id'=>$id,'artifact_id'=>$record['artifact_id']??$record['id'],'version_id'=>$id,'source_type'=>'uploaded_file','source_external_ref'=>$record['id'],'source_url'=>$record['url'],'origin_provider'=>$record['provider'],'stored_name'=>$stored,'name'=>$name,'mime'=>$mime,'size'=>$download['bytes'],'category'=>'arte','role'=>'artwork','checksum_sha256'=>$sha,'analysis'=>array('sha256'=>$sha),'uploaded_by'=>$actor,'uploaded_at'=>gmdate('c'),'status'=>'uploaded','quote_id'=>$record['quote_id']??0,'quote_item_id'=>$record['quote_item_id']??'','line_uuid'=>$record['line_uuid']??'','association_status'=>'active');
            if(!empty($record['order_item_id'])){$local['order_item_id']=$record['order_item_id'];$local['artwork_side']='general';}
            $local=GE_WTP_File_Analysis::record($local,$root);$result=array('local'=>$local);
            update_option('ge_external_import_'.$record['id'],$result,false);
            // Shared daily reservation accounting, including saved-order imports.
            if(!get_option(self::stage_key($actor,$id))){add_option(self::stage_key($actor,$id),array('id'=>$id,'actor_id'=>$actor,'created_at'=>time(),'size'=>$local['size'],'record'=>$local,'status'=>'ready','session_id'=>'','quote_id'=>$local['quote_id']),'',false);}
            return $result;
        }finally{if(is_file($part)){unlink($part);}}
    }
}

<?php
defined('ABSPATH') || exit;
/** Customer-safe, read-only PDFs backed by native orders and credited payments. */
final class GE_WTP_Customer_Work_Documents {
    const ACK_META = '_ge_customer_work_document_ack';
    public static function init() {
        add_action('admin_post_ge_customer_work_pdf', array(__CLASS__, 'download'));
        add_action('admin_post_ge_customer_work_ack', array(__CLASS__, 'acknowledge'));
    }
    public static function allowed($order, $kind, $actor = 0) {
        $actor = $actor ?: get_current_user_id();
        if (!$order || !$actor || !in_array($kind,array('receipt','work'),true) || 'yes' === $order->get_meta('_ge_commercial_payment_order',true)) return false;
        $org = $order->get_meta('_ge_organization_id',true);
        $primary = GE_Organization::PRIMARY;
        if (($org && $org !== $primary) || (!$org && $primary !== 'graph-express')) return false;
        if (GE_WTP_Portal::customer_owns_order($order,$actor)) return true;
        return $kind === 'receipt' ? GE_WTP_Customer_Invoices::staff($actor,false) : GE_Organization_Runtime::allowed('orders',false,$actor);
    }
    public static function url($order,$kind) {
        return wp_nonce_url(add_query_arg(array('action'=>'ge_customer_work_pdf','order_id'=>$order->get_id(),'kind'=>$kind),admin_url('admin-post.php')),'ge_customer_work_pdf_'.$order->get_id().'_'.$kind);
    }
    public static function receipt_rows($balance) {
        if (is_wp_error($balance)) return $balance;
        if (($balance['amount_paid_cents']??0)<=0) return new WP_Error('unpaid','Todavía no hay cobros acreditados para emitir un recibo.');
        $rows=array();$sum=0;
        foreach ($balance['ledger']??array() as $entry) {
            $amount=(int)($entry['amount_cents']??0); if($amount<=0)continue;
            $rows[]=array('id'=>(string)$entry['id'],'date'=>(string)($entry['received_date']??''),'method'=>(string)($entry['method']??''),'amount'=>$amount);$sum+=$amount;
        }
        foreach ($balance['historical']??array() as $entry) {
            $amount=(int)($entry['amount_cents']??0);if($amount<=0)continue;
            $rows[]=array('id'=>(string)$entry['id'],'date'=>'','method'=>'historical','amount'=>$amount);$sum+=$amount;
        }
        $remaining=(int)$balance['amount_paid_cents']-$sum;
        if($remaining<0)return new WP_Error('mismatch','Los cobros requieren conciliación.');
        // Gateway/legacy credit is authoritative, but its bank date/method is not inferred.
        if($remaining>0)$rows[]=array('id'=>'credited-other','date'=>'','method'=>'credited','amount'=>$remaining);
        return $rows;
    }
    public static function model($order,$kind) {
        if(!in_array($kind,array('receipt','work'),true))return new WP_Error('kind','Documento inválido.');
        if($kind==='receipt' && GE_Work_Finance::dependent($order))return new WP_Error('parent','El recibo corresponde al pedido comercial principal.');
        $snapshot=$order->get_meta('_ge_commercial_quote_snapshot',true);$snapshot=is_array($snapshot)?$snapshot:array();
        $receiver=$snapshot['receiver_snapshot']??$order->get_meta('_ge_billing_profile_snapshot',true);$receiver=is_array($receiver)?$receiver:array();
        $issuer=GE_WTP_Billing_Issuers::order_snapshot($order);
        $m=array('kind'=>$kind,'order_id'=>$order->get_id(),'number'=>$order->get_order_number(),'currency'=>$order->get_currency(),'customer'=>($receiver['legal_name']??'')?:$order->get_formatted_billing_full_name(),'cuit'=>$receiver['cuit']??'','issuer'=>($issuer['legal_name']??'')?:'Graphex','issuer_cuit'=>$issuer['cuit']??'','items'=>array());
        foreach($order->get_items('line_item') as $item){$m['items'][]=array('id'=>$item->get_id(),'name'=>$item->get_name(),'quantity'=>$item->get_quantity(),'details'=>(string)$item->get_meta('Especificaciones',true));}
        if($kind==='receipt'){
            $b=GE_Work_Finance::balance($order);$rows=self::receipt_rows($b);if(is_wp_error($rows))return $rows;
            $m['payments']=$rows;$m['total']=$b['total_cents'];$m['paid']=$b['amount_paid_cents'];$m['due']=$b['amount_due_cents'];
        }else{
            $m['stage']=GE_WTP_Order_Lifecycle::label($order);
            $m['promised']=(string)$order->get_meta('_ge_production_promised_date',true);
            $m['files']=self::files($order);
        }
        $m['revision']=substr(hash('sha256',wp_json_encode($m)),0,12);
        $m['reference']=($kind==='receipt'?'RX-':'OT-').$m['number'].'-'.strtoupper(substr($m['revision'],0,6));
        return $m;
    }
    public static function files($order){
        $out=array();
        foreach(GE_WTP_Documents::get_documents($order->get_id()) as $d){
            if(!empty($d['superseded_at']) || !GE_WTP_Documents::customer_visible($d) || !in_array($d['category']??'',array('arte','prueba','aprobado'),true))continue;
            $out[]=array('id'=>'document:'.($d['id']??''),'name'=>(string)($d['name']??$d['original_name']??'Archivo del trabajo'),'version'=>(string)($d['version_id']??$d['id']??''),'hash'=>(string)($d['analysis']['sha256']??''));
        }
        foreach(GE_WTP_Artwork_Library::get_order_ids($order) as $id){
            if(get_post_meta($id,'_ge_artwork_status',true)==='archived')continue;
            $d=(array)get_post_meta($id,'_ge_artwork_original',true);
            if(!$d)continue;
            $out[]=array('id'=>'artwork:'.$id,'name'=>(string)($d['name']??get_the_title($id)),'version'=>(string)($d['file_id']??$id),'hash'=>(string)($d['analysis']['sha256']??''));
        }
        return $out;
    }
    /** Resolve only an order-linked private original; never an arbitrary path or URL. */
    public static function thumbnail($order_id,$file){
        $order=wc_get_order($order_id);if(!$order)return false;
        $linked=false;foreach(self::files($order) as $f)if($f===$file){$linked=true;break;}
        if(!$linked)return false;
        $token=$file['id'];$record=array();$dir='';
        if(strpos($token,'document:')===0){foreach(GE_WTP_Documents::get_documents($order_id) as $d)if((string)$d['id']===substr($token,9))$record=$d;$dir=GE_WTP_Documents::private_directory();}
        elseif(strpos($token,'artwork:')===0){$record=(array)get_post_meta(absint(substr($token,8)),'_ge_artwork_original',true);$dir=WP_CONTENT_DIR.'/ge-private/artwork-originals';}
        $provider=$record['provider']??'local';
        if($provider==='vps')$path=GE_WTP_VPS_Storage::download_path($record['relative_path']??'');
        elseif($provider==='local')$path=trailingslashit($dir).wp_basename($record['stored_name']??'');else return false;
        $path=GE_WTP_File_Analysis::safe_path($path);
        if(!$path||is_wp_error($path)||!is_file($path)||filesize($path)>50*1024*1024)return false;
        if($file['hash']&&!hash_equals($file['hash'],hash_file('sha256',$path)))return false;
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $tmp=tempnam(get_temp_dir(),'ge-work-preview-');if(!$tmp)return false;
        $jpg=$tmp.'.jpg';$bytes=false;
        try{
            if($mime==='application/pdf' && function_exists('proc_open') && is_executable('/usr/bin/pdftoppm')){
                $proc=proc_open(array('/usr/bin/pdftoppm','-f','1','-singlefile','-scale-to','900','-jpeg',$path,$tmp),array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','w'),2=>array('file','/dev/null','w')),$pipes);
                if(is_resource($proc)){$start=microtime(true);while(($status=proc_get_status($proc))['running'] && microtime(true)-$start<6)usleep(50000);if($status['running'])proc_terminate($proc,9);proc_close($proc);}
            }elseif(in_array($mime,array('image/jpeg','image/png','image/webp'),true)){
                $size=@getimagesize($path);if(!$size||$size[0]*$size[1]>24000000)return false;
                $editor=wp_get_image_editor($path);if(!is_wp_error($editor)){$editor->resize(900,900,false);$editor->save($jpg,'image/jpeg');}
            }
            if(is_file($jpg)&&filesize($jpg)<2000000){$info=@getimagesize($jpg);if($info&&($info['channels']??3)!==4)$bytes=file_get_contents($jpg);}
        }finally{if(is_file($tmp))unlink($tmp);if(is_file($jpg))unlink($jpg);}
        return $bytes;
    }
    private static function money($n,$currency){return $currency.' $'.number_format($n/100,2,',','.');}
    private static function date($date){return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)?substr($date,8,2).'/'.substr($date,5,2).'/'.substr($date,0,4):'Sin fecha registrada';}
    /** Some production iconv builds reject TRANSLIT and the shared engine falls back to UTF-8.
     * Normalize only text operands before the engine calculates stream lengths and xrefs.
     * Already encoded WinAnsi and image/font bytes are left untouched.
     */
    private static function encoded_page($commands){
        return preg_replace_callback('/\\((?:\\\\.|[^\\\\)])*\\) Tj/s',function($match){
            return mb_check_encoding($match[0],'UTF-8')?mb_convert_encoding($match[0],'Windows-1252','UTF-8'):$match[0];
        },$commands);
    }
    public static function pdf($model){
        if(is_wp_error($model))return $model;
        $p=new GE_WTP_Simple_PDF();$p->use_brand_body_font();$pages=array();$y=0;$page=0;
        $header=function()use($p,$model,&$y,&$page){$page++;$p->begin_page();$p->brand_symbol(38,30,34);$p->text(82,55,19,'GRAPHEX',true,45,35,65);$p->text_right(557,48,18,$model['kind']==='receipt'?'RECIBO X':'ORDEN DE TRABAJO',true,109,69,239);$p->text_right(557,68,9,$model['reference']);$p->fill_rect(38,83,519,2,109,69,239);$y=109;};
        $end=function()use($p,&$pages,&$page,$model){$p->text(38,805,8,$model['kind']==='receipt'?'Documento no válido como factura. Constancia de cobros registrados.':'Documento informativo. La conformidad no sustituye la aprobación del arte.');$p->text_right(557,821,8,'Graphex · '.$page);$pages[]=self::encoded_page($p->end_page());};
        $line=function($text,$size=10,$bold=false)use($p,&$y,$header,$end){
            foreach(explode("\n",(string)$text)as$paragraph){$tokens=preg_split('/\s+/u',trim(wp_strip_all_tags($paragraph)));$words=array();foreach($tokens as $token){$chunk='';foreach(preg_split('//u',$token,-1,PREG_SPLIT_NO_EMPTY) as $char){$candidate=$chunk.$char;if($chunk!==''&&max($p->measure($candidate,$size,$bold),mb_strlen($candidate)*$size*0.6)>490){$words[]=$chunk;$chunk=$char;}else $chunk=$candidate;}$words[]=$chunk;}$row='';foreach($words as $word){$next=$row===''?$word:$row.' '.$word;if(max($p->measure($next,$size,$bold),mb_strlen($next)*$size*0.48)>500 && $row!==''){if($y>770){$end();$header();}$p->text(38,$y,$size,$row,$bold);$y+=$size+5;$row=$word;}else{$row=$next;}}if($y>770){$end();$header();}$p->text(38,$y,$size,$row,$bold);$y+=$size+5;}
        };
        $header();$line('Emisor del trabajo: '.$model['issuer'],10,true);if($model['issuer_cuit'])$line('CUIT '.$model['issuer_cuit'],9);
        $y+=8;$line('Cliente: '.$model['customer'],11,true);if($model['cuit'])$line('CUIT '.$model['cuit'],9);$line('Trabajo #'.$model['number'],10);$y+=10;
        if($model['kind']==='work'){$line('Estado actual: '.$model['stage'],11,true);$line('Fecha prometida: '.($model['promised']?self::date($model['promised']):'A coordinar'));$y+=8;}
        $line($model['kind']==='receipt'?'Concepto del cobro':'Características del trabajo',12,true);
        foreach($model['items'] as $item){$line($item['quantity'].' × '.$item['name'],10,true);if($model['kind']==='work' && $item['details'])$line($item['details'],9);$y+=5;}
        if($model['kind']==='receipt'){
            $y+=12;$line('Cobros recibidos',12,true);$labels=array('transfer'=>'Transferencia','cash'=>'Efectivo','other'=>'Otro medio','historical'=>'Cobro histórico confirmado; medio no registrado','credited'=>'Pago acreditado; medio no detallado');
            foreach($model['payments'] as $row){$line(self::date($row['date']).' · '.($labels[$row['method']]??'Medio no registrado').' · '.self::money($row['amount'],$model['currency']),10);}
            $y+=15;$line('Total del trabajo: '.self::money($model['total'],$model['currency']),11);
            $line('TOTAL RECIBIDO: '.self::money($model['paid'],$model['currency']),14,true);$line('Saldo pendiente: '.self::money($model['due'],$model['currency']),12,true);
            $y+=10;$line('Esta constancia reúne los cobros detallados. No constituye un nuevo cargo ni un nuevo pago.',9);
        }else{
            $y+=12;$line('Archivos del trabajo',12,true);
            if(!$model['files'])$line('No hay archivos compartidos vinculados. Consultá antes de imprimir.',9);
            $preview_count=0;$preview_deadline=microtime(true)+10;
            foreach($model['files']as$f){
                if($y>570){$end();$header();}
                $line($f['name'],10,true);$line('Referencia de archivo: '.$f['version'],8);
                $image=$preview_count<6&&microtime(true)<$preview_deadline?self::thumbnail($model['order_id'],$f):false;$preview_count++;
                if($image){$size=getimagesizefromstring($image);$scale=min(260/$size[0],150/$size[1]);$w=$size[0]*$scale;$h=$size[1]*$scale;$p->image_jpeg(38,$y,$w,$h,$image);$y+=$h+12;$line('Miniatura orientativa de la primera página; no es una prueba de color.',8);}
                else $line('Vista previa no disponible en esta hoja. Consultá el archivo original en el portal.',8);
                $y+=8;
            }
            $y+=10;$line('La fecha y las características corresponden a esta versión de la orden. Podés prestar conformidad o informar una observación desde tu portal. Descargar este documento no modifica la producción.',9);
        }
        $end();return $p->output($pages);
    }
    public static function render($order,$staff=false){
        if(!self::allowed($order,'work') && !self::allowed($order,'receipt'))return;
        echo '<section class="ge-panel ge-production-card" id="ge-customer-work-documents"><h2>Documentos del trabajo</h2><p>Orden de trabajo y constancia de cobros, disponibles en PDF.</p>';
        if(self::allowed($order,'work'))echo '<p><a class="ge-staff-button is-secondary" href="'.esc_url(self::url($order,'work')).'">Descargar orden de trabajo</a></p>';
        if(self::allowed($order,'receipt')){$m=self::model($order,'receipt');if(!is_wp_error($m))echo '<p><a class="ge-staff-button is-secondary" href="'.esc_url(self::url($order,'receipt')).'">Descargar recibo X</a> · Total recibido '.esc_html(self::money($m['paid'],$m['currency'])).'</p>';else echo '<p>'.esc_html($m->get_error_message()).'</p>';}
        $m=self::model($order,'work');$ack=$order->get_meta(self::ACK_META,true);$current=is_array($ack)&&($ack['revision']??'')===$m['revision'];
        if(is_array($ack)&&!empty($ack['comment']))echo '<p>Observaci�n: '.esc_html($ack['comment']).'</p>';
        if($ack)echo '<p>Conformidad: '.esc_html(($ack['decision']??'')==='accepted'?'Conforme':'Con observaciones').($current?'':' · corresponde a una versión anterior').'</p>';
        if(!$staff && GE_WTP_Portal::customer_owns_order($order,get_current_user_id())){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_customer_work_ack"><input type="hidden" name="order_id" value="'.absint($order->get_id()).'"><input type="hidden" name="revision" value="'.esc_attr($m['revision']).'">';wp_nonce_field('ge_customer_work_ack_'.$order->get_id());
            echo '<p>Conformidad opcional de la orden. No reemplaza la aprobación del archivo.</p><label>Observación (opcional)<textarea name="comment" maxlength="2000"></textarea></label><button name="decision" value="accepted">Estoy conforme</button> <button name="decision" value="observed">Informar observación</button></form>';
        }echo '</section>';
    }
    public static function download(){
        $id=absint($_GET['order_id']??0);$kind=sanitize_key($_GET['kind']??'');$o=wc_get_order($id);
        if(!self::allowed($o,$kind))wp_die('Acceso denegado.','',array('response'=>403));check_admin_referer('ge_customer_work_pdf_'.$id.'_'.$kind);
        $m=self::model($o,$kind);$bytes=self::pdf($m);if(is_wp_error($bytes))wp_die(esc_html($bytes->get_error_message()),'',array('response'=>409));
        nocache_headers();header('X-Content-Type-Options: nosniff');header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.sanitize_file_name($m['reference']).'.pdf"');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
    }
    public static function acknowledge(){
        $id=absint($_POST['order_id']??0);$o=wc_get_order($id);if(!self::allowed($o,'work')||!GE_WTP_Portal::customer_owns_order($o,get_current_user_id()))wp_die('Acceso denegado.','',array('response'=>403));check_admin_referer('ge_customer_work_ack_'.$id);
        $m=self::model($o,'work');$decision=sanitize_key($_POST['decision']??'');if(!in_array($decision,array('accepted','observed'),true)||!hash_equals($m['revision'],(string)($_POST['revision']??'')))wp_die('La orden cambió. Volvé al portal y revisá la versión actual.','',array('response'=>409));
        $ack=array('revision'=>$m['revision'],'decision'=>$decision,'comment'=>mb_substr(sanitize_textarea_field(wp_unslash($_POST['comment']??'')),0,2000),'actor'=>get_current_user_id(),'at'=>current_time('mysql'));
        $o->update_meta_data(self::ACK_META,$ack);$o->save();$o->add_order_note('Conformidad de orden '.$m['reference'].': '.$decision.'. '.$ack['comment'],false);
        wp_safe_redirect(wp_get_referer()?:home_url('/cliente-markcom/'));exit;
    }
}

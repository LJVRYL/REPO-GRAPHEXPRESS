<?php
 defined( 'ABSPATH' ) || exit;

/** One read-only workspace over the existing commercial and operational records. */
final class GE_WTP_Work_Panel {
    public static function sections() { return array( 'requests'=>'Solicitudes', 'quotes'=>'Presupuestos', 'orders'=>'Pedidos', 'production'=>'Producción' ); }
    public static function contains( $section ) { return 'jobs' === $section || isset( self::sections()[ $section ] ); }
    public static function allowed( $section ) {
        $module = 'requests' === $section ? 'quotes' : $section;
        return GE_WTP_Staff_Portal::can_access() && ( ! class_exists( 'GE_Organization_Runtime' ) || GE_Organization_Runtime::allowed( $module ) );
    }
    public static function accessible() {
        foreach ( self::sections() as $section=>$label ) { if ( self::allowed( $section ) ) { return true; } }
        return false;
    }
    public static function overview( $section ) {
        if ( 'jobs' === $section ) { return true; }
        if ( ! self::contains( $section ) ) { return false; }
        foreach ( array( 'request_id','quote_id','order_id','new','edit','view','filter','trash','s','q' ) as $key ) { if ( ! empty( $_GET[ $key ] ) ) { return false; } }
        return true;
    }
    public static function url( $args = array() ) { return GE_WTP_Staff_Portal::portal_url( 'jobs', $args ); }
    public static function assets() {
        wp_enqueue_style( 'ge-work-panel', GE_WTP_PLUGIN_URL.'assets/css/work-panel.css', array(), (string) filemtime( GE_WTP_PLUGIN_DIR.'assets/css/work-panel.css' ) );
    }
    public static function header( $section ) {
        self::assets();
        $active = 'jobs' === $section ? sanitize_key( $_GET['tipo'] ?? '' ) : $section;
        echo '<section class="ge-work-heading"><div><span class="ge-work-eyebrow">SOLICITUD → PRESUPUESTO → PEDIDO → ENTREGA</span><h1>Trabajos</h1><p>Todo el recorrido en un solo panel. Abrí un trabajo para continuar desde su etapa actual.</p></div><div class="ge-work-create">';
        if ( self::allowed( 'quotes' ) && ( ! class_exists('GE_Organization_Runtime') || GE_Organization_Runtime::allowed('quotes',true) ) ) { echo '<a class="ge-staff-button" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('quotes',array('new'=>1))).'">Nuevo presupuesto</a>'; }
        if ( self::allowed( 'orders' ) && self::allowed( 'production' ) && ( ! class_exists('GE_Organization_Runtime') || GE_Organization_Runtime::allowed('orders',true) ) ) { echo '<a class="ge-staff-button is-secondary" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('production',array('view'=>'new'))).'">Nuevo pedido</a>'; }
        echo '</div></section><nav class="ge-work-tabs" aria-label="Filtrar trabajos por etapa">';
        $tabs = array_merge( array(''=>'Todos'), self::sections() );
        foreach ( $tabs as $key=>$label ) {
            if ( $key && ! self::allowed( $key ) ) { continue; }
            $args = $key ? array('tipo'=>$key) : array();
            // Keep search and active/closed scope while changing only the stage.
            foreach ( array('buscar','estado') as $param ) { if ( !empty($_GET[$param]) ) { $args[$param]=sanitize_text_field(wp_unslash($_GET[$param])); } }
            echo '<a'.($active===$key?' aria-current="page"':'').' href="'.esc_url(self::url($args)).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';
        if ( 'production' === $active && self::allowed('production') ) {
            echo '<details class="ge-work-tools"><summary>Herramientas de producción</summary>';
            GE_WTP_Gestion_V3::domain_tabs(sanitize_key($_GET['view']??'queue'));
            echo '</details>';
        }
        if ( ! self::overview($section) ) { echo '<a class="ge-work-back" href="'.esc_url(self::url()).'">← Volver a todos los trabajos</a>'; }
    }
    private static function customer( $id, $fallback = '' ) {
        $user = get_userdata( $id ); return $user ? $user->display_name : ( $fallback ?: 'Cliente sin ficha vinculada' );
    }
    private static function item_title( $items ) {
        $names=array(); foreach((array)$items as $item) { $title=is_object($item)?$item->get_name():($item['title']??$item['name']??''); if($title)$names[]=$title; }
        return implode(' · ',array_slice($names,0,2)).(count($names)>2?' · +'.(count($names)-2).' productos':'');
    }
    private static function order_row( $order ) {
        $stage=GE_WTP_Order_Lifecycle::stage($order);
        $operational=array('production'=>'produccion','ready'=>'listo')[$order->get_meta('_ge_production_status',true)]??'';
        if($operational&&'entregado'!==$stage&&!in_array($order->get_status(),array('cancelled','refunded','failed'),true))$stage=$operational;
        $closed='entregado'===$stage || in_array($order->get_status(),array('cancelled','refunded','failed'),true);
        $kind=in_array($stage,array('produccion','listo','entregado'),true)?'production':'orders';
        $target=self::allowed('production')?'production':'orders';
        $next='Revisar el pedido y sus archivos'; $owner='Graph Express';
        if('entregado'===$stage){$next='Consultar entrega y documentos';$owner='Trabajo entregado';}
        elseif('listo'===$stage){$next='Coordinar la entrega';$owner='Graph Express y cliente';}
        elseif('produccion'===$stage){$next='Completar producción';}
        elseif(in_array($order->get_status(),array('cancelled','refunded','failed'),true)){$next='Revisar el historial del pedido';}
        else { $blocked=GE_WTP_Artwork_Library::blocked_item_names($order); if($blocked){$next='Revisar y aprobar el archivo exacto';$owner='Graph Express y cliente';}else{$next='Revisar condiciones y liberar producción';} }
        $date=$order->get_date_modified()?:$order->get_date_created();
        return array('key'=>'order:'.$order->get_id(),'kind'=>$kind,'order_id'=>$order->get_id(),'customer_id'=>(int)$order->get_customer_id(),'reference'=>'#'.$order->get_order_number(),'customer'=>self::customer($order->get_customer_id(),$order->get_formatted_billing_full_name()),'title'=>self::item_title($order->get_items()),'status'=>$closed?GE_WTP_Order_Lifecycle::label($order):GE_WTP_Order_Lifecycle::stages()[$stage],'next'=>$next,'owner'=>$owner,'closed'=>$closed,'updated'=>$date?$date->getTimestamp():0,'version'=>(int)$order->get_meta('_ge_commercial_quote_version',true),'quote_id'=>(int)$order->get_meta('_ge_commercial_quote_id',true),'request_id'=>0,'url'=>GE_WTP_Staff_Portal::portal_url($target,array('order_id'=>$order->get_id())));
    }
    public static function rows() {
        if(!self::accessible())return array();
        $rows=array();$actor=get_current_user_id();$represented_quotes=array();$represented_requests=array();
        if(self::allowed('orders')||self::allowed('production')){
            $ids=wc_get_orders(array('limit'=>-1,'return'=>'ids','orderby'=>'modified','order'=>'DESC'));
            foreach($ids as $id){
                $order=wc_get_order($id);if(!$order||'yes'===$order->get_meta('_ge_commercial_payment_order',true))continue;
                $legacy_quote=class_exists('GE_WTP_Customer_Quotes')&&GE_WTP_Customer_Quotes::is_quote_order($order);
                if($legacy_quote&&!self::allowed('quotes'))continue;
                $row=self::order_row($order);
                if($legacy_quote){$row['kind']='quotes';$row['status']=GE_WTP_Customer_Quotes::stage_label($order);$row['next']='Revisar el presupuesto y sus archivos';$row['url']=GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$id));}
                $rows[$row['key']]=$row;
            }
        }
        if(self::allowed('quotes')){
            foreach(get_posts(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'modified','order'=>'DESC')) as $id){
                $quote=GE_WTP_Commercial_Quotes::get($id,$actor);if(is_wp_error($quote))continue;
                $order_key='order:'.(int)$quote['converted_order_id'];
                $source=(int)get_post_meta($id,'_ge_quote_request_source',true);$request=$source?GE_WTP_Quote_Requests::get($source,$actor):null;
                $source=$request&&!is_wp_error($request)&&(int)$request['customer_id']===(int)$quote['customer_id']&&(int)$request['quote_id']===(int)$id?$source:0;
                if(isset($rows[$order_key])&&$rows[$order_key]['customer_id']===(int)$quote['customer_id']&&$rows[$order_key]['quote_id']===(int)$id){
                    $rows[$order_key]['reference']=$quote['number'];$rows[$order_key]['request_id']=$source;$represented_quotes[$id]=true;if($source)$represented_requests[$source]=true;continue;
                }
                $labels=array('draft'=>'En borrador','sent'=>'Esperando respuesta','viewed'=>'Esperando respuesta','accepted'=>'Aceptado','converted'=>'Pedido creado','rejected'=>'Rechazado','expired'=>'Vencido','cancelled'=>'Cancelado');
                $next='Revisar propuesta';$owner='Graph Express';
                if('draft'===$quote['status'])$next='Completar y enviar presupuesto';
                elseif(in_array($quote['status'],array('sent','viewed'),true)){$next='Esperar respuesta del cliente';$owner='Cliente';}
                elseif('accepted'===$quote['status'])$next='Crear pedido con la versión aceptada';
                elseif(in_array($quote['status'],array('rejected','expired','cancelled'),true))$next='Consultar historial de la propuesta';
                $rows['quote:'.$id]=array('key'=>'quote:'.$id,'kind'=>'quotes','order_id'=>0,'quote_id'=>(int)$id,'request_id'=>$source,'customer_id'=>(int)$quote['customer_id'],'reference'=>$quote['number'],'customer'=>self::customer($quote['customer_id']),'title'=>self::item_title($quote['snapshot']['items']??array()),'status'=>$labels[$quote['status']]??'En revisión','next'=>$next,'owner'=>$owner,'closed'=>in_array($quote['status'],array('rejected','expired','cancelled'),true),'updated'=>get_post_modified_time('U',true,$id),'version'=>(int)$quote['version'],'url'=>GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$id)));
                $represented_quotes[$id]=true;if($source)$represented_requests[$source]=true;
            }
            foreach(get_posts(array('post_type'=>GE_WTP_Quote_Requests::TYPE,'post_status'=>'private','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'modified','order'=>'DESC')) as $id){
                if(isset($represented_requests[$id]))continue;
                $r=GE_WTP_Quote_Requests::get($id,$actor);if(is_wp_error($r)||'draft'===$r['status'])continue;
                // A validated forward link is sufficient for older records without reverse provenance.
                $linked=!empty($r['quote_id'])?GE_WTP_Commercial_Quotes::get($r['quote_id'],$actor):null;
                if($linked&&!is_wp_error($linked)&&(int)$linked['customer_id']===(int)$r['customer_id']&&isset($represented_quotes[$linked['id']]))continue;
                $rows['request:'.$id]=array('key'=>'request:'.$id,'kind'=>'requests','order_id'=>0,'quote_id'=>0,'request_id'=>(int)$id,'customer_id'=>(int)$r['customer_id'],'reference'=>'Solicitud #'.$id,'customer'=>self::customer($r['customer_id']),'title'=>self::item_title($r['items']),'status'=>GE_WTP_Job_Flow::request_label($r['status']),'next'=>'info'===$r['status']?'Esperar información del cliente':('closed'===$r['status']?'Consultar solicitud cerrada':'Preparar presupuesto'),'owner'=>'info'===$r['status']?'Cliente':'Graph Express','closed'=>'closed'===$r['status'],'updated'=>strtotime($r['updated_at']??'')?:get_post_modified_time('U',true,$id),'version'=>0,'url'=>GE_WTP_Staff_Portal::portal_url('requests',array('request_id'=>$id)));
            }
        }
        uasort($rows,static function($a,$b){return $b['updated']<=>$a['updated'] ?: strcmp($a['key'],$b['key']);});
        return array_values($rows);
    }
    public static function filter( $rows, $type, $scope, $query ) {
        return array_values(array_filter($rows,static function($r)use($type,$scope,$query){
            if($type&&$r['kind']!==$type)return false;
            if('active'===$scope&&$r['closed']||'closed'===$scope&&!$r['closed'])return false;
            $haystack=$r['reference'].' '.$r['customer'].' '.$r['title'].' '.$r['status'].' '.$r['owner'].' '.$r['order_id'].' '.$r['quote_id'].' '.$r['request_id'];
            return !$query||false!==mb_stripos($haystack,$query);
        }));
    }
    public static function render( $section ) {
        $type='jobs'===$section?sanitize_key($_GET['tipo']??''):$section;
        if($type&&!isset(self::sections()[$type]))$type='';
        if($type&&!self::allowed($type)){echo '<p>Tu rol no tiene acceso a esta etapa.</p>';return;}
        $scope=sanitize_key($_GET['estado']??'active');if(!in_array($scope,array('active','closed','all'),true))$scope='active';
        $query=mb_substr(sanitize_text_field(wp_unslash($_GET['buscar']??'')),0,120);$rows=self::rows();$filtered=self::filter($rows,$type,$scope,$query);
        $page=max(1,absint($_GET['pagina']??1));$pages=max(1,(int)ceil(count($filtered)/25));$page=min($page,$pages);
        echo '<section class="ge-work-panel"><form class="ge-work-filters" action="'.esc_url(self::url()).'" method="get"><input type="hidden" name="section" value="jobs"><input type="hidden" name="tipo" value="'.esc_attr($type).'"><label>Buscar un trabajo<input type="search" name="buscar" value="'.esc_attr($query).'" placeholder="Cliente, número o producto" maxlength="120"></label><label>Mostrar<select name="estado">';
        foreach(array('active'=>'En curso','closed'=>'Cerrados / entregados','all'=>'Todos los estados') as $value=>$label){echo '<option value="'.esc_attr($value).'"'.selected($scope,$value,false).'>'.esc_html($label).'</option>';}
        echo '</select></label><button class="ge-staff-button" type="submit">Aplicar filtros</button><a class="ge-work-reset" href="'.esc_url(self::url($type?array('tipo'=>$type):array())).'">Limpiar</a></form>';
        echo '<div class="ge-work-results"><h2>'.esc_html($type?self::sections()[$type]:'Todos los trabajos').'</h2><span>'.esc_html(count($filtered)).' trabajo(s) · '.esc_html(array('active'=>'en curso','closed'=>'cerrados / entregados','all'=>'todos los estados')[$scope]).'</span></div>';
        if(!$filtered){echo '<div class="ge-work-empty"><h3>No hay trabajos con estos filtros</h3><p>Probá otro cliente o número, cambiá el estado o volvé a todos los trabajos.</p><a class="ge-staff-button is-secondary" href="'.esc_url(self::url(array('estado'=>'all'))).'">Ver todos los trabajos</a></div>';}
        else{
            echo '<div class="ge-work-table" role="table" aria-label="Trabajos y próxima acción"><div class="ge-work-table-head" role="row"><span role="columnheader">Trabajo / cliente</span><span role="columnheader">Etapa / estado</span><span role="columnheader">Próxima acción</span><span role="columnheader">Actualizado</span><span role="columnheader">Abrir</span></div>';
            foreach(array_slice($filtered,($page-1)*25,25) as $r){
                echo '<article class="ge-work-row" role="row"><div role="cell"><strong>'.esc_html($r['reference']).'</strong><span>'.esc_html($r['customer']).'</span><small>'.esc_html($r['title']).'</small>';if($r['version'])echo '<small>Versión '.esc_html($r['version']).($r['order_id']?' conservada':'').'</small>';echo '</div><div role="cell"><span class="ge-work-stage">'.esc_html(self::sections()[$r['kind']]).'</span><strong>'.esc_html($r['status']).'</strong></div><div role="cell"><span>'.esc_html($r['next']).'</span><small>'.esc_html($r['owner']).'</small></div><div role="cell"><time datetime="'.esc_attr(gmdate('c',$r['updated'])).'">'.esc_html(wp_date('d/m/Y',$r['updated'])).'</time></div><div role="cell"><a class="ge-staff-button is-secondary" aria-label="Abrir '.esc_attr($r['reference']).'" href="'.esc_url($r['url']).'">Abrir trabajo →</a></div></article>';
            }
            echo '</div>';
            if($pages>1){echo '<nav class="ge-work-pagination" aria-label="Páginas de trabajos">';foreach(array($page-1=>'← Anterior',$page+1=>'Siguiente →') as $p=>$label){if($p<1||$p>$pages)continue;echo '<a class="ge-staff-button is-secondary" href="'.esc_url(self::url(array('tipo'=>$type,'estado'=>$scope,'buscar'=>$query,'pagina'=>$p))).'">'.esc_html($label).'</a>';}echo '<span>Página '.esc_html($page).' de '.esc_html($pages).'</span></nav>';}
        }
        echo '<p class="ge-work-note">Cada trabajo aparece en su etapa actual. Al abrirlo podés consultar su solicitud, presupuesto, pedido y archivos vinculados.</p></section>';
    }
}

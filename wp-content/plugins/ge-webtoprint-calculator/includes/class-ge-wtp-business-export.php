<?php
defined( 'ABSPATH' ) || exit;

/** Read-only, explicitly projected business records. No raw metadata or files leave this service. */
final class GE_WTP_Business_Export {
    const SCHEMA = 1;
    // Existing organization action policy classifies organization + download as company/read.
    const ACTION = 'ge_organization_business_download';
    const BATCH = 100;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
        add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'denied' ) );
        add_action( 'template_redirect', array( __CLASS__, 'front_page' ), 1 );
        add_action( 'wp_footer', array( __CLASS__, 'shortcut' ) );
    }

    public static function domains() {
        return array( 'customers'=>'Clientes', 'crm'=>'CRM', 'requests'=>'Solicitudes', 'quotes'=>'Presupuestos y versiones', 'orders'=>'Pedidos y entregas', 'invoices'=>'Facturas y documentos', 'receipts'=>'Registros de cobro' );
    }

    public static function allowed() {
        if ( ! is_user_logged_in() || ! class_exists( 'GE_Organization_Runtime' ) || ! class_exists( 'GE_Organization' ) ) { return false; }
        $actor = get_current_user_id();
        $binding = get_option( GE_Organization_Runtime::BINDING );
        return $binding && $binding === GE_Organization_Runtime::binding()
            && GE_Organization::can( GE_Organization::PRIMARY, $actor, true );
    }

    public static function require_domain( $domain ) {
        $modules = array( 'customers'=>'customers', 'crm'=>'communications', 'requests'=>'orders', 'quotes'=>'quotes', 'orders'=>'orders', 'invoices'=>'finance', 'receipts'=>'finance' );
        if ( ! self::allowed() || ! isset( $modules[$domain] ) || ! GE_Organization_Runtime::allowed( $modules[$domain], false ) ) {
            throw new RuntimeException( 'Acceso no disponible para este conjunto de datos.', 403 );
        }
        if($domain==='crm' && (!class_exists('GE_CRM') || !GE_CRM::can(false)))throw new RuntimeException('Acceso CRM no disponible.',403);
    }

    public static function denied() { wp_die( 'Acceso denegado.', '', array( 'response'=>403 ) ); }

    private static function db_ok() {
        global $wpdb;
        if(!empty($wpdb->last_error))throw new RuntimeException('Falló la lectura de los registros.');
    }

    private static function post_meta( $id, $key, $single = true ) {
        $value=get_post_meta($id,$key,$single);self::db_ok();return $value;
    }

    private static function user_meta( $id, $key, $single = true ) {
        $value=get_user_meta($id,$key,$single);self::db_ok();return $value;
    }

    private static function order_meta( $order, $key, $single = true ) {
        $value=$order->get_meta($key,$single);self::db_ok();return $value;
    }

    public static function menu() {
        if ( self::allowed() ) { add_management_page( 'Exportar registros Graphex', 'Exportar registros Graphex', 'read', self::ACTION, array( __CLASS__, 'page' ) ); }
    }

    public static function shortcut() {
        if ( is_page( 'gestion' ) && self::allowed() ) {
            echo '<p class="ge-admin-panel"><a class="ge-staff-button" href="' . esc_url( add_query_arg( 'ge_business_export', '1', home_url( '/gestion/' ) ) ) . '">Exportar registros</a></p>';
        }
    }

    public static function front_page() {
        if ( ! is_page( 'gestion' ) || ! isset( $_GET['ge_business_export'] ) ) { return; }
        if ( ! self::allowed() ) { self::denied(); }
        nocache_headers(); header( 'Cache-Control: private, no-store' );
        get_header(); self::page(); get_footer(); exit;
    }

    public static function page() {
        if ( ! self::allowed() ) { self::denied(); }
        echo '<main class="wrap ge-admin-panel"><h1>Exportar registros</h1><p>Descarga privada de los registros actuales. No cambia estados ni envía avisos. Presupuestos, pedidos, facturas y cobros conservan sus relaciones; no deben sumarse como ventas independientes.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<p><label for="ge-export-domain">Registros</label><br><select id="ge-export-domain" name="domain"><option value="all">Todos los conjuntos habilitados</option>';
        foreach ( self::domains() as $key=>$label ) {
            if ( GE_Organization_Runtime::allowed( array( 'customers'=>'customers','crm'=>'communications','requests'=>'orders','quotes'=>'quotes','orders'=>'orders','invoices'=>'finance','receipts'=>'finance' )[$key], false ) ) {
                echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
            }
        }
        echo '</select></p><p><label for="ge-export-format">Formato</label><br><select id="ge-export-format" name="format"><option value="csv">CSV UTF-8 para Excel</option><option value="json">JSON con relaciones y versiones</option></select></p>';
        echo '<p><label for="ge-export-customer">ID de cliente (vacío: todos)</label><br><input type="number" min="1" id="ge-export-customer" name="customer_id"></p>';
        echo '<p><label for="ge-export-from">Desde</label><br><input type="date" id="ge-export-from" name="from"></p><p><label for="ge-export-to">Hasta</label><br><input type="date" id="ge-export-to" name="to"></p><p>Fechas inclusivas en UTC: creación del registro, emisión del documento o fecha del cobro según el conjunto. Las versiones acompañan al presupuesto seleccionado.</p>';
        echo '<p><label for="ge-export-status">Estado exacto (vacío: todos)</label><br><input id="ge-export-status" name="status" maxlength="40"></p><p>Para conservar todas las relaciones en una primera conciliación, descargá todos los conjuntos sin filtros. No incluye adjuntos, archivos de diseño, conversaciones ni notas privadas.</p><button type="submit" class="button button-primary ge-staff-button">Descargar registros</button></form></main>';
    }

    public static function filters( $raw ) {
        $f = array();
        foreach ( array( 'domain','format','customer_id','from','to','status' ) as $key ) {
            if ( isset( $raw[$key] ) && ! is_scalar( $raw[$key] ) ) { throw new InvalidArgumentException( 'Filtro inválido.' ); }
            $f[$key] = isset( $raw[$key] ) ? trim( (string) $raw[$key] ) : '';
        }
        if ( ! in_array( $f['domain'], array_merge( array('all'), array_keys(self::domains()) ), true ) || ! in_array( $f['format'], array('csv','json'), true ) ) { throw new InvalidArgumentException( 'Formato o conjunto inválido.' ); }
        if ( $f['customer_id'] !== '' && ! preg_match( '/^[1-9][0-9]{0,17}$/D', $f['customer_id'] ) ) { throw new InvalidArgumentException( 'Cliente inválido.' ); }
        foreach ( array('from','to') as $key ) {
            if ( $f[$key] === '' ) { continue; }
            if ( ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $f[$key], $m) || ! checkdate((int)$m[2],(int)$m[3],(int)$m[1]) ) { throw new InvalidArgumentException('Fecha inválida.'); }
        }
        if ( $f['from'] && $f['to'] && $f['from'] > $f['to'] ) { throw new InvalidArgumentException('Rango de fechas inválido.'); }
        if ( $f['status'] !== '' && ! preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $f['status']) ) { throw new InvalidArgumentException('Estado inválido.'); }
        return $f;
    }

    /** Project only named scalar fields; never recursively export unknown payloads. */
    public static function fields( $source, $keys ) {
        $out = array();
        foreach ( $keys as $key ) { if ( is_array($source) && array_key_exists($key,$source) && (is_scalar($source[$key]) || $source[$key] === null) ) { $out[$key]=$source[$key]; } }
        return $out;
    }

    public static function scope( $tag, $customer = 0 ) {
        if ( $tag && (string)$tag !== (string)GE_Organization::PRIMARY ) { return false; }
        if ( $customer ) {
            $u = get_userdata($customer);
            self::db_ok();
            if(!$u)return false;
            $is_customer=GE_WTP_Portal::is_customer_user($u);self::db_ok();if(!$is_customer)return false;
            $qa=GE_Organization::qa_only($customer);self::db_ok();if($qa)return false;
            $customer_tag=self::user_meta($customer,'_ge_organization_id',true);
            if ( $customer_tag && (string)$customer_tag !== (string)GE_Organization::PRIMARY ) { return false; }
        }
        return true;
    }

    public static function matches( $row, $f ) {
        if ( $f['customer_id'] !== '' && (string)($row['customer_id']??'') !== $f['customer_id'] ) { return false; }
        if ( $f['status'] !== '' && (string)($row['status']??'') !== $f['status'] ) { return false; }
        $date=substr((string)($row['date']??''),0,10);
        if ( ($f['from'] || $f['to']) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) ) { return false; }
        return !($f['from'] && $date<$f['from']) && !($f['to'] && $date>$f['to']);
    }

    private static function posts( $type ) {
        $page=1;
        do {
            $q=new WP_Query(array('post_type'=>$type,'post_status'=>array_keys(get_post_stati()),'posts_per_page'=>self::BATCH,'paged'=>$page++,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>false,'suppress_filters'=>false,'cache_results'=>false,'update_post_meta_cache'=>false,'update_post_term_cache'=>false));
            self::db_ok();
            foreach($q->posts as $post) { yield $post; }
        } while($page <= (int)$q->max_num_pages);
    }

    private static function orders() {
        $page=1;
        do {
            $batch=wc_get_orders(array('type'=>'shop_order','status'=>array_keys(wc_get_order_statuses()),'limit'=>self::BATCH,'page'=>$page++,'paginate'=>true,'orderby'=>'ID','order'=>'ASC'));
            self::db_ok();
            foreach($batch->orders as $order) { if(self::scope(self::order_meta($order,'_ge_organization_id',true),(int)$order->get_customer_id())) { yield $order; } }
        } while($page <= (int)$batch->max_num_pages);
    }

    public static function snapshot( $s ) {
        $out=self::fields($s,array('currency','subtotal_cents','net_cents','taxable_base_cents','discount_cents','tax_cents','total_cents','valid_until','delivery_date','created_at','accepted_at','deposit_percent','deposit_enabled','billing_profile_id','issuer_profile_id'));
        foreach(array('receiver'=>'customer_billing_profile','issuer'=>'issuer_fiscal_snapshot') as $key=>$source) { $out[$key]=self::fields($s[$source]??$s[$key]??array(),array('id','name','legal_name','company','cuit','vat_status')); }
        $out['items']=array();
        foreach((array)($s['items']??array()) as $item) { $out['items'][]=self::fields($item,array('id','line_uuid','product_id','variation_id','name','sku','quantity','unit_net_cents','unit_price_cents','net_cents','taxable_base_cents','subtotal_cents','tax_cents','total_cents','specification','selection_type','selection_group')); }
        return $out;
    }

    private static function fiscal_document( $order, $doc ) {
        if(empty($doc['arca_invoice_id']) || !class_exists('GE_WTP_ARCA_Emission'))return array();
        $journal=GE_WTP_ARCA_Emission::journal($order->get_id(),'production');
        self::db_ok();
        if(!$journal || (int)$journal['id']!==(int)$doc['arca_invoice_id'])return array('fiscal_metadata_missing'=>true);
        $payload=$journal['payload']??array();$detail=$payload['detail']??array();
        // Frozen emission payload only. Do not use current order amounts for an old fiscal document.
        return array('fiscal_journal_id'=>(int)$journal['id'],'fiscal_state'=>$journal['state'],'currency'=>($detail['MonId']??'')==='PES'?'ARS':($detail['MonId']??''),'amount'=>isset($detail['ImpTotal'])?(string)$detail['ImpTotal']:null,'net'=>isset($detail['ImpNeto'])?(string)$detail['ImpNeto']:null,'tax'=>isset($detail['ImpIVA'])?(string)$detail['ImpIVA']:null,'issuer'=>self::fields($payload['issuer']??array(),array('legal_name','name','cuit')),'receiver'=>self::fields($payload['receiver']??array(),array('legal_name','name','cuit')));
    }

    public static function records( $domain ) {
        self::require_domain($domain);
        if($domain==='customers') {
            $page=1;
            do {
                $users=get_users(array('number'=>self::BATCH,'paged'=>$page++,'orderby'=>'ID','order'=>'ASC','fields'=>array('ID','display_name','user_email','user_registered')));
                self::db_ok();
                foreach($users as $u) {
                    if(!self::scope(self::user_meta($u->ID,'_ge_organization_id',true),$u->ID))continue;
                    $row=array('entity'=>'customer','source_id'=>(int)$u->ID,'customer_id'=>(int)$u->ID,'name'=>$u->display_name,'email'=>$u->user_email,'date'=>$u->user_registered,'status'=>(string)self::user_meta($u->ID,'_ge_customer_account_type',true));
                    $row['billing']=array();foreach(array('billing_company','billing_first_name','billing_last_name','billing_phone','_ge_whatsapp') as $key)$row['billing'][$key]=self::user_meta($u->ID,$key,true);
                    $profiles=GE_WTP_Customer_Branches::profiles($u->ID,true);self::db_ok();
                    $row['billing_profiles']=array();foreach($profiles as $p)$row['billing_profiles'][]=self::fields($p,array('id','label','name','company','legal_name','cuit','vat_status','active','is_default'));
                    yield $row;
                }
            } while(count($users)===self::BATCH);
        } elseif($domain==='crm') {
            if(!class_exists('GE_CRM'))throw new RuntimeException('CRM no disponible.');
            global $wpdb;
            // Fixed native table, fixed column allowlist, organization predicate and bounded ID cursor.
            $cursor=0;
            do {
                $rows=$wpdb->get_results($wpdb->prepare('SELECT id,kind,title,status,customer_id,lead_id,opportunity_id,quote_id,order_id,stage,due_date,email,phone,cuit,revision,created_at,updated_at FROM '.GE_CRM::table().' WHERE organization_id=%s AND id>%d ORDER BY id ASC LIMIT %d',GE_Organization::PRIMARY,$cursor,self::BATCH),ARRAY_A);
                if($wpdb->last_error)throw new RuntimeException('CRM no disponible.');
                foreach($rows as $r) {
                    $cursor=(int)$r['id'];if(!self::scope('',(int)$r['customer_id']))continue;
                    yield array_merge($r,array('entity'=>'crm','source_id'=>$cursor,'date'=>$r['created_at']));
                }
            }while(count($rows)===self::BATCH);
        } elseif($domain==='quotes' || $domain==='requests') {
            $type=$domain==='quotes'?GE_WTP_Commercial_Quotes::POST_TYPE:'ge_quote_request';
            foreach(self::posts($type) as $p) {
                $request=$domain==='requests'?self::post_meta($p->ID,'_ge_quote_request',true):array();
                $customer=$domain==='quotes'?(int)self::post_meta($p->ID,GE_WTP_Commercial_Quotes::CUSTOMER_META,true):(int)($request['customer_id']??0);
                if(!self::scope(self::post_meta($p->ID,'_ge_organization_id',true),$customer))continue;
                $row=array('entity'=>$domain==='quotes'?'quote':'request','source_id'=>(int)$p->ID,'customer_id'=>$customer,'name'=>$p->post_title,'date'=>$p->post_date_gmt,'modified_at'=>$p->post_modified_gmt,'status'=>$p->post_status);
                if($domain==='requests') {
                    $row=array_merge($row,self::fields($request,array('status','quote_id','needed_by','urgency','updated_at')));
                    $row['items']=array();foreach((array)($request['items']??array()) as $item)$row['items'][]=self::fields($item,array('product_id','name','quantity','specification'));
                    yield $row; continue;
                }
                $row['status']=(string)self::post_meta($p->ID,GE_WTP_Commercial_Quotes::STATUS_META,true);
                $row['number']=class_exists('GE_WTP_Gestion_V3')?GE_WTP_Gestion_V3::lookup('quote_id',$p->ID):null;
                self::db_ok();
                $row['order_id']=(int)self::post_meta($p->ID,GE_WTP_Commercial_Quotes::ORDER_META,true);
                $row['version']=(int)self::post_meta($p->ID,GE_WTP_Commercial_Quotes::CURRENT_META,true);
                $versions=self::post_meta($p->ID,GE_WTP_Commercial_Quotes::VERSIONS_META,true);
                $current=is_array($versions)&&isset($versions[$row['version']])?$versions[$row['version']]:array();
                if(!self::scope($current['organization_snapshot']['organization_id']??$current['organization_snapshot']['id']??'',$customer))continue;
                $row['accepted_at']=self::post_meta($p->ID,'_ge_commercial_accepted_at',true);
                $row['current_version_missing']=!is_array($current)||!$current;
                $row['versions']=array();foreach((array)$versions as $version=>$snapshot) {
                    if(!is_array($snapshot))continue;
                    $org=$snapshot['organization_snapshot']['organization_id']??$snapshot['organization_snapshot']['id']??'';
                    if(!self::scope($org,$customer))continue;
                    $row['versions'][]=array('version'=>(int)$version,'current'=>(int)$version===$row['version'],'snapshot'=>self::snapshot($snapshot));
                    if((int)$version===$row['version']) { $row['currency']=$snapshot['currency']??'';$row['net_cents']=$snapshot['net_cents']??null;$row['tax_cents']=$snapshot['tax_cents']??null;$row['total_cents']=$snapshot['total_cents']??null; }
                }
                $selection=self::post_meta($p->ID,'_ge_commercial_selection',true);
                if(is_array($selection)&&isset($selection['snapshot'])&&self::scope($selection['snapshot']['organization_snapshot']['organization_id']??$selection['snapshot']['organization_snapshot']['id']??'',$customer)) {
                    $row['stored_selection']=array('version'=>(int)($selection['version']??0),'version_matches'=>(int)($selection['version']??0)===$row['version'],'proposal_hash_matches'=>isset($selection['proposal_hash'])&&hash_equals(hash('sha256',wp_json_encode($current)),(string)$selection['proposal_hash']),'snapshot'=>self::snapshot($selection['snapshot']));
                    if(in_array($row['status'],array('accepted','converted'),true)&&$row['stored_selection']['version_matches']&&$row['stored_selection']['proposal_hash_matches']) {
                        $row['selection_applied']=true;
                        foreach(array('currency','net_cents','tax_cents','total_cents') as $key)$row[$key]=$selection['snapshot'][$key]??null;
                    }
                }
                yield $row;
            }
        } elseif($domain==='orders' || $domain==='receipts') {
            foreach(self::orders() as $o) {
                $number=$o->get_order_number();self::db_ok();
                $base=array('entity'=>'order','source_id'=>$o->get_id(),'order_id'=>$o->get_id(),'number'=>$number,'customer_id'=>(int)$o->get_customer_id(),'date'=>$o->get_date_created()?$o->get_date_created()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):'','status'=>$o->get_status(),'currency'=>$o->get_currency());
                if($domain==='receipts') {
                    foreach((array)self::order_meta($o,'_ge_work_manual_payments',true) as $entry) {
                        if(!is_array($entry))continue;
                        yield array_merge($base,self::fields($entry,array('id','amount_cents','currency','received_date','method','actor','at')),array('entity'=>'receipt','source_id'=>$o->get_id().':'.($entry['id']??''),'date'=>$entry['received_date']??$entry['at']??'','date_basis'=>isset($entry['received_date'])?'received_date':'recorded_at','status'=>'recorded_manual','bank_reconciliation'=>'not_inferred'));
                    }
                    foreach((array)self::order_meta($o,'_ge_commercial_credited_attempts',true) as $index=>$entry) {
                        if(!is_array($entry))continue;
                        // These are ledger evidence, not additional receipts to sum with manual records.
                        yield array_merge($base,self::fields($entry,array('amount_cents')),array('entity'=>'credit_ledger_entry','source_id'=>$o->get_id().':credit:'.$index,'ledger_reference'=>strpos((string)($entry['key']??''),'manual:')===0?substr($entry['key'],7):null,'date_basis'=>'order_created_not_payment_date','status'=>'credited_record','not_additive_with_receipts'=>true));
                    }
                    continue;
                }
                $base['quote_id']=(int)self::order_meta($o,'_ge_commercial_quote_id',true);$base['quote_version']=(int)self::order_meta($o,'_ge_commercial_quote_version',true);
                $base['source_order_id']=(int)self::order_meta($o,'_ge_source_quote_order_id',true);$base['payment_attempt_order']=self::order_meta($o,'_ge_commercial_payment_order',true)==='yes';
                $base['payment_method']=$o->get_payment_method();$base['paid_at']=$o->get_date_paid()?$o->get_date_paid()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
                $base['tax']=$o->get_total_tax();$base['total']=$o->get_total();
                try { $total_cents=GE_WTP_Quote_Balance::cents(wc_format_decimal($base['total'],2));$tax_cents=GE_WTP_Quote_Balance::cents(wc_format_decimal($base['tax'],2));$base['net']=GE_WTP_Quote_Balance::decimal($total_cents-$tax_cents); }
                catch(InvalidArgumentException $e) { $base['net']=null;$base['amount_requires_review']=true; }
                foreach(array('production_status'=>'_ge_production_status','lifecycle_stage'=>'_ge_lifecycle_stage','delivered_at'=>'_ge_lifecycle_delivered_at','delivery_confirmed_at'=>'_ge_delivery_confirmed_at','payment_state'=>'_ge_payment_state','paid_cents'=>'_ge_amount_paid_cents','due_cents'=>'_ge_amount_due_cents','promised_date'=>'_ge_production_promised_date') as $key=>$meta)$base[$key]=self::order_meta($o,$meta,true);
                $items=$o->get_items();self::db_ok();
                $base['items']=array();foreach($items as $id=>$item)$base['items'][]=array('id'=>$id,'name'=>$item->get_name(),'product_id'=>$item->get_product_id(),'quantity'=>$item->get_quantity(),'net'=>$item->get_total(),'tax'=>$item->get_total_tax());
                $base['name']=implode(' / ',array_column($base['items'],'name'));
                yield $base;
            }
        } elseif($domain==='invoices') {
            foreach(self::posts(GE_WTP_Customer_Invoices::TYPE) as $p) {
                if(!self::scope(self::post_meta($p->ID,'_ge_organization_id',true)))continue;
                $r=self::post_meta($p->ID,GE_WTP_Customer_Invoices::META,true);
                if(!is_array($r)||!self::scope('',(int)($r['customer_id']??0)))continue;
                $row=self::fields($r,array('customer_id','profile_id','type','number','date','issuer_name','currency','amount','order_id','quote_id','quote_version','replaces','created_at','superseded'));
                $row['receiver']=self::fields($r['receiver']??array(),array('id','name','company','legal_name','cuit','vat_status'));
                $row['name']=$row['receiver']['legal_name']??$row['receiver']['company']??$row['receiver']['name']??'';
                yield array_merge($row,array('entity'=>'invoice','source_id'=>'invoice:'.$p->ID,'status'=>$p->post_status));
            }
            foreach(self::orders() as $o) {
                $documents=GE_WTP_Documents::get_documents($o->get_id());self::db_ok();
                foreach($documents as $doc) {
                    if(!is_array($doc)||!isset(GE_WTP_Customer_Invoices::types()[$doc['category']??''])||!empty($doc['invoice_ref']))continue;
                    $row=array_merge(self::fields($doc,array('category','document_number','issue_date','currency','amount','superseded_at')),self::fiscal_document($o,$doc),array('entity'=>'order_document','source_id'=>'order:'.$o->get_id().':'.($doc['id']??''),'customer_id'=>(int)$o->get_customer_id(),'order_id'=>$o->get_id(),'quote_id'=>(int)self::order_meta($o,'_ge_commercial_quote_id',true),'number'=>$doc['document_number']??'','date'=>$doc['issue_date']??$doc['uploaded_at']??'','status'=>empty($doc['superseded_at'])?'recorded':'superseded'));
                    $row['amount_missing']=!isset($row['amount']);yield $row;
                }
            }
        }
    }

    public static function csv_cell( $value ) {
        $s=(string)$value;
        return preg_match('/^[\x00-\x20\x7f\x{00a0}\x{feff}]*[=+@-]/u',$s) ? "'".$s : $s;
    }

    /** Keep versions/items in separate Excel rows instead of oversized nested JSON cells. */
    public static function csv_rows( $row ) {
        $parent=$row;unset($parent['versions'],$parent['items'],$parent['billing_profiles'],$parent['stored_selection']);yield $parent;
        $link=self::fields($row,array('customer_id','quote_id','order_id','currency','date'));
        $link['parent_source_id']=$row['source_id'];$link['not_additive_with_parent']=true;
        if($row['entity']==='quote')$link['quote_id']=$row['source_id'];
        foreach($row['versions']??array() as $v) {
            $snapshot=$v['snapshot'];$items=$snapshot['items']??array();unset($snapshot['items']);
            $version=array_merge($link,$snapshot,array('entity'=>'quote_version','source_id'=>$row['source_id'].':v'.$v['version'],'version'=>$v['version'],'status'=>$v['current']?'current':'historical'));
            yield $version;
            foreach($items as $index=>$item)yield array_merge($link,$item,array('entity'=>'quote_line','source_id'=>$version['source_id'].':line:'.$index,'version'=>$v['version']));
        }
        if(isset($row['stored_selection'])) {
            $s=$row['stored_selection'];$items=$s['snapshot']['items']??array();unset($s['snapshot']['items']);
            yield array_merge($link,$s['snapshot'],self::fields($s,array('version','version_matches','proposal_hash_matches')),array('entity'=>'stored_selection','source_id'=>$row['source_id'].':selection'));
            foreach($items as $index=>$item)yield array_merge($link,$item,array('entity'=>'selection_line','source_id'=>$row['source_id'].':selection:line:'.$index,'version'=>$s['version']));
        }
        foreach($row['items']??array() as $index=>$item)yield array_merge($link,$item,array('entity'=>$row['entity'].'_line','source_id'=>$row['source_id'].':line:'.$index));
        foreach($row['billing_profiles']??array() as $index=>$profile)yield array_merge($link,$profile,array('entity'=>'customer_profile','source_id'=>$row['source_id'].':profile:'.$index,'name'=>$profile['legal_name']??$profile['company']??$profile['name']??''));
    }

    public static function json( $value ) {
        $json=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false)throw new RuntimeException('No se pudo serializar la exportación.');
        return $json;
    }

    private static function write( $stream, $text ) {
        $offset=0;$length=strlen($text);
        while($offset<$length) { $written=fwrite($stream,substr($text,$offset));if($written===false||$written===0)throw new RuntimeException('No se pudo completar el archivo.');$offset+=$written; }
    }

    /** Fully stage the download first: database/encoding failures never produce a successful partial file. */
    public static function build( $f ) {
        if(!self::allowed())throw new RuntimeException('Acceso denegado.',403);
        $domains=$f['domain']==='all'?array_keys(self::domains()):array($f['domain']);$selected=array();$excluded=array();
        foreach($domains as $domain) { try { self::require_domain($domain);self::db_ok();$selected[]=$domain; } catch(RuntimeException $e) { self::db_ok();if($f['domain']!=='all'||$e->getCode()!==403)throw $e;$excluded[$domain]='module_or_role_not_available'; } }
        if(!$selected)throw new RuntimeException('No hay conjuntos habilitados.',403);
        $stream=fopen('php://temp/maxmemory:2097152','w+b');if(!$stream)throw new RuntimeException('No se pudo preparar la descarga.');
        $metadata=array('schema_version'=>self::SCHEMA,'organization_id'=>GE_Organization::PRIMARY,'generated_at'=>gmdate('c'),'timezone'=>'UTC','filters'=>$f,'excluded_domains'=>$excluded,'files_included'=>false,'bank_reconciliation_inferred'=>false,'consistency'=>'repeatable-read database transaction; native object caches remain subject to their normal invalidation','relations'=>'Source IDs retained; filters may leave references outside export. No quote/order/invoice/receipt totals should be added together.');
        $columns=array('entity','source_id','number','customer_id','quote_id','order_id','version','name','status','date','currency','total','amount','paid_cents','due_cents','net_cents','tax_cents','total_cents','amount_cents','net','tax','quantity','product_id','parent_source_id','details_json');$counts=array();$first=true;$csv_rows=0;
        global $wpdb;$transaction=false;
        try {
            if($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')===false || $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')===false)throw new RuntimeException('No se pudo iniciar la lectura consistente.');
            $transaction=true;
            if($f['format']==='json')self::write($stream,'{"manifest":'.self::json($metadata).',"records":[');
            else { self::write($stream,"\xEF\xBB\xBF");if(fputcsv($stream,$columns,';', '"', '')===false)throw new RuntimeException('No se pudo escribir el encabezado.'); }
            foreach($selected as $domain) {
                $counts[$domain]=array('scanned'=>0,'exported'=>0);
                foreach(self::records($domain) as $row) {
                    self::db_ok();
                    $counts[$domain]['scanned']++;if(!self::matches($row,$f))continue;$counts[$domain]['exported']++;
                    if($f['format']==='json') { self::write($stream,($first?'':',').self::json($row));$first=false; }
                    else {
                        foreach(self::csv_rows($row) as $csv_row) {
                            $values=array();foreach($columns as $key)$values[]=self::csv_cell($key==='details_json'?self::json($csv_row):($csv_row[$key]??''));if(fputcsv($stream,$values,';', '"', '')===false)throw new RuntimeException('No se pudo escribir la descarga.');$csv_rows++;
                        }
                    }
                    if($wpdb->last_error)throw new RuntimeException('Falló una consulta de exportación.');
                }
                self::db_ok();
            }
            self::db_ok();
            $summary=array('complete'=>true,'total_records'=>array_sum(array_column($counts,'exported')),'record_counts'=>$counts,'completed_at'=>gmdate('c'));
            if($f['format']==='csv')$summary['csv_data_rows']=$csv_rows;
            if($f['format']==='json')self::write($stream,'],"result":'.self::json($summary).'}');
            else { $summary_row=array_fill(0,count($columns),'');$summary_row[0]='export_manifest';$summary_row[count($columns)-1]=self::json(array_merge($metadata,$summary));if(fputcsv($stream,$summary_row,';', '"', '')===false)throw new RuntimeException('No se pudo escribir el resumen.'); }
            if($wpdb->query('ROLLBACK')===false)throw new RuntimeException('No se pudo cerrar la lectura.');$transaction=false;
            if(fflush($stream)===false)throw new RuntimeException('No se pudo completar la descarga.');
            rewind($stream);return $stream;
        } catch(Throwable $e) { if($transaction)$wpdb->query('ROLLBACK');fclose($stream);throw $e; }
    }

    public static function download() {
        if(!self::allowed()) { self::denied(); }
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('Método no permitido.','',array('response'=>405));
        check_admin_referer(self::ACTION);
        try {
            $f=self::filters(wp_unslash($_POST));$stream=self::build($f);$size=fstat($stream)['size'];
        } catch(Throwable $e) { wp_die('No se pudo generar la exportación. Revisá filtros, permisos y disponibilidad de los módulos. No se modificaron registros.','',array('response'=>$e->getCode()===403?403:400)); }
        if(headers_sent()) { fclose($stream);wp_die('No se pudo iniciar la descarga.','',array('response'=>500)); }
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: '.($f['format']==='json'?'application/json':'text/csv').'; charset=UTF-8');
        header('Content-Disposition: attachment; filename="graphex-registros-'.gmdate('Ymd-His').'.'.$f['format'].'"');header('Content-Length: '.$size);
        fpassthru($stream);fclose($stream);exit;
    }
}

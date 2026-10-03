<?php
defined('ABSPATH') || exit;
/** Structured operational export. Credentials/options/authentication metadata are never read. */
final class GE_Organization_Export {
    public static function clean($value) {
        if(!is_array($value))return $value;
        $out=array();foreach($value as $key=>$item) {
            if(is_string($key) && preg_match('/password|secret|token|private.?key|api.?key|credentials?_ref|cert_ref|nonce|authorization|cookie|signed.?url/i',$key))continue;
            $out[$key]=self::clean($item);
        }return $out;
    }
    public static function build($actor) {
        if(!GE_Organization::can(GE_Organization::PRIMARY,$actor,true))return new WP_Error('forbidden','Owner o administrador requerido.');
        global $wpdb;$o=GE_Organization::get(GE_Organization::PRIMARY);$domains=array();$s=$o['settings'];
        $domains['users_roles']=array();$domains['customers']=array();
        foreach(get_users() as $u) {
            if(GE_Organization::qa_only($u->ID))continue;
            $row=array('source_id'=>$u->ID,'login'=>$u->user_login,'name'=>$u->display_name,'email'=>$u->user_email,'organization_id'=>GE_Organization::PRIMARY);
            if(isset($o['members'][(string)$u->ID])) {$row['role']=$o['members'][(string)$u->ID];$domains['users_roles'][]=$row;}
            elseif(!empty($s['modules']['customers'])) {
                foreach(array('billing_first_name','billing_last_name','billing_company','billing_address_1','billing_city','billing_postcode','billing_country','billing_phone','_ge_customer_account_type') as $k)$row[$k]=get_user_meta($u->ID,$k,true);
                $domains['customers'][]=$row;
            }
        }
        if(!empty($s['modules']['cost_engine'])) {
            $domains['cost_sources']=array();
            foreach(get_posts(array('post_type'=>'ge_cost_source','post_status'=>'any','numberposts'=>-1)) as $p)$domains['cost_sources'][]=array('source_id'=>$p->ID,'organization_id'=>GE_Organization::PRIMARY,'rate'=>get_post_meta($p->ID,'_ge_cost_rate',true));
        }
        foreach(array('quotes'=>'ge_commercial_quote','communications'=>'ge_internal_alert','orders'=>'ge_quote_request','cost_engine'=>'product') as $module=>$type) {
            if(empty($s['modules'][$module]))continue;$domain=$type==='product'?'products':$type;$domains[$domain]=array();
            foreach(get_posts(array('post_type'=>$type,'post_status'=>'any','numberposts'=>-1)) as $p) {
                $row=array('source_id'=>$p->ID,'type'=>$type,'title'=>$p->post_title,'content'=>$p->post_content,'status'=>$p->post_status,'created_at'=>$p->post_date_gmt,'organization_id'=>GE_Organization::PRIMARY);
                if($type==='ge_commercial_quote') {
                    $row['snapshot']=GE_WTP_Commercial_Quotes::get($p->ID)['snapshot'];
                    $row['versions']=get_post_meta($p->ID,GE_WTP_Commercial_Quotes::VERSIONS_META,true);
                    $row['customer_id']=get_post_meta($p->ID,GE_WTP_Commercial_Quotes::CUSTOMER_META,true);
                }
                $domains[$domain][]=$row;
            }
        }
        if(!empty($s['modules']['orders'])) {
            $domains['orders']=array();$domains['documents']=array();$page=1;
            do {
                $batch=wc_get_orders(array('limit'=>100,'page'=>$page++,'paginate'=>true,'type'=>'shop_order'));
                foreach($batch->orders as $order) {
                    $row=$order->get_data();unset($row['meta_data'],$row['line_items'],$row['fee_lines'],$row['shipping_lines'],$row['coupon_lines'],$row['tax_lines'],$row['order_key'],$row['customer_ip_address'],$row['customer_user_agent']);
                    $row['organization_id']=GE_Organization::PRIMARY;$row['items']=array();
                    foreach($order->get_items() as $item){$item_row=$item->get_data();unset($item_row['meta_data']);$row['items'][]=$item_row;}
                    $row['organization_snapshot']=$order->get_meta('_ge_organization_snapshot');$row['commercial_snapshot']=$order->get_meta('_ge_commercial_quote_snapshot');$domains['orders'][]=$row;
                    foreach((array)$order->get_meta('_ge_markcom_documents') as $doc)if(is_array($doc))$domains['documents'][]=array_intersect_key(array_merge($doc,array('order_id'=>$order->get_id(),'organization_id'=>GE_Organization::PRIMARY)),array_flip(array('id','order_id','organization_id','name','type','mime','size','sha256','created_at')));
                }
            }while($page<=$batch->max_num_pages);
        }
        if(!empty($s['modules']['suppliers']))$domains['suppliers']=GE_WTP_Production::suppliers();
        // Only domain tables, never wp_options/wp_users, integration or authentication tables.
        foreach($wpdb->get_col('SHOW TABLES') as $table) {
            if(strpos($table,$wpdb->prefix.'ge_op_')===0)$domain=strpos($table,'finance')!==false||preg_match('/expense|payment|payable|cost|income/',$table)?'finance':'stock';
            elseif(strpos($table,$wpdb->prefix.'ge_cost_')===0)$domain='cost_engine';else continue;
            if(empty($s['modules'][$domain]) || !preg_match('/^[a-zA-Z0-9_]+$/D',$table))continue;
            $domains[$domain]['tables'][substr($table,strlen($wpdb->prefix))]=$wpdb->get_results('SELECT * FROM `'.$table.'`',ARRAY_A);
        }
        return self::clean(array('manifest'=>array('format'=>'graphex-organization-data','schema_version'=>1,'organization_id'=>GE_Organization::PRIMARY,'exported_at'=>gmdate('c'),'contains_secrets'=>false,'files_included'=>false,'import_mode'=>'controlled-migration-required'),'domains'=>$domains));
    }
}

<?php
defined('ABSPATH') || exit;

/** Row/range locks cover metadata writes that do not participate in advisory locks. */
final class GE_Work_History_Storage {
    private static $guard=false;
    private static $control=false;
    private static $connection=0;
    private static $started=false;
    public static function owned(){return self::$started && self::$guard;}
    /** Woo HPOS can turn metadata writes into a full order save when the clock advances. */
    public static function metadata_only($save){return self::owned()?false:$save;}
    public static function save_order_meta($order){
        if(!self::owned() || !$order instanceof WC_Order)throw new RuntimeException('Historical metadata requires an owned transaction.');
        $hook='woocommerce_orders_table_datastore_should_save_after_meta_change';$callback=array(__CLASS__,'metadata_only');add_filter($hook,$callback,PHP_INT_MAX);
        try{$order->save_meta_data();self::check();}finally{remove_filter($hook,$callback,PHP_INT_MAX);}
    }
    public static function check() {global $wpdb;if($wpdb->last_error)throw new RuntimeException('No se confirmó una lectura o escritura del pedido. No se aplicará la conciliación.');}
    public static function query_guard($sql) {
        self::check();
        if(!self::$control && preg_match('/^\s*(?:START\s+TRANSACTION|BEGIN\b|COMMIT\b|ROLLBACK\b|ALTER\b|CREATE\b|DROP\b|TRUNCATE\b|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+(?:SESSION\s+)?AUTOCOMMIT)/i',$sql))throw new RuntimeException('Una operación ajena intentó interrumpir la conciliación.');
        return $sql;
    }
    private static function sql($sql) {global $wpdb;self::$control=true;try{$r=$wpdb->query($sql);if($r===false)throw new RuntimeException('No se confirmó la transacción.');self::check();return $r;}finally{self::$control=false;}}
    private static function table($name) {if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Tabla no válida.');return '`'.$name.'`';}
    private static function rows($table,$column,$ids) {
        global $wpdb;$ids=array_values(array_filter(array_unique(array_map('absint',$ids))));sort($ids,SORT_NUMERIC);if(!$ids)return;
        $wpdb->get_col('SELECT '.self::table($column).' FROM '.self::table($table).' WHERE '.self::table($column).' IN ('.implode(',',$ids).') ORDER BY '.self::table($column).' FOR UPDATE');self::check();
    }
    public static function begin($root,$source_order_ids,$quote_ids) {
        global $wpdb;
        self::check();
        $autocommit=$wpdb->get_var('SELECT @@session.autocommit');self::check();
        if((string)$autocommit!=='1')throw new RuntimeException('Hay otra transacción activa; no se modificará ni confirmará.');
        if(self::$guard)throw new RuntimeException('Ya hay una conciliación activa.');
        add_filter('query',array(__CLASS__,'query_guard'),PHP_INT_MAX);self::$guard=true;
        try {
            $hpos=class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $tables=array($wpdb->posts,$wpdb->postmeta,$wpdb->options,$wpdb->prefix.'woocommerce_order_items',$wpdb->prefix.'woocommerce_order_itemmeta');
            if(class_exists('GE_WTP_Gestion_V3') && GE_WTP_Gestion_V3::numbers_enabled())$tables[]=GE_WTP_Gestion_V3::table();
            if($hpos)foreach(array('wc_orders','wc_orders_meta','wc_order_addresses','wc_order_operational_data') as $suffix)$tables[]=$wpdb->prefix.$suffix;
            $placeholders=implode(',',array_fill(0,count($tables),'%s'));
            $engines=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')',...$tables),ARRAY_A);self::check();
            if(count($engines)!==count($tables))throw new RuntimeException('No se verificaron todas las tablas de la conciliación.');
            foreach($engines as $table)if(strtoupper($table['ENGINE'])!=='INNODB')throw new RuntimeException('La base no garantiza una restauración transaccional de estas fichas.');
            // MySQL rejects SET TRANSACTION inside an existing active transaction without committing it.
            self::sql('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');self::sql('START TRANSACTION');self::$started=true;self::sql('SAVEPOINT ge_native_history_atomic');
            self::$connection=(int)$wpdb->get_var('SELECT CONNECTION_ID()');self::check();
            // Lock the source declaration and quote snapshots before reading either.
            $wpdb->get_col($wpdb->prepare('SELECT option_id FROM '.self::table($wpdb->options).' WHERE option_name=%s FOR UPDATE','ge_work_reconciliation_v1'));self::check();
            self::rows($wpdb->posts,'ID',$quote_ids);self::rows($wpdb->postmeta,'post_id',$quote_ids);
            $meta=$hpos?$wpdb->prefix.'wc_orders_meta':$wpdb->postmeta;$owner=$hpos?'order_id':'post_id';
            // The indexed key range also prevents a new child backlink appearing during the operation.
            $children=array();$routes=array_values(array_unique(array_filter(array_map('absint',array_merge(array($root),$source_order_ids)))));sort($routes,SORT_NUMERIC);foreach($routes as $route){$found=$wpdb->get_col($wpdb->prepare('SELECT '.self::table($owner).' FROM '.self::table($meta).' WHERE meta_key=%s AND meta_value=%s ORDER BY '.self::table($owner).' FOR UPDATE','_ge_source_quote_order_id',(string)$route));self::check();$children=array_merge($children,$found);}
            if(count($children)>100)throw new RuntimeException('Demasiados vínculos para conciliar automáticamente.');
            $ids=array_merge(array($root),$source_order_ids,$children);$ids=array_values(array_filter(array_unique(array_map('absint',$ids))));sort($ids,SORT_NUMERIC);
            // Include CPT placeholders/synchronized copies in HPOS installations.
            self::rows($wpdb->posts,'ID',$ids);self::rows($wpdb->postmeta,'post_id',$ids);
            if($hpos){self::rows($wpdb->prefix.'wc_orders','id',$ids);self::rows($meta,'order_id',$ids);self::rows($wpdb->prefix.'wc_order_addresses','order_id',$ids);self::rows($wpdb->prefix.'wc_order_operational_data','order_id',$ids);}
            $items_table=$wpdb->prefix.'woocommerce_order_items';
            $items=array();if($ids){$items=$wpdb->get_col('SELECT order_item_id FROM '.self::table($items_table).' WHERE order_id IN ('.implode(',',$ids).') ORDER BY order_id,order_item_id FOR UPDATE');self::check();}
            self::rows($wpdb->prefix.'woocommerce_order_itemmeta','order_item_id',$items);
            GE_Work_Finance::clear_item_cache($items);
            foreach($ids as $id)GE_Work_Finance::clear_order_cache($id);
            foreach($quote_ids as $id)clean_post_cache($id);
            wp_cache_delete('ge_work_reconciliation_v1','options');wp_cache_delete('alloptions','options');
            return $ids;
        }catch(Throwable $e){self::abort();throw $e;}
    }
    public static function verify() {
        global $wpdb;self::check();$connection=$wpdb->get_var('SELECT CONNECTION_ID()');self::check();
        if((int)$connection!==self::$connection)throw new RuntimeException('Cambió la conexión transaccional. Revisá la ficha antes de repetir.');
    }
    public static function commit(){self::verify();self::sql('RELEASE SAVEPOINT ge_native_history_atomic');self::sql('COMMIT');self::finish();}
    public static function abort(){global $wpdb;if(self::$guard){remove_filter('query',array(__CLASS__,'query_guard'),PHP_INT_MAX);self::$guard=false;if(self::$started)$wpdb->query('ROLLBACK');}self::$started=false;self::$connection=0;}
    private static function finish(){if(self::$guard)remove_filter('query',array(__CLASS__,'query_guard'),PHP_INT_MAX);self::$guard=false;self::$started=false;self::$connection=0;}
}

<?php
defined('ABSPATH')||exit;
/** Cost integration owns separate hooks/assets, leaving the quote UI independently deployable. */
final class GE_WTP_Cost_Quotes {
    private static $pending=null;
    public static function init(){add_action('wp_enqueue_scripts',array(__CLASS__,'enqueue'),30);add_action('admin_post_ge_commercial_quote_save',array(__CLASS__,'prepare'),1);add_action('added_post_meta',array(__CLASS__,'version'),10,4);add_action('updated_post_meta',array(__CLASS__,'version'),10,4);}
    public static function enqueue(){
        if(!GE_WTP_Cost_Engine::enabled()||!is_user_logged_in()||sanitize_key($_GET['section']??'')!=='quotes'||(!current_user_can('manage_options')&&!current_user_can('ge_view_costs')))return;
        $refs=array();$id=absint($_GET['quote_id']??0);if($id){$quote=GE_WTP_Commercial_Quotes::get($id,get_current_user_id());if(!is_wp_error($quote))foreach((array)get_post_meta($id,'_ge_cost_quote_version_'.$quote['version'],true) as $index=>$r)$refs[$index]=absint($r['snapshot_id']??0);}
        wp_enqueue_script('ge-cost-quotes',GE_WTP_PLUGIN_URL.'assets/js/cost-quotes.js',array(),GE_WTP_Cost_Engine::VERSION,true);wp_localize_script('ge-cost-quotes','geCostQuotes',array('calculatorUrl'=>GE_WTP_Cost_UI::url(),'refs'=>$refs));
    }
    public static function prepare(){
        if(!GE_WTP_Cost_Engine::enabled())return;
        $uses_cost=false;foreach((array)($_POST['lines']??array()) as $line)if(!empty($line['cost_snapshot_id']))$uses_cost=true;if(!$uses_cost)return;
        check_admin_referer('ge_commercial_quote_save');GE_WTP_Cost_Engine::permission();$lines=array();
        foreach((array)($_POST['lines']??array()) as $line){if(!trim($line['label']??''))continue;$lines[]=array('source_type'=>sanitize_key($line['source_type']??''),'quantity'=>sanitize_text_field(wp_unslash($line['quantity']??'')),'cost_snapshot_id'=>absint($line['cost_snapshot_id']??0));}
        $id=absint($_POST['quote_id']??0);try{self::$pending=array('quote_id'=>$id,'costs'=>GE_WTP_Cost_Engine::quote_costs($lines,$id));}catch(Throwable $e){wp_die(esc_html($e->getMessage()),'',array('response'=>400));}
    }
    public static function version($meta_id,$post_id,$key,$value){
        if(self::$pending===null||$key!==GE_WTP_Commercial_Quotes::CURRENT_META||get_post_type($post_id)!==GE_WTP_Commercial_Quotes::POST_TYPE)return;
        if(self::$pending['quote_id']&&self::$pending['quote_id']!==(int)$post_id)return;
        $private=self::$pending['costs'];self::$pending=null;
        GE_WTP_Cost_Engine::put($post_id,'_ge_cost_quote_version_'.absint($value),$private);GE_WTP_Cost_Engine::audit('quote_linked',$post_id,null,array('version'=>absint($value),'costs'=>$private));
    }
}

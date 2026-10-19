<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/class-ge-deco-mp-signature.php';

/** Signed intake persists a bounded private job before acknowledging delivery. */
final class GE_WTP_Deco_Marketplace_Webhooks {
    public static function init() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('ge_deco_mp_process_webhooks',array(__CLASS__,'worker'));
    }
    public static function routes() {
        if(!GE_WTP_Deco_Marketplace_Checkout::enabled())return;
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/marketplace-webhook',array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'receive')));
    }
    private static function directory() {
        $dir=GE_WTP_Deco_Gallery::root().'/.mp-webhooks';
        if(!is_dir($dir)&&!wp_mkdir_p($dir))throw new InvalidArgumentException('webhook_queue_unavailable');
        if(is_link($dir))throw new InvalidArgumentException('webhook_queue_unavailable');return $dir;
    }
    public static function receive($request) {
        try{
            if(!GE_WTP_Deco_Marketplace_Checkout::enabled())throw new InvalidArgumentException('webhook_unavailable');
            if(strlen($request->get_body())>65536)throw new InvalidArgumentException('invalid_webhook');
            $query=$request->get_query_params();$id=$query['data.id']??$query['data_id']??'';$purchase=$query['purchase']??'';
            if(!is_string($id)||!is_string($purchase)||!preg_match('/^[a-f0-9]{64}$/D',$purchase))throw new InvalidArgumentException('invalid_webhook');
            $delivery=GE_Deco_MP_Signature::verify($request->get_header('x-signature'),$request->get_header('x-request-id'),$id,(string)GE_DECO_MP_WEBHOOK_SECRET,time());
            $body=$request->get_json_params();
            if(!is_array($body)||($body['type']??'')!=='payment'||(string)($body['data']['id']??'')!==$id)throw new InvalidArgumentException('invalid_webhook');
            $order_id=(int)get_option('ge_deco_mp_purchase_'.hash('sha256',$purchase));if($order_id<1)throw new InvalidArgumentException('unknown_payment_reference');
            $dir=self::directory();$path=$dir.'/'.$delivery.'.json';
            if(!is_file($path)){
                $lock=fopen($dir.'/intake.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('webhook_queue_unavailable');
                try{if(count(glob($dir.'/*.json'))>=500)throw new InvalidArgumentException('webhook_queue_unavailable');
                    if(!is_file($path)){$temp=$path.'.'.bin2hex(random_bytes(6)).'.part';$job=array('order_id'=>$order_id,'payment_id'=>$id,'delivery'=>$delivery,'attempts'=>0,'queued_at'=>time());
                        if(file_put_contents($temp,wp_json_encode($job),LOCK_EX)===false)throw new InvalidArgumentException('webhook_queue_unavailable');chmod($temp,0600);
                        if(!rename($temp,$path))throw new InvalidArgumentException('webhook_queue_unavailable');}
                }finally{flock($lock,LOCK_UN);fclose($lock);}
            }
            if(!wp_next_scheduled('ge_deco_mp_process_webhooks'))wp_schedule_single_event(time()+1,'ge_deco_mp_process_webhooks');
            return new WP_REST_Response(array('received'=>true),200);
        }catch(InvalidArgumentException $e){$busy=$e->getMessage()==='webhook_queue_unavailable';return new WP_Error('webhook_rejected','Notificación no aceptada.',array('status'=>$busy?503:401));}
    }
    public static function worker() {
        if(!GE_WTP_Deco_Marketplace_Checkout::enabled())return 0;
        $dir=self::directory();$lock=fopen($dir.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return 0;$done=0;
        $pending=false;$attempted=0;
        try{foreach(glob($dir.'/*.json')as $path){
            if(is_link($path)||filesize($path)>4096)continue;$job=json_decode(file_get_contents($path),true);
            if(!is_array($job)||basename($path)!==($job['delivery']??'').'.json'||($job['attempts']??0)>=10)continue;
            $pending=true;if(($job['next_at']??0)>time())continue;if($attempted>=20)break;$attempted++;
            try{GE_WTP_Deco_Marketplace_Checkout::reconcile((int)$job['order_id'],(string)$job['payment_id']);unlink($path);$done++;}
            catch(Throwable $e){$job['attempts']++;$job['next_at']=time()+min(3600,30*(2**$job['attempts']));
                $job['error']=$e instanceof InvalidArgumentException?$e->getMessage():'payment_processing_unavailable';
                $temp=$path.'.retry';file_put_contents($temp,wp_json_encode($job),LOCK_EX);chmod($temp,0600);rename($temp,$path);
                $order=wc_get_order((int)$job['order_id']);if($order&&$order->get_meta('_ge_deco_marketplace_review',true)!==$job['error']){
                    $order->update_meta_data('_ge_deco_marketplace_review',$job['error']);$order->save();
                    $order->add_order_note('El pago del artista necesita conciliación interna. No liberar producción hasta verificar el reparto.');}
            }
        }
        if($pending&&glob($dir.'/*.json')&&!wp_next_scheduled('ge_deco_mp_process_webhooks'))wp_schedule_single_event(time()+60,'ge_deco_mp_process_webhooks');
        return $done;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

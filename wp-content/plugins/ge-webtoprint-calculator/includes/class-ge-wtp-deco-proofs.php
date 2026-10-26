<?php
defined('ABSPATH') || exit;

/** Preparation is permitted before payment; approval and production remain native. */
final class GE_WTP_Deco_Proofs {
    public static function init() {
        add_action('woocommerce_checkout_order_created',array(__CLASS__,'safe_queue'),40);
        add_action('woocommerce_store_api_checkout_order_processed',array(__CLASS__,'safe_queue'),40);
        add_filter('ge_wtp_item_artwork_ready',array(__CLASS__,'approval_ready'),20,3);
    }
    public static function approval_ready($ready,$item,$order) {
        $snapshot=$item->get_meta('_ge_deco_snapshot',true);if(!$snapshot||!$ready)return $ready;
        if($order&&$order->get_meta('_ge_deco_marketplace_sale_v1',true)&&$order->get_meta('_ge_deco_marketplace_settlement_verified',true)!=='yes')return false;
        $fingerprint=self::fingerprint($snapshot);
        if($item->get_meta('_ge_deco_proof_snapshot_sha256',true)!==$fingerprint)return false;
        $expected=$item->get_meta('_ge_deco_proof_pdf_sha256',true);if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected))return false;
        foreach(GE_WTP_Documents::get_documents($order->get_id()) as $document){
            if(($document['deco_snapshot_sha256']??'')!==$fingerprint||($document['deco_source_sha256']??'')!==($snapshot['production_sha256']??'')||(int)($document['order_item_id']??0)!==(int)$item->get_id())continue;
            $token='document:'.($document['id']??'');if(!in_array($token,(array)$item->get_meta('_ge_item_artwork_sources',true),true))continue;
            $analysis=GE_WTP_File_Analysis::from_ref($document['file_analysis_ref']??$document['analysis']['file_analysis_ref']??'');
            if(!$analysis||!in_array($analysis['status'],array('analyzed','warning'),true)||!empty($analysis['facts']['blockers'])||($analysis['facts']['page_count']??0)!==1)continue;
            $measured=$analysis['facts']['page_size_mm']??array();$wanted=$snapshot['composition']['print_mm'];
            if(count($measured)!==2||abs($measured[0]-$wanted[0])>.1||abs($measured[1]-$wanted[1])>.1)continue;
            $path=GE_WTP_VPS_Storage::download_path($document['relative_path']??'');if($path&&!is_link($path)&&hash_file('sha256',$path)===$expected)return true;
        }
        return false;
    }
    public static function production_projection($order) {
        if(PHP_SAPI!=='cli'||!$order instanceof WC_Order)throw new InvalidArgumentException('cli_order_required');
        $rows=array();$pages=0;
        foreach($order->get_items() as $item){
            $snapshot=$item->get_meta('_ge_deco_snapshot',true);if(!$snapshot)continue;
            if(!GE_WTP_Artwork_Library::item_ready_for_production($item,$order))throw new InvalidArgumentException('exact_approved_proof_required');
            $fingerprint=self::fingerprint($snapshot);$quantity=(int)$item->get_quantity();if($quantity<1||$quantity>200)throw new InvalidArgumentException('invalid_quantity');$pages+=$quantity;
            $found=false;
            foreach(GE_WTP_Documents::get_documents($order->get_id()) as $document){
                if(($document['deco_snapshot_sha256']??'')!==$fingerprint||(int)($document['order_item_id']??0)!==(int)$item->get_id())continue;
                $path=GE_WTP_VPS_Storage::download_path($document['relative_path']??'');$sha=$item->get_meta('_ge_deco_proof_pdf_sha256',true);
                if(!$path||hash_file('sha256',$path)!==$sha)continue;
                $rows[]=array('item_id'=>$item->get_id(),'approved_pdf'=>$path,'approved_pdf_sha256'=>$sha,'snapshot_sha256'=>$fingerprint,'source_sha256'=>$snapshot['production_sha256'],'quantity'=>$quantity,'print_mm'=>$snapshot['composition']['print_mm'],'paper_finish'=>$snapshot['composition']['paper_finish'],'route'=>$snapshot['route']);$found=true;break;
            }
            if(!$found)throw new InvalidArgumentException('exact_approved_proof_required');
        }
        if(!$rows||$pages>200)throw new InvalidArgumentException('invalid_batch');
        return array('order_id'=>$order->get_id(),'items'=>$rows,'design_approved'=>true,'production_release_authorized'=>false,'payment_guard'=>is_wp_error(GE_WTP_Job_Flow::payment_check($order))?'blocked':'eligible_for_native_review');
    }
    public static function fingerprint($snapshot) { return hash('sha256',wp_json_encode($snapshot)); }
    public static function safe_queue($order) {
        if(!$order instanceof WC_Order)return;
        try{self::queue($order);if($order->get_meta('_ge_deco_preparation_error',true)){$order->delete_meta_data('_ge_deco_preparation_error');$order->save();}}
        catch(Throwable $error){
            $code=$error instanceof InvalidArgumentException?$error->getMessage():'preparation_unavailable';
            // Preparation failures must never undo or replay a completed payment.
            if($order->get_meta('_ge_deco_preparation_error',true)!==$code){
                $order->update_meta_data('_ge_deco_preparation_error',$code);$order->save();
                $order->add_order_note('La preparación del cuadro requiere revisión interna antes de solicitar la aprobación del archivo.',false);
            }
        }
    }
    public static function queue($order) {
        if(!$order instanceof WC_Order)return;
        $identities=array();foreach($order->get_items() as $item){$s=$item->get_meta('_ge_deco_snapshot',true);if($s)$identities[$item->get_id()]=self::fingerprint($s);}
        if(!$identities)return;
        $target=GE_WTP_Deco_Gallery::root().'/.orders/order-'.$order->get_id().'/request.json';
        if(is_file($target)){
            $request=json_decode(file_get_contents($target),true);$previous=array();foreach($request['items']??array() as $row){$previous[$row['item_id']]=$row['snapshot_sha256'];}
            ksort($identities);ksort($previous);
            if(($request['order_id']??0)!==$order->get_id()||$previous!==$identities)throw new InvalidArgumentException('order_proof_revision_changed');
            return;
        }
        $rows=array();
        foreach($order->get_items() as $item){
            $snapshot=$item->get_meta('_ge_deco_snapshot',true);if(!$snapshot)continue;
            $ref=$snapshot['private_asset_ref']??'';
            if(!is_string($ref)||!preg_match('/^(nga-[0-9]+|obra-[1-8]|bauhaus-[0-9]{3}|tu-imagen-[a-f0-9]{32}|autor-[1-9][0-9]*-v[1-9][0-9]*)$/D',$ref))throw new InvalidArgumentException('unregistered_private_asset');
            GE_WTP_Deco_Prints::verify_asset($snapshot);
            $path=0===strpos($ref,'autor-')?GE_WTP_Deco_Artist_Commerce::source($ref,true)['path']:(0===strpos($ref,'tu-imagen-')?GE_WTP_Deco_Uploads::source($ref)['path']:GE_WTP_Deco_Gallery::root().'/'.$ref.'/print-master.jpg');
            $rows[]=array('item_id'=>$item->get_id(),'work_id'=>$snapshot['artwork_id'],'source_path'=>$path,'source_sha256'=>$snapshot['production_sha256'],'snapshot_sha256'=>self::fingerprint($snapshot),'print_mm'=>$snapshot['composition']['print_mm'],'fit'=>$snapshot['composition']['fit']);
        }
        if(!$rows)return;
        $folder=GE_WTP_Deco_Gallery::root().'/.orders/order-'.$order->get_id();wp_mkdir_p($folder);$target=$folder.'/request.json';$payload=wp_json_encode(array('order_id'=>$order->get_id(),'items'=>$rows),JSON_PRETTY_PRINT);
        if(is_file($target)){
            if(hash_file('sha256',$target)!==hash('sha256',$payload))throw new InvalidArgumentException('order_proof_revision_changed');
            return;
        }
        $handle=fopen($target,'x');if(!$handle)throw new InvalidArgumentException('proof_queue_unavailable');fwrite($handle,$payload);fclose($handle);chmod($target,0600);
    }
    public static function sync() {
        if(PHP_SAPI!=='cli')throw new InvalidArgumentException('cli_only');
        $root=GE_WTP_Deco_Gallery::root();$count=0;wp_mkdir_p($root.'/.orders');$lock=fopen($root.'/.orders/sync.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return 0;
        try{
        foreach(glob($root.'/.orders/order-*/result.json') as $result_path){
            $folder=dirname($result_path);$result=json_decode(file_get_contents($result_path),true);$request_path=$folder.'/request.json';
            if(($result['status']??'')!=='ready'||!is_file($request_path)||hash_file('sha256',$request_path)!==($result['request_sha256']??''))continue;
            $order=wc_get_order($result['order_id']??0);if(!$order||basename($folder)!=='order-'.$order->get_id())continue;
            foreach($result['items']??array() as $proof){
                $item=$order->get_item($proof['item_id']);if(!$item)continue;
                $snapshot=$item->get_meta('_ge_deco_snapshot',true);$fingerprint=self::fingerprint($snapshot);
                if($fingerprint!==$proof['snapshot_sha256']||($snapshot['production_sha256']??'')!==$proof['source_sha256'])continue;
                if($item->get_meta('_ge_deco_proof_snapshot_sha256',true)===$fingerprint)continue;
                if($proof['file']!=='item-'.$item->get_id().'.pdf')continue;
                $path=$folder.'/'.$proof['file'];if(!is_file($path)||is_link($path)||hash_file('sha256',$path)!==$proof['sha256'])continue;
                $base=defined('GE_WTP_PRIVATE_UPLOAD_DIR')?GE_WTP_PRIVATE_UPLOAD_DIR:getenv('GE_WTP_PRIVATE_UPLOAD_DIR');$base=realpath($base);$public=realpath(ABSPATH);
                if(!$base||$base===$public||0===strpos($base.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR))throw new InvalidArgumentException('private_storage_required');
                $relative='orders/'.$order->get_id().'/'.$item->get_id().'/deco-'.$fingerprint.'.pdf';$destination=$base.'/'.$relative;wp_mkdir_p(dirname($destination));
                if(is_file($destination)&&hash_file('sha256',$destination)!==$proof['sha256'])throw new InvalidArgumentException('immutable_proof_changed');
                if(!is_file($destination)&&!copy($path,$destination))throw new InvalidArgumentException('proof_copy_failed');chmod($destination,0640);
                $document_id='deco-'.$item->get_id().'-'.substr($fingerprint,0,16);$documents=GE_WTP_Documents::get_documents($order->get_id());
                $document=array('id'=>$document_id,'provider'=>'vps','relative_path'=>$relative,'name'=>'Graphex · Tu cuadro '.$item->get_id().'.pdf','size'=>filesize($destination),'mime'=>'application/pdf','category'=>'arte','order_item_id'=>$item->get_id(),'artwork_side'=>'general','uploaded_by'=>0,'uploaded_at'=>current_time('mysql'),'deco_snapshot_sha256'=>$fingerprint,'deco_source_sha256'=>$proof['source_sha256']);
                $document=GE_WTP_File_Analysis::record($document,GE_WTP_Documents::private_directory());
                $documents=array_values(array_filter($documents,static function($row)use($document_id){return ($row['id']??'')!==$document_id;}));$documents[]=$document;
                $order->update_meta_data(GE_WTP_Documents::META_KEY,$documents);$order->save();
                GE_WTP_Artwork_Library::attach_customer_uploads_to_item($order,$item,array($document));
                $item->update_meta_data('_ge_item_artwork_client_required','yes');$item->update_meta_data('_ge_deco_proof_snapshot_sha256',$fingerprint);$item->update_meta_data('_ge_deco_proof_pdf_sha256',$proof['sha256']);$item->save();$count++;
            }
        }
        return $count;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

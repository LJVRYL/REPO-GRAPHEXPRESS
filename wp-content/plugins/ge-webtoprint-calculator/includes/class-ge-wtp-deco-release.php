<?php
defined('ABSPATH') || exit;

/** Builds finite native product options only from an explicitly approved private release. */
final class GE_WTP_Deco_Release {
    public static function find_products($work_id,$published_only=false) {
        // Public catalog filters intentionally hide these products. This private lookup
        // resolves identity only; callers still enforce owner, release and composition.
        global $wpdb;
        $statuses=$published_only?array('publish'):array('publish','draft','private');
        $placeholders=implode(',',array_fill(0,count($statuses),'%s'));
        $sql="SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_type='product' AND m.meta_key='_ge_deco_work_id' AND m.meta_value=%s AND p.post_status IN ($placeholders) ORDER BY p.ID ASC LIMIT 2";
        return array_map('intval',$wpdb->get_col($wpdb->prepare($sql,array_merge(array($work_id),$statuses))));
    }
    public static function policy() {
        $policy=get_option('ge_deco_commercial_release',array());
        if(!is_array($policy)||($policy['approved']??false)!==true)throw new InvalidArgumentException('commercial_release_required');
        if(($policy['currency']??'')!=='ARS'||get_woocommerce_currency()!=='ARS')throw new InvalidArgumentException('currency_mismatch');
        foreach(array('revision','geometry_approval_ref','pricing_approval_ref','tax_approval_ref') as $key){if(empty($policy[$key])||!is_string($policy[$key]))throw new InvalidArgumentException('commercial_release_required');}
        if(empty($policy['frame_asset_use_ref'])&&empty($policy['frame_image_permission_ref']))throw new InvalidArgumentException('commercial_release_required');
        $now=time();foreach(GE_WTP_Deco_Observations::times($policy) as $observed){if(!is_int($observed)||$observed>$now+300||$observed<$now-GE_WTP_Deco_Prints::PRICE_MAX_AGE)throw new InvalidArgumentException('stale_price_snapshot');}
        $tax=$policy['tax_context']??array();if(!is_numeric($tax['multiplier']??null)||!is_finite((float)$tax['multiplier'])||$tax['multiplier']<=0||empty($tax['label']))throw new InvalidArgumentException('tax_context_required');
        if(empty($policy['options'])||!is_array($policy['options']))throw new InvalidArgumentException('commercial_release_required');
        return $policy;
    }
    public static function ensure_product($work_id) {
        if(!defined('GE_DECO_PRINTS_ENABLED')||GE_DECO_PRINTS_ENABLED!==true)throw new InvalidArgumentException('catalog_not_released');
        GE_WTP_Deco_Gallery::assert_available($work_id);
        $policy=self::policy();$owner=0;$artist_context=null;
        if(0===strpos($work_id,'autor-')){
            $source=GE_WTP_Deco_Artist_Commerce::source($work_id);$artist_context=GE_WTP_Deco_Artist_Commerce::context($source['row']);
            if((float)$policy['tax_context']['multiplier']!==1.0)throw new InvalidArgumentException('artist_tax_review_required');
            $pixels=$source['pixels'];$sha=$source['sha256'];$title=$source['row']['title'];$rights='creator-approved:'.$work_id;$analyzer='private-analysis:'.$sha;
        }elseif(0===strpos($work_id,'tu-imagen-')){
            $source=GE_WTP_Deco_Uploads::source($work_id);$owner=get_current_user_id();$pixels=$source['pixels'];$sha=$source['sha256'];$title='Tu cuadro personalizado';$rights='customer-library:'.$source['artwork_id'];$analyzer=$source['analysis']['analysis_id'];
        }else{
            $record=GE_WTP_Deco_Gallery::record($work_id,true);if(!$record)throw new InvalidArgumentException('analysis_required');if(GE_WTP_Deco_Gallery::is_licensed_pack_id($work_id)&&!GE_WTP_Deco_Gallery::licensed_sales($record))throw new InvalidArgumentException('artwork_sales_unavailable');$pixels=$record['work']['pixels'];$sha=$record['source_sha256'];$title=$record['work']['title'];$rights=(GE_WTP_Deco_Gallery::is_licensed_pack_id($work_id)?'licensed-record:':'museum-record:').$work_id;$analyzer='private-analysis:'.$sha;
        }
        $manifest=array('revision'=>hash('sha256',wp_json_encode($policy).':'.$sha),'rights_status'=>'approved','rights_evidence_ref'=>$rights,'artwork_id'=>$work_id,'production_sha256'=>$sha,'production_pixels'=>$pixels,'private_asset_ref'=>$work_id,'analyzer_ref'=>$analyzer,'frame_asset_use_ref'=>$policy['frame_asset_use_ref']??$policy['frame_image_permission_ref'],'freight_policy'=>$policy['freight_policy']??array(),'options'=>array(),'owner_id'=>$owner);
        if($artist_context){$manifest['artist_context']=$artist_context;$manifest['artist_id']=$artist_context['artist_id'];$manifest['revision']=hash('sha256',$manifest['revision'].wp_json_encode($artist_context));}
        $config=array('mode'=>'fixed','min_qty'=>1,'step'=>1,'tax_context'=>$policy['tax_context'],'options'=>array());
        $models=array();foreach(GE_WTP_Deco_Gallery::catalog()['models']??array() as $model){$models[$model['id']]=$model['name'];}
        foreach(GE_WTP_Deco_Gallery::options() as $option){
            $approved=$policy['options'][$option['id']]??null;if(!is_array($approved)||($approved['available']??false)!==true)continue;
            if(!GE_WTP_Deco_Observations::allowed($option['id'],$policy))continue;
            if(!is_int($approved['unit_base_cents']??null)||$approved['unit_base_cents']<=0||($approved['print_dimensions_verified']??false)!==true)throw new InvalidArgumentException('invalid_approved_option');
            $large=min($option['size'])>30||max($option['size'])>45;$papers=$large?array('catalogo-fotografico'):array('mate','brillo');
            foreach(array('portrait','landscape') as $orientation){
                $size=$orientation==='portrait'?$option['size']:array_reverse($option['size']);$mm=array_map(static function($v){return (int)round($v*10);},$size);$outer=$approved['outer_mm']??null;if($outer!==null&&$orientation==='landscape')$outer=array_reverse($outer);
                foreach(array('cover','contain') as $fit){
                    $quality=GE_Deco_Print_Quality::metrics($pixels,$mm,$fit);if($quality['effective_ppi']<200)continue;
                    foreach($papers as $paper){
                        $key=substr(hash('sha256',implode(':',array($option['id'],$orientation,$fit,$paper))),0,32);$price=($artist_context?$approved['unit_base_cents']:GE_WTP_Deco_Payments::regular_cents($approved['unit_base_cents'],$policy))/100;$artist_quote=null;
                        if($artist_context){$royalty=GE_Deco_Artist_Pricing::quote($approved['unit_base_cents'],$option['model'],$artist_context['pricing'],$artist_context['work_rule']);
                            $artist_quote=GE_Deco_Marketplace_Settlement::unit_quote($approved['unit_base_cents'],$royalty['artist_cents'],$artist_context['fee'],time());$price=$artist_quote['total_cents']/100;}
                        $entry=array('enabled'=>true,'geometry_verified'=>true,'cost_version_ref'=>$policy['revision'],'price_approval_ref'=>$policy['pricing_approval_ref'],'supplier_variant_ref'=>$approved['variant_ref']??'','catalog_option_id'=>$option['id'],'orientation'=>$orientation,'print_mm'=>$mm,'outer_mm'=>$outer,'fit'=>$fit,'paper_finish'=>$paper,'crop_anchor'=>array(.5,.5),'frame_observed_at'=>$policy['frame_observed_at'],'print_observed_at'=>$policy['print_observed_at'],'available'=>true,'valid_until'=>min($policy['frame_observed_at'],$policy['print_observed_at'])+GE_WTP_Deco_Prints::PRICE_MAX_AGE,'approved_base_price'=>$price);
                        if($artist_quote)$entry['artist_quote']=$artist_quote;
                        if(!$artist_context&&!empty($policy['checkout_pricing']))$entry['retail_pricing']=GE_WTP_Deco_Payments::RETAIL_RULE;
                        $label=implode(' × ',$size).' cm · '.($models[$option['model']]??$option['model']).' · '.$option['finish'].' · '.($orientation==='portrait'?'vertical':'horizontal').' · '.($fit==='cover'?'llenar':'con márgenes').' · '.$paper;
                        $manifest['options'][$key]=$entry;$config['options'][$key]=array('label'=>$label,'price'=>$price);
                    }
                }
            }
        }
        if(!$manifest['options'])throw new InvalidArgumentException('no_approved_formats');
        $lock=fopen(GE_WTP_Deco_Gallery::root().'/.product-'.hash('sha256',$work_id).'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new InvalidArgumentException('busy');
        try{
            $products=self::find_products($work_id);
            if(count($products)>1)throw new InvalidArgumentException('duplicate_artwork_product');
            $product=$products?wc_get_product($products[0]):new WC_Product_Simple();if(!$product)throw new InvalidArgumentException('invalid_product');
            if($products&&(int)get_post_meta($product->get_id(),'_ge_deco_owner_id',true)!==$owner)throw new InvalidArgumentException('artwork_owner_mismatch');
            $previous=$products?GE_WTP_Deco_Prints::manifest($product->get_id()):array();
            if(($previous['revision']??'')!==$manifest['revision']){
                $product->set_name($title);$product->set_status('publish');$product->set_catalog_visibility('hidden');$product->set_virtual(false);$product->set_tax_status('none');$product->set_regular_price((string)min(array_column($config['options'],'price')));$product->save();
                update_post_meta($product->get_id(),'_ge_deco_work_id',$work_id);update_post_meta($product->get_id(),'_ge_deco_owner_id',$owner);update_post_meta($product->get_id(),'_ge_deco_print',$manifest);update_post_meta($product->get_id(),'_ge_storefront_config',$config);
            }
            return $product->get_id();
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

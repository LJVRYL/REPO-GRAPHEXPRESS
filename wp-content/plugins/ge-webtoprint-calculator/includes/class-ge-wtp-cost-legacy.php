<?php
defined('ABSPATH')||exit;
/** Projection of existing Woo supplier cost metadata. Mappings never copy or overwrite cost values. */
final class GE_WTP_Cost_Legacy {
    const META='_ge_cost_source_mapping';
    public static function catalog(){
        GE_WTP_Cost_Engine::permission();$out=array();$ids=get_posts(array('post_type'=>'product','post_status'=>array('publish','draft','private','pending'),'numberposts'=>500,'fields'=>'ids','meta_key'=>'_ge_supplier_costs'));
        foreach($ids as $id){$costs=get_post_meta($id,'_ge_supplier_costs',true);$mappings=get_post_meta($id,self::META,true)?:array();$label=get_post_meta($id,'_ge_supplier_source',true);$date=get_post_meta($id,'_ge_supplier_source_date',true);$unit_label=get_post_meta($id,'_ge_supplier_cost_unit',true);$hash=GE_WTP_Cost_Engine::hash($costs);
            foreach((array)$costs as $key=>$value){if(!is_scalar($value)||!is_numeric($value)||(float)$value<0)continue;$mapping=$mappings[$key]??array();$reviewed=($mapping['source_hash']??'')===$hash;
                $out[]=array('ref'=>'woo-cost/'.$id.'/'.rawurlencode((string)$key),'description'=>get_the_title($id).' · '.$key,'unit'=>$mapping['unit']??'unknown','original_unit'=>$unit_label,'base_cost'=>(float)$value,'currency'=>$mapping['currency']??'UNKNOWN','tax_treatment'=>$mapping['tax_treatment']??'unknown','tax_percent'=>$mapping['tax_percent']??0,'min_qty'=>$mapping['min_qty']??0,'pricing_mode'=>$mapping['pricing_mode']??'unit','tiers'=>array(),'status'=>$reviewed?'active':'incomplete','confidence'=>$reviewed?'reviewed_mapping':'legacy_requires_review','source'=>'woo_supplier_catalog','supplier_id'=>$mapping['supplier_id']??'','supplier_label'=>$label,'effective_from'=>$date,'product_id'=>(int)$id,'cost_key'=>(string)$key,'provenance'=>array('meta_key'=>'_ge_supplier_costs','product_id'=>(int)$id,'cost_key'=>(string)$key,'source_hash'=>$hash,'source_date'=>$date,'mapping'=>$mapping));
            }
        }return $out;
    }
    public static function map($a){
        GE_WTP_Cost_Engine::permission(true);$id=absint($a['product_id']??0);$key=(string)($a['cost_key']??'');$p=wc_get_product($id);$costs=$p?$p->get_meta('_ge_supplier_costs',true):null;
        if(!$p||!is_array($costs)||!array_key_exists($key,$costs)||!is_numeric($costs[$key]))throw new RuntimeException('Costo existente no disponible.');
        $supplier=sanitize_key($a['supplier_id']??'');if($supplier&&!GE_WTP_Supplier_Workspace::supplier($supplier))throw new RuntimeException('Proveedor inexistente.');
        $list=array('id'=>0,'supplier_id'=>$supplier,'workspace'=>array('valid_from'=>$p->get_meta('_ge_supplier_source_date',true)));
        $row=GE_WTP_Cost_Engine::normalize(array(array_merge($a,array('supplier_sku'=>$key,'description'=>$p->get_name(),'base_cost'=>$costs[$key]))),$list)[0];
        if($row['tax_treatment']==='unknown'||$row['pricing_mode']==='formula')throw new RuntimeException('Completá impuestos y modelo de costo.');
        return GE_WTP_Cost_Engine::lock('legacy-cost:'.$id,function()use($id,$key,$row,$costs,$supplier){return GE_WTP_Operations::transaction(function()use($id,$key,$row,$costs,$supplier){$before=get_post_meta($id,self::META,true)?:array();$next=$before;$next[$key]=array_intersect_key($row,array_flip(array('unit','currency','tax_treatment','tax_percent','min_qty','pricing_mode')));$next[$key]+=array('supplier_id'=>$supplier,'source_hash'=>GE_WTP_Cost_Engine::hash($costs),'reviewed_at'=>gmdate('c'),'reviewed_by'=>get_current_user_id());GE_WTP_Cost_Engine::put($id,self::META,$next);GE_WTP_Cost_Engine::invalidate();GE_WTP_Cost_Engine::audit('legacy_mapping',$id,$before,$next);return array('product_id'=>$id,'cost_key'=>$key,'cost_value_unchanged'=>true,'price_unchanged'=>true);});});
    }
}

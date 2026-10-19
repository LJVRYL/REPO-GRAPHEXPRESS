<?php
/** Integer-cent pricing for the artist's work; physical production is a separate floor. */
final class GE_Deco_Artist_Pricing {
    public static function rule($input,$inherit=false,$unset=false) {
        if(!is_array($input))throw new InvalidArgumentException('invalid_artist_price');
        $mode=$input['mode']??'';$value=$input['value']??null;
        if(($mode==='inherit'&&$inherit)||($mode==='unset'&&$unset))return array('mode'=>$mode);
        if(!in_array($mode,array('fixed','percent'),true)||!is_int($value)||$value<0||$value>($mode==='fixed'?100000000:100000))throw new InvalidArgumentException('invalid_artist_price');
        return array('mode'=>$mode,'value'=>$value);
    }
    public static function profile($input,$models) {
        if(!is_array($input)||!is_array($input['models']??null)||count($input['models'])>count($models))throw new InvalidArgumentException('invalid_artist_price');
        $result=array('default'=>self::rule($input['default']??null,false,true),'models'=>array());
        foreach($input['models'] as $id=>$rule){if(!in_array($id,$models,true))throw new InvalidArgumentException('unknown_frame_model');$result['models'][$id]=self::rule($rule);}
        return $result;
    }
    public static function quote($base_cents,$model,$profile,$work_rule) {
        if(!is_int($base_cents)||$base_cents<=0||$base_cents>100000000)throw new InvalidArgumentException('invalid_graphex_base');
        $work=self::rule($work_rule,true);$rule=$work['mode']==='inherit'?($profile['models'][$model]??$profile['default']??array('mode'=>'unset')):$work;
        $rule=self::rule($rule,false,true);if($rule['mode']==='unset')throw new InvalidArgumentException('artist_price_required');
        $royalty=$rule['mode']==='fixed'?$rule['value']:intdiv($base_cents*$rule['value']+5000,10000);
        return array('base_cents'=>$base_cents,'artist_cents'=>$royalty,'subtotal_cents'=>$base_cents+$royalty,
            'rule'=>$rule,'percentage_basis'=>'graphex_frame_and_print_base_excluding_order_freight',
            'payment_processing_included'=>false,'sales_enabled'=>false);
    }
}

<?php
defined('ABSPATH') || exit;

/** Pure, bounded cost arithmetic. No eval, prices or implicit FX. */
final class GE_WTP_Cost_Math {
    public static function number($v, $name, $min=0, $max=1000000000) {
        if (!is_scalar($v) || !preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',(string)$v) || !is_finite((float)$v) || (float)$v<$min || (float)$v>$max) throw new InvalidArgumentException('Valor inválido: '.$name);
        return (float)$v;
    }
    public static function price($cost, $rule) {
        $value=self::number($rule['value']??0,'margen',0,1000); $mode=$rule['mode']??'markup';
        if (!in_array($mode,array('markup','margin'),true) || ($mode==='margin' && $value>=100)) throw new InvalidArgumentException('Margen inválido.');
        $net=$mode==='margin'?$cost/(1-$value/100):$cost*(1+$value/100);
        $net=max($net,self::number($rule['minimum']??0,'mínimo'))+self::number($rule['extras']??0,'extras');
        $round=self::number($rule['round_to']??0,'redondeo',0,1000000);
        if($round) $net=ceil(($net-0.0000001)/$round)*$round;
        $net=round($net,2); $tax=self::number($rule['tax_percent']??0,'impuesto',0,100);
        return array('suggested_net'=>$net,'tax'=>round($net*$tax/100,2),'final'=>round($net*(1+$tax/100),2),'markup_percent'=>$cost>0?round(($net/$cost-1)*100,4):null,'margin_percent'=>$net>0?round((1-$cost/$net)*100,4):null,'rule'=>$rule);
    }
    public static function quantity($line,$input) {
        $qty=self::number($input['quantity']??1,'cantidad',0.000001,1000000);
        $basis=$line['basis']??'output'; $factor=self::number($line['factor']??1,'factor',0.000001,1000000);
        $base=$qty;
        if($basis==='job') $base=1;
        elseif($basis==='area') $base=$qty*self::number($input['width_m']??0,'ancho',0.000001,1000)*self::number($input['length_m']??0,'largo',0.000001,1000);
        elseif($basis==='meter') $base=$qty*self::number($input['length_m']??0,'largo',0.000001,1000);
        elseif($basis==='sheet') $base=ceil($qty/self::number($line['outputs_per_sheet']??1,'rendimiento',0.000001,1000000));
        elseif($basis==='click') $base=$qty*self::number($input['faces']??1,'caras',1,2);
        elseif($basis==='hours') $base=self::number($input['hours']??0,'horas',0.000001,10000);
        elseif($basis!=='output') throw new InvalidArgumentException('Base de fórmula desconocida.');
        $base*=$factor;
        if($base>1000000000) throw new InvalidArgumentException('Cantidad calculada fuera de rango.');
        return $base;
    }
    public static function line($source,$quantity,$input) {
        if(($source['status']??'')!=='active' || !isset($source['base_cost'])) throw new InvalidArgumentException('Costo sin revisar o no disponible.');
        if(($source['currency']??'')!==($input['currency']??'ARS')) throw new InvalidArgumentException('Moneda distinta; cargá una conversión explícita como costo separado.');
        if($quantity<self::number($source['min_qty']??0,'mínimo')) throw new InvalidArgumentException('Cantidad inferior a la escala mínima.');
        $cost=self::number($source['base_cost'],'costo'); $mode=$source['pricing_mode']??'unit';
        foreach((array)($source['tiers']??array()) as $tier) if($quantity>=(float)$tier['min_qty']) { $cost=self::number($tier['cost'],'costo de escala'); $mode=$tier['mode']??$mode; }
        if(!in_array($mode,array('unit','lot'),true)) throw new InvalidArgumentException('Fórmula del proveedor requiere revisión manual.');
        $tax=$source['tax_treatment']??'unknown';
        if(!in_array($tax,array('excluded','included_recoverable','included_nonrecoverable','exempt'),true)) throw new InvalidArgumentException('Falta tratamiento impositivo del costo.');
        if($tax==='included_recoverable') $cost/=1+self::number($source['tax_percent']??null,'IVA de origen',0,100)/100;
        return round($cost*($mode==='lot'?1:$quantity),4);
    }
    public static function compare($old,$new) {
        $new_counts=array();foreach($new as $r){$k=$r['supplier_sku']??'';$new_counts[$k]=($new_counts[$k]??0)+1;}$index=array(); foreach($old as $row) $index[$row['supplier_sku']][]=$row;
        $seen=array(); $out=array(); $counts=array_fill_keys(array('unchanged','increased','decreased','new','removed','ambiguous','changed'),0);
        foreach($new as $row) {
            $key=$row['supplier_sku']; $seen[$key]=true; $prior=$index[$key]??array();
            if(!$key || count($prior)>1 || ($new_counts[$key]??0)>1) $status='ambiguous';
            elseif(!$prior) $status='new';
            elseif(($prior[0]['currency']??'')!==$row['currency'] || ($prior[0]['unit']??'')!==$row['unit'] || ($prior[0]['tax_treatment']??'')!==$row['tax_treatment'] || ($prior[0]['pricing_mode']??'unit')!==($row['pricing_mode']??'unit') || ($prior[0]['tiers']??array())!==($row['tiers']??array()) || ($prior[0]['attributes']??array())!==($row['attributes']??array()) || ($prior[0]['description']??'')!==$row['description'] || ($prior[0]['tax_percent']??0)!==($row['tax_percent']??0) || ($prior[0]['min_qty']??0)!==($row['min_qty']??0) || ($prior[0]['formula_note']??'')!==($row['formula_note']??'')) $status='changed';
            else $status=(float)$row['base_cost']===(float)$prior[0]['base_cost']?'unchanged':((float)$row['base_cost']>(float)$prior[0]['base_cost']?'increased':'decreased');
            $counts[$status]++; $out[]=array('status'=>$status,'sku'=>$key,'before'=>$prior[0]??null,'after'=>$row,'percent'=>count($prior)===1&&(float)$prior[0]['base_cost']>0&&$prior[0]['currency']===$row['currency']?round(((float)$row['base_cost']/(float)$prior[0]['base_cost']-1)*100,2):null);
        }
        foreach($old as $row) if(!isset($seen[$row['supplier_sku']])) { $counts['removed']++; $out[]=array('status'=>'removed','sku'=>$row['supplier_sku'],'before'=>$row,'after'=>null,'percent'=>null); }
        return array('counts'=>$counts,'items'=>$out);
    }
}

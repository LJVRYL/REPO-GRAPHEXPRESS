<?php
define('ABSPATH',__DIR__);
class WP_Error {private $code,$message;function __construct($c,$m){$this->code=$c;$this->message=$m;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_title($v){$v=strtolower(iconv('UTF-8','ASCII//TRANSLIT',$v));return trim(preg_replace('/[^a-z0-9]+/','-',$v),'-');}
function wp_json_encode($v){return json_encode($v);}
function wp_parse_url($v){return parse_url($v);}
function esc_url_raw($v){return $v;}
require __DIR__.'/../wp-content/mu-plugins/ge-logistics/core.php';
$n=0;function ck($v,$label){global $n;if(!$v)throw new Exception($label);$n++;}
$a=GE_Logistics::address(['street'=>'Calle QA 123','city'=>'Ciudad QA','province'=>'CABA','postal_code'=>'C1234ABC','recipient'=>'Receptor QA','phone'=>'+54 11 5555 0101']);
ck(!is_wp_error(GE_Logistics::address_check($a)),'Valid AR address');
foreach(['CABA','Capital Federal','Ciudad Autónoma de Buenos Aires','C'] as $p)ck(GE_Logistics::province($p)==='C','CABA alias');
foreach(GE_Logistics::provinces() as $code=>$name)ck(GE_Logistics::province($name)===$code,'Province canonical');
foreach(['street','city','province','phone','recipient'] as $key){$b=$a;$b[$key]='';ck(is_wp_error(GE_Logistics::address_check($b)),'Missing '.$key);}
$b=$a;$b['country']='CL';ck(is_wp_error(GE_Logistics::address_check($b)),'International unavailable');
foreach(['123','abcd','12345','C1234AB',''] as $cp){$b=$a;$b['postal_code']=$cp;ck(is_wp_error(GE_Logistics::address_check($b)),'Invalid CP');}
$b=$a;$b['postal_code']='1234';ck(!is_wp_error(GE_Logistics::address_check($b)),'Numeric CP');
$b=GE_Logistics::address(['street'=>'<script>unsafe</script> Calle QA','province'=>['bad']]);ck($b['province']===''&&strpos($b['street'],'<')===false,'Malformed input sanitized');
$p=GE_Logistics::packages([['count'=>2,'weight_kg'=>1,'length_cm'=>30,'width_cm'=>20,'height_cm'=>10]]);ck(!is_wp_error($p),'Multiple packages');
$w=GE_Logistics::weight($p);ck($w['count']===2&&$w['real_kg']===2.0&&$w['chargeable_kg']===null,'Unknown aforo stays unknown');
$w=GE_Logistics::weight($p,5000,.5,1);ck($w['chargeable_kg']===3.0,'Per package max actual/volume then rounding');
foreach(['count','weight_kg','length_cm','width_cm','height_cm'] as $key){$bad=$p;$bad[0][$key]=0;ck(is_wp_error(GE_Logistics::packages($bad)),'Zero package field');}
$bad=$p;$bad[0]['count']=1.5;ck(is_wp_error(GE_Logistics::packages($bad)),'Fractional count');
$bad=$p;$bad[0]['count']=101;ck(is_wp_error(GE_Logistics::packages($bad)),'Too many packages');
$bad=$p;$bad[0]['weight_kg']=INF;ck(is_wp_error(GE_Logistics::packages($bad)),'Infinite weight');
$bad=$p;$bad[0]['weight_kg']=26;ck(is_wp_error(GE_Logistics::carrier_check('correo',$bad)),'Correo conservative weight gate');
$bad=$p;$bad[0]['length_cm']=151;ck(is_wp_error(GE_Logistics::carrier_check('correo',$bad)),'Correo side limit');
$bad=$p;$bad[0]['length_cm']=140;$bad[0]['width_cm']=80;$bad[0]['height_cm']=40;ck(is_wp_error(GE_Logistics::carrier_check('correo',$bad)),'Correo sum limit');
$bad=$p;$bad[0]['weight_kg']=101;ck(is_wp_error(GE_Logistics::carrier_check('via_cargo',$bad)),'Via Cargo >100kg manual exception');
ck(!is_wp_error(GE_Logistics::carrier_check('pickup',[])),'Pickup no parcel required');
ck(is_wp_error(GE_Logistics::carrier_check('andreani',[])),'Carrier no empty parcels');
ck(GE_Logistics::cents('0')===0&&GE_Logistics::cents('123.45')===12345,'Exact money');
foreach(['','-1','1,00','1e3','1.234',[]] as $v)ck(GE_Logistics::cents($v)===null,'Malformed money');
$spec=['quantity'=>1500,'width_cm'=>21,'height_cm'=>29.7,'gsm'=>150,'sheets_per_unit'=>1,'thickness_mm'=>.15,'packaging_kg'=>.2,'extra_kg'=>0,'max_units_per_box'=>1000,'padding_cm'=>1,'basis'=>'Synthetic QA spec'];
$e=GE_Logistics::estimate($spec);ck(!is_wp_error($e)&&count($e['packages'])===2,'Partial final box');ck($e['packages'][0]['weight_kg']===9.556&&$e['packages'][1]['weight_kg']===4.878,'Paper mass with packaging');
$spec['quantity']=1501;ck(count(GE_Logistics::estimate($spec)['packages'])===2,'Quantity change physical estimate');
unset($spec['thickness_mm']);ck(is_wp_error(GE_Logistics::estimate($spec)),'No fabricated thickness');
ck(is_wp_error(GE_Logistics::product_contract(['unit_net_weight_kg'=>null],1000)),'Catalog null means measurement needed');
$physical=['validated'=>true,'measurement_date'=>'2026-10-10','responsible'=>'QA synthetic','evidence_ref'=>'QA sample','packaging_profile_id'=>'qa','finished_size_mm'=>['width'=>210,'height'=>297],'unfolded_net_area_m2'=>.12474,'grammage_g_m2'=>150,'folded_thickness_mm'=>.3,'unit_net_weight_kg'=>.019,'units_per_package'=>1000,'packaging_tare_kg'=>.2,'padding_cm'=>1];
$spec=GE_Logistics::product_contract($physical,1500);ck(!is_wp_error($spec),'Measured product contract adapter');$e=GE_Logistics::estimate($spec);ck($e['packages'][0]['weight_kg']===19.2&&$e['packages'][1]['weight_kg']===9.7,'Measured unit mass with remainder and packaging');
$physical['finished_size_mm']['width']='invalid';ck(is_wp_error(GE_Logistics::product_contract($physical,1)),'Malformed contract dimension rejected');
$f=GE_Logistics::fingerprint($a,$p,[1000],['origin'=>'QA']);$o=['id'=>'qa','coverage_verified'=>true,'fingerprint'=>$f,'price_cents'=>0,'expires_at'=>200];ck(!is_wp_error(GE_Logistics::offer_check($o,$f,100)),'Live offer');ck(is_wp_error(GE_Logistics::offer_check($o,$f,200)),'Expired offer');
ck(is_wp_error(GE_Logistics::offer_check($o,GE_Logistics::fingerprint($a,$p,[1001],['origin'=>'QA']),100)),'Quantity invalidates offer');$b=$a;$b['city']='Other QA';ck(is_wp_error(GE_Logistics::offer_check($o,GE_Logistics::fingerprint($b,$p,[1000],['origin'=>'QA']),100)),'Destination invalidates offer');
ck(!is_wp_error(GE_Logistics::tracking_url('https://www.andreani.com/seguimiento/qa','andreani')),'Official tracking');
foreach(['https://andreani.com.evil.invalid/','https://evil.invalid/','http://andreani.com/','https://x@andreani.com/','javascript:alert(1)','https://andreani.com:8443/'] as $url)ck(is_wp_error(GE_Logistics::tracking_url($url,'andreani')),'Unsafe tracking rejected');
echo "PASS $n logistics unit checks\n";

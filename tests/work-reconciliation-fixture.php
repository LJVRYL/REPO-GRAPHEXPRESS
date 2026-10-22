<?php
/** Synthetic historical declarations; never loads WordPress or accesses production. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['actor']=1;$GLOBALS['permitted']=true;$GLOBALS['state']=null;$GLOBALS['checks']=0;$GLOBALS['writes']=0;$GLOBALS['version']=1;
function check($yes,$label){if(!$yes)throw new RuntimeException($label);$GLOBALS['checks']++;}
function rejects($f,$label){try{$f();}catch(Throwable $e){$GLOBALS['checks']++;return;}throw new RuntimeException('Accepted '.$label);}
function get_current_user_id(){return $GLOBALS['actor'];}
function get_option($key,$default=null){return $GLOBALS['state']??$default;}
function update_option($key,$value,$autoload=false){if(!empty($GLOBALS['write_failure']))return false;$GLOBALS['state']=$value;$GLOBALS['writes']++;if(defined('GE_RECON_HTTP_FIXTURE'))file_put_contents(getenv('GE_RECON_TEST_STATE'),json_encode($value));return true;}
function wp_cache_delete($key,$group){}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_generate_uuid4(){return sprintf('00000000-0000-4000-8000-%012d',++$GLOBALS['uuid']);}
function sanitize_text_field($v){return strip_tags($v);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return esc_html($v);}
function esc_attr($v){return esc_html($v);}
function home_url($p){return 'https://example.test'.$p;}
function add_query_arg($key,$value,$url){return $url.(strpos($url,'?')===false?'?':'&').urlencode($key).'='.urlencode($value);}
function is_wp_error($v){return $v instanceof Exception;}
function get_user_meta($id,$key,$single=true){return $id===99?'foreign':'';}
function get_post_meta($id,$key,$single=true){return $id===99?'foreign':'';}
function add_action($name,$handler){}
function add_filter($name,$handler){}
class GE_Organization {const PRIMARY='graph-express';}
class GE_Organization_Runtime {static function allowed($module,$write=false){return $GLOBALS['permitted'] && $module!==($GLOBALS['disabled_module']??'');}}
class GE_WTP_Business_Export {static function allowed(){return $GLOBALS['actor']===1 && $GLOBALS['permitted'];}}
class GE_WTP_Staff_Portal {static function portal_url($section,$args){return '/gestion/?section='.$section.'&'.http_build_query($args);}}
class GE_WTP_Commercial_Quote_UI {static function money($c){return '$'.number_format($c/100,2);}}
class GE_WTP_Commercial_Quotes {static function get($id,$actor){return $id===404?new Exception('missing'):array('customer_id'=>$id===98?99:1,'version'=>$GLOBALS['version'],'status'=>'sent','snapshot'=>array('total_cents'=>12345));}}
class SyntheticOrder {
    public $id;function __construct($id){$this->id=$id;}
    function get_meta($key,$single=true){if($key==='_ge_organization_id')return $this->id===99?'foreign':'';if($key==='_ge_commercial_payment_order')return $this->id===97?'yes':'';return '';}
    function get_customer_id(){return $this->id===98?99:1;}
    function get_status(){return 'processing';}function get_total(){return '123.45';}
    function get_date_modified(){return new DateTime('@'.$GLOBALS['version']);}
    function save(){throw new RuntimeException('Forbidden source mutation');}
}
function wc_get_order($id){return $id===404?false:new SyntheticOrder($id);}
class SyntheticDB {
    public $prefix='test_',$last_error='';
    function prepare($sql,$v){return $sql;}
    function get_var($sql){return strpos($sql,'RELEASE_LOCK')!==false?'1':(!empty($GLOBALS['locked'])?'0':'1');}
}
$GLOBALS['wpdb']=new SyntheticDB();$GLOBALS['uuid']=0;
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-work-reconciliation.php';
if(defined('GE_RECON_HTTP_FIXTURE'))return;
$row=array('key'=>'work-one','customer'=>'Cliente Ñ','reference'=>'Pedido #1','order_id'=>1,'quote_id'=>0,'commercial'=>'performed','production'=>'completed','delivery'=>'completed','payment'=>'fully_paid','reference_cents'=>12345,'performed_cents'=>12345,'collected_cents'=>12345,'note'=>'Confirmación histórica; fecha real no informada.','declarant'=>'Leo');
$doc=array('schema_version'=>1,'organization_id'=>'graph-express','source_sha256'=>str_repeat('a',64),'rows'=>array($row));
$validated=GE_WTP_Work_Reconciliation::validate($doc);
check(count($validated['rows'])===1,'one validated declaration');
check(count($validated['source_fingerprints'])===1,'source fingerprint');
check(GE_WTP_Work_Reconciliation::current()['rows']===array(),'initial empty');
foreach(array('order_id','quote_id') as $field)foreach(array(99,98,404) as $id){$bad=$doc;$bad['rows'][0][$field]=$id;rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'foreign or missing '.$field);}
$bad=$doc;$bad['rows'][0]['order_id']=97;rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'payment attempt');
foreach(array(-1,1.2,'100',100000000001) as $money){$bad=$doc;$bad['rows'][0]['collected_cents']=$money;rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'invalid money');}
foreach(array('commercial','production','delivery','payment') as $field){$bad=$doc;$bad['rows'][0][$field]='unexpected';rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'invalid enum');}
$bad=$doc;$bad['rows'][0]['secret']='x';rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'unknown fields');
$bad=$doc;$bad['rows'][]=$row;rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'duplicate key');
$bad=$doc;$extra=$row;$extra['key']='another';$bad['rows'][]=$extra;rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'duplicate source');
$bad=$doc;$bad['rows'][0]['production']='not_performed';rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'contradictory not performed');
$bad=$doc;$bad['rows'][0]['payment']='unpaid';rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'unpaid collected');
$bad=$doc;$bad['organization_id']='foreign';rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'foreign document');
$bad=$doc;$bad['rows']=array_fill(0,201,$row);rejects(function()use($bad){GE_WTP_Work_Reconciliation::validate($bad);},'oversize');
$GLOBALS['actor']=0;check(!GE_WTP_Work_Reconciliation::allowed(),'anonymous denied');rejects(function()use($validated){GE_WTP_Work_Reconciliation::append($validated,'');},'anonymous write');$GLOBALS['actor']=1;
$GLOBALS['disabled_module']='finance';check(!GE_WTP_Work_Reconciliation::allowed(),'finance missing denied');$GLOBALS['disabled_module']='';
$GLOBALS['locked']=true;rejects(function()use($validated){GE_WTP_Work_Reconciliation::append($validated,'');},'concurrent write');$GLOBALS['locked']=false;
$GLOBALS['version']=2;rejects(function()use($validated){GE_WTP_Work_Reconciliation::append($validated,'');},'stale native state');$GLOBALS['version']=1;
$GLOBALS['write_failure']=true;rejects(function()use($validated){GE_WTP_Work_Reconciliation::append($validated,'');},'failed persistence');$GLOBALS['write_failure']=false;
check($GLOBALS['writes']===0,'no failed writes');
$first=GE_WTP_Work_Reconciliation::append($validated,'');check($GLOBALS['writes']===1,'one atomic option write');check($first['actor_id']===1,'actor recorded');check($first['rows'][0]['collected_cents']===12345,'declared collection preserved');
$again=GE_WTP_Work_Reconciliation::append($validated,'');check($again['id']===$first['id'] && $GLOBALS['writes']===1,'idempotent replay');
$doc2=$doc;$doc2['rows'][0]['payment']='unpaid';$doc2['rows'][0]['collected_cents']=0;$next=GE_WTP_Work_Reconciliation::validate($doc2);
rejects(function()use($next){GE_WTP_Work_Reconciliation::append($next,'stale');},'stale revision');
$second=GE_WTP_Work_Reconciliation::append($next,$first['id']);check(count(GE_WTP_Work_Reconciliation::state()['revisions'])===2,'before image retained');
$rollback=GE_WTP_Work_Reconciliation::append($first['id'],$second['id'],true);check($rollback['rows']===$first['rows'],'rollback restores declaration');check(count(GE_WTP_Work_Reconciliation::state()['revisions'])===3,'rollback audited not destructive');
$native=array(array('order_id'=>1,'quote_id'=>0,'status'=>'Original','next'=>'Original','owner'=>'Original','closed'=>false,'kind'=>'orders'));
$decorated=GE_WTP_Work_Reconciliation::decorate($native);check($decorated[0]['closed']===true,'delivered classified closed');check(strpos($decorated[0]['owner'],'Cobrado completo')!==false,'declared payment label');check($native[0]['status']==='Original','input preserved');
$GLOBALS['version']=2;$stale=GE_WTP_Work_Reconciliation::decorate($native);check($stale[0]['status']==='Original','new native state not masked');$GLOBALS['version']=1;
$empty=GE_WTP_Work_Reconciliation::append('empty',$rollback['id'],true);check(!$empty['rows'],'disable projection by audited rollback');check(count(GE_WTP_Work_Reconciliation::state()['revisions'])===4,'history retained after empty');
echo 'PASS '.$GLOBALS['checks']." historical reconciliation checks; synthetic only.\n";

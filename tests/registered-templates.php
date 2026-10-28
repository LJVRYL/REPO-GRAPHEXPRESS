<?php
define('ABSPATH', __DIR__ . '/synthetic/');
define('GE_WTP_PLUGIN_URL', 'https://example.invalid/plugin/');
$logged_in = true;
function is_user_logged_in() { global $logged_in; return $logged_in; }
function add_action() {}
function WP_Error_stub() {}
class WP_Error { private $message; function __construct($code,$message){$this->message=$message;} function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function wp_json_encode($s){return json_encode($s);}
function home_url($s){return 'https://example.invalid'.$s;}
function admin_url($s){return home_url('/wp-admin/'.$s);}
function wp_create_nonce($s){return 'synthetic-'.$s;}
function add_query_arg($a,$u){return $u.'?'.http_build_query($a);}
function wp_login_url($u){return home_url('/login?redirect_to='.rawurlencode($u));}
function get_permalink($id){return home_url('/product/test/');}
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-registered-templates.php';
$checks=0;
function check($ok,$message){global $checks;if(!$ok){throw new Exception($message);} $checks++;}
$catalog=GE_WTP_Registered_Templates::catalog();check(count($catalog['templates'])===83,'Expected 83 live templates');
foreach($catalog['templates'] as $id=>$entry){$pdf=GE_WTP_Registered_Templates::pdf_bytes($id);check(is_string($pdf),'Decode '.$id);check(hash('sha256',$pdf)===$entry['sha256'],'Hash '.$id);}
foreach(array('../wp-config','gx-ref-016','gx-ref-020','gx-ref-061','gx-ref-074','gx-ref-091','gx-ref-999',array('gx-ref-006'),null,'GX-REF-006') as $id){check(is_wp_error(GE_WTP_Registered_Templates::pdf_bytes($id)),'Unknown/held id rejected');}
$logged_in=false;check(GE_WTP_Registered_Templates::download_url('gx-ref-006')==='','Guests never receive download URLs');$guest=GE_WTP_Registered_Templates::index();check(strpos($guest,GE_WTP_Registered_Templates::ACTION)===false,'No URL leakage in guest library');check(strpos($guest,'Ingresar y ver')!==false,'Guest sign-in CTA');
$logged_in=true;$member=GE_WTP_Registered_Templates::index();check(substr_count($member,'class="gx-template-card"')===83,'Member cards');check(strpos($member,'gx-ref-016')===false,'Held template absent');check(stripos($member,'grupowind')===false,'No supplier identity');
class QA_Product { private $slug; private $id; function __construct($slug,$id=123){$this->slug=$slug;$this->id=$id;}function get_slug(){return $this->slug;}function get_id(){return $this->id;}function get_name(){return $this->slug;}function get_status(){return 'publish';} }
foreach($catalog['products'] as $slug=>$opts){$product=new QA_Product($slug);check(GE_WTP_Registered_Templates::product_rows($product)===$opts,'Exact product mapping');ob_start();GE_WTP_Registered_Templates::product_panel();$panel=ob_get_clean();check(strpos($panel,'data-template-map')!==false,'Member option map');$logged_in=false;ob_start();GE_WTP_Registered_Templates::product_panel();$panel=ob_get_clean();check(strpos($panel,GE_WTP_Registered_Templates::ACTION)===false,'Guest product no PDF URL');$logged_in=true;}
// Verify integration with the real organization policy, not a permissive imitation of it.
class GE_Organization { const PRIMARY='graph-express'; static function get($id){return array('members'=>array('1'=>'owner'));} static function modules(){return array('orders','customers','production','communications','stock','finance','suppliers','cost_engine','file_analyzer','ecommerce');} }
function get_current_user_id(){return 1;}
function wp_doing_ajax(){return false;}
require dirname(__DIR__).'/wp-content/mu-plugins/ge-organization/class-ge-organization-runtime.php';
check(GE_Organization_Runtime::action_policy(GE_WTP_Registered_Templates::ACTION)===array('orders',false),'Central policy recognizes the download as order-file read access');
foreach(GE_Organization_Runtime::permissions() as $role=>$permission){check(in_array('orders',$permission['read'],true),'Existing staff read policy '.$role);}

// Per-item gates: no real accounts, cookies or registration records are created by this fixture.
class QA_Redirect extends Exception { public $url; function __construct($url){$this->url=$url;} }
class QA_Denied extends Exception { public $status; function __construct($status){$this->status=$status;} }
function wp_safe_redirect($url,$status=302){throw new QA_Redirect($url);}
function wp_die($message,$title,$args){throw new QA_Denied($args['response']);}
function nocache_headers(){}
function wc_get_product($id){global $fixture_items;return $fixture_items[$id]??false;}
function wp_hash($s){return hash_hmac('sha256',$s,'synthetic-fixture-key');}
function is_ssl(){return false;}
$fixture_items=array();$id=100;
foreach($catalog['products'] as $slug=>$opts){
    $product=new QA_Product($slug,$id++);$fixture_items[$product->get_id()]=$product;
    $logged_in=false;ob_start();GE_WTP_Registered_Templates::item_button();$guest_card=ob_get_clean();
    $logged_in=true;ob_start();GE_WTP_Registered_Templates::item_button();$member_card=ob_get_clean();
    check($guest_card===$member_card,'Stable cache-safe card URL');
    $available=GE_WTP_Registered_Templates::item_has_template($opts);
    check((strpos($guest_card,'Descargar plantilla')!==false)===$available,'Card reflects actual template availability');
    check(strpos($guest_card,'_wpnonce')===false,'No user nonce on catalog cards');
    if(!$available){continue;}
    $_GET=array('gx_template_item'=>(string)$product->get_id());$logged_in=false;
    try{GE_WTP_Registered_Templates::item_download();check(false,'Guest must redirect');}catch(QA_Redirect $e){check(strpos($e->url,'modo=registro')!==false,'Guest sent to actual registration');check(strpos($e->url,'gx_template_product='.$product->get_id())!==false,'Registration retains product context');}
    $logged_in=true;
    try{GE_WTP_Registered_Templates::item_download();check(false,'Member must redirect');}catch(QA_Redirect $e){$refs=array_unique(array_column($opts,'ref'));if(count($refs)===1&&reset($refs)){check(strpos($e->url,GE_WTP_Registered_Templates::ACTION)!==false,'Exact single reference downloads');}else{check(strpos($e->url,'#gx-template-heading')!==false,'Several measures require exact option selection');}}
}
foreach(array('../wp-config',array('100'),'999999') as $bad){$_GET=array('gx_template_item'=>$bad);try{GE_WTP_Registered_Templates::item_download();check(false,'Invalid item must be rejected');}catch(QA_Denied $e){check($e->status===404,'Invalid item closed');}}
$valid_id=null;foreach($fixture_items as $id=>$item){if(GE_WTP_Registered_Templates::valid_item($id)){$valid_id=$id;break;}}
$payload=$valid_id.'.'.time();$cookie=$payload.'.'.wp_hash($payload);$_REQUEST=array('action'=>'ge_customer_register');$_COOKIE['gx_template_return']=$cookie;
check(GE_WTP_Registered_Templates::registration_return('/normal',303)==='/normal','No return before successful authentication');
GE_WTP_Registered_Templates::registration_authenticated('fixture',(object)array('ID'=>1));
foreach(array('https://outside.invalid',array($cookie),$cookie.'bad',$valid_id.'.1.'.wp_hash($valid_id.'.1')) as $bad){$_COOKIE['gx_template_return']=$bad;check(GE_WTP_Registered_Templates::registration_return('/normal',303)==='/normal','Tampered or expired return context rejected');}
$_COOKIE['gx_template_return']=$cookie;check(strpos(GE_WTP_Registered_Templates::registration_return('/normal',303),'gx_template_item='.$valid_id)!==false,'Completed registration returns to same product download gate');
check(!isset($_COOKIE['gx_template_return']),'Return context consumed once');

echo json_encode(array('status'=>'PASS','checks'=>$checks,'templates'=>83,'product_pages'=>25,'mode'=>'synthetic-users-no-real-records')).PHP_EOL;

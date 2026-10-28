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
$logged_in=false;check(GE_WTP_Registered_Templates::download_url('gx-ref-006')==='','Guests never receive download URLs');$guest=GE_WTP_Registered_Templates::index();check(strpos($guest,'ge_graphex_template_download')===false,'No URL leakage in guest library');check(strpos($guest,'Ingresar y ver')!==false,'Guest sign-in CTA');
$logged_in=true;$member=GE_WTP_Registered_Templates::index();check(substr_count($member,'class="gx-template-card"')===83,'Member cards');check(strpos($member,'gx-ref-016')===false,'Held template absent');check(stripos($member,'grupowind')===false,'No supplier identity');
class QA_Product { private $slug; function __construct($slug){$this->slug=$slug;}function get_slug(){return $this->slug;}function get_id(){return 123;} }
foreach($catalog['products'] as $slug=>$opts){$product=new QA_Product($slug);check(GE_WTP_Registered_Templates::product_rows($product)===$opts,'Exact product mapping');ob_start();GE_WTP_Registered_Templates::product_panel();$panel=ob_get_clean();check(strpos($panel,'data-template-map')!==false,'Member option map');$logged_in=false;ob_start();GE_WTP_Registered_Templates::product_panel();$panel=ob_get_clean();check(strpos($panel,'ge_graphex_template_download')===false,'Guest product no PDF URL');$logged_in=true;}
echo json_encode(array('status'=>'PASS','checks'=>$checks,'templates'=>83,'product_pages'=>25,'mode'=>'synthetic-users-no-real-records')).PHP_EOL;

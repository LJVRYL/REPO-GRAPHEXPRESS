<?php
define('ABSPATH',__DIR__); define('GE_WTP_PLUGIN_FILE',__FILE__);
class GE_Organization { const PRIMARY='example-organization'; }
class GE_Organization_Runtime { public static function settings(){return array('general'=>array('brand_name'=>'Otra empresa & socios','website'=>'https://tenant.example','email'=>'soporte@tenant.example','phone'=>'','address'=>''),'branding'=>array('logo_url'=>'https://tenant.example/logo.png','primary_color'=>'#225577'),'portal'=>array());} }
function get_bloginfo($key){return 'Otra empresa';}
function home_url($path=''){return 'https://tenant.example'.$path;}
function sanitize_email($value){return $value;}
function sanitize_text_field($value){return strip_tags($value);}
function sanitize_hex_color($value){return preg_match('/^#[0-9a-f]{6}$/i',$value)?$value:null;}
function esc_url_raw($value,$protocols=array()){return $value;}
function esc_url($value){return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function esc_html($value){return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}
function wp_kses_post($value){return $value;}
function wp_strip_all_tags($value){return strip_tags($value);}
function wp_parse_url($value,$component=-1){return parse_url($value,$component);}
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-email-branding.php';
$profile=GE_WTP_Email_Branding::profile();
$html=GE_WTP_Email_Branding::render('Aviso','<p>Contenido propio</p>');
$checks=array($profile['name']==='Otra empresa & socios',$profile['logo']==='https://tenant.example/logo.png',$profile['email']==='soporte@tenant.example',strpos($html,'Otra empresa &amp; socios')!==false,strpos($html,'graphex.ar')===false,strpos($html,'graphex-email-symbol.png')===false,GE_WTP_Email_Branding::subject('Documento de Graph Express S.A.')==='Documento de Graph Express S.A.',GE_WTP_Email_Branding::operational_url('https://graphex.ar/portal')==='');
foreach($checks as $ok)if(!$ok)throw new Exception('Tenant branding failure');
echo json_encode(array('tenant_checks'=>count($checks),'passed'=>true));

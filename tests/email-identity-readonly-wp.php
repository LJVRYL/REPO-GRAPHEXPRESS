<?php
/** Read-only renderer integration. Candidate classes are isolated from installed classes. */
$_SERVER['HTTP_HOST']='graphex.ar'; $_SERVER['HTTPS']='on';
define('DISABLE_WP_CRON',true); define('GE_EMAIL_IDENTITY_QA',true);
$load=getenv('GE_WP_LOAD'); $candidate=getenv('GE_EMAIL_CANDIDATE');
if (!$load || !$candidate || strpos($candidate,'email-identity-qa-')===false) throw new Exception('Explicit private candidate required');
require $load;
$sent=0; $checks=0; $errors=array(); $renders=array();
add_filter('pre_wp_mail',function()use(&$sent){$sent++;throw new Exception('QA attempted delivery');},-1000);
add_filter('query',function($sql){if(preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql))throw new Exception('QA attempted database mutation '.implode('>',array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,12),'function')));return $sql;},-1000);
function eb_check($condition,$label){global $checks;if(!$condition)throw new Exception($label);$checks++;}
$base=$candidate.'/wp-content/plugins/ge-webtoprint-calculator/includes/';
foreach(array('branding','content','core','woocommerce') as $part) { $class='GE_WTP_Email_'.ucfirst($part); if($part==='woocommerce')$class='GE_WTP_Email_WooCommerce'; if(!class_exists($class))require $base.'class-ge-wtp-email-'.$part.'.php'; }
GE_WTP_Email_Branding::init(); GE_WTP_Email_Core::init();
GE_WTP_Email_WooCommerce::init();
$aliases=array('GE_WTP_Email_Preview'=>'GE_WTP_Email_Preview_Email_QA','GE_WTP_Notifications'=>'GE_WTP_Notifications_Email_QA','GE_WTP_Email_Templates'=>'GE_WTP_Email_Templates_Email_QA','GE_WTP_Review_Requests'=>'GE_WTP_Review_Requests_Email_QA','GE_WTP_Newsletter'=>'GE_WTP_Newsletter_Email_QA','GE_WTP_Newsletter_Review'=>'GE_WTP_Newsletter_Review_Email_QA');
foreach(array('notifications','email-templates','review-requests','newsletter-review','newsletter') as $part) {
    $code=file_get_contents($base.'class-ge-wtp-'.$part.'.php');
    $code=preg_replace('/^<\?php/','',$code);
    $code=str_replace("require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';",'', $code);
    eval(strtr($code,$aliases));
}
$code=preg_replace('/^<\?php/','',file_get_contents($base.'class-ge-wtp-email-preview.php'));
eval(strtr($code,$aliases));
define('DOING_AJAX',true); wp_set_current_user(1);
class GE_Email_Preview_Exit extends Exception {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new GE_Email_Preview_Exit();};});
function eb_ajax($id) {
    $_POST=array('template_id'=>$id,'nonce'=>wp_create_nonce('ge_email_preview')); $_REQUEST=$_POST;
    ob_start(); try { GE_WTP_Email_Templates_Email_QA::preview_template(); } catch(GE_Email_Preview_Exit $e) {}
    $result=json_decode(ob_get_clean(),true); return $result;
}
$before=hash('sha256',serialize(get_option('ge_email_templates_v1_graph-express',array())));
$catalog=GE_WTP_Email_Templates_Email_QA::catalog();
foreach($catalog as $id=>$row) {
    if (!GE_WTP_Email_Preview_Email_QA::supports($id))continue;
    $r=GE_WTP_Email_Preview_Email_QA::render($id);
    if(is_wp_error($r)){$errors[$id]=array('message'=>$r->get_error_message(),'data'=>$r->get_error_data());continue;}
    eb_check(strpos($r['body'],'ge-email-branding-v1')!==false,'Shared shell '.$id);
    eb_check(strpos($r['body'],'graphex-email-symbol.png')!==false,'Raster logo '.$id);
    eb_check(strpos($r['body'],'servicio@graphex.ar')!==false,'Configured signature '.$id);
    eb_check(!preg_match('/<(script|iframe)\b|javascript:/i',$r['body']),'Unsafe markup '.$id);
    $admin_email=get_option('admin_email'); if(strpos($id,'woocommerce_customer_pos_')===0 && $admin_email!==GE_WTP_Email_Branding::profile()['email']) eb_check(strpos($r['body'],$admin_email)===false,'Staff address not used as customer contact '.$id);
    $renders[$id]=array('title'=>$row['title'],'group'=>$row['group'],'reference'=>strpos($id,'wordpress_')===0,'subject'=>$r['subject'],'body'=>$r['body'],'plain'=>$r['plain'],'notice'=>$r['notice'],'attachment'=>$r['attachment']);
}
foreach($catalog as $id=>$row) if (!empty($row['preview_available'])) {
    $ajax=eb_ajax($id); eb_check(is_array($ajax)&&!empty($ajax['success'])&&!empty($ajax['data']['body']),'Authorized AJAX preview '.$id);
}
$hostile=array('subject'=>'Moderación · comentario','message'=>'<p>Ver pedido</p><a href="https://attacker.example">Ver pedido</a><strong style="letter-spacing:.1em">GRAPH EXPRESS</strong>','headers'=>array('Content-Type: text/plain; charset=UTF-8'));
$escaped=GE_WTP_Email_Branding::mail($hostile);
eb_check(strpos($escaped['message'],'&lt;p&gt;Ver pedido&lt;/p&gt;')!==false,'Plaintext markup remains literal');
eb_check(strpos($escaped['message'],'&lt;strong')!==false,'Plaintext identity slot remains literal');
$org_code=preg_replace('/^<\?php/','',file_get_contents($candidate.'/wp-content/mu-plugins/ge-organization/class-ge-organization.php'));
$org_code=preg_replace('/\bGE_Organization\b/','GE_Organization_Email_QA',$org_code); eval($org_code);
eb_check(GE_Organization_Email_QA::mail($hostile)===$hostile,'Organization legacy adapter respects MIME');
$runtime_code=preg_replace('/^<\?php/','',file_get_contents($candidate.'/wp-content/mu-plugins/ge-organization/class-ge-organization-runtime.php'));
$runtime_code=preg_replace('/\bGE_Organization_Runtime\b/','GE_Organization_Runtime_Email_QA',$runtime_code); eval($runtime_code);
$snapshot=array('organization_snapshot'=>array('organization_id'=>GE_Organization::PRIMARY,'general'=>array('brand_name'=>'Graph Express','display_name'=>'Graph Express','legal_name'=>'Graph Express S.A.','email'=>'contacto@example.invalid','phone'=>'','address'=>''),'documents'=>array('email_text'=>'Condiciones de ejemplo','terms'=>'','payment_terms'=>''),'branding'=>array('logo_url'=>'')));
$summary=GE_Organization_Runtime_Email_QA::email_summary($snapshot);
eb_check(strpos($summary,'<strong>GRAPHEX</strong>')!==false&&strpos($summary,'Graph Express S.A.')!==false,'Archived brand refreshed while legal snapshot retained');
eb_check(strpos(GE_WTP_Email_Branding::subject('Factura de Graph Express S.A.'),'Graph Express S.A.')!==false,'Legal subject name retained');
$r=GE_WTP_Email_Templates_Email_QA::render('commercial_quote_sent',array('customer_name'=>'<script>alert(1)</script> & María','quote_number'=>'123','intro'=>'Presupuesto de ejemplo','portal_url'=>'https://example.invalid/presupuestos/123','summary'=>'<table><tr><td>Emisor legal: Graph Express S.A.</td><td>ARS 25.000</td></tr></table>','first_access'=>'','brand_name'=>'GRAPHEX'));
$r=GE_WTP_Email_Templates_Email_QA::envelope($r,'cliente@example.invalid',2);
eb_check(strpos($r['body'],'<script>')===false,'Variables escaped');
eb_check(strpos($r['body'],'Graph Express S.A.')!==false,'Legal identity retained');
eb_check(strpos($r['body'],'versión 2')!==false,'PDF version metadata');
$r=GE_WTP_Email_Templates_Email_QA::envelope(GE_WTP_Email_Templates_Email_QA::render('commercial_quote_sent',array('customer_name'=>'Cliente de ejemplo','quote_number'=>'123','intro'=>'Tenés un nuevo presupuesto para revisar.','portal_url'=>'https://example.invalid/presupuestos/123','summary'=>'<table><tr><td>Tarjetas personales · 500 unidades</td><td>ARS 25.000</td></tr></table>','first_access'=>'','brand_name'=>'GRAPHEX')),'cliente@example.invalid',2);
$renders['commercial_quote_sent']=array('title'=>'Presupuesto comercial','group'=>'Presupuestos','subject'=>$r['subject'],'body'=>$r['body'],'plain'=>$r['plain'],'notice'=>'Ejemplo. El PDF real se genera únicamente al enviar; su fallo bloquea el envío.','attachment'=>$r['attachment']);
$prepared=GE_WTP_Email_Branding::prepare('Aviso','<p>Hola</p>');
eb_check(GE_WTP_Email_Branding::prepare($prepared['subject'],$prepared['body'])===$prepared,'Idempotent shell');
eb_check(strpos(GE_WTP_Email_Branding::inactive_preview('<html><head></head><body><a href="https://example.invalid/private">Acción</a></body></html>'),'href=')===false,'Preview navigation disabled');
$input=array('subject'=>'Acceso · Graph Express','message'=>"Seguridad\nhttps://example.invalid/restablecer?key=EXAMPLE&login=cliente",'headers'=>array('Reply-To: contacto@example.invalid','List-Unsubscribe: <https://example.invalid/baja>'),'attachments'=>array('/private/existing.pdf'));
$decorated=GE_WTP_Email_Branding::mail($input);
eb_check($decorated['attachments']===$input['attachments'],'Attachments unchanged');
eb_check(in_array($input['headers'][0],$decorated['headers'],true)&&in_array($input['headers'][1],$decorated['headers'],true),'Transport headers retained');
eb_check(strpos(GE_WTP_Email_Branding::plain($decorated['message']),'https://example.invalid/restablecer?key=EXAMPLE&login=cliente')!==false,'Security URL retained');
eb_check(GE_WTP_Email_Branding::operational_url('https://unrelated.example/steal')==='','Unrelated portal origin rejected');
eb_check(GE_WTP_Email_Branding::operational_url('javascript:alert(1)')==='','Unsafe URL protocol rejected');
eb_check(strpos(GE_WTP_Email_Branding::operational_url(home_url('/cliente-markcom/')),'graphex.ar')!==false,'Current portal origin retained');
$original=array('to'=>'cliente@example.invalid','subject'=>'[Graph Express] Cambio de contraseña','message'=>'###SITENAME### https://example.invalid/reset?key=EXAMPLE','headers'=>'X-Context: security');
add_filter('password_change_email',array('GE_WTP_Email_Core','email_array'),90);
$core=apply_filters('password_change_email',$original);
eb_check($core['to']===$original['to']&&$core['headers']===$original['headers'],'Core recipient/header unchanged');
eb_check(strpos($core['message'],'https://example.invalid/reset?key=EXAMPLE')!==false,'Core reset URL unchanged');
$mailer=(object)array('Body'=>$prepared['body'],'AltBody'=>'');GE_WTP_Email_Branding::alternate($mailer);eb_check($mailer->AltBody===GE_WTP_Email_Branding::plain($prepared['body']),'Multipart alternate');
foreach(array('customer_portal_invite','customer_welcome_verification','customer_email_verification','workflow_supplier_portal') as $context) eb_check(strpos(GE_WTP_Notifications_Email_QA::safe_log_body('<a href="https://example.invalid/private?key=EXAMPLE">Private</a>',$context),'key=')===false,'Private log redaction '.$context);
eb_check($before===hash('sha256',serialize(get_option('ge_email_templates_v1_graph-express',array()))),'Stored edits unchanged');
$raw=file_get_contents(GE_WTP_PLUGIN_DIR.'templates/newsletter-v3-template.html');
$campaign=(object)array('subject'=>'PUBLICIDAD · ejemplo','preview_text'=>'Ejemplo','content'=>$raw);
$meta=array('template_sha256'=>hash('sha256',$raw),'version'=>'example','statutory_notice'=>'','notice_source'=>'');
$original_hash=GE_WTP_Newsletter_Review_Email_QA::content_fingerprint($campaign,$meta);
$organizations=GE_Organization::all(); $organizations[GE_Organization::PRIMARY]['settings']['general']['brand_name']='Marca de prueba';
$replace_organization=function()use($organizations){return $organizations;};
add_filter('pre_option_ge_organizations_v1',$replace_organization,90);
try { eb_check($original_hash!==GE_WTP_Newsletter_Review_Email_QA::content_fingerprint($campaign,$meta),'Presentation changes invalidate campaign review'); }
finally { remove_filter('pre_option_ge_organizations_v1',$replace_organization,90); }
eb_check($sent===0,'No mail attempted');
echo json_encode(array('checks'=>$checks,'mail_attempts'=>$sent,'database_mutation_guard'=>true,'catalog_count'=>count($catalog),'renders_count'=>count($renders),'errors'=>$errors,'renders'=>$renders),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

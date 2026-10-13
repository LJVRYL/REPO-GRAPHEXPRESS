<?php
/** Private attachments and reception health in the existing CRM. */
defined('ABSPATH') || exit;
final class GE_CRM_Mail_Reception {
 const ROOT='/home/graphexpress/mail-reception/attachments/';
 public static function init(){
  add_action('ge_crm_thread_agent',array(__CLASS__,'attachments'),5);
  add_action('ge_crm_inbox_channel_details',array(__CLASS__,'health'));
  add_action('wp_footer',array(__CLASS__,'banner'));
  add_action('admin_post_ge_crm_mail_attachment',array(__CLASS__,'download'));
 }
 public static function health_state($h,$now=null){
  $now=$now??time();$at=strtotime($h['checked_at']??'');
  if(!$at||$now-$at>180)return array('ok'=>false,'text'=>'Recepción sin confirmar: falta una comprobación reciente. Revisar antes de enviar campañas.');
  if(empty($h['receiver_running'])||!empty($h['poll_failed'])||!empty($h['pending_events']))return array('ok'=>false,'text'=>'Hay correos pendientes de incorporar o un fallo de recepción. Revisar el CRM antes de enviar campañas.');
  return array('ok'=>true,'text'=>'Recepción comprobada: los correos detectados quedaron incorporados al CRM.');
 }
 public static function health(){
  if(!class_exists('GE_CRM')||!GE_CRM::can())return;
  $channels=get_option('ge_crm_attention_channels',array());$h=$channels['email']['consumer_health']??array();$s=self::health_state($h);
  echo '<section class="ge-crm-panel" role="'.($s['ok']?'status':'alert').'"><h2>Recepción de consultas por correo</h2><p>'.esc_html($s['text']).'</p><p>Última comprobación: '.esc_html($h['checked_at']??'Sin registro').' · Pendientes: '.absint($h['pending_events']??0).' · Servicio: '.esc_html($h['runtime']??'Local').'</p><p>Esta comprobación cubre la recepción en el CRM; no confirma entrega ni lectura de campañas.</p></section>';
 }
 public static function banner(){
  if(!class_exists('GE_CRM')||!GE_CRM::can()||!preg_match('#^/gestion/#',parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)))return;
  $channels=get_option('ge_crm_attention_channels',array());$s=self::health_state($channels['email']['consumer_health']??array());
  if(($s['ok']??false)&&($_GET['section']??'')!=='communications')return;
  ob_start();self::health();$html=ob_get_clean();
  echo '<script>document.addEventListener("DOMContentLoaded",function(){var main=document.getElementById("ge-main");if(main&&!main.querySelector("[data-ge-mail-health]")){var box=document.createElement("div");box.setAttribute("data-ge-mail-health","");box.innerHTML='.wp_json_encode($html).';main.prepend(box);}});</script>';
 }
 public static function attachments($record){
  $items=$record['mail_private_attachments']??array();if(!$items)return;
  echo '<section class="ge-crm-panel"><h2>Archivos recibidos por correo</h2><p>Originales privados. Revisar su contenido antes de cotizar o producir.</p><ul>';
  foreach($items as $i=>$item){echo '<li>'.esc_html($item['filename']??'Adjunto').' · '.esc_html($item['content_type']??'');
   if(!empty($item['stored'])){$url=wp_nonce_url(add_query_arg(array('action'=>'ge_crm_mail_attachment','record_id'=>$record['id'],'attachment_index'=>$i),admin_url('admin-post.php')),'ge_crm_mail_attachment_'.$record['id'].'_'.$i);echo ' <a href="'.esc_url($url).'">Descargar original</a>';}
   else echo ' · Pendiente de extracción; la consulta está conservada.';
   echo '</li>';
  }echo '</ul></section>';
 }
 public static function download(){
  if(!class_exists('GE_CRM')||!GE_CRM::can())wp_die('Acceso denegado.','',array('response'=>403));
  $id=absint($_GET['record_id']??0);$index=absint($_GET['attachment_index']??0);check_admin_referer('ge_crm_mail_attachment_'.$id.'_'.$index);
  try{$r=GE_CRM::get($id,'thread');$item=$r['mail_private_attachments'][$index]??array();$eid=$r['attention_event']['external_id']??'';$sha=$item['sha256']??'';
   if(empty($item['stored'])||!preg_match('/^[a-f0-9]{64}$/D',$eid)||!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new RuntimeException('Invalid attachment');
   $path=realpath(self::ROOT.$eid.'/'.$sha.'.blob');$root=realpath(self::ROOT);
   if(!$path||!$root||strpos($path,$root.'/')!==0||is_link(self::ROOT.$eid.'/'.$sha.'.blob')||hash_file('sha256',$path)!==$sha)throw new RuntimeException('Attachment changed');
   nocache_headers();header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.sanitize_file_name($item['filename']).'"');header('Content-Length: '.filesize($path));readfile($path);exit;
  }catch(Throwable $e){wp_die('No se pudo verificar el archivo original.','',array('response'=>404));}
 }
}
GE_CRM_Mail_Reception::init();

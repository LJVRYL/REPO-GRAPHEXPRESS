<?php
/** Reject discarded oversized bodies before authentication/action routing; never show internal paths. */
defined('ABSPATH') || exit;
$ge_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$ge_setting = trim((string)ini_get('post_max_size'));
$ge_unit = strtolower(substr($ge_setting,-1));
$ge_limit = (int)$ge_setting * ('g'===$ge_unit?1073741824:('m'===$ge_unit?1048576:('k'===$ge_unit?1024:1)));
if ('POST'===($_SERVER['REQUEST_METHOD']??'') && $ge_limit>0 && $ge_length>$ge_limit) {
    status_header(413);nocache_headers();
    $ge_message='La carga supera el límite de '.round($ge_limit/1048576).' MiB por solicitud. Para artes grandes, abrí Editar presupuesto y usá Archivos / Arte: los archivos se suben por partes.';
    if (false!==strpos($_SERVER['REQUEST_URI']??'','admin-ajax.php')) {header('Content-Type: application/json; charset=utf-8');echo wp_json_encode(array('success'=>false,'data'=>array('message'=>$ge_message)));exit;}
    wp_die(esc_html($ge_message),'Archivo demasiado grande',array('response'=>413));
}

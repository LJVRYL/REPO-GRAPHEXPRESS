<?php
/** Preserve the site's HTTPS identity when WordPress is loaded by a CLI worker. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
function ge_deco_cli_context($url=null) {
    $url=$url?:getenv('GE_DECO_SITE_URL');if(!$url)return;
    $parts=is_string($url)?parse_url($url):false;
    if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])
        ||!preg_match('/^[a-zA-Z0-9.-]+$/D',$parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment'])
        ||!in_array($parts['path']??'',array('','/'),true))throw new InvalidArgumentException('invalid_cli_site_url');
    $_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']=$parts['host'];$_SERVER['SERVER_NAME']=$parts['host'];$_SERVER['REQUEST_SCHEME']='https';
    // The native organization guard still verifies the database, roots and canonical home.
}

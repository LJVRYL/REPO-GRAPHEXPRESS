<?php
defined('ABSPATH') || exit;

/** Shared presentation only. Never creates links, credentials, recipients or attachments. */
final class GE_WTP_Email_Branding {
    public static function init() {
        add_filter('wp_mail', array(__CLASS__, 'mail'), 90);
        add_action('phpmailer_init', array(__CLASS__, 'alternate'), 99);
        add_filter('wp_mail_from_name', array(__CLASS__, 'sender'), 90);
    }
    public static function profile() {
        $s = class_exists('GE_Organization_Runtime') ? GE_Organization_Runtime::settings() : array();
        $g = $s['general'] ?? array(); $b = $s['branding'] ?? array();
        $primary = class_exists('GE_Organization') ? GE_Organization::PRIMARY : '';
        $graphex = $primary === 'graph-express';
        $name = $g['brand_name'] ?? ''; if (!$name) $name = $g['display_name'] ?? get_bloginfo('name');
        $email = sanitize_email($g['email'] ?? '');
        if (class_exists('GE_WTP_Notification_Center')) $email = sanitize_email(GE_WTP_Notification_Center::settings()['sender_email'] ?? $email);
        $logo = $b['logo_url'] ?? '';
        if ($graphex) $logo = plugins_url('assets/images/graphex-email-symbol.png', GE_WTP_PLUGIN_FILE);
        elseif (preg_match('/\.svg(?:\?|$)/i', $logo)) $logo = ''; // Text fallback for unsupported tenant assets.
        return array('name'=>$name, 'logo'=>esc_url_raw($logo, array('https')), 'color'=>$graphex ? '#443acc' : (sanitize_hex_color($b['primary_color'] ?? '') ?: '#343434'), 'email'=>$email,
            'website'=>esc_url_raw($g['website'] ?? home_url('/'), array('https')), 'portal'=>class_exists('GE_WTP_Portal') ? GE_WTP_Portal::portal_url() : '',
            'language'=>substr(function_exists('get_locale')?get_locale():($g['locale']??'es'),0,2), 'phone'=>sanitize_text_field($g['phone'] ?? ''), 'address'=>sanitize_text_field($g['address'] ?? ''), 'graphex'=>$graphex);
    }
    public static function operational_url($url) {
        $parsed=wp_parse_url((string)$url); if (!is_array($parsed)||empty($parsed['host'])||!in_array($parsed['scheme']??'',array('http','https'),true)) return '';
        if (!empty($parsed['user'])||!empty($parsed['pass']))return '';
        $hosts=array(strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)),'example.invalid');
        if (class_exists('GE_Organization_Runtime')) { $settings=GE_Organization_Runtime::settings(); foreach(array('domain','portal_domain') as $key) { $domain=$settings['portal'][$key]??''; if ($domain) $hosts[]=strtolower((string)wp_parse_url(strpos($domain,'://')===false?'https://'.$domain:$domain,PHP_URL_HOST)); } }
        return in_array(strtolower($parsed['host']),$hosts,true)?esc_url($url):'';
    }
    public static function sender($name) { $p=self::profile(); return $p['graphex'] ? $p['name'] : $name; }
    public static function subject($subject) {
        $p=self::profile(); $subject=(string)$subject;
        if (!$p['graphex']) return $subject;
        return stripos($subject,$p['name'])!==false ? $subject : $subject.' · '.$p['name'];
    }
    public static function header($heading = '') {
        $p=self::profile();
        $logo=$p['logo'] ? '<img src="'.esc_url($p['logo']).'" width="48" height="48" alt="'.esc_attr($p['name']).'" style="display:block;border:0;width:48px;height:48px">' : '';
        return '<!doctype html><html lang="'.esc_attr($p['language']).'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><style>@media only screen and (max-width:600px){.ge-mail-pad{padding:24px 20px!important}.ge-mail-title{font-size:26px!important}.ge-mail-shell{width:100%!important}}</style></head><body style="margin:0;padding:0;background:#efeeeb;color:#111116;font-family:Arial,Helvetica,sans-serif"><!-- ge-email-branding-v1 --><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#efeeeb"><tr><td align="center" style="padding:24px 8px"><!--[if mso]><table role="presentation" width="640"><tr><td><![endif]--><table class="ge-mail-shell" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff"><tr><td class="ge-mail-pad" style="padding:26px 32px;background:#111116"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>'.($logo?'<td width="60" style="width:60px">'.$logo.'</td>':'').'<td style="font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:30px;font-weight:bold;color:#f9f8f5">'.esc_html($p['name']).'</td></tr></table></td></tr><tr><td class="ge-mail-pad" style="padding:32px;font-size:16px;line-height:26px;color:#33333b"><div id="body_content_inner">'.($heading !== '' ? '<h1 class="ge-mail-title" style="margin:0 0 24px;font-size:30px;line-height:38px;color:#111116;font-weight:bold">'.esc_html($heading).'</h1>' : '');
    }
    public static function footer($extra_html = '') {
        $p=self::profile(); $links=array();
        if ($p['email']) $links[]='<a href="mailto:'.esc_attr($p['email']).'" style="color:'.esc_attr($p['color']).'">'.esc_html($p['email']).'</a>';
        if ($p['website']) $links[]='<a href="'.esc_url($p['website']).'" style="color:'.esc_attr($p['color']).'">Sitio web</a>';
        if ($p['portal']) $links[]='<a href="'.esc_url($p['portal']).'" style="color:'.esc_attr($p['color']).'">Portal de clientes</a>';
        return '</div></td></tr><tr><td class="ge-mail-pad" style="padding:24px 32px;border-top:1px solid #dddcdf;background:#f9f8f5;font-size:14px;line-height:23px;color:#60606b"><strong style="color:#111116">'.esc_html($p['name']).'</strong><br>'.implode(' · ', $links).($p['phone']?'<br>'.esc_html($p['phone']):'').($p['address']?'<br>'.esc_html($p['address']):'').($extra_html ? '<div style="margin-top:12px">'.wp_kses_post($extra_html).'</div>' : '').'</td></tr></table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';
    }
    public static function render($heading, $content, $context = '') { return self::header($heading).self::content($content).self::footer(); }
    public static function text_content($text) {
        $parts=preg_split('~(https?://[^\s<>"\']+)~',(string)$text,-1,PREG_SPLIT_DELIM_CAPTURE); $html='';
        foreach($parts as $part) $html.=preg_match('~^https?://~',$part)?'<a href="'.esc_url($part).'">'.esc_html($part).'</a>':esc_html($part);
        return '<p>'.nl2br($html).'</p>';
    }
    public static function content($html) {
        $html=(string)$html;
        if (preg_match('~<body\b[^>]*>(.*)</body>~is',$html,$body_match)) $html=$body_match[1];
        $html=preg_replace('~<(?:script|style|iframe|object)\b[^>]*>.*?</(?:script|style|iframe|object)>~is','',$html);
        if (!preg_match('/<(?:p|div|table|html|h[1-6]|a|br)\b/i',$html)) $html=self::text_content($html);
        if (!class_exists('DOMDocument')) return wp_kses_post($html);
        $dom=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $xpath=new DOMXPath($dom);
        $p=self::profile();
        // Remove only the known legacy presentation labels, never legal issuer rows.
        foreach($xpath->query('//div/strong') as $node) {
            $parent=$node->parentNode;
            if ($parent && $parent->nodeName==='div' && in_array(trim($parent->textContent),array('GRAPH EXPRESS','GRAPHEX',$p['name'],strtoupper($p['name']).' · ORDEN A PROVEEDOR'),true)) $parent->parentNode->removeChild($parent);
        }
        foreach($xpath->query('//img[contains(@src,"/favicon/graphex-")]') as $image) {
            $ancestor=$image->parentNode; while($ancestor && $ancestor->nodeName!=='table')$ancestor=$ancestor->parentNode;
            if ($ancestor && preg_replace('/\s+/u','',trim($ancestor->textContent))===preg_replace('/\s+/u','',$p['name'].'Impresión que comunica')) $ancestor->parentNode->removeChild($ancestor);
        }
        foreach($xpath->query('//img[@src]') as $image) { if (preg_match('/\.svg(?:\?|$)/i',$image->getAttribute('src'))) $image->parentNode->removeChild($image); }
        foreach($xpath->query('//p') as $node) {
            if (in_array(trim($node->textContent),array('Graph Express · Entregas a coordinar',$p['name'].' · Entregas a coordinar','Este es un correo operativo relacionado con tu pedido. Graph Express · Entregas a coordinar.','Este es un correo operativo relacionado con tu pedido. '.$p['name'].' · Entregas a coordinar.'),true)) $node->parentNode->removeChild($node);
        }
        $p=self::profile();
        foreach($xpath->query('//a[@href]') as $node) {
            $style=$node->getAttribute('style');
            if (strpos($style,'inline-block')!==false || strpos($style,'background:')!==false || preg_match('/^(Ver |Revisar |Definir |Verificar |Consultar |Crear |Mis solicitudes|Abrir |Dejar )/u',trim($node->textContent))) $node->setAttribute('style','display:inline-block;background:'.$p['color'].';color:#ffffff;padding:14px 22px;font-size:16px;line-height:24px;font-weight:bold;text-decoration:none;border-radius:4px;max-width:100%;box-sizing:border-box;');
            elseif (!$style) $node->setAttribute('style','color:'.$p['color'].';text-decoration:underline;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word;');
        }
        foreach($xpath->query('//div[@style]') as $node) {
            $style=$node->getAttribute('style');
            if (strpos($style,'max-width:')!==false || strpos($style,'border-radius:0 0')!==false) $node->setAttribute('style','font-size:16px;line-height:26px;');
        }
        foreach($xpath->query('//table[not(@role="presentation")]') as $table) {
            if (!$table->hasAttribute('style')) $table->setAttribute('style','width:100%;border-collapse:collapse;margin:20px 0;background:#f9f8f5;');
        }
        foreach($xpath->query('//table[not(@role="presentation")]//td[not(@style)]|//table[not(@role="presentation")]//th[not(@style)]') as $cell) {
            $align=$cell->nodeName==='td' && $cell->nextSibling===null?'text-align:right;':'';
            $cell->setAttribute('style','padding:12px;border-bottom:1px solid #dddcdf;'.$align);
        }
        foreach($xpath->query('//img') as $image) $image->setAttribute('style',$image->getAttribute('style').';max-width:100%;height:auto;');
        foreach($xpath->query('//*[@style]') as $node) {
            $style=$node->getAttribute('style');
            $style=str_ireplace(array('#6d45ef','#17152a','#777382','#898594','#85818e','#777'),array($p['color'],'#111116','#60606b','#60606b','#60606b','#60606b'),$style);
            $style=preg_replace('/font-size\s*:\s*(?:11|12|13)px/i','font-size:14px',$style);
            $node->setAttribute('style',$style);
        }
        foreach($xpath->query('//h1') as $node) $node->setAttribute('class','ge-mail-title');
        $body=$dom->getElementsByTagName('body')->item(0); $out='';
        foreach($body->childNodes as $child) $out.=$dom->saveHTML($child);
        return wp_kses_post($out);
    }
    public static function inactive_preview($html) {
        if (!class_exists('DOMDocument'))return str_replace('href=', 'data-inactive-href=', (string)$html);
        $dom=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.(string)$html,LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($previous);
        foreach($dom->getElementsByTagName('a') as $link) { $link->removeAttribute('href'); $link->setAttribute('tabindex','-1'); $link->setAttribute('aria-disabled','true'); }
        $meta=$dom->createElement('meta'); $meta->setAttribute('http-equiv','Content-Security-Policy');
        $origin=wp_parse_url(home_url('/')); $host=($origin['scheme']??'https').'://'.($origin['host']??'').(isset($origin['port'])?':'.$origin['port']:'');
        $meta->setAttribute('content',"default-src 'none'; img-src ".$host."; style-src 'unsafe-inline'; navigate-to 'none'");
        $head=$dom->getElementsByTagName('head')->item(0); if($head)$head->appendChild($meta);
        return $dom->saveHTML();
    }
    public static function plain($html) {
        $html=preg_replace('/<head\b[^>]*>.*?<\/head>/is','',(string)$html);
        $html=preg_replace_callback('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',function($m){return wp_strip_all_tags($m[2]).' ('.html_entity_decode($m[1],ENT_QUOTES,'UTF-8').')';},$html);
        $html=preg_replace('/<(?:br\s*\/?|\/p|\/div|\/h[1-6]|\/tr|\/li)>/i',"\n",$html);
        $html=preg_replace('/<\/(?:td|th)>/i',"\t",$html);
        return trim(preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/',"\n\n",html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES,'UTF-8')));
    }
    public static function prepare($subject, $body, $context = '') {
        $subject=self::subject($subject);
        if (strpos($body,'<!-- ge-email-branding-v1 -->')===false) $body=self::render(preg_match('/<h[12]\b/i',$body)?'':preg_replace('/ · '.preg_quote(self::profile()['name'],'/').'$/u','',$subject),$body,$context);
        return array('subject'=>$subject,'body'=>$body,'plain'=>self::plain($body));
    }
    public static function mail($atts) {
        $atts['subject']=self::subject($atts['subject'] ?? '');
        $message=(string)($atts['message'] ?? '');
        $headers=$atts['headers'] ?? array(); if (!is_array($headers)) $headers=preg_split('/\r?\n/',$headers);
        $type=apply_filters('wp_mail_content_type','text/plain');
        foreach($headers as $header) if(preg_match('/^\s*Content-Type:\s*([^;]+)/i',$header,$mime))$type=strtolower(trim($mime[1]));
        $owned=strpos($message,'<!-- ge-email-branding-v1 -->')!==false || strtolower($type)==='text/html';
        if (strtolower($type)==='text/plain') $message=self::text_content($message);
        if ($owned && class_exists('GE_Organization_Runtime')) {
            $s=GE_Organization_Runtime::settings(); $reply=sanitize_email($s['email']['reply_to']??''); $present=false;
            foreach($headers as $header)if(stripos(trim($header),'Reply-To:')===0)$present=true;
            if ($reply && !$present) { $headers[]='Reply-To: '.$reply; $atts['headers']=$headers; }
        }
        if (strpos($message,'<!-- ge-email-branding-v1 -->')===false) {
            $atts['message']=self::prepare($atts['subject'],$message)['body'];
            $headers=$atts['headers'] ?? array(); if (!is_array($headers)) $headers=preg_split('/\r?\n/',$headers);
            $headers=array_values(array_filter($headers,function($h){return stripos(trim($h),'Content-Type:')!==0;}));
            $headers[]='Content-Type: text/html; charset=UTF-8'; $atts['headers']=$headers;
        }
        return $atts;
    }
    public static function alternate($mailer) {
        if (strpos((string)$mailer->Body,'<!-- ge-email-branding-v1 -->')!==false) $mailer->AltBody=self::plain($mailer->Body);
    }
}

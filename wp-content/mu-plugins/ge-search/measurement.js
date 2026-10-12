(function (w, d) {
  'use strict';
  var cfg = w.geGrowthConfig || {}, granted = false, loaded = false, pageViewed = false, seen = Object.create(null);
  var params=new URLSearchParams(w.location.search),sensitive=/^\/(wp-admin|gestion)(\/|$)|^\/wp-login\.php$/.test(w.location.pathname);
  ['token','key','password','resetpass','reset_password','email','access_token','auth','order_key'].forEach(function(k){if(params.has(k))sensitive=true;});
  var events = ['contact_click','landing_quote_click','landing_shop_click','registration_started','registration_completed','quote_request_started','quote_request_submitted','company_account_started','company_account_created','category_view','product_view','add_to_cart','begin_checkout'];
  function cookie(name, value, days) { d.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; SameSite=Lax; Max-Age=' + (days * 86400) + (w.location.protocol === 'https:' ? '; Secure' : ''); }
  function read(name) { var p = d.cookie.split('; ').find(function(x) {return x.indexOf(name + '=') === 0;}); try {return p ? decodeURIComponent(p.slice(name.length+1)) : '';} catch(e) {return '';} }
  function clearGaCookies(){d.cookie.split('; ').forEach(function(pair){var name=pair.split('=')[0];if(!/^_ga(?:_[A-Z0-9]+)?$/.test(name))return;cookie(name,'',0);if(w.location.hostname){d.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax; Domain='+w.location.hostname;d.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax; Domain=.'+w.location.hostname;if(/(^|\.)graphex\.ar$/.test(w.location.hostname))d.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax; Domain=.graphex.ar';}});}
  function touch() {
    var p = new URLSearchParams(w.location.search), out = {landing: /^\/[a-zA-Z0-9/_\-]{0,180}$/.test(w.location.pathname) ? w.location.pathname : '/'};
    ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','gclid','gbraid','wbraid'].forEach(function(k){var v=p.get(k); if(v && /^[a-zA-Z0-9_.~+\-]{1,100}$/.test(v)) out[k]=v;});
    return out;
  }
  function persist() { var old;try{old=JSON.parse(read('ge_growth_touch'));}catch(e){}var current=touch(),now=Math.floor(Date.now()/1000),at=new Date().toISOString(); if(!old || !Number.isFinite(old.expires_at) || old.expires_at<=now)old={first:current,last:current,first_at:at,last_at:at,expires_at:now+30*86400};else if(current.utm_source || current.gclid || current.gbraid || current.wbraid){old.last=current;old.last_at=at;}cookie('ge_growth_touch',JSON.stringify(old),(old.expires_at-now)/86400); }
  function load() {
    if(loaded || sensitive || !/^G-[A-Z0-9]+$/.test(cfg.measurementId || ''))return;
    loaded=true; w.dataLayer=w.dataLayer||[];w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};
    w.gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
    w.gtag('consent','update',{analytics_storage:'granted'});w.gtag('js',new Date());
    var config={send_page_view:false,page_location:w.location.origin+touch().landing,page_referrer:'',page_title:'',allow_google_signals:false,allow_ad_personalization_signals:false,cookie_expires:180*86400,cookie_update:false};
    var campaign=touch(),fields={utm_source:'campaign_source',utm_medium:'campaign_medium',utm_campaign:'campaign_name',utm_content:'campaign_content',utm_term:'campaign_term'};
    Object.keys(fields).forEach(function(k){if(campaign[k])config[fields[k]]=campaign[k];});
    w.gtag('config',cfg.measurementId,config);
    w.gtag('get',cfg.measurementId,'session_id',function(id){if(granted && /^\d{1,12}$/.test(String(id)))cookie('ge_growth_session',String(id),1);});
    var script=d.createElement('script');script.async=true;script.src='https://www.googletagmanager.com/gtag/js?id='+cfg.measurementId;d.head.appendChild(script);
  }
  var withdrawal = Promise.resolve(true);
  function permission(action) {
    return w.fetch('/?ge_sales_permission=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+action,cache:'no-store'}).then(function(response){if(!response.ok)throw new Error('No se pudo guardar la preferencia');return response.json();}).then(function(result){if(!result.ok)throw new Error('No se pudo guardar la preferencia');cookie('ge_sales_scope_v1',action==='grant'?'granted':'denied',180);return true;});
  }
  function consent(value) {
    if(cfg.measurementReady!==true)return;
    granted=value === true;cookie('ge_growth_consent',granted?'granted':'denied',180);
    if(granted){w['ga-disable-'+cfg.measurementId]=false;persist();load();if(w.gtag&&loaded&&!pageViewed){pageViewed=true;w.gtag('event','page_view',{send_to:cfg.measurementId,page_location:w.location.origin+touch().landing,page_referrer:'',page_title:''});}if(cfg.pageEvent)emit({event:cfg.pageEvent,product_id:cfg.pageId});companyEvents();}else{if(read('ge_sales_scope_v1')==='granted'){withdrawal=permission('revoke');withdrawal.catch(function(){banner();});}w['ga-disable-'+cfg.measurementId]=true;cookie('ge_growth_touch','',0);cookie('ge_growth_session','',0);clearGaCookies();if(w.gtag)w.gtag('consent','update',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});}
  }
  function emit(detail) {
    if(!granted || !detail || events.indexOf(detail.event)<0)return;
    // Existing funnel script already emits dataLayer. This adapter only exports
    // the documented event name and a path, never arbitrary event payloads.
    var key=detail.event+':'+(detail.request_id||detail.product_id||'');
    if(detail.event!=='add_to_cart' && seen[key])return;seen[key]=true;
    var payload={send_to:cfg.measurementId,page_location:w.location.origin+touch().landing,page_referrer:'',page_title:''};
    if(Number.isInteger(Number(detail.product_id)) && Number(detail.product_id)>0)payload.items=[{item_id:String(Number(detail.product_id))}];
    var names={product_view:'view_item',category_view:'view_item_list'};
    if(w.gtag && loaded)w.gtag('event',names[detail.event]||detail.event,payload);
  }
  w.geGrowth={setAnalyticsConsent:consent,emit:emit};
  // CMP is the authority: no banner and no implied acceptance is introduced.
  d.addEventListener('ge:analytics-consent',function(e){consent(e.detail === true);});
  d.addEventListener('graphex:funnel',function(e){emit(e.detail);});
  d.addEventListener('click',function(e){var a=e.target.closest && e.target.closest('a[data-funnel-event]');if(a)emit({event:a.dataset.funnelEvent});var contact=e.target.closest && e.target.closest('a[href]');if(contact && /^(https:\/\/wa\.me\/|tel:|mailto:)/.test(contact.getAttribute('href')||''))emit({event:'contact_click'});});
  // Woo translates classic jQuery additions to the public Blocks DOM event.
  // Coalesce the synchronous pair once; subsequent user actions get new tasks.
  var pendingAdd=null;
  function added(productId){if(pendingAdd){if(productId)pendingAdd.product_id=productId;return;}pendingAdd={event:'add_to_cart',product_id:productId};w.setTimeout(function(){var event=pendingAdd;pendingAdd=null;emit(event);},0);}
  d.addEventListener('wc-blocks_added_to_cart',function(){added();});
  if(w.jQuery)w.jQuery(d.body).on('added_to_cart',function(e,fragments,hash,button){added(button && button.data('product_id'));});
  function companyEvents(){var field=d.querySelector('.ge-quick-register input[name="cuenta"][value="empresa"]');if(field)emit({event:'company_account_started'});if(cfg.companyCompleted)emit({event:'company_account_created'});}
  if(cfg.analyticsConsent === true)consent(true);
  if(cfg.measurementReady!==true)return;
  var style=d.createElement('style');style.textContent=`
    .gx-cookie-panel{position:fixed;bottom:calc(12px + env(safe-area-inset-bottom,0px));left:12px;right:12px;z-index:99999;max-width:640px;max-height:50dvh;overflow:auto;box-sizing:border-box;padding:20px;background:var(--gx-paper,#f9f8f5);color:var(--gx-ink,#111116);border:1px solid var(--gx-line,#dddcdf);border-radius:16px;box-shadow:0 8px 32px #0002;font:400 15px/1.5 Jost,system-ui,sans-serif}
    .gx-cookie-panel h2{margin:0 0 8px;font:700 20px/1.2 Jost,system-ui,sans-serif;color:inherit}
    .gx-cookie-panel p{margin:0 0 12px;color:inherit;font:inherit}
    .gx-cookie-panel details{margin:0 0 12px}.gx-cookie-panel summary{cursor:pointer;min-height:32px}.gx-cookie-panel details p{margin-top:8px;font-size:14px}
    .gx-cookie-actions{display:flex;gap:8px;flex-wrap:wrap;position:sticky;bottom:-20px;background:var(--gx-paper,#f9f8f5);padding:8px 0}
    .gx-cookie-choice{min-height:44px;padding:10px 18px;border:1px solid var(--gx-ink,#111116);border-radius:10px;background:white;color:var(--gx-ink,#111116);font:600 15px/1.3 Jost,system-ui,sans-serif;cursor:pointer;flex:1}
    .gx-cookie-choice:last-child{background:var(--gx-ink,#111116);color:white}.gx-cookie-choice:disabled{opacity:.6;cursor:wait}
    .gx-cookie-prefs{display:inline-block;position:static;min-height:44px;padding:10px 0;margin:0 80px 0 0;border:0;background:transparent;color:inherit;font:inherit;text-decoration:underline;cursor:pointer}
    .gx-cookie-panel a{color:var(--gx-violet-deep,#443acc);text-decoration:underline}.gx-cookie-panel :focus-visible,.gx-cookie-prefs:focus-visible{outline:3px solid var(--gx-violet,#5b51f5);outline-offset:3px}
    .gx-whatsapp-float,.ge-whatsapp-float{bottom:calc(var(--gx-cookie-offset,24px) + env(safe-area-inset-bottom,0px))!important}
    @media(max-width:480px){.gx-cookie-panel{padding:16px;font-size:14px}.gx-cookie-panel h2{font-size:18px}.gx-cookie-actions{bottom:-16px}}
  `;d.head.appendChild(style);
  function syncFloating(){var panel=d.getElementById('gx-cookie-panel');d.documentElement.style.setProperty('--gx-cookie-offset',panel?(Math.ceil(panel.getBoundingClientRect().height)+28)+'px':'24px');}
  w.addEventListener('resize',syncFloating);
  function banner() {
    if(d.getElementById('gx-cookie-panel'))return;
    var box=d.createElement('section');box.id='gx-cookie-panel';box.className='gx-cookie-panel';box.setAttribute('aria-labelledby','gx-cookie-title');
    var title=d.createElement('h2');title.id='gx-cookie-title';title.textContent='Cookies y privacidad';box.appendChild(title);
    var text=d.createElement('p');text.textContent='¿Aceptás cookies de Google Analytics para entender cómo se usa el sitio y medir compras confirmadas? Es opcional. Podés rechazar o cambiar tu elección cuando quieras.';box.appendChild(text);
    var details=d.createElement('details'),summary=d.createElement('summary');summary.textContent='Qué datos usamos';details.appendChild(summary);
    var info=d.createElement('p');info.textContent='Si aceptás, también permitís enviar a Google los productos, la moneda y el importe de las compras con un identificador seudónimo, sin nombre, DNI ni correo. Recordamos la procedencia durante 30 días y tu elección durante 180 días. Rechazar o retirar el permiso detiene los nuevos envíos y elimina las cookies de Analytics; no borra lo que Google ya recibió.';details.appendChild(info);
    if(cfg.privacyUrl){var link=d.createElement('a');link.href=cfg.privacyUrl;link.textContent='Política de privacidad';details.appendChild(link);}box.appendChild(details);
    var actions=d.createElement('div');actions.className='gx-cookie-actions';box.appendChild(actions);
    function choice(label,value){var button=d.createElement('button');button.type='button';button.textContent=label;button.className='gx-cookie-choice';button.onclick=function(){Array.from(box.querySelectorAll('button')).forEach(function(b){b.disabled=true;});var hadSales=read('ge_sales_scope_v1')==='granted';consent(value);(value?permission('grant'):(hadSales?withdrawal:permission('revoke'))).then(function(){box.remove();syncFloating();prefs.focus({preventScroll:true});}).catch(function(){Array.from(box.querySelectorAll('button')).forEach(function(b){b.disabled=false;});text.setAttribute('role','alert');text.textContent='No pudimos guardar tu elección. Si rechazaste, las cookies de Analytics ya se desactivaron; volvé a intentar para detener también los envíos de compras pendientes.';});};actions.appendChild(button);}
    choice('Rechazar',false);choice('Aceptar',true);d.body.appendChild(box);
    syncFloating();if(w.ResizeObserver)new w.ResizeObserver(syncFloating).observe(box);
    actions.querySelector('button').focus({preventScroll:true});
  }
  var prefs=d.createElement('button');prefs.type='button';prefs.textContent='Cookies y privacidad';prefs.className='gx-cookie-prefs';prefs.onclick=banner;(d.querySelector('.gx-footer-bottom')||d.querySelector('footer')||d.body).appendChild(prefs);
  if(!read('ge_growth_consent') || !read('ge_sales_scope_v1'))banner();
})(window, document);



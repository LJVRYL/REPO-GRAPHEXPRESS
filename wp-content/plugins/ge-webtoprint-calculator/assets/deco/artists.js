(()=>{'use strict';
 const runtime=window.GRAPHEX_RUNTIME||{};
 if(runtime.creatorPortalUrl)document.querySelectorAll('[data-portal]').forEach(link=>{link.href=runtime.creatorPortalUrl;});
 const input=document.getElementById('artValue'),display=document.getElementById('artValueDisplay'),artist=document.getElementById('artistTotal'),buyer=document.getElementById('buyerTotal'),label=document.getElementById('artValueLabel'),rule=document.getElementById('calculationRule');
 const money=new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS',maximumFractionDigits:0});let mode='fixed';
 function update(){const value=Number(input.value),amount=mode==='fixed'?value:Math.round(30000*value/100);display.textContent=mode==='fixed'?money.format(value):value+'%';artist.textContent=money.format(amount);buyer.textContent=money.format(30000+amount);input.setAttribute('aria-valuetext',mode==='fixed'?money.format(value):value+' por ciento');}
 document.querySelectorAll('[data-mode]').forEach(button=>button.addEventListener('click',()=>{mode=button.dataset.mode;document.querySelectorAll('[data-mode]').forEach(item=>item.setAttribute('aria-pressed',String(item===button)));input.max=mode==='fixed'?'20000':'50';input.step=mode==='fixed'?'500':'1';input.value=mode==='fixed'?'5000':'20';label.textContent=mode==='fixed'?'Valor de tu obra':'Porcentaje sobre la base Graphex';rule.textContent=mode==='fixed'?'Un importe por tu obra, además de la base del cuadro.':'Tu porcentaje se calcula sobre la base de Graphex, con su ganancia incluida.';update();}));input.addEventListener('input',update);update();
 const flow=document.getElementById('paymentFlow'),toggle=document.getElementById('flowToggle'),reduced=window.matchMedia('(prefers-reduced-motion: reduce)');let paused=reduced.matches;
 function motion(){flow.dataset.playing=String(!paused);toggle.textContent=paused?'Reanudar animación':'Pausar animación';toggle.setAttribute('aria-pressed',String(paused));}
 toggle.addEventListener('click',()=>{paused=!paused;motion();});reduced.addEventListener('change',event=>{paused=event.matches;motion();});motion();
 if('IntersectionObserver'in window){new IntersectionObserver(entries=>{flow.dataset.visible=String(entries[0].isIntersecting);},{threshold:.15}).observe(flow);}
})();

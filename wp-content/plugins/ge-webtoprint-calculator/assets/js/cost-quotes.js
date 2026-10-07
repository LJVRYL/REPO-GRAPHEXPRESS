(function(){
 'use strict';if(!window.geCostQuotes)return;let pending=null,popup=null;
 const connected=new WeakSet();
 function connect(){document.querySelectorAll('[data-ge-lines] [data-ge-line]').forEach(line=>{
   if(connected.has(line))return;connected.add(line);const actions=line.querySelector('.ge-quote-line-actions');if(!actions)return;
   const quantity=line.querySelector('input[name$="[quantity]"]');if(!quantity)return;const prefix=quantity.name.replace(/\[quantity\]$/,'');const match=prefix.match(/\[(\d+)\]$/);
   const button=line.querySelector('[data-ge-calculate-cost]')||document.createElement('button');button.type='button';button.dataset.geCalculateCost='';button.textContent='Estimar costo y precio';
   const hidden=line.querySelector('input[name$="[cost_snapshot_id]"]')||document.createElement('input');hidden.type='hidden';hidden.name=prefix+'[cost_snapshot_id]';hidden.value=match?geCostQuotes.refs[match[1]]||'':'';
   const hint=line.querySelector('[data-ge-cost-private-hint]')||document.createElement('span');hint.dataset.geCostPrivateHint='';hint.textContent=hidden.value?'Costo interno asociado; no se muestra al cliente.':'';
   actions.prepend(button,hidden,hint);
 });}
 function clear(line){const hidden=line.querySelector('input[name$="[cost_snapshot_id]"]');if(hidden)hidden.value='';const hint=line.querySelector('[data-ge-cost-private-hint]');if(hint)hint.textContent='';}
 document.addEventListener('click',e=>{const button=e.target.closest('[data-ge-calculate-cost]');if(!button)return;const line=button.closest('[data-ge-line]');if(line.dataset.geSource!=='custom'){alert('Usá el cálculo de costo en un ítem personalizado.');return;}pending=line;const url=new URL(geCostQuotes.calculatorUrl,location.origin);url.searchParams.set('quantity',line.querySelector('input[name$="[quantity]"]').value);popup=window.open(url.href,'graphex-cost-calculator','width=1150,height=900');});
 window.addEventListener('message',event=>{
   if(event.origin!==location.origin||event.source!==popup||!pending||event.data?.type!=='ge-cost-result')return;
   const data=event.data;if(!data.result?.complete||data.result.currency!=='ARS')return;
   if(Number(pending.querySelector('input[name$="[quantity]"]').value)!==Number(data.result.input.quantity)){alert('La cantidad cambió. Volvé a calcular el costo.');return;}
   pending.querySelector('input[name$="[cost_snapshot_id]"]').value=data.snapshot_id;const price=pending.querySelector('input[name$="[unit_price]"]');price.value=(data.result.pricing.suggested_net/data.result.input.quantity).toFixed(2);price.dispatchEvent(new Event('input',{bubbles:true}));pending.querySelector('[data-ge-cost-private-hint]').textContent='Costo privado guardado; no se muestra al cliente.';pending=null;
 });
 document.addEventListener('input',e=>{const line=e.target.closest('[data-ge-line]');if(line&&e.target.name?.endsWith('[quantity]'))clear(line);});
 connect();const root=document.querySelector('[data-ge-lines]');if(root)new MutationObserver(connect).observe(root,{childList:true});
})();

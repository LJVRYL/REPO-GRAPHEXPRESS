'use strict';
(()=>{
 const C=GRAPHEX_CATALOG,$=id=>document.getElementById(id),fold=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 let data={collections:[],sources:[]},selected='destacadas',query='',orientation='all',visible=24,card,csrf=null;

 const favoriteKey='graphex-prints-favorites-v1',favoriteLimit=1000;
 function parseFavorites(raw){try{const value=JSON.parse(raw||'[]');return new Set(Array.isArray(value)?value.filter(id=>typeof id==='string'&&/^[a-z0-9-]{1,100}$/.test(id)).slice(0,favoriteLimit):[])}catch{return new Set()}}
 let favorites;try{favorites=parseFavorites(localStorage.getItem(favoriteKey))}catch{favorites=new Set()}
 const favoriteLive=document.createElement('p');favoriteLive.className='favorite-live';favoriteLive.setAttribute('role','status');favoriteLive.setAttribute('aria-live','polite');document.body.append(favoriteLive);
 const favoriteInfo=document.createElement('p');favoriteInfo.className='favorite-info';favoriteInfo.hidden=true;$('galleryCount').before(favoriteInfo);
 let favoriteStorageAvailable=true;
 const favoriteCount=()=>C.works.filter(w=>!w.uploaded&&w.catalog_hidden!==true&&favorites.has(w.id)).length;
 function updateFavoriteButton(b){const saved=favorites.has(b.dataset.favoriteId),title=b.dataset.favoriteTitle;b.setAttribute('aria-pressed',String(saved));b.setAttribute('aria-label',(saved?'Quitar '+title+' de':'Guardar '+title+' en')+' favoritos');b.title=b.getAttribute('aria-label');const label=b.querySelector('span');if(label)label.textContent=saved?'Guardado en favoritos':'Guardar en favoritos'}
 function paintFavorites(){document.querySelectorAll('[data-favorite-id]').forEach(updateFavoriteButton);const filter=document.querySelector('[data-favorites-filter]');if(filter)filter.textContent='Mis favoritos ('+favoriteCount()+')';favoriteInfo.hidden=selected!=='favoritos';favoriteInfo.textContent=favoriteStorageAvailable?'Se guardan en este navegador, sin necesidad de registrarte.':'Tu navegador no permite guardarlos: estarán disponibles sólo durante esta visita.'}
 function refreshFavorites(){const focused=document.activeElement?.dataset.favoriteId;paintFavorites();if(card&&selected==='favoritos'&&!$('gallery').hidden){results();if(focused){const buttons=[...$('grid').querySelectorAll('[data-favorite-id]')];(buttons.find(b=>b.dataset.favoriteId===focused)||buttons[0]||document.querySelector('[data-favorites-filter]'))?.focus({preventScroll:true})}}}
 function toggleFavorite(id){if(!favorites.has(id)&&favorites.size>=favoriteLimit){favoriteLive.textContent='Podés guardar hasta '+favoriteLimit+' obras en favoritos.';return}const saved=!favorites.has(id);if(saved)favorites.add(id);else favorites.delete(id);try{localStorage.setItem(favoriteKey,JSON.stringify([...favorites]));favoriteStorageAvailable=true}catch{favoriteStorageAvailable=false}favoriteLive.textContent=(saved?'Obra guardada en favoritos.':'Obra quitada de favoritos.')+(favoriteStorageAvailable?'':' Tu navegador no permite conservarlos al cerrar esta visita.');refreshFavorites()}
 function favoriteButton(work,full=false){if(work.uploaded)return null;const b=document.createElement('button');b.type='button';b.className='favorite-toggle'+(full?' favorite-product':'');b.dataset.favoriteId=work.id;b.dataset.favoriteTitle=work.title;const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d','M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z');svg.append(path);const label=document.createElement('span');label.hidden=!full;b.append(svg,label);b.onclick=()=>toggleFavorite(work.id);updateFavoriteButton(b);return b}
 window.addEventListener('storage',event=>{if(event.key!==favoriteKey&&event.key!==null)return;favorites=parseFavorites(event.newValue);refreshFavorites()});
 const defaultEmptyText=$('emptyGallery').querySelector('p').textContent,defaultClearText=$('clearGallery').textContent;

 const verified=new Set(),preparing=new Map();
 let activeOptions=null,commerceAvailable=true,publicPrices={},handling={};
 const runtime=window.GRAPHEX_RUNTIME,asset=url=>runtime&&url&&!/^(https?:|\/|blob:|data:)/.test(url)?runtime.assetBase+url:url;
 if(runtime){for(const work of C.works)work.image=asset(work.image);for(const template of C.templates||[])template.asset=asset(template.asset)}
 async function json(url,options={}){if(runtime){url=url.startsWith('/api/')?runtime.apiBase+url.slice(4):asset(url);options.headers={...options.headers,'X-WP-Nonce':runtime.wpNonce}}const response=await fetch(url,{credentials:'same-origin',...options,signal:AbortSignal.timeout(20000)});const result=await response.json();if(!response.ok)throw Error(response.status===429?'Estamos preparando otra imagen. Probá nuevamente en un momento.':result.code==='catalog_not_released'?'Esta obra todavía no está disponible para comprar.':'No pudimos completar esta acción. Probá nuevamente.');return result}
 const ready=(async()=>{
  try{
   data=await json('galleries.json'+(runtime?.catalogRevision?'?v='+runtime.catalogRevision:''));
   try{const creators=await json('/api/creators');data.works.push(...creators.works||[]);data.collections.push(...creators.collections||[])}catch{}
   if(runtime?.commerce){try{const released=await json('/api/options');activeOptions=new Set(released.option_ids||[]);commerceAvailable=released.commerce_available===true;publicPrices=released.unit_prices_cents||{};handling=released.handling_cents||{}}catch{activeOptions=new Set();commerceAvailable=false}}
   data.collections=data.collections.filter(collection=>collection.catalog_hidden!==true);
   for(const incoming of data.works){incoming.image=asset(incoming.image);const existing=C.works.find(w=>w.id===incoming.id);const w=existing?Object.assign(existing,incoming):incoming;w.remote=/^(artic|met|nga|bauhaus|trendy)-\d+$/.test(w.id)||w.creator_showcase===true;if(!existing)C.works.push(w)}
   try{const cached=await json('/api/artworks/prepared');for(const work of cached.works||[]){const existing=C.works.find(w=>w.id===work.id);if(existing&&existing.imageId===work.imageId)Object.assign(existing,work,{remote:true})}}catch{}
  }catch{data={collections:[{id:'destacadas',name:'Elegidas por Graphex',cover:C.works[0].id,count:C.works.length}],sources:[]};for(const w of C.works)w.collections=['destacadas'];}
 })();
 async function token(){if(!csrf)csrf=(await json('/api/session')).csrf;return csrf}
 async function post(url,body={}){return json(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':await token()},body:JSON.stringify(body)})}
 async function prepare(work,onStage){
  if(!work.remote||verified.has(work.id))return work;
  if(preparing.has(work.id))return preparing.get(work.id);
  const promise=(async()=>{
   const endpoint=work.creator_showcase?'/api/creator-work/':'/api/artworks/';let result=await post(endpoint+work.id+'/prepare');const started=Date.now();
   while(result.status==='queued'||result.status==='analyzing'){
    onStage?.(result.stage||'Preparando los tamaños disponibles');
    if(Date.now()-started>180000)throw Error('Esta obra está demorando un poco más. Podés volver a intentarlo en un momento.');
    await new Promise(resolve=>setTimeout(resolve,1500));result=await json(endpoint+work.id);
   }
   if(result.status!=='ready'||!result.work?.verified)throw Error(result.stage||'No pudimos preparar esta obra. Probá nuevamente.');
   Object.assign(work,result.work,{remote:true});verified.add(work.id);return work;
  })().finally(()=>preparing.delete(work.id));preparing.set(work.id,promise);return promise;
 }
 function link(text,url){const a=document.createElement('a');a.textContent=text;a.href=url;a.target='_blank';a.rel='noopener noreferrer';return a}
 function credits(work){
  const target=$('artworkCredit');target.replaceChildren();if(!work.source)return;
  const details=document.createElement('details'),summary=document.createElement('summary'),artist=document.createElement('p'),source=document.createElement('p'),license=document.createElement('p');
  summary.textContent='Obra y procedencia';artist.textContent=work.title+' · '+work.artist+(work.date?' · '+work.date:'');source.append(link(work.source.name+' ↗',work.source.url));license.append(link(work.source.license,work.source.licenseUrl));details.append(summary,artist,source,license);
  if(work.credit){const p=document.createElement('p');p.textContent=work.credit;details.append(p)}target.append(details);
 }
 function collections(){
  $('collections').replaceChildren();
  for(const collection of data.collections){
   const work=C.works.find(w=>w.id===collection.cover),a=document.createElement('a'),box=document.createElement('div'),im=document.createElement('img'),name=document.createElement('b'),count=document.createElement('span');
   if(!work)continue;a.href='#galeria/'+collection.id;a.className='collection-card';box.className='collection-image';im.src=work.image;im.alt='';im.loading='lazy';im.decoding='async';im.referrerPolicy='no-referrer';im.width=600;im.height=430;box.append(im);name.textContent=collection.name;count.textContent=collection.count+' obras';a.append(box,name,count);$('collections').append(a);
  }
  $('sourceLinks').replaceChildren(...data.sources.map(source=>link(source.name+' ↗',source.url)));
 }
 function filterWorks(){return C.works.filter(w=>{const ratio=w.pixels[0]/w.pixels[1],shape=ratio<.92?'portrait':ratio>1.08?'landscape':'square';return !w.uploaded&&w.catalog_hidden!==true&&(selected==='favoritos'?favorites.has(w.id):(selected==='todas'||w.collections?.includes(selected)))&&(!query||fold(w.title+' '+w.artist+' '+(w.category||'')).includes(fold(query)))&&(orientation==='all'||shape===orientation)})}
 function results(){
  const works=filterWorks(),shown=works.slice(0,visible);$('grid').replaceChildren(...shown.map(card));$('galleryCount').textContent=works.length?(shown.length===works.length?works.length+(works.length===1?' obra':' obras'):shown.length+' de '+works.length+' obras'):'No encontramos obras con esa búsqueda.';$('loadMore').hidden=visible>=works.length;$('emptyGallery').hidden=works.length>0;const emptyFavorites=selected==='favoritos'&&favoriteCount()===0;if(emptyFavorites)$('galleryCount').textContent='Todavía no guardaste favoritos.';$('emptyGallery').querySelector('p').textContent=emptyFavorites?'Marcá el corazón de las obras que te gusten para encontrarlas acá.':defaultEmptyText;$('clearGallery').textContent=emptyFavorites?'Explorar todas las obras':defaultClearText;
 }
 function render(makeCard){
  card=makeCard;const hash=location.hash.match(/^#galeria\/([a-z0-9-]+)$/),next=hash?hash[1]:location.hash==='#coleccion'?'todas':'destacadas';selected=next==='todas'||next==='favoritos'||data.collections.some(c=>c.id===next)?next:'destacadas';visible=24;
  collections();$('filters').replaceChildren();
  for(const collection of [{id:'todas',name:'Todas'},{id:'favoritos',name:'Mis favoritos ('+favoriteCount()+')'},...data.collections]){const b=document.createElement('button');b.textContent=collection.id==='destacadas'?'Destacadas':collection.name;if(collection.id==='favoritos')b.dataset.favoritesFilter='true';b.setAttribute('aria-pressed',String(selected===collection.id));b.onclick=()=>{location.hash='#galeria/'+collection.id};$('filters').append(b)}
  $('collectionTitle').textContent=selected==='favoritos'?'Mis favoritos.':selected==='todas'?'Todas las obras.':(data.collections.find(c=>c.id===selected)?.name||'Elegidas por Graphex')+'.';
  $('artSearch').value=query;$('artOrientation').value=orientation;paintFavorites();results();
 }
 $('artSearch').addEventListener('input',event=>{query=event.target.value;visible=24;if(card)results()});
 $('artOrientation').addEventListener('change',event=>{orientation=event.target.value;visible=24;if(card)results()});
 $('loadMore').onclick=()=>{visible+=24;results()};
 $('clearGallery').onclick=()=>{query='';orientation='all';$('artSearch').value='';$('artOrientation').value='all';if(selected==='favoritos'&&favoriteCount()>0){results();return}if(selected!=='todas')location.hash='#galeria/todas';else results()};
 window.GraphexGallery={ready,render,credits,prepare,favoriteButton,unitPrice:(id,work)=>work?.creator_showcase?work.unit_prices_cents?.[id]:publicPrices[id],handling:(work,optionId)=>work?.creator_showcase?work.handling_by_option_cents?.[optionId]||work.handling_cents||{}:handling,isVerified:id=>verified.has(id),isOptionAvailable:id=>!activeOptions||activeOptions.has(id),commerceAvailable:()=>commerceAvailable,currentGallery:()=>'#galeria/'+selected,verifySelection:(work,state)=>post((work.creator_showcase?'/api/creator-work/':'/api/artworks/')+work.id+'/selection',state),checkout:items=>post('/api/cart',{items})};
})();

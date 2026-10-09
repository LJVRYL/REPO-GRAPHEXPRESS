const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
 constructor(){this.listeners={};this.hidden=false;this.disabled=false;this.value='';this.dataset={};this.children=[];this.textContent='';}
 addEventListener(type,fn){(this.listeners[type]??=[]).push(fn);}
 dispatchEvent(event){event.target??=this;for(const fn of this.listeners[event.type]??[])fn(event);return true;}
 async fire(type,event={}){event.type=type;event.target??=this;await Promise.all((this.listeners[type]??[]).map(fn=>fn(event)));}
 append(...items){this.children.push(...items);}
 replaceChildren(){this.children=[];}
 scrollIntoView(){}
 matches(selector){return selector==='[data-ge-field]' && !!this.dataset.geField;}
}
(async()=>{
 const selectors=['connect','connected','status','designs','more','retry','refresh','forget'];
 const panel=new Element(), form=new Element(); const els=Object.fromEntries(selectors.map(name=>[name,new Element()]));
 panel.querySelector=selector=>els[selector.match(/data-canva-([a-z]+)/)?.[1]];
 const control=new Element();control.dataset.geField='impresion';control.value='doble';control.options=[{value:'doble'},{value:'simple'}];
 const file=new Element(),upload=new Element(),claims=new Element();
 form.querySelector=selector=>({'[data-ge-digital-files]':file,'[data-ge-digital-upload]':upload,'[data-ge-digital-claims]':claims}[selector]);
 form.querySelectorAll=()=>[control];
 let observer, releaseExport; const pendingExport=new Promise(resolve=>releaseExport=resolve);
 upload.click=()=>{file.dataset.uploadedFingerprint='fixture';claims.value='[{"claim":"fixture"}]';upload.disabled=false;observer.fn();};
 const calls=[];
 const context={console,Blob,FormData,URL,crypto:require('node:crypto').webcrypto,location:{href:'https://graphex.ar/product/tarjetas-express/',assign(){}},window:{geCardsCanva:true},geCardsCanva:{nonce:'fixture',ajaxUrl:'fixture'},
 document:{querySelector:selector=>selector==='.gxc-canva-panel'?panel:form,createElement:()=>new Element()},
 File:class extends Blob{constructor(parts,name,options){super(parts,options);this.name=name;}},
 DataTransfer:class{constructor(){this.files=[];this.items={add:file=>this.files.push(file)};}},
 Event:class{constructor(type){this.type=type;}},
 MutationObserver:class{constructor(fn){this.fn=fn;observer=this;}observe(){}disconnect(){}},
 setTimeout:(fn,ms)=>{if(ms===3000)queueMicrotask(fn);return 1;},clearTimeout(){},
 fetch:async(url,{body})=>{const op=body.get('action').replace('ge_customer_cards_design_','');calls.push(op);let data;
 if(op==='status')data={connected:true};
 else if(op==='designs')data={items:[{id:'fixture-design',title:'Own fixture'}]};
 else if(op==='export'){await pendingExport;data={job:'fixture-job'};}
 else if(op==='export_status')data={status:'ready'};
 else if(op==='pdf')return {ok:true,headers:{get:()=> 'application/pdf'},blob:async()=>new Blob(['%PDF-fixture'])};
 else if(op==='preflight')data={status:'review_required'};
 else throw new Error(op);
 return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({success:true,data})};
 }};
 vm.runInNewContext(readFileSync(require('node:path').join(__dirname,'../wp-content/mu-plugins/ge-cards-canva/customer.js'),'utf8'),context);
 await new Promise(resolve=>setImmediate(resolve));
 await els.refresh.fire('click');
 const bring=els.designs.children[0].children[2];
 const importing=bring.fire('click');
 assert.equal(control.disabled,true,'Configuration locked during export');
 let blocked=false;await form.fire('submit',{preventDefault(){blocked=true;}});assert.equal(blocked,true,'Busy import blocks purchase before job exists');
 releaseExport();await importing;
 assert.equal(control.disabled,false,'Configuration restored after import');
 assert.equal(form.children[0].value,'fixture-job','Imported PDF is associated with job');
 blocked=false;await form.fire('submit',{preventDefault(){blocked=true;}});assert.equal(blocked,false,'Valid analysis allows existing purchase flow');
 control.value='simple';await form.fire('change',{target:control});
 blocked=false;await form.fire('submit',{preventDefault(){blocked=true;}});assert.equal(blocked,true,'Changing page configuration blocks purchase');
 assert.match(els.status.textContent,/Esperá la revisión/);
 assert.deepEqual(calls,['status','designs','export','export_status','pdf','preflight']);
 console.log(JSON.stringify({passed:7,fixture_only:true,network_calls:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});

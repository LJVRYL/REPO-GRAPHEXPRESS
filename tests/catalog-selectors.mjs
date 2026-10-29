import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';import {execFileSync} from 'node:child_process';
const data=JSON.parse(execFileSync(process.argv[2],process.argv[3]?[process.argv[3],'--export']:['tests/catalog-selectors-fixture.php'],{encoding:'utf8'}));
for(const c of Object.values(data))for(const o of Object.values(c.options)){o.min_qty??=1;o.step??=1;}
const code=fs.readFileSync('wp-content/plugins/ge-webtoprint-calculator/assets/js/storefront.js','utf8');
const galleryCode=fs.readFileSync('wp-content/plugins/ge-webtoprint-calculator/assets/js/flags-product.js','utf8');
class Field{constructor(options){this.options=options;this.selectedIndex=0;this.events={};this.wrapper={hidden:false};}closest(){return this.wrapper;}get value(){return this.options[this.selectedIndex].value;}set value(v){this.selectedIndex=this.options.findIndex(o=>o.value===v);}addEventListener(k,f){(this.events[k]??=[]).push(f);}dispatchEvent(e){for(const f of this.events[e.type]??[])f(e);}querySelector(s){return this.options.find(o=>s==='option[value="'+o.value+'"]');}}
class Event{constructor(type){this.type=type;}}
let checked=0;
for(const [family,c] of Object.entries(data)){
 const select=new Field(Object.entries(c.options).map(([value,o])=>({value,dataset:{price:o.price,min:o.min_qty,step:o.step},textContent:o.label})));
 const fields=c.selectors.map(f=>new Field(Object.keys(f.options).map(value=>({value}))));
 const quantity={value:'1',events:{},addEventListener(k,f){this.events[k]=f;}},price={},base={},details={},submit={};
 const nodes={'[data-ge-option]':select,'[data-ge-quantity]':quantity,'[data-ge-price]':price,'[data-ge-base]':base,'[data-ge-option-details]':details,'.ge-storefront-submit':submit};
 const form={dataset:{options:JSON.stringify(c.options),optionMap:JSON.stringify(c.option_map),taxMultiplier:'1.21',netMultiplier:'1',priceDecimals:'2'},querySelector:s=>nodes[s]??null,querySelectorAll:()=>fields};
 const buttons=[{dataset:{kind:'mockup',src:'mockup',full:'mockup',label:'Mockup'}},...Object.keys(c.options).map(key=>({dataset:{kind:'render',gxOptions:key,src:key,full:key,label:key}}))];
 for(const b of buttons){b.attrs={'aria-pressed':b.dataset.kind==='mockup'?'true':'false'};b.getAttribute=k=>b.attrs[k];b.setAttribute=(k,v)=>b.attrs[k]=v;b.addEventListener=()=>{};}
 const image={removeAttribute(){}},full={},caption={},context={};const media={querySelector:s=>({'[data-gx-flags-image]':image,'[data-gx-flags-full]':full,'[data-gx-flags-caption]':caption,'[data-gx-flags-context]':context}[s]??null),querySelectorAll:()=>buttons};
 const document={querySelector:s=>s.includes('select[data-ge-option]')?select:null,querySelectorAll:s=>s==='[data-ge-storefront]'?[form]:s==='[data-gx-flags-media]'?[media]:[]};const window={location:{hash:''},addEventListener(){}};
 const ctx=vm.createContext({document,window,Intl,Event});vm.runInContext(code,ctx);vm.runInContext(galleryCode,ctx);
 for(const [combination,key] of Object.entries(c.option_map)){
  const choices=combination.split('|');for(let n=0;n<fields.length;n++){fields[n].value=choices[n];fields[n].dispatchEvent(new Event('change'));}
  const o=c.options[key];assert.equal(select.value,key,family+' '+combination);assert.equal(submit.disabled,false);assert.equal(Number(quantity.value),o.min_qty);assert.equal(Number(quantity.step),o.step);
  for(const field of fields)assert.equal(field.wrapper.hidden,field.value==='__none__','only relevant fields remain visible');
  const money=new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS',minimumFractionDigits:2,maximumFractionDigits:2});assert.equal(price.textContent,money.format(o.price*o.min_qty)+' + IVA');assert.equal(base.textContent,'Total con IVA: '+money.format(o.price*o.min_qty*1.21));
  for(const b of buttons)assert.equal(b.hidden,b.dataset.kind!=='mockup'&&b.dataset.gxOptions!==key,'gallery follows exact key');
  checked++;
 }
}
console.log(JSON.stringify({status:'PASS',families:Object.keys(data).length,reachable_native_options:checked,price_and_quantity_rules:true,gallery_change_events:true}));

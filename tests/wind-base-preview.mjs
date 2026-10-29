import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const code=fs.readFileSync('wp-content/plugins/ge-webtoprint-calculator/assets/js/storefront.js','utf8');
class Field{constructor(options){this.options=options;this.selectedIndex=0;this.events={};}get value(){return this.options[this.selectedIndex].value;}set value(v){this.selectedIndex=this.options.findIndex(o=>o.value===v);}addEventListener(k,f){this.events[k]=f;}change(v){this.value=v;this.events.change();}}
const flags=new Field([{value:'wb-161',textContent:'320 · Simple faz',dataset:{price:81900,min:2,step:1}},{value:'wb-174',textContent:'520 · Simple faz',dataset:{price:163800,min:2,step:1}}]);
const bases=new Field([{value:'',dataset:{}},{value:'wb-200',dataset:{price:15210,maxHeight:0}},{value:'wb-203',dataset:{price:9360,maxHeight:320}}]);
const qty={value:2,events:{},addEventListener(k,f){this.events[k]=f;}},price={},total={},summary={};
const nodes={'[data-ge-option]':flags,'[data-ge-wind-base]':bases,'[data-ge-wind-base-summary]':summary,'[data-ge-quantity]':qty,'[data-ge-price]':price,'[data-ge-base]':total};
const form={dataset:{priceDecimals:'2',taxMultiplier:'1.21',options:'{}'},querySelector:s=>nodes[s]??null,querySelectorAll:()=>[]};
vm.runInNewContext(code,{document:{querySelectorAll:()=>[form]},Intl,window:{}});
const money=n=>new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS',minimumFractionDigits:2,maximumFractionDigits:2}).format(n);
assert.equal(price.textContent,money(163800)+' + IVA');bases.change('wb-200');assert.equal(price.textContent,money(194220)+' + IVA');assert.equal(total.textContent,'Total con IVA: '+money(235006.2));assert.match(summary.textContent,/2 windbanners \+ 2 bases/);
qty.value=4;qty.events.input();assert.equal(price.textContent,money(388440)+' + IVA');assert.match(summary.textContent,/4 windbanners \+ 4 bases/);
bases.change('wb-203');flags.change('wb-174');assert.equal(bases.value,'');assert.equal(bases.options[2].disabled,true);assert.equal(price.textContent,money(327600)+' + IVA');
flags.change('wb-161');assert.equal(bases.options[2].disabled,false);assert.equal(bases.value,'');
console.log(JSON.stringify({status:'PASS',optional_no_base:true,ratio_1_to_1:true,net_and_iva_totals:true,incompatible_stake_reset:true}));

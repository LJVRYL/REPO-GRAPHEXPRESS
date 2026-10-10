import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const root=new URL('../wp-content/plugins/ge-webtoprint-calculator/',import.meta.url);
const rows=JSON.parse(fs.readFileSync(new URL('data/windbanners-catalog.json',root),'utf8')).filter(r=>r.active);
const code=fs.readFileSync(new URL('assets/js/storefront.js',root),'utf8');
let checks=0;
function fixture(row,legacy=false){
  const handlers={};
  const option={value:row.option_key,dataset:{price:String(row.price),min:String(row.min_qty),step:String(row.step)}};
  const select={options:[option],selectedIndex:0,value:row.option_key,addEventListener:(k,f)=>handlers[k]=f};
  const qty={value:'1',addEventListener:(k,f)=>handlers['qty-'+k]=f};
  const price={textContent:''},base={textContent:''},details={textContent:''};
  const nodes={'[data-ge-option]':select,'[data-ge-quantity]':qty,'[data-ge-price]':price,'[data-ge-base]':base,'[data-ge-option-details]':details};
  const form={dataset:{options:JSON.stringify({[row.option_key]:{...row,source_id:row.id}}),priceDecimals:legacy?'0':'2',taxMultiplier:legacy?'1.21':'1'},querySelectorAll:()=>[],querySelector:k=>nodes[k]??null};
  vm.runInNewContext(code,{document:{querySelectorAll:()=>[form]},Intl,window:{},console});
  return {qty,price,base,details,handlers};
}
const money=new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS',minimumFractionDigits:2,maximumFractionDigits:2});
for(const row of rows){
  const f=fixture(row);assert.equal(Number(f.qty.value),row.min_qty);assert.equal(Number(f.qty.step),row.step);
  assert.equal(f.price.textContent,money.format(row.price*row.min_qty));assert.equal(f.base.textContent,'Base sin IVA: '+money.format(row.price*row.min_qty));
  f.qty.value=String(row.min_qty+row.step);f.handlers['qty-input']();assert.equal(f.price.textContent,money.format(row.price*(row.min_qty+row.step)));checks+=5;
}
const old=fixture({option_key:'legacy',id:null,price:1000,min_qty:1,step:1},true);assert.match(old.price.textContent,/1[.]210/);checks++;
console.log(JSON.stringify({passed:checks,active_options:rows.length,legacy_tax_unchanged:true}));

'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.resolve(__dirname,'../../../wp-content/plugins/ge-webtoprint-calculator/assets/deco/upload.js'),'utf8');
function harness(stall){
 const nodes=new Map(),calls=[],ready=[];let firstPoll=true;
 class Element{constructor(){this.textContent='';this.files=[];this.disabled=false;this.hidden=false;this.value='';this.classList={toggle(){},add(){},remove(){}};}querySelectorAll(){return []}addEventListener(){}click(){this.clicked=true}}
 const get=id=>{if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id)};
 const reply=data=>({ok:true,status:200,json:async()=>data});
 const fetch=async(url,options={})=>{calls.push({url,method:options.method||'GET'});
  if(url.endsWith('/session'))return reply({authenticated:true,csrf:'synthetic',upload:{ajaxUrl:'https://qa.invalid/prepare',nonce:'synthetic'}});
  if(url.endsWith('/prepare'))return reply({success:true,data:{uploads:[{url:'https://qa.invalid/put',token:'synthetic'}]}});
  if(url.endsWith('/put'))return reply({success:true});
  if(url.endsWith('/uploads'))return reply(options.method==='POST'?{id:'synthetic-job'}:{works:[]});
  if(url.endsWith('/uploads/synthetic-job')){if(firstPoll){firstPoll=false;if(stall==='headers')return new Promise(()=>{});if(stall==='body')return {ok:true,status:200,json:()=>new Promise(()=>{})};}return reply({status:'ready',has_sizes:true,work:{id:'tu-imagen-synthetic'}})}
  throw Error('Unexpected fixture URL');
 };
 const location={hash:'#tu-imagen'};
 vm.runInNewContext(source,{window:{GRAPHEX_RUNTIME:{apiBase:'https://qa.invalid',wpNonce:'synthetic',accountUrl:'/account'}},document:{getElementById:get,dispatchEvent:event=>ready.push(event.detail)},CustomEvent:class{constructor(name,options){this.detail=options.detail}},location,fetch,AbortController,URL:{createObjectURL:()=> 'blob:synthetic',revokeObjectURL(){}},URLSearchParams,clearTimeout,setTimeout:(fn,delay)=>setTimeout(fn,delay>=20000?5:delay)});
 return {get,calls,ready,location};
}
(async()=>{
 for(const stall of ['headers','body']){
  const h=harness(stall);await new Promise(setImmediate);
  h.get('uploadFile').files=[{name:'synthetic.jpg',type:'image/jpeg',size:1234}];
  await h.get('uploadFile').onchange();
  assert.match(h.get('uploadError').textContent,/demorando/);
  assert.equal(h.get('uploadFile').disabled,false,'hung response releases file input');
  assert.equal(h.get('retryUpload').hidden,false,'retry available');
  await h.get('retryUpload').onclick();
  assert.equal(h.calls.filter(c=>c.url.endsWith('/prepare')).length,1);
  assert.equal(h.calls.filter(c=>c.url.endsWith('/put')).length,1,'retry must not transfer file again');
  assert.equal(h.calls.filter(c=>c.url.endsWith('/uploads')&&c.method==='POST').length,1,'reuse existing job');
  assert.equal(h.ready.length,1);
  assert.equal(h.location.hash,'#cuadro/tu-imagen-synthetic');
 }
 console.log(JSON.stringify({passed:16,scenarios:['personal upload stalled headers','personal upload stalled body','same-job retry without duplicate transfer'],network_requests:0}));
})().catch(error=>{console.error(error);process.exitCode=1});

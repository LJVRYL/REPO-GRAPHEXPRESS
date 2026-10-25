'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.resolve(__dirname,'../../../wp-content/plugins/ge-webtoprint-calculator/assets/deco/creators.js'),'utf8');
function harness(legacy=false){
 const nodes=new Map(),calls=[];let failPoll=true;
 class Element{constructor(id){this.id=id;this.textContent='';this.disabled=false;this.checked=false;this.files=[];this.children=[];this.dataset={};this._value='';}get value(){return this._value}set value(v){this._value=v;if(this.id==='gc-file'&&v==='')this.files=[];}append(...items){this.children.push(...items)}replaceChildren(...items){this.children=items}setAttribute(){}insertAdjacentElement(position,item){nodes.set(item.id,item)}querySelectorAll(){return[]}focus(){this.focused=true}close(){this.open=false}showModal(){this.open=true}}
 function get(id){if(id==='gc-review'||(legacy&&['gc-file-status','gc-upload-submit'].includes(id)&&!nodes.has(id)))return null;if(!nodes.has(id))nodes.set(id,new Element(id));return nodes.get(id)}
 const profile={works:[],library:[],profile:{title:'Synthetic',bio:'',public:false,collections:{works:'Works'}},gallery_url:'http://qa.invalid/gallery',drive_configured:false};
 const response=data=>({ok:true,status:200,json:async()=>data});
 const fetch=async(url,options={})=>{calls.push({url,method:options.method||'GET'});
  if(url.endsWith('/creator'))return response(profile);
  if(url.endsWith('/session'))return response({upload:{ajaxUrl:'http://qa.invalid/prepare',nonce:'synthetic'}});
  if(url.endsWith('/prepare'))return response({success:true,data:{uploads:[{url:'http://qa.invalid/upload',token:'synthetic-claim'}]}});
  if(url.endsWith('/upload'))return response({success:true});
  if(url.endsWith('/uploads'))return response({id:'synthetic-job'});
  if(url.endsWith('/uploads/synthetic-job')){if(failPoll){failPoll=false;throw Error('Synthetic network interruption')}return response({status:'ready',artwork_library_id:123});}
  if(url.endsWith('/creator/from-library'))return response({id:123});throw Error('Unexpected mock request');
 };
 vm.runInNewContext(source,{window:{GE_CREATOR_PORTAL:{api:'http://qa.invalid',nonce:'synthetic',csrf:'synthetic'}},document:{getElementById:get,querySelectorAll:()=>[],createElement:tag=>new Element(tag)},fetch,location:{search:''},URLSearchParams,URL,Intl,setTimeout,console});
 return {get,calls};
}
(async()=>{
 const h=harness();await new Promise(setImmediate);const file={name:'own-test.jpg',size:1234,type:'image/jpeg'};h.get('gc-file').files=[file];h.get('gc-file').onchange();
 const transfers=()=>h.calls.filter(c=>c.url.endsWith('/upload'));
 assert.equal(transfers().length,0,'choosing a file must not transfer it before consent');
 await h.get('gc-upload-submit').onclick();assert.match(h.get('gc-import-error').textContent,/título/);assert.equal(h.get('gc-file').files[0],file);assert.equal(h.get('gc-upload-submit').disabled,false);
 h.get('gc-import-title-input').value='Own synthetic work';await h.get('gc-upload-submit').onclick();assert.match(h.get('gc-import-error').textContent,/Confirm/);assert.equal(transfers().length,0,'rights must be confirmed before transfer');
 h.get('gc-rights').checked=true;await h.get('gc-upload-submit').onclick();assert.match(h.get('gc-import-error').textContent,/interruption/);assert.equal(transfers().length,1);
 await h.get('gc-upload-submit').onclick();assert.equal(transfers().length,1,'resume an existing analysis rather than uploading a duplicate');assert.equal(h.calls.filter(c=>c.url.endsWith('/uploads')).length,1,'reuse the registered upload job');assert.equal(h.calls.filter(c=>c.url.endsWith('/creator/from-library')).length,1);assert.equal(h.get('gc-file').files.length,0);assert.equal(h.get('gc-rights').checked,false,'ask for rights again for the next artwork');assert.equal(h.get('gc-upload-submit').disabled,true);
 const legacy=harness(true);await new Promise(setImmediate);assert.equal(typeof legacy.get('gc-upload-submit').onclick,'function','new JS accepts the old template');assert.equal(legacy.get('gc-upload-submit').disabled,true);legacy.get('gc-file').files=[file];legacy.get('gc-file').onchange();assert.equal(legacy.get('gc-upload-submit').disabled,false);assert.equal(legacy.calls.filter(c=>c.url.endsWith('/upload')).length,0);
 console.log(JSON.stringify({passed:18,scenarios:['file first','missing title retry','missing rights','interrupted analysis resume','no duplicate upload or registration','consent reset'],network_requests:0}));
})().catch(error=>{console.error(error.message);process.exitCode=1});


const {chromium}=require('/mnt/c/Users/Leo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('fs'),path=require('path');const base='http://127.0.0.1:18785';
const pixel=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6KCIAAAAASUVORK5CYII=','base64');
function check(ok,name){console.log((ok?'PASS ':'FAIL ')+name);if(!ok)throw Error(name);}
(async()=>{
const browser=await chromium.launch({headless:true,executablePath:'/home/leo/.cache/ms-playwright/chromium-1247/chrome-linux64/chrome',args:['--no-sandbox']});
const page=await browser.newPage({viewport:{width:1440,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto(base+'/edit');const lines=page.locator('[data-ge-line]');check(await lines.count()===3,'three quote items');
async function add(i,url,name,note=''){const block=lines.nth(i);await block.locator('[data-ge-add-link]').click();await block.locator('[data-ge-link-url]').fill(url);await block.locator('[data-ge-link-name]').fill(name);await block.locator('[data-ge-link-notes]').fill(note);await block.locator('[data-ge-save-link]').click();await block.locator('[data-ge-link-form]').waitFor({state:'hidden'});}
await add(0,'https://drive.google.com/file/d/FAKE_QA_REFERENCE/view?resourcekey=qa','Multiplex · Frente','Enlace QA de referencia; no producir');
await add(0,'https://www.dropbox.com/s/qa/artwork.pdf?dl=0','Multiplex · Dorso');
await add(1,'https://we.tl/t-qa-reference','Cartelería');
await add(2,'https://www.w3.org/People/mimasa/test/imgformat/img/w3c_home.png','Archivo público QA');
check(await lines.nth(0).locator('[data-ge-external-id]').count()===2,'multiple links same item');check(await lines.nth(1).locator('[data-ge-external-id]').count()===1,'different links distinct items');
await lines.nth(0).locator('[data-ge-artwork-select]').setInputFiles({name:'qa-upload.png',mimeType:'image/png',buffer:pixel});await page.waitForFunction(()=>document.querySelectorAll('[data-ge-artwork-list] input[type=hidden]').length===5);
check(await lines.nth(0).locator('[data-ge-artwork-list] li').count()===3,'upload plus links');
await lines.nth(0).locator('[data-ge-add-link]').click();await lines.nth(0).locator('[data-ge-link-url]').fill('javascript:alert(1)');await lines.nth(0).locator('[data-ge-save-link]').click();check((await lines.nth(0).locator('[data-ge-link-error]').innerText()).includes('http'),'invalid URL rejected in UI');await lines.nth(0).locator('[data-ge-cancel-link]').click();
await lines.nth(2).locator('[data-ge-import-link]').click();await page.waitForFunction(()=>!document.querySelector('[data-ge-line]:nth-child(3) [data-state="uploading"]'),{},{timeout:60000});
const publicNotice=await lines.nth(2).locator('[data-ge-external-notice]').innerText();console.log('IMPORT_RESULT='+publicNotice);check(publicNotice.includes('Importado'),'real public server-side import');check(await lines.nth(2).locator('[data-ge-artwork-list] input[type=hidden]').count()===2,'local version added beside provenance');
await lines.nth(0).locator('[data-ge-artwork]').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(__dirname,'../outputs/external-links-desktop.png')});
await page.setViewportSize({width:390,height:844});await lines.nth(0).locator('[data-ge-artwork]').scrollIntoViewIfNeeded();check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile no overflow');await page.screenshot({path:path.join(__dirname,'../outputs/external-links-mobile.png')});
await page.setViewportSize({width:1440,height:1100});await Promise.all([page.waitForURL(u=>!u.pathname.includes('/edit')),page.locator('button[name=quote_intent][value=draft]').click()]);
const id=new URL(page.url()).searchParams.get('quote_id');let stored=await(await page.request.get(base+'/inspect?quote_id='+id)).json();check(stored.files.length===6,'save preserves four links and two local files');
check(stored.files.filter(r=>r.source_type==='external_link').every(r=>!r.file_analysis_ref),'pure links never analyzed');const imported=stored.files.find(r=>r.source_external_ref);check(!!imported.file_analysis_ref&&!!imported.checksum_sha256,'real import canonical analysis and checksum');
await page.goto(base+'/edit?quote_id='+id);check(await lines.nth(0).locator('[data-ge-external-id]').count()===2,'edit retains links');await add(1,'https://1drv.ms/u/s!qa-reference','OneDrive QA');
await lines.nth(0).locator('[data-ge-external-id]').nth(1).getByRole('button',{name:'Quitar vínculo'}).click();await Promise.all([page.waitForURL(u=>!u.pathname.includes('/edit')),page.locator('button[name=quote_intent][value=draft]').click()]);
stored=await(await page.request.get(base+'/inspect?quote_id='+id)).json();check(stored.files.length===7,'detach retains historical reference');check(stored.files.filter(r=>r.association_status==='detached').length===1,'one link detached without destruction');
await page.goto(base+'/edit?quote_id='+id);check(await page.locator('[data-ge-external-id]').count()===4,'active links only in edit');check(errors.length===0,'no browser errors');
fs.writeFileSync(path.join(__dirname,'qa-browser-context.json'),JSON.stringify({quote_id:Number(id),imported_id:imported.id,link_id:imported.source_external_ref}));await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});

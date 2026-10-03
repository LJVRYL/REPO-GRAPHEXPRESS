import json,urllib.request,urllib.parse,urllib.error,uuid
from pathlib import Path
root=Path(__file__).resolve().parent;base='http://127.0.0.1:18785'
ctx=json.load(urllib.request.urlopen(base+'/context'))
def post(data,path='/wp-admin/admin-ajax.php'):
    req=urllib.request.Request(base+path,urllib.parse.urlencode(data,doseq=True).encode())
    try:
        with urllib.request.urlopen(req,timeout=70)as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:return e.code,json.load(e)
def check(ok,label):
    print(('PASS 'if ok else'FAIL ')+label,flush=True)
    if not ok:raise AssertionError(label)
def fields(**more):return dict(action='ge_external_artwork',nonce=ctx['nonce'],op='add',ref_id=str(uuid.uuid4()),session_id=ctx['session_id'],quote_id=ctx['quote_id'],url='https://example.org/art.pdf',**more)
data=fields();s,r=post(data);check(s==200 and r['success'],'save URL without remote request');check(r['data']['record']['access_status']=='unverified','initial access unverified');
s,r2=post(data);check(r2['data']['record']['id']==r['data']['record']['id'],'add retry idempotent');
data['url']='https://example.org/other.pdf';s,r=post(data);check(s==409,'different payload same id rejected');
data=fields();data['nonce']='bad';s,r=post(data);check(s==403,'invalid nonce rejected');
data=fields();s,r=post(data,'/unauthorized');check(s==403,'anonymous import denied');
for url in ['javascript:alert(1)','file:///etc/passwd','http://127.0.0.1/x','http://169.254.169.254/x','https://user:password@example.com/']:
    d=fields();d['url']=url;s,r=post(d);check(s==422 and not r['success'],'unsafe URL rejected server-side')
d=fields();d['url']='http://127.0.0.1.nip.io/private';s,r=post(d);check(s==200,'domain link saved with unverified access');d['op']='import';s,r=post(d);check(s==422 and not r['success'] and 'pública' in r['data']['message'],'private DNS destination denied before fetch');
d=fields();d['url']='https://www.w3.org/';s,r=post(d);d['op']='import';s,r=post(d);check(s==422 and r['data']['record']['access_status']=='requires_access','HTML is not imported as artwork');check(not r['data']['record']['file_analysis_ref'],'HTML never analyzed');
bc=json.loads((root/'qa-browser-context.json').read_text());d=dict(action='ge_external_artwork',nonce=ctx['nonce'],op='import',ref_id=bc['link_id'],quote_id=bc['quote_id']);s,r=post(d);check(s==200 and r['data']['local']['id']==bc['imported_id'],'saved import retry returns same immutable local version');
d['quote_id']=0;d['session_id']=str(uuid.uuid4());s,r=post(d);check(s==403,'foreign session reference denied');
print('HTTP_QA_COMPLETE')

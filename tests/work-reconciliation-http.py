"""Actual loopback HTTP controls with synthetic identities and isolated private state."""
from pathlib import Path
import contextlib, json, os, re, socket, subprocess, time, uuid, urllib.request, urllib.parse, urllib.error
php=Path('C:/Users/Leo/Documents/Codex/runtimes/php-8.4.26-nts-win64/php.exe')
root=Path(__file__).resolve().parent
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
opener=urllib.request.build_opener(NoRedirect)
checks=0
def check(yes,label):
    global checks
    assert yes,label
    checks+=1
scratch=root.parent.parent/'synthetic-http-state'
scratch.mkdir(exist_ok=True)
with contextlib.nullcontext(str(scratch)) as temp:
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    state_path=Path(temp)/('state-'+str(uuid.uuid4())+'.json')
    env=dict(os.environ,GE_RECON_TEST_STATE=str(state_path))
    process=subprocess.Popen([str(php),'-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    def request(query='',fields=None):
        data=None if fields is None else urllib.parse.urlencode(fields).encode()
        req=urllib.request.Request(f'http://127.0.0.1:{port}/work-reconciliation-http-fixture.php?'+query,data=data)
        try:r=opener.open(req,timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.headers,r.read().decode('utf-8')
    try:
        for _ in range(60):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        code,headers,body=request();check(code==200,'owner page');check('private, no-store' in headers.get('Cache-Control',''),'private cache');check('0 grupos registrados' in body,'empty initial state')
        check(request('actor=0')[0]==403,'anonymous denied')
        check(request('disabled=finance')[0]==403,'missing module denied')
        check(request('route=handle',{'operation':'preview'})[0]==403,'bad nonce denied')
        check(request('route=handle&actor=0',{'_wpnonce':'synthetic-nonce','operation':'preview'})[0]==403,'anonymous write denied')
        doc={'schema_version':1,'organization_id':'graph-express','source_sha256':'a'*64,'rows':[{'key':'test-work','customer':'Cliente Ñ','reference':'#TEST','order_id':1,'quote_id':0,'commercial':'performed','production':'completed','delivery':'completed','payment':'fully_paid','reference_cents':12345,'performed_cents':12345,'collected_cents':12345,'note':'Texto <script>alert(1)</script>','declarant':'Leo'}]}
        code,headers,body=request('route=handle',{'_wpnonce':'synthetic-nonce','operation':'preview','document':json.dumps(doc)})
        check(code==303,'preview redirects: '+str(code)+' '+body[:200]);token=urllib.parse.parse_qs(urllib.parse.urlparse(headers['Location']).query)['review'][0]
        code,headers,body=request('review='+token);check(code==200 and 'Revisar antes de publicar' in body,'preview page');check('<script>' not in body,'no script injection');check('Cliente Ñ' in body,'UTF8 preserved')
        fields={'_wpnonce':'synthetic-nonce','operation':'publish','review':token}
        check(request('route=handle',fields)[0]==409,'confirmation required')
        fields['confirmed']='1';check(request('route=handle',fields)[0]==303,'publish succeeds')
        code,headers,body=request();check('1 grupos registrados' in body and 'Cobrado completo' in body,'published readback');check('Pedido original' in body,'source retained')
        check(request('route=handle',fields)[0]==409,'consumed preview cannot replay')
        check(request('route=download',{'_wpnonce':'bad'})[0]==403,'backup nonce')
        code,headers,body=request('route=download',{'_wpnonce':'synthetic-nonce'});check(code==200,'backup success');state=json.loads(body);check(len(state['revisions'])==1,'only one revision');check(headers['Content-Type'].startswith('application/json'),'backup JSON header')
        fields={'_wpnonce':'synthetic-nonce','operation':'rollback','target':'empty','expected':state['revisions'][-1]['id']}
        check(request('route=handle',fields)[0]==303,'rollback succeeds');check('0 grupos registrados' in request()[2],'rollback readback')
        state=json.loads(state_path.read_text());check(len(state['revisions'])==2 and state['revisions'][0]['rows'],'immutable prior revision retained')
        print(json.dumps({'status':'PASS','http_checks':checks,'data':'synthetic_only','production_mutations':0}))
    finally:
        process.terminate();process.wait(timeout=10)
        for path in (state_path,Path(str(state_path)+'.preview')):
            assert path.resolve().parent==scratch.resolve()
            path.unlink(missing_ok=True)

import requests,uuid,json,base64,hashlib
from pathlib import Path
base=Path(__file__).parent
url='http://127.0.0.1:18765'
ctx=requests.get(url+'/context').json()
s=requests.Session();count=0
def check(ok,name):
    global count
    print(('PASS ' if ok else 'FAIL ')+name,flush=True);count+=1
    if not ok:raise RuntimeError(name)
def post(op,id,extra={},chunk=None,endpoint='/wp-admin/admin-ajax.php'):
    data={'action':'ge_quote_artwork_v2','nonce':ctx['nonce'],'op':op,'upload_id':id,'session_id':ctx['session_id'],'quote_id':ctx['quote_id']};data.update(extra)
    r=s.post(url+endpoint,data=data,files={'chunk':('chunk.bin',chunk,'application/octet-stream')} if chunk is not None else None)
    try:return r,r.json()
    except Exception:print(r.status_code,r.text[:150]);raise
pixel=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6KCIAAAAASUVORK5CYII=')
records=[]
def upload(name,size):
    id=str(uuid.uuid4());r,start=post('start',id,{'name':name,'size':size});check(start['success'],'start '+name)
    offset=0;digest=hashlib.sha256();first=None
    while offset<size:
        length=min(8388608,size-offset);chunk=(pixel+b'\0'*(length-len(pixel))) if offset==0 else b'\0'*length
        if offset==0:first=chunk
        digest.update(chunk);r,result=post('chunk',id,{'offset':offset},chunk);check(result['success'] and result['data']['offset']==offset+length,'chunk '+name+' '+str(offset))
        if offset==0:
            r,retry=post('chunk',id,{'offset':0},first);check(retry['success'] and retry['data']['offset']==length,'same block retry idempotent')
            bad=bytearray(first);bad[-1]^=1;r,conflict=post('chunk',id,{'offset':0},bytes(bad));check(r.status_code==409 and not conflict['success'],'changed block retry rejected')
        offset+=length
    r,finish=post('finish',id);check(finish['success'],'finish '+name);record=finish['data']['record'];check('stored_name' not in record,'private path omitted');check(bool(record.get('file_analysis_ref')),'analyzer automatically queued')
    r,retry=post('finish',id);check(retry['success'] and retry['data']['record']['id']==record['id'],'finish retry idempotent')
    records.append({'id':id,'name':name,'size':size,'sha256':digest.hexdigest()});return id
ids=[upload('qa-item-a.png',len(pixel)),upload('qa-item-b.png',len(pixel)),upload('qa-item-c.png',len(pixel)),upload('qa-item-a-extra.png',len(pixel))]
large=upload('qa-large-233MiB.png',244461000);ids.append(large)
r,result=post('start',str(uuid.uuid4()),{'name':'qa-too-big.png','size':262144001});check(r.status_code==422 and '250' in result['data']['message'],'over max friendly error')
r,result=post('start',str(uuid.uuid4()),{'name':'qa.exe','size':1});check(r.status_code==422,'extension rejected')
r,result=post('start',str(uuid.uuid4()),{'name':'qa.png','size':1,'nonce':'bad'});check(r.status_code==403,'nonce enforced')
r,result=post('start',str(uuid.uuid4()),{'name':'qa.png','size':1},endpoint='/unauthorized');check(r.status_code==403,'auth enforced')
id=str(uuid.uuid4());post('start',id,{'name':'qa-malformed.png','size':100});r,result=post('finish',id);check(r.status_code==409,'incomplete upload rejected');r,result=post('chunk',id,{'offset':-1},b'bad');check(r.status_code==422,'malformed offset rejected')
id=str(uuid.uuid4());post('start',id,{'name':'qa-fake.png','size':4});post('chunk',id,{'offset':0},b'fake');r,result=post('finish',id);check(r.status_code==422,'MIME mismatch rejected')
base.joinpath('qa-upload-records.json').write_text(json.dumps({'records':records,'ids':ids,'ctx':ctx},indent=2))
print('HTTP_CHECKS='+str(count),flush=True)

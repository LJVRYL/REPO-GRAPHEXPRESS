"""Exercise real resolver/download code over isolated local PHP HTTP, with synthetic data."""
import contextlib, os, pathlib, socket, subprocess, tempfile, urllib.request, urllib.error, urllib.parse, json, time
root=pathlib.Path(__file__).resolve().parent
php=pathlib.Path('C:/Users/Leo/Documents/Codex/runtimes/php-8.4.26-nts-win64/php.exe')
private_dir=root.parents[1]/'invoice-download-qa-private'
private_dir.mkdir(exist_ok=True)
with contextlib.nullcontext(str(private_dir)) as private:
 data=b'%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF\n'
 pathlib.Path(private,'synthetic-original.pdf').write_bytes(data)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 env=dict(os.environ,GE_SYNTHETIC_INVOICE_DIR=private)
 startup=subprocess.STARTUPINFO();startup.dwFlags|=subprocess.STARTF_USESHOWWINDOW
 process=subprocess.Popen([str(php),'-S',f'127.0.0.1:{port}',str(root/'customer-invoice-download-fixture.php')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,startupinfo=startup)
 def request(**args):
  url=f'http://127.0.0.1:{port}/?'+urllib.parse.urlencode(args)
  try:
   with urllib.request.urlopen(url,timeout=5) as r:return r.status,dict(r.headers),r.read()
  except urllib.error.HTTPError as e:return e.code,dict(e.headers),e.read()
 checks=[]
 def check(name,value):
  assert value,name
  checks.append(name)
 try:
  for _ in range(30):
   try:request(operation='resolve');break
   except OSError:time.sleep(.1)
  d=dict(operation='download',invoice='order:700:arca-7',_wpnonce='synthetic-valid')
  for actor in [1,100]:
   status,h,body=request(**d,actor=actor)
   check(f'exact bytes actor{actor}',status==200 and body==data)
   check(f'PDF MIME/name actor{actor}',h.get('Content-Type')=='application/pdf' and h.get('Content-Disposition')=='attachment; filename="synthetic-original.pdf"')
   check(f'private no-store actor{actor}',h.get('Cache-Control')=='private, no-store' and h.get('X-Content-Type-Options')=='nosniff')
  for actor in [0,2,101]:check(f'forbidden actor{actor}',request(**d,actor=actor)[0]==403)
  check('invalid nonce',request(operation='download',invoice='order:700:arca-7',_wpnonce='invalid')[0]==403)
  check('cross organization',request(**d,wrong_org=1)[0]==403)
  check('unknown record ID',request(operation='download',invoice='order:701:arca-7',_wpnonce='synthetic-valid')[0]==403)
  check('unknown document ID',request(operation='download',invoice='order:700:arca-8',_wpnonce='synthetic-valid')[0]==403)
  check('missing PDF404',request(**d,missing=1)[0]==404)
  check('integrity mismatch409',request(**d,corrupt=1)[0]==409)
  r=json.loads(request(operation='resolve')[2]);check('immutable fiscal amount issuer CAE',r['amount']=='25.50' and r['issuer_name']=='Synthetic Issuer' and r['cae']=='SYNTHETIC-CAE')
  r=json.loads(request(operation='resolve',wrong_journal=1)[2]);check('unrelated journal not enriched',r['amount']=='' and 'cae' not in r)
  r=json.loads(request(operation='resolve',preview=101)[2]);check('cross customer preview denied',r.get('error')=='forbidden')
  html=request()[2].decode();check('direct authenticated download', 'Descargar original' in html and 'ge_invoice_download' in html and 'Autorizada por ARCA' in html and 'Synthetic Issuer' in html and '25,50' in html)
  html=request(missing=1)[2].decode();check('missing file shown without download or emission', 'PDF no disponible' in html and 'ge_invoice_download' not in html and 'ge_arca_invoice_emit' not in html)
  html=request(historical=1)[2].decode();check('historical remains downloadable', 'Archivo histórico' in html and 'Descargar original' in html)
  check('no upload notice or fiscal emission triggered',pathlib.Path(private,'synthetic-original.pdf').read_bytes()==data)
  print(json.dumps({'status':'PASS','checks':checks,'count':len(checks),'real_business_data':False,'real_fiscal_emission':False},ensure_ascii=False))
 finally:process.terminate();process.wait(timeout=5)

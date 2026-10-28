from pathlib import Path
import importlib.util,time,copy,tempfile,json
BIN=Path(__file__).resolve().parents[3]/'wp-content/plugins/ge-webtoprint-calculator/bin'
spec=importlib.util.spec_from_file_location('partial_observer',BIN/'deco-price-observer.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=int(time.time());checks=0
def check(value):
 global checks
 assert value
 checks+=1
mapping=[{'public_id':'opcion-'+str(n),'source':'https://fixture.invalid/'+('missing'if n==2 else'present'),'supplier_variant_id':n,'nominal_variant':['20x30','Negro']}for n in(1,2,3)]
policy={'revision':'fixture-approved','frame_source_map':mapping,'options':{'opcion-'+str(n):{'variant_ref':'variant:'+str(n),'frame_cost_cents':1000000,'printing_cents':147400,'available':True,'print_dimensions_verified':True}for n in(1,2,3)}}
catalog={'options':[{'id':'opcion-'+str(n),'size':[20,30]}for n in(1,2,3)]}
prices={'currency':'ARS','observed_at':now,'a4-plus_cents':147400,'a3-plus_cents':267500,'gf_m2_cents':1911000}
def page(url):return {'source':url,'sha256':'a'*64,'variants':{n:{'id':n,'price_cents':1000000+(100 if n==3 else 0),'available':True,'options':['20x30','Negro']}for n in(1,2,3)}}
def fetch(url):return {'source':url,'error':'HTTP404'}if url.endswith('missing')else page(url)
before=copy.deepcopy(policy)
r=m.observe(policy,catalog,prices,fetch,allow_partial=True);p=r['partial_evidence'];rows=p['options']
check(r['complete']is False);check(p['complete']is False);check(p['source_complete']is False)
check(rows['opcion-1']['status']=='verified'and rows['opcion-1']['unchanged'])
check(rows['opcion-2']['status']=='unknown'and not rows['opcion-2']['unchanged'])
check('frame_observed_at'not in rows['opcion-2']and 'print_observed_at'not in rows['opcion-2'])
check(rows['opcion-3']['status']=='changed'and not rows['opcion-3']['unchanged'])
check(rows['opcion-1']['source_sha256']=='a'*64);check(rows['opcion-1']['print_observed_at']==now)
check(len(p['source_digest'])==64 and len(p['printing_source_digest'])==64);check(policy==before)
with tempfile.TemporaryDirectory()as folder:
 root=Path(folder);full=m.observe(policy,catalog,prices,page);check(full['complete'])
 m.save(full,root);original=(root/'latest-pricing-observation.json').read_bytes()
 m.save(r,root);check((root/'latest-pricing-observation.json').read_bytes()==original)
 check(json.loads((root/'latest-pricing-partial-observation.json').read_text())==p)
 check(len(list((root/'price-observations').glob('*.json')))==2)
for modify in('id','options','available','sha'):
 def altered(url):
  data=page(url)
  if modify=='sha':data['sha256']='bad'
  else:data['variants'][1][modify]={'id':999,'options':['40x60','Negro'],'available':False}[modify]
  return data
 observed=m.observe(policy,catalog,prices,altered,allow_partial=True)['partial_evidence']['options']['opcion-1']
 check(not observed['unchanged'])
for stamp in(now-86401,now+301):
 try:m.observe(policy,catalog,{**prices,'observed_at':stamp},fetch,allow_partial=True)
 except ValueError:check(True)
 else:raise AssertionError('Invalid print time accepted')
bad=copy.deepcopy(policy);bad['options']['opcion-1']['variant_ref']='variant:999'
try:m.observe(bad,catalog,prices,fetch,allow_partial=True)
except ValueError:check(True)
else:raise AssertionError('Wrong approved identity accepted')
print(json.dumps({'passed':True,'checks':checks,'network_requests':0,'full_snapshot_preserved':True,'approved_policy_unchanged':policy==before}))

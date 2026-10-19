from pathlib import Path
import importlib.util,tempfile,time,json,copy
BIN=Path(__file__).resolve().parents[3]/'wp-content/plugins/ge-webtoprint-calculator/bin'
spec=importlib.util.spec_from_file_location('observer',BIN/'deco-price-observer.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=int(time.time());checks=0
def check(value):
 global checks
 assert value
 checks+=1
policy={'revision':'approved-fixture','frame_source_map':[{'public_id':'one','source':'https://fixture.invalid/','supplier_variant_id':'123','nominal_variant':['20x30','negro']}],'options':{'one':{'frame_cost_cents':1000000,'printing_cents':267500,'available':True}}}
catalog={'options':[{'id':'one','size':[20,30]}]};prices={'currency':'ARS','observed_at':now,'a4-plus_cents':147400,'a3-plus_cents':267500,'gf_m2_cents':1911000}
page={'source':'https://fixture.invalid/','sha256':'a'*64,'variants':{'123':{'price_cents':1000000,'available':True,'options':['20x30','negro']}}}
def observe(p=page):return m.observe(policy,catalog,prices,lambda _:copy.deepcopy(p))
policy['options']['one']['printing_cents']=147400
result=observe();check(result['complete'] and not result['changes'] and result['options']['one']['unchanged'])
changed=copy.deepcopy(page);changed['variants']['123']['price_cents']+=100;check(not observe(changed)['options']['one']['unchanged'])
changed=copy.deepcopy(page);changed['variants']['123']['available']=False;check(not observe(changed)['options']['one']['unchanged'])
changed=copy.deepcopy(page);changed['variants']['123']['options']=['40x60','negro'];check(not observe(changed)['options']['one']['unchanged'])
missing=copy.deepcopy(page);missing['variants']={};partial=observe(missing);check(not partial['complete'])
check(not observe({'source':page['source'],'error':'http403'})['complete'])
with tempfile.TemporaryDirectory() as folder:
 root=Path(folder);m.save(result,root);before=(root/'latest-pricing-observation.json').read_bytes();m.save(partial,root);check((root/'latest-pricing-observation.json').read_bytes()==before);check(len(list((root/'price-observations').glob('*.json')))==2)
for key,value in [('currency','USD'),('observed_at',now-86401),('a3-plus_cents',0)]:
 bad={**prices,key:value}
 try:m.observe(policy,catalog,bad,lambda _:page)
 except ValueError:check(True)
 else:raise AssertionError('Invalid print snapshot accepted')
check(m.printing([45,30],prices)==267500)
check(m.printing([40,60],prices)==458600)
check(m.printing([21,29.7],prices)==147400)
print(json.dumps({'passed':True,'checks':checks,'network_requests':0,'approved_policy_unchanged':True}))

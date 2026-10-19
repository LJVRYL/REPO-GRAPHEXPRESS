from pathlib import Path
import importlib.util, os, json
file=Path(__file__).resolve().parents[3]/'wp-content/plugins/ge-webtoprint-calculator/bin/deco-frame-source.py'
spec=importlib.util.spec_from_file_location('frame_source',file);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0
def deny(url,reason):
 global checks
 assert reason in m.fetch(url)['error'];checks+=1
os.environ.pop('GE_DECO_FRAME_SOURCE_HOSTS',None)
deny('https://fixture.invalid/','source_hosts_not_configured')
os.environ['GE_DECO_FRAME_SOURCE_HOSTS']='["fixture.invalid"]'
for url in ['https://other.invalid/','http://fixture.invalid/','https://user:password@fixture.invalid/','https://fixture.invalid:8443/']:
 deny(url,'unexpected_source')
os.environ['GE_DECO_FRAME_SOURCE_HOSTS']='["https://fixture.invalid"]'
deny('https://fixture.invalid/','source_hosts_not_configured')
print(json.dumps({'checks':checks,'network_requests':0,'private_hosts_required':True}))

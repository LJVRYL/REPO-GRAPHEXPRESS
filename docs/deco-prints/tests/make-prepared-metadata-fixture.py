"""Copy genuine metadata only for reproducible prepared-loop benchmarks."""
import argparse,json,shutil
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--private-root',required=True);p.add_argument('--registry-json',required=True);p.add_argument('--output-root',required=True);p.add_argument('--prefix',choices=['trendy-','bauhaus-'],default='trendy-');p.add_argument('--inactive',action='store_true');a=p.parse_args()
source=Path(a.private_root).resolve();out=Path(a.output_root).resolve()
if out==source or source in out.parents:raise SystemExit('Output must be outside original private payload')
registry=json.loads(Path(a.registry_json).read_text(encoding='utf-8'));registry['works']=[w for w in registry['works'] if w['id'].startswith(a.prefix)]
if not registry['works']:raise SystemExit('Empty selection')
for w in registry['works']:
 if Path(w['id']).name!=w['id']:raise SystemExit('Invalid ID path')
 d=out/'private'/w['id'];d.mkdir(parents=True,exist_ok=True)
 for name in ['catalog-record.json','license-evidence.txt','analysis.json']:
  original=source/w['id']/name
  if original.is_symlink() or not original.is_file():raise SystemExit('Missing original metadata: '+str(original))
  with original.open('rb') as r,(d/name).open('xb') as f:shutil.copyfileobj(r,f)
 if a.inactive:w['sales_enabled']=False;w['catalog_hidden']=True
assets=out/'plugin/assets/deco';assets.mkdir(parents=True,exist_ok=True)
with (assets/'galleries.json').open('x',encoding='utf-8') as f:json.dump(registry,f)
print(json.dumps(dict(copied_records=len(registry['works']),masters_copied=0,private_root=str(out/'private'),registry_plugin_root=str(out/'plugin'),original_payload_writes=0)))

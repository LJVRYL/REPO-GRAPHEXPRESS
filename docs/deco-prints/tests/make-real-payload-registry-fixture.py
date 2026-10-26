"""Build a registry fixture from complete real records, reading payload only."""
import argparse,json
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--private-root',required=True);p.add_argument('--registry-plugin-root',required=True);p.add_argument('--limit',type=int);a=p.parse_args();root=Path(a.private_root).resolve();out=Path(a.registry_plugin_root).resolve()
if out==root or root in out.parents:raise SystemExit('Fixture output must be outside private payload')
works=[];pending=0
for d in sorted(root.iterdir()):
 if not d.is_dir() or not d.name.startswith(('bauhaus-','trendy-')):continue
 if not all((d/f).is_file() for f in ('catalog-record.json','analysis.json','print-master.jpg','license-evidence.txt')):pending+=1;continue
 r=json.loads((d/'catalog-record.json').read_text(encoding='utf-8'));an=json.loads((d/'analysis.json').read_text(encoding='utf-8'))
 if an.get('status') not in ('analyzed','warning') or an.get('blockers'):pending+=1;continue
 works.append(r['work'])
 if a.limit and len(works)>=a.limit:break
if not works:raise SystemExit('No complete genuinely analyzed records available; no analysis fabricated')
assets=out/'assets/deco';assets.mkdir(parents=True,exist_ok=True);target=assets/'galleries.json'
with target.open('x',encoding='utf-8') as f:json.dump(dict(works=works),f,ensure_ascii=False)
print(json.dumps(dict(fixture=str(target),selected=len(works),incomplete_seen=pending,payload_writes=0)))

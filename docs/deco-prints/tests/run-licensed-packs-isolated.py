"""Run synthetic licensed-pack acceptance in fresh PHP processes, outside WP."""
import argparse, hashlib, json, subprocess, uuid
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--php',required=True);args=p.parse_args()
repo=Path(__file__).resolve().parents[3]
for withdrawn in ('none','bauhaus','trendy'):
 d=str(repo/'outputs'/'trendy-backend-20261026'/('fixture-'+withdrawn+'-'+uuid.uuid4().hex))
 root=Path(d);public=root/'public';private=root/'private';(public/'assets/deco').mkdir(parents=True);private.mkdir()
 works=[]
 for id in ('bauhaus-001','trendy-0001'):
  folder=private/id;folder.mkdir();master=b'synthetic-private-master:'+id.encode();sha=hashlib.sha256(master).hexdigest();evidence=b'synthetic-authorized-license';esh=hashlib.sha256(evidence).hexdigest();image=hashlib.sha256(id.encode()).hexdigest();hidden=id.startswith(withdrawn)
  works.append(dict(id=id,imageId=image,pixels=[4000,6000],sales_enabled=not hidden,catalog_hidden=hidden))
  record=dict(work=dict(id=id,title=id,pixels=[4000,6000]),image_id=image,source_sha256=sha,source=dict(id='graphex-pack'),rights_evidence=dict(physical_print_sales=True,evidence_sha256=esh,image_sha256=image,reviewed_at='2026-10-26'))
  (folder/'print-master.jpg').write_bytes(master);(folder/'license-evidence.txt').write_bytes(evidence);(folder/'catalog-record.json').write_text(json.dumps(record));(folder/'analysis.json').write_text(json.dumps(dict(checksum_sha256=sha,status='analyzed',blockers=[],page_count=1,pixel_dimensions=[4000,6000])))
 (public/'assets/deco/galleries.json').write_text(json.dumps(dict(works=works)))
 (public/'assets/deco/catalog.js').write_text('const C='+json.dumps(dict(options=[dict(id='opcion-1',group='frames',model='basic',finish='black',size=[20,30])]))+';')
 subprocess.run([args.php,str(Path(__file__).with_name('licensed-packs-isolated-test.php')),d,withdrawn,'qa'],check=True)

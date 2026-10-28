#!/usr/bin/env python3
"""Read-only source observations. Never changes WP products, orders or approved recipes."""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal,ROUND_HALF_UP
import argparse,json,hashlib,time,os,uuid,subprocess,importlib.util,re
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('deco_frame_source',HERE/'deco-frame-source.py');source=importlib.util.module_from_spec(spec);spec.loader.exec_module(source)
def digest(value):return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def printing(size,prices,cost_rule=None):
 width,height=sorted(size)
 if width<=30 and height<=45:return prices['a4-plus_cents' if width<=22 and height<=31 else 'a3-plus_cents']
 if cost_rule:
  if type(cost_rule.get('unit_cost_cents'))is not int or cost_rule['unit_cost_cents']<=0 or not cost_rule.get('approval_ref'):raise ValueError('large_format_cost_review_required')
  minimum=Decimal(str(cost_rule['minimum_area_m2']))
  if not minimum.is_finite()or minimum<=0:raise ValueError('large_format_cost_review_required')
  area=max(minimum,Decimal(str(width))*Decimal(str(height))/10000)
  return int((Decimal(cost_rule['unit_cost_cents'])*area).quantize(Decimal('1'),rounding=ROUND_HALF_UP))
 return int((Decimal(prices['gf_m2_cents'])*Decimal(str(width))*Decimal(str(height))/1000000).quantize(Decimal('1'),rounding=ROUND_HALF_UP))*100
def per_option(policy,catalog,prices,pages,now):
 """A separate, explicitly partial projection; never a replacement full snapshot."""
 mapping=policy['frame_source_map'];catalog_options={r['id']:r for r in catalog['options']}
 if len({r['public_id'] for r in mapping})!=len(mapping) or {r['public_id'] for r in mapping}!=set(policy['options']):raise ValueError('invalid_partial_mapping')
 by_source={r['source']:r for r in pages};rows={}
 for item in mapping:
  id=item['public_id'];approved=policy['options'][id];variant_id=str(item['supplier_variant_id']);page=by_source.get(item['source'],{})
  if not re.fullmatch(r'[1-9][0-9]*',variant_id) or approved.get('variant_ref')!='variant:'+variant_id:raise ValueError('invalid_partial_identity')
  row={'status':'unknown','unchanged':False,'source':item['source'],'variant_id':variant_id,'nominal_variant':item['nominal_variant'],'reason':'source_unavailable'}
  variants=page.get('variants',{})
  variant=variants.get(item['supplier_variant_id'],variants.get(variant_id)) if isinstance(variants,dict) else None
  if 'error' not in page and re.fullmatch('[a-f0-9]{64}',str(page.get('sha256',''))) and isinstance(variant,dict):
   if str(variant.get('id',''))==variant_id and type(variant.get('price_cents')) is int and variant['price_cents']>0 and type(variant.get('available')) is bool and isinstance(variant.get('options'),list):
    size=catalog_options[id]['size'];cost=printing(size,prices,policy.get('large_format_cost_rule'));large=min(size)>30 or max(size)>45
    reference=not(large and policy.get('large_format_cost_rule')) or policy.get('large_format_reference_cents')==prices['gf_m2_cents']
    unchanged=reference and variant['price_cents']==approved.get('frame_cost_cents') and variant['available'] is True and approved.get('available') is True and approved.get('print_dimensions_verified') is True and variant['options']==item['nominal_variant'] and cost==approved.get('printing_cents')
    row.update(status='verified' if unchanged else 'changed',unchanged=unchanged,nominal_variant=variant['options'],frame_observed_at=now,print_observed_at=prices['observed_at'],source_sha256=page['sha256'],frame_cost_cents=variant['price_cents'],printing_cents=cost,available=variant['available'],reference_catalog_cents=prices['gf_m2_cents'] if large and policy.get('large_format_cost_rule') else None)
    row.pop('reason')
   else:row['reason']='invalid_variant_evidence'
  elif 'error' not in page:row['reason']='mapped_variant_unverified'
  rows[id]=row
 result={'schema_version':1,'kind':'graphex-price-evidence-per-option','complete':False,'source_complete':not any(r['status']=='unknown' for r in rows.values()),'approved_revision':policy['revision'],'observed_at':now,'printing_source_digest':digest(prices),'options':rows}
 result['source_digest']=digest(result)
 return result

def observe(policy,catalog,prices,fetch=source.fetch,allow_partial=False):
 for key in ('a4-plus_cents','a3-plus_cents','gf_m2_cents'):
  if type(prices.get(key)) is not int or prices[key]<=0:raise ValueError('invalid_print_snapshot')
 if prices.get('currency')!='ARS' or type(prices.get('observed_at')) is not int or not -300<=time.time()-prices['observed_at']<=86400:raise ValueError('stale_print_snapshot')
 mapping=policy['frame_source_map'];urls=list(dict.fromkeys(row['source'] for row in mapping))
 with ThreadPoolExecutor(max_workers=3) as pool:pages=list(pool.map(fetch,urls))
 errors=[row for row in pages if 'error' in row];by_source={row['source']:row for row in pages if 'variants' in row}
 for row in mapping:
  if row['source'] in by_source and row['supplier_variant_id'] not in by_source[row['source']]['variants']:errors.append({'source':row['source'],'error':'mapped_variant_missing','variant_id':row['supplier_variant_id']})
 now=int(time.time());result={'observed_at':now,'complete':not errors,'approved_revision':policy['revision'],'frame_observed_at':now,'print_observed_at':prices['observed_at'],'errors':errors,'changes':[],'options':{},'products_updated':False,'orders_updated':False,'approved_files_updated':False}
 if allow_partial:
  result['partial_evidence']=per_option(policy,catalog,prices,pages,now)
  if not result['partial_evidence']['source_complete']:
   result['complete']=False
   if not errors:errors.extend({'source':row['source'],'error':row.get('reason','unverified_variant'),'variant_id':row['variant_id']}for row in result['partial_evidence']['options'].values()if row['status']=='unknown')
 if errors:return result
 options={row['id']:row for row in catalog['options']};raw=[]
 for row in mapping:
  id=row['public_id'];variant=by_source[row['source']]['variants'][row['supplier_variant_id']];approved=policy['options'][id];size=options[id]['size'];cost=printing(size,prices,policy.get('large_format_cost_rule'))
  large=min(size)>30 or max(size)>45
  reference_current=not(large and policy.get('large_format_cost_rule'))or policy.get('large_format_reference_cents')==prices['gf_m2_cents']
  unchanged=reference_current and variant['price_cents']==approved.get('frame_cost_cents') and variant['available'] is True and approved.get('available') is True and variant['options']==row['nominal_variant'] and cost==approved.get('printing_cents')
  result['options'][id]={'unchanged':unchanged,'available':variant['available'],'frame_cost_cents':variant['price_cents'],'printing_cents':cost}
  raw.append({'id':str(row['supplier_variant_id']),**result['options'][id]})
  if not unchanged:result['changes'].append({'option_id':id,'variant_id':row['supplier_variant_id'],'approved_frame_cents':approved.get('frame_cost_cents'),'observed_frame_cents':variant['price_cents'],'approved_printing_cents':approved.get('printing_cents'),'observed_printing_cents':cost,'reference_catalog_current':reference_current,'available':variant['available'],'options':variant['options']})
 result['source_digest']=digest({'frames':raw,'printing':prices});result['page_evidence']=[{'source':row['source'],'sha256':row['sha256']} for row in pages]
 return result
def save(result,private):
 history=private/'price-observations';history.mkdir(mode=0o700,exist_ok=True)
 payload=json.dumps(result,ensure_ascii=False,indent=2);snapshot=history/(str(time.time_ns())+'.json');snapshot.write_text(payload,encoding='utf8');os.chmod(snapshot,0o600)
 if 'partial_evidence' in result:
  temporary=private/('partial-'+uuid.uuid4().hex+'.next');temporary.write_text(json.dumps(result['partial_evidence'],ensure_ascii=False,indent=2),encoding='utf8');os.chmod(temporary,0o600);temporary.replace(private/'latest-pricing-partial-observation.json')
 if result['complete']:
  temporary=private/('observation-'+uuid.uuid4().hex+'.next');temporary.write_text(payload,encoding='utf8');os.chmod(temporary,0o600);temporary.replace(private/'latest-pricing-observation.json')
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--allow-partial',action='store_true');parser.add_argument('--approved',required=True);parser.add_argument('--catalog',required=True);parser.add_argument('--private',required=True);parser.add_argument('--print-snapshot');parser.add_argument('--php',default='/opt/php8-3/bin/php-cli');parser.add_argument('--wp-cli',default='/usr/local/bin/wp');parser.add_argument('--wp-path');parser.add_argument('--wp-url',default='https://graphex.ar');args=parser.parse_args()
 private=Path(args.private).resolve(strict=True);policy=json.loads(Path(args.approved).read_text(encoding='utf8'));text=Path(args.catalog).read_text(encoding='utf8');catalog=json.loads(text.split('=',1)[1].rstrip(';\n'))
 try:
  if args.print_snapshot:prices=json.loads(Path(args.print_snapshot).read_text(encoding='utf8'))
  else:
   if not args.wp_path:raise ValueError('wp_path_required')
   command=[args.php,args.wp_cli,'--path='+args.wp_path,'--url='+args.wp_url]
   if os.geteuid()==0:command.append('--allow-root')
   result=subprocess.run(command+['eval-file',str(HERE/'deco-print-observe.php')],capture_output=True,text=True,timeout=50)
   marker='GRAPHEX_DECO_PRINT_OBSERVATION\n'
   if result.returncode or marker not in result.stdout:raise ValueError('print_source_unavailable')
   prices=json.loads(result.stdout.split(marker,1)[1].splitlines()[0])
  result=observe(policy,catalog,prices,allow_partial=args.allow_partial)
 except Exception as error:result={'observed_at':int(time.time()),'complete':False,'approved_revision':policy.get('revision'),'errors':[{'error':type(error).__name__,'reason':str(error)[:100]}],'changes':[],'products_updated':False,'orders_updated':False,'approved_files_updated':False}
 usable=sum(r.get('unchanged') is True for r in result.get('partial_evidence',{}).get('options',{}).values())
 save(result,private);print(json.dumps({'complete':result['complete'],'partial_allowed_options':usable,'changes':len(result['changes']),'errors':result['errors'],'products_updated':False,'orders_updated':False}))
 if not result['complete'] and not(args.allow_partial and usable):raise SystemExit(1)
if __name__=='__main__':main()

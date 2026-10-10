import json, hashlib, csv, re, unicodedata
from pathlib import Path
from decimal import Decimal, ROUND_HALF_UP
import argparse
parser=argparse.ArgumentParser(description='Normalize public Windbanners evidence; never publishes or modifies WordPress.')
parser.add_argument('--evidence-dir',type=Path,required=True,help='provider-products.json, provider-app.js and provider-variants/*.json')
parser.add_argument('--baseline',type=Path,required=True,help='previous published catalog JSON')
parser.add_argument('--catalog-output',type=Path,required=True)
parser.add_argument('--report-dir',type=Path,required=True,help='PRIVATE: contains supplier costs; never deploy this directory')
parser.add_argument('--date',required=True,help='Verified capture date YYYY-MM-DD')
args=parser.parse_args()
assert re.fullmatch(r'\d{4}-\d{2}-\d{2}',args.date)
root=args.evidence_dir;out=args.report_dir;out.mkdir(parents=True,exist_ok=True)
source=json.loads((root/'provider-products.json').read_text(encoding='utf-8-sig'))['rows']
old=json.loads(args.baseline.read_text(encoding='utf-8-sig')); prior={int(r['id']):r for r in old}
catalog=[];audit=[];missing=[]; identities=set()
def norm(s):return ' '.join(unicodedata.normalize('NFKD',s or '').encode('ascii','ignore').decode().lower().split())
def money(v):return Decimal(str(v)).quantize(Decimal('.01'),rounding=ROUND_HALF_UP)
for p in source:
    pid=int(p['rowid']); active=int(p['estado'])==1; category=p['categoria']; name=p['nombre'].strip()
    assert pid not in identities;identities.add(pid)
    was=prior.get(pid)
    if was and (norm(was['name'])!=norm(name) or was['category']!=category):missing.append({'id':pid,'issue':'identity_changed','before':was,'current_name':name,'current_category':category})
    variants=[]
    vf=root/'provider-variants'/f'{pid}.json'
    if active and p.get('variable'):
        assert vf.exists(),pid
        variants=[v for v in json.loads(vf.read_text(encoding='utf-8-sig'))['rows'] if int(v['estado'])==1]
        assert all(int(v['producto_id'])==pid for v in variants),pid
        variants.sort(key=lambda v:(int(v['orden'] or 999),int(v['rowid'])))
    use_variants=active and bool(p.get('variable')) and (norm(category) in ['carpas','wall banners','cintas','puff fiacas','puff kids','tiempo libre'])
    if use_variants and not variants:missing.append({'id':pid,'issue':'no_active_variants'})
    options=variants if use_variants else ([None] if active else [])
    tiers=[{'minimum_quantity':int(p[f'descuento_cantidad_{i}']), 'percent':float(p[f'descuento_porcentaje_{i}'])} for i in range(1,6) if p.get(f'descuento_cantidad_{i}') and p.get(f'descuento_porcentaje_{i}')]
    if not active:
        catalog.append({'id':pid,'category':category,'name':name,'price':was['price'] if was else None,'active':False,'source_date':args.date,'source_url':f'https://grupowindbanners.com.ar/carrito-producto/{pid}','availability':'Consultar disponibilidad: producto inactivo en la fuente.'})
        audit.append({'source_id':pid,'variant_id':None,'name':name,'state':'inactive','before_sale_net':was['price'] if was else None,'after_sale_net':None})
    for v in options:
        original=money((v or p)['precio']);assert original>0,pid
        cost=(original*Decimal('.90')).quantize(Decimal('1'),rounding=ROUND_HALF_UP)
        sale=money(cost*Decimal('1.30'))
        key=f'wb-{pid}'+(f'-v{v["rowid"]}' if v else '')
        inc=p.get('que_incluye') or '';description=' '.join(filter(None,[p.get('descripcion_corta'),p.get('descripcion_general')]))
        row={'id':pid,'category':category,'name':name,'price':float(sale),'active':True,'option_key':key,'variant_id':int(v['rowid']) if v else None,'variant_name':v['nombre'].strip() if v else None,'variant_description':v.get('descripcion') if v else None,'min_qty':max(1,int(p.get('orden_minima') or 1)),'step':max(1,int(p.get('multiplo_orden') or 1)),'includes':inc,'description':description,'source_date':args.date,'source_url':f'https://grupowindbanners.com.ar/carrito-producto/{pid}','template_url':p.get('plantilla'),'base_accessory':int(p.get('base') or 0)==1,'availability':'Activo en catálogo proveedor; stock físico y fecha final a confirmar.'}
        catalog.append(row)
        audit.append({'source_id':pid,'variant_id':row['variant_id'],'option_key':key,'category':category,'name':name,'variant_name':row['variant_name'],'state':'active','source_url':row['source_url'],'captured_date':args.date,'original_unit_net':str(original),'gremio_percent':10,'discount_basis':'original_public_unit_net','discounted_unit_net':str(cost),'sale_markup_percent':30,'before_sale_net':was['price'] if was else None,'after_sale_net':str(sale),'delta_net':str(sale-money(was['price'])) if was else None,'min_qty':row['min_qty'],'step':row['step'],'source_vat_price':(v or p).get('precio_con_iva'),'offer_net':p.get('precio_oferta'),'quantity_discounts':tiers,'includes':inc,'confidence':'source original/variant verified; gremio10 rule supplied by Leo; authenticated price not read','accessory_discount_caveat':category=='Bases'})
for pid in set(prior)-identities:
    missing.append({'id':pid,'issue':'absent_from_current_source'});r=dict(prior[pid]);r.update(active=False,availability='No localizado en fuente actual; consultar.',source_date=args.date);catalog.append(r)
assert money(Decimal('75000')*Decimal('.90')*Decimal('1.30'))==Decimal('87750.00')
assert len({r['option_key'] for r in catalog if r['active']})==sum(r['active'] for r in catalog)
args.catalog_output.write_text(json.dumps(catalog,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
manifest={'date':args.date,'source_products':len(source),'source_active':sum(int(p['estado'])==1 for p in source),'source_inactive':sum(int(p['estado'])!=1 for p in source),'active_options':sum(r['active'] for r in catalog),'active_variants':sum(r['active'] and r.get('variant_id') is not None for r in catalog),'prior_rows':len(old),'rows':audit,'issues':missing,'rule':{'original_discount':10,'sale_markup_on_discounted_cost':30,'supplier_rounding':'Math.round discounted unit (observed provider JS)','sale_decimals':2,'quantity_discounts_applied':False,'supplier_offers_applied':False,'supplier_tax_into_cost':False},'source_hashes':{str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in [root/'provider-products.json',root/'provider-app.js',*sorted((root/'provider-variants').glob('*.json'))]}}
(out/'Conciliacion-Windbanners.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
fields=['source_id','variant_id','option_key','category','name','variant_name','state','source_url','original_unit_net','discounted_unit_net','before_sale_net','after_sale_net','delta_net','min_qty','step']
with (out/'Conciliacion-Windbanners.csv').open('w',newline='',encoding='utf-8-sig') as f:
    w=csv.DictWriter(f,fields,extrasaction='ignore');w.writeheader();w.writerows(audit)
print(json.dumps({k:manifest[k] for k in ['source_products','source_active','source_inactive','active_options','active_variants','prior_rows','issues']},ensure_ascii=False))

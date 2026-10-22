"""Offline layout/data/QR checks against preserved authorized originals and synthetic stress cases."""
from pathlib import Path
import copy, json, os, subprocess, hashlib, re
from pypdf import PdfReader
import pypdfium2 as pdfium
from PIL import ImageChops

root=Path(__file__).resolve().parent
qa=root.parents[1]/'native-invoice-qa'
php=Path('C:/Users/Leo/Documents/Codex/runtimes/php-8.4.26-nts-win64/php.exe')
checks=[]
def check(name,ok):
    assert ok,name
    checks.append(name)
def render(source,target,timezone='UTC'):
    r=subprocess.run([str(php),'-d','date.timezone='+timezone,str(root/'arca-native-pdf-render.php'),str(source),str(target)],capture_output=True,check=True)
    return json.loads(r.stdout)
for n in [1,2]:
    source=qa/f'row-{n}.json';target=qa/f'native-C-00011-{n:08}.pdf'
    row=json.loads(source.read_text(encoding='utf-8'))
    result=render(source,target);a=target.read_bytes()
    render(source,target,'America/Argentina/Buenos_Aires')
    check(f'{n} deterministic and timezone independent',a==target.read_bytes())
    r=PdfReader(target);text='\n'.join(p.extract_text() for p in r.pages)
    check(f'{n} one page compact',len(r.pages)==1)
    for value in [row['authorization']['cae'],'00011-'+f'{n:08}',row['payload']['issuer']['cuit'],row['payload']['receiver']['cuit'],'01/04/2018','Contado','GRAPHEX']:
        check(f'{n} fiscal/display value '+value,value in text)
    check(f'{n} Jost embedded',bool(r.pages[0]['/Resources']['/Font']['/F1']['/FontDescriptor'].get('/FontFile2')))
    original=root.parents[1]/f'factura-C-00011-{n:08}-original.pdf'
    old_image=pdfium.PdfDocument(str(original))[0].render(scale=4).to_pil().crop((160,2876,532,3248)).convert('RGB')
    new_image=pdfium.PdfDocument(str(target))[0].render(scale=4).to_pil().crop((144,2856,516,3228)).convert('RGB')
    check(f'{n} original fiscal QR pixel identical',ImageChops.difference(old_image,new_image).getbbox() is None)
    pdfium.PdfDocument(str(target))[0].render(scale=1.5).to_pil().save(qa/f'native-{n}.png')

base=json.loads((qa/'row-2.json').read_text(encoding='utf-8'));base['payload']['environment']='homologation'
stress=copy.deepcopy(base);stress['payload']['items']=[{'name':f'SYNTHETIC_ITEM_{i:03}', 'description':f'SYNTHETIC_ITEM_{i:03} '+'Detalle largo con acentos: impresión. '*9,'quantity':1,'net':'1.00'} for i in range(40)]
stress['payload']['items'][0]['description']+='\n'+'Z'*1200
p=qa/'synthetic-stress.json';p.write_text(json.dumps(stress,ensure_ascii=False),encoding='utf-8');out=qa/'synthetic-stress.pdf';render(p,out)
r=PdfReader(out);text='\n'.join(page.extract_text() for page in r.pages)
check('Long items paginate without losing any item',len(r.pages)>1 and all(f'SYNTHETIC_ITEM_{i:03}' in text for i in range(40)))
check('Unbroken string characters preserved across page headers and wrapping',sum(len(x) for x in re.findall('Z{2,}',text))==1200)
check('Test environment labelled on every page',all('HOMOLOGACIÓN' in page.extract_text() for page in r.pages))
service=copy.deepcopy(base);service['payload']['detail'].update(Concepto=2,FchServDesde='20260101',FchServHasta='20260131',FchVtoPago='20260201')
p=qa/'synthetic-service.json';p.write_text(json.dumps(service,ensure_ascii=False),encoding='utf-8');out=qa/'synthetic-service.pdf';render(p,out);text=PdfReader(out).pages[0].extract_text()
check('Service dates retained',all(s in text for s in ['01/01/2026','31/01/2026','01/02/2026']))
service['payload']['items'][0].update(quantity=3,net='1.00');p.write_text(json.dumps(service,ensure_ascii=False),encoding='utf-8');render(p,out);check('Fractional unit price retains four decimals','0,3333' in PdfReader(out).pages[0].extract_text())
service['payload']['items'][0].update(quantity='0.001',net='1.00');p.write_text(json.dumps(service,ensure_ascii=False),encoding='utf-8');render(p,out);check('Small fractional quantity never rounds to zero','0,001' in PdfReader(out).pages[0].extract_text())
for kind, number, expected, absent in [(80,'20000000001','CUIT: 20000000001','DNI:'),(96,'12345678','DNI: 12345678','CUIT: 12345678'),(99,'0','Consumidor final','CUIT: 0')]:
    consumer=copy.deepcopy(base);consumer['payload']['detail'].update(DocTipo=kind,DocNro=number,CondicionIVAReceptorId=5)
    source=qa/f'synthetic-document-{kind}.json';target=qa/f'synthetic-document-{kind}.pdf';source.write_text(json.dumps(consumer,ensure_ascii=False),encoding='utf-8');render(source,target)
    text=PdfReader(target).pages[0].extract_text();check(f'Receiver document type {kind} labelled correctly',expected in text and absent not in text)

env=dict(os.environ,GE_NATIVE_PDF_QA_DIR=str(qa))
refresh=json.loads(subprocess.check_output([str(php),str(root/'arca-native-pdf-refresh.php')],env=env))
check('Native publication and refresh safety',refresh['status']=='PASS')
result={'status':'PASS','render_checks':len(checks),'checks':checks,'refresh_checks':refresh['count'],'synthetic_stress_pages':len(r.pages),'live_publication':False,'real_fiscal_emission':False}
(qa/'qa-result.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(result,ensure_ascii=False))

#!/usr/bin/env python3
"""Group exact approved PDFs by printing route/paper; never rerender approved art."""
from pathlib import Path
from pypdf import PdfReader,PdfWriter
import argparse,json,hashlib,os
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def build(request,private,out):
 if request.get('design_approved') is not True:raise ValueError('exact_approved_proof_required')
 rows=request.get('items');resolved=[];pages=0
 if not isinstance(rows,list) or not rows:raise ValueError('invalid_batch')
 for row in rows:
  path=Path(row['approved_pdf']);real=path.resolve(strict=True)
  if path.is_symlink() or not real.is_relative_to(private) or sha(real)!=row['approved_pdf_sha256']:raise ValueError('approved_pdf_changed')
  quantity=row['quantity'];pages+=quantity
  if type(quantity) is not int or not 1<=quantity<=200 or pages>200:raise ValueError('invalid_quantity')
  paper=row['paper_finish'];route=row['route'];size=row['print_mm']
  if route=='digital' and paper not in ('mate','brillo') or route=='gran-formato' and paper!='catalogo-fotografico' or route not in ('digital','gran-formato'):raise ValueError('invalid_paper_route')
  pdf=PdfReader(real)
  if len(pdf.pages)!=1:raise ValueError('invalid_approved_pdf')
  actual=[float(pdf.pages[0].mediabox.width)*25.4/72,float(pdf.pages[0].mediabox.height)*25.4/72]
  if len(size)!=2 or any(abs(a-b)>.1 for a,b in zip(actual,size)):raise ValueError('approved_dimensions_changed')
  resolved.append((row,pdf))
 if out.exists():raise ValueError('output_already_exists')
 out.mkdir(mode=0o700,parents=True);manifest={'order_id':request['order_id'],'design_approved':True,'production_release_authorized':False,'payment_guard':request['payment_guard'],'files':[],'note':'Preparar archivos no libera producción ni reemplaza la seña y aprobación del sistema.'}
 groups=sorted({row['route']+'-'+row['paper_finish'] for row,_ in resolved})
 for group in groups:
  writer=PdfWriter();records=[]
  for row,pdf in resolved:
   if row['route']+'-'+row['paper_finish']!=group:continue
   for copy in range(row['quantity']):writer.add_page(pdf.pages[0]);records.append({**{key:row[key] for key in ('item_id','snapshot_sha256','source_sha256','approved_pdf_sha256','print_mm','paper_finish','route')},'copy':copy+1})
  target=out/(group+'.pdf')
  with target.open('xb') as output:writer.write(output)
  os.chmod(target,0o600);check=PdfReader(target)
  if len(check.pages)!=len(records):raise ValueError('invalid_output_pages')
  manifest['files'].append({'file':target.name,'sha256':sha(target),'pages':records})
 (out/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf8');os.chmod(out/'manifest.json',0o600)
 return manifest
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--request',required=True);parser.add_argument('--private-root',required=True);parser.add_argument('--output',required=True);args=parser.parse_args()
 private=Path(args.private_root).resolve(strict=True);out=Path(args.output).resolve()
 if not out.is_relative_to(private):raise SystemExit('Private output required')
 result=build(json.loads(Path(args.request).read_text(encoding='utf8')),private,out);print(json.dumps({'order_id':result['order_id'],'files':len(result['files']),'production_release_authorized':False}))

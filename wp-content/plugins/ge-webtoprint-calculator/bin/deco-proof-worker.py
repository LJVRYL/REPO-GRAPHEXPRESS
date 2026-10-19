#!/usr/bin/env python3
"""Render exact private order proofs; never approves art, charges or releases production."""
from pathlib import Path
from decimal import Decimal
from PIL import Image,ImageOps
from reportlab.pdfgen.canvas import Canvas
from reportlab.lib.utils import ImageReader
from pypdf import PdfReader
import argparse,json,hashlib,fcntl,os,tempfile,shutil

def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def atomic(path,value):
 temporary=path.with_suffix('.next');temporary.write_text(json.dumps(value,ensure_ascii=False,indent=2),encoding='utf8');os.chmod(temporary,0o600);temporary.replace(path)
def render(request,folder,roots,reserve_bytes=0):
 rows=request.get('items');resolved=[];budget=48*1024**2
 if not isinstance(rows,list) or not 1<=len(rows)<=200:raise ValueError('invalid_batch')
 for row in rows:
  if type(row.get('item_id')) is not int or row['item_id']<1:raise ValueError('invalid_item')
  path=Path(row['source_path']);real=path.resolve(strict=True)
  if path.is_symlink() or not any(real.is_relative_to(root) for root in roots):raise ValueError('invalid_source')
  if sha(real)!=row['source_sha256']:raise ValueError('original_checksum_mismatch')
  size=row['print_mm']
  if not isinstance(size,list) or len(size)!=2 or any(type(n) is not int or n<1 or n>2000 for n in size):raise ValueError('invalid_dimensions')
  if row['fit'] not in ('cover','contain'):raise ValueError('invalid_composition')
  with Image.open(real) as image:
   if image.width*image.height>50_000_000 or image.format not in ('JPEG','PNG'):raise ValueError('invalid_image')
   pixels=ImageOps.exif_transpose(image).size
   budget+=real.stat().st_size*2 if image.format=='JPEG' else pixels[0]*pixels[1]*8
   budget+=1024**2
  scale=(max if row['fit']=='cover' else min)(Decimal(size[0])/pixels[0],Decimal(size[1])/pixels[1]);ppi=Decimal('25.4')/scale
  if ppi<200:raise ValueError('insufficient_resolution')
  resolved.append((row,real,float(ppi)))
 # All originals pass before any proof is written.
 if reserve_bytes and shutil.disk_usage(folder).free<reserve_bytes+budget:raise ValueError('storage_capacity')
 outputs=[]
 for row,path,ppi in resolved:
  target=folder/('item-'+str(row['item_id'])+'.pdf');preview=folder/('item-'+str(row['item_id'])+'.jpg')
  width,height=[value*72/25.4 for value in row['print_mm']]
  if target.exists():raise ValueError('proof_already_exists_without_result')
  canvas=Canvas(str(target),pagesize=(width,height),pageCompression=1,invariant=1);canvas.setTitle('GRAPHEX · Prueba de tu cuadro');canvas.setAuthor('Graphex')
  with Image.open(path) as original:
   image=ImageOps.exif_transpose(original).copy();source=str(path) if original.format=='JPEG' and original.getexif().get(274,1)==1 else ImageReader(image)
  scale=(max if row['fit']=='cover' else min)(width/image.width,height/image.height);iw,ih=image.width*scale,image.height*scale
  canvas.setFillColorRGB(1,1,1);canvas.rect(0,0,width,height,fill=1,stroke=0);canvas.saveState();clip=canvas.beginPath();clip.rect(0,0,width,height);canvas.clipPath(clip,stroke=0,fill=0);canvas.drawImage(source,(width-iw)/2,(height-ih)/2,width=iw,height=ih,mask='auto');canvas.restoreState();canvas.showPage();canvas.save();os.chmod(target,0o600)
  pdf=PdfReader(target)
  if len(pdf.pages)!=1 or any(abs(actual-wanted)>.001 for actual,wanted in zip((float(pdf.pages[0].mediabox.width),float(pdf.pages[0].mediabox.height)),(width,height))):raise ValueError('pdf_measurement_mismatch')
  # Preview only. The PDF above embeds the source and is never rasterized from this preview.
  import pypdfium2 as pdfium
  document=pdfium.PdfDocument(str(target));page=document[0];bitmap=page.render(scale=min(1.5,1400/max(width,height)));bitmap.to_pil().convert('RGB').save(preview,quality=90);bitmap.close();page.close();document.close();os.chmod(preview,0o600)
  outputs.append({'item_id':row['item_id'],'file':target.name,'preview':preview.name,'sha256':sha(target),'preview_sha256':sha(preview),'source_sha256':row['source_sha256'],'snapshot_sha256':row['snapshot_sha256'],'effective_ppi':ppi,'print_mm':row['print_mm'],'fit':row['fit'],'production_approved':False})
 return outputs
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--private',required=True);parser.add_argument('--upload-root',required=True);args=parser.parse_args();root=Path(args.private).resolve(strict=True);uploads=Path(args.upload_root).resolve(strict=True)
 queue=root/'.orders';queue.mkdir(mode=0o700,exist_ok=True)
 with (queue/'worker.lock').open('a') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:return
  for folder in sorted(queue.glob('order-[0-9]*')):
   request_path=folder/'request.json';result_path=folder/'result.json'
   if not folder.is_dir() or folder.is_symlink() or not request_path.is_file() or request_path.is_symlink() or result_path.exists():continue
   request=json.loads(request_path.read_text(encoding='utf8'));fingerprint=sha(request_path)
   try:
    # Render a complete generation privately; a failed attempt cannot leave a
    # half-written accepted proof. Deterministic bytes allow crash-safe resume.
    reserve=10*1024**3
    if shutil.disk_usage(root).free<reserve+16*1024**2:raise ValueError('storage_capacity')
    with tempfile.TemporaryDirectory(prefix='.render-',dir=folder) as temporary:
     staging=Path(temporary);outputs=render(request,staging,(root,uploads),reserve)
     for item in outputs:
      for field in ('file','preview'):
       source=staging/item[field];target=folder/item[field]
       if target.exists():
        if target.is_symlink() or sha(target)!=sha(source):raise ValueError('immutable_proof_changed')
       else:source.replace(target)
    atomic(result_path,{'order_id':request['order_id'],'request_sha256':fingerprint,'items':outputs,'status':'ready','production_approved':False});print(json.dumps({'order_id':request['order_id'],'proofs':len(outputs),'status':'ready'}),flush=True)
   except Exception as error:
    atomic(folder/'failure.json',{'type':type(error).__name__,'reason':str(error)[:120],'request_sha256':fingerprint});print(json.dumps({'order_id':request.get('order_id'),'status':'failed'}),flush=True)
if __name__=='__main__':main()

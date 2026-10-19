#!/usr/bin/env python3
"""Unprivileged CLI consumer. No HTTP listener, SSH, WP credentials or browser paths."""
from pathlib import Path
import argparse,json,hashlib,subprocess,threading,time,importlib.util,fcntl,os,shutil
from PIL import Image,ImageOps

EXPECTED='8422ac300b80f5d4627395e463075fcadd288411ecb1d48fcc610ef747b2bdbe'
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('deco_gallery_service',HERE/'deco-gallery-service.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def atomic(path,value):
 temp=path.with_suffix('.next');temp.write_text(json.dumps(value,ensure_ascii=False),encoding='utf8');os.chmod(temp,0o600);temp.replace(path)
def metrics(pixels,size,orientation,fit):
 width,height=size if orientation=='portrait' else size[::-1]
 scale=(min if fit=='contain' else max)(width/pixels[0],height/pixels[1])
 return {'effective_ppi':2.54/scale,'minimum_ppi':200}
def analyze(path,folder):
 analyzer=HERE/'file-analyzer.py'
 if hashlib.sha256(analyzer.read_bytes()).hexdigest()!=EXPECTED:raise ValueError('analyzer_changed')
 result=subprocess.run([os.sys.executable,str(analyzer),str(path),'image/jpeg','technical'],capture_output=True,text=True,timeout=75,env={'PATH':str(Path(os.sys.executable).parent)+':/usr/bin:/bin','LC_ALL':'C'})
 if result.returncode or len(result.stdout)>131072:raise ValueError('analysis_failed')
 facts=json.loads(result.stdout)
 if facts.get('checksum_sha256')!=module.digest(path) or facts.get('blockers') or facts.get('status') not in ('analyzed','warning'):raise ValueError('analysis_failed')
 with Image.open(path) as image:
  if list(image.size)!=facts.get('pixel_dimensions'):raise ValueError('analysis_dimensions_mismatch')
  image=ImageOps.exif_transpose(image);image.thumbnail((1400,1400),Image.Resampling.LANCZOS);image.convert('RGB').save(folder/'preview.jpg',quality=90,optimize=True)
 atomic(folder/'analysis.json',facts)
 return facts
class PersistentService(module.GalleryService):
 def update(self,id,**values):
  super().update(id,**values)
  atomic(self.folder(id)/'job.json',{k:v for k,v in self.status(id).items() if k!='work'})
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--registry',required=True);parser.add_argument('--private',required=True);parser.add_argument('--once',action='store_true');args=parser.parse_args()
 registry_path=Path(args.registry).resolve();private=Path(args.private).resolve()
 if not registry_path.is_file() or not private.is_dir():raise SystemExit('Registry and provisioned private root required')
 queue=private/'.queue';queue.mkdir(mode=0o700,exist_ok=True)
 with (queue/'worker.lock').open('a') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:return
  registry=json.loads(registry_path.read_text(encoding='utf8'));service=PersistentService(registry,private,analyze,threading.BoundedSemaphore(1),metrics,[])
  service.reserve_bytes=10*1024**3
  while True:
   jobs=sorted(queue.glob('nga-*.json'),key=lambda p:p.stat().st_mtime)
   for job in jobs[:1]:
    if job.is_symlink():raise ValueError('invalid_queue_file')
    value=json.loads(job.read_text(encoding='utf8'));id=value.get('id')
    if id not in service.works or job.name!=id+'.json':raise ValueError('unregistered_job')
    used=sum(path.stat().st_size for path in private.rglob('*') if path.is_file() and not path.is_symlink())
    if shutil.disk_usage(private).free<service.reserve_bytes+8*1024**2 or used>8*1024**3:
     folder=service.folder(id);folder.mkdir(mode=0o700,exist_ok=True);atomic(folder/'job.json',{'status':'failed','stage':'Esta obra necesita revisión. Elegí otra mientras la revisamos.','error':'storage_capacity'});job.unlink();print(json.dumps({'id':id,'status':'failed','error':'storage_capacity'}),flush=True);continue
    result=service.prepare(id);deadline=time.monotonic()+190
    while result['status'] in ('queued','analyzing') and time.monotonic()<deadline:
     time.sleep(.25);result=service.status(id)
    if result['status'] in ('queued','analyzing'):raise ValueError('preparation_timeout')
    atomic(service.folder(id)/'job.json',{k:v for k,v in result.items() if k!='work'})
    # Only the exact queue entry consumed by this worker is removed; masters are immutable.
    job.unlink()
    print(json.dumps({'id':id,'status':result['status']}),flush=True)
   if args.once:break
   time.sleep(2 if jobs else 5)
if __name__=='__main__':main()

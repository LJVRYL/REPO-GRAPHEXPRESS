"""Private, on-demand production masters for the museum registry, local review only."""
from pathlib import Path
from urllib.request import Request,urlopen,build_opener,HTTPRedirectHandler
from urllib.parse import urlsplit
import json,hashlib,threading,uuid,time,math,shutil
from PIL import Image

class NoRedirect(HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('unexpected_redirect')

def digest(path):
 h=hashlib.sha256()
 with path.open('rb') as file:
  for chunk in iter(lambda:file.read(1024*1024),b''):h.update(chunk)
 return h.hexdigest()

class GalleryService:
 def __init__(self,registry,private,analyze,slots,metrics,options):
  self.works={w['id']:w for w in registry['works'] if w.get('productionUrl') or w['id'].startswith('artic-')};self.private=Path(private);self.private.mkdir(parents=True,exist_ok=True,mode=0o700)
  self.analyze=analyze;self.slots=slots;self.metrics=metrics;self.options=options;self.jobs={};self.lock=threading.RLock();self.capacity=threading.BoundedSemaphore(1)
 def folder(self,id):
  if id not in self.works:raise ValueError('unknown_artwork')
  return self.private/id
 def cached(self,id,verify=False):
  try:
   folder=self.folder(id);record=json.loads((folder/'catalog-record.json').read_text(encoding='utf8'))
   if record['image_id']!=self.works[id]['imageId'] or not (folder/'preview.jpg').is_file():return None
   if verify and digest(folder/'print-master.jpg')!=record['source_sha256']:return None
   return record
  except (ValueError,OSError,KeyError):return None
 def prepared(self):return [r['work'] for id in self.works if (r:=self.cached(id))]
 def status(self,id):
  self.folder(id)
  with self.lock:
   if id in self.jobs:return dict(self.jobs[id])
  cached=self.cached(id)
  return {'status':'ready','work':cached['work']} if cached else {'status':'pending'}
 def prepare(self,id):
  self.folder(id)
  with self.lock:
   job=self.jobs.get(id)
   if job and job['status'] in ('queued','analyzing'):return dict(job)
   cached=self.cached(id,verify=True)
   if cached:return {'status':'ready','work':cached['work']}
   if (self.folder(id)/'catalog-record.json').exists():
    return {'status':'failed','stage':'Esta obra necesita una nueva revisión. Elegí otra mientras la revisamos.'}
   if not self.capacity.acquire(blocking=False):return {'status':'busy'}
   if not self.slots.acquire(blocking=False):self.capacity.release();return {'status':'busy'}
   self.jobs[id]={'status':'queued','stage':'Preparando esta obra'}
   threading.Thread(target=self.worker,args=(id,),daemon=True).start()
   return dict(self.jobs[id])
 def update(self,id,**values):
  with self.lock:self.jobs[id].update(values)
 def worker(self,id):
  try:
   work=self.works[id];folder=self.folder(id);folder.mkdir(exist_ok=True,mode=0o700)
   self.update(id,status='analyzing',stage='Verificando la imagen y su procedencia')
   if work['source']['id']=='nga':
    base=work['iiif'];evidence=work.get('rightsEvidence',{})
    if evidence.get('openAccess') is not True or evidence.get('imageUuid')!=work['imageId'] or base!='https://api.nga.gov/iiif/'+work['imageId']:raise ValueError('rights_not_verified')
    with urlopen(Request(base+'/info.json',headers={'User-Agent':'GraphexDecoCatalog/1.0'}),timeout=35) as response:info=json.load(response)
    width,height=info['width'],info['height']
    if not width or not height:raise ValueError('unknown_dimensions')
    licenses=info.get('license',[]);licenses=[licenses] if isinstance(licenses,str) else licenses
    if any('/licenses/' in str(license) for license in licenses):raise ValueError('rights_changed')
    factor=min(1,9000/max(width,height),math.sqrt(49_000_000/(width*height)))
    bound=max(1,math.floor(max(width,height)*factor));master_url=base+f'/full/!{bound},{bound}/0/default.jpg';host='api.nga.gov';download_kind='bounded-production-master-not-full-source-original';current={'openAccess':evidence,'imageInfo':info}
   elif work['source']['id']=='met':
    url='https://collectionapi.metmuseum.org/public/collection/v1/objects/'+id.removeprefix('met-')
    with urlopen(Request(url,headers={'User-Agent':'GraphexDecoCatalog/1.0'}),timeout=35) as response:current=json.load(response)
    if current.get('isPublicDomain') is not True or current.get('primaryImage')!=work['productionUrl']:raise ValueError('rights_or_image_changed')
    master_url=current['primaryImage'];host='images.metmuseum.org';download_kind='museum-original'
    if urlsplit(master_url).scheme!='https' or urlsplit(master_url).hostname!=host:raise ValueError('invalid_image_host')
   else:
    url='https://api.artic.edu/api/v1/artworks/'+id.removeprefix('artic-')+'?fields=id,image_id,is_public_domain,thumbnail'
    with urlopen(Request(url,headers={'User-Agent':'GraphexDecoCatalog/1.0'}),timeout=35) as response:current=json.load(response)['data']
    if current.get('is_public_domain') is not True or current.get('image_id')!=work['imageId']:raise ValueError('rights_or_image_changed')
    thumb=current.get('thumbnail') or {};width,height=thumb.get('width',0),thumb.get('height',0)
    if not width or not height:raise ValueError('unknown_dimensions')
    factor=min(1,9000/max(width,height),math.sqrt(49_000_000/(width*height)))
    bound=max(1,math.floor(max(width,height)*factor))
    master_url=f"https://www.artic.edu/iiif/2/{work['imageId']}/full/!{bound},{bound}/0/default.jpg";host='www.artic.edu';download_kind='bounded-production-master-not-full-source-original'
   target=folder/'print-master.jpg';partial=folder/'download.part'
   self.update(id,stage='Preparando el archivo en alta resolución')
   opener=build_opener(NoRedirect())
   with opener.open(Request(master_url,headers={'User-Agent':'GraphexDecoCatalog/1.0'}),timeout=90) as response,partial.open('wb') as output:
    if urlsplit(response.url).hostname!=host:raise ValueError('invalid_image_host')
    total=0
    while True:
     chunk=response.read(65536)
     if not chunk:break
     total+=len(chunk)
     if total>64*1024*1024:raise ValueError('image_too_large')
     reserve=getattr(self,'reserve_bytes',0)
     if reserve and shutil.disk_usage(folder).free-len(chunk)<reserve+8*1024**2:raise ValueError('storage_capacity')
     output.write(chunk)
   with Image.open(partial) as image:
    if image.format!='JPEG' or image.width*image.height>50_000_000:raise ValueError('invalid_image')
    image.verify()
   partial.replace(target)
   self.update(id,stage='Analizando la calidad para cada tamaño')
   analysis=self.analyze(target,folder)
   if analysis['checksum_sha256']!=digest(target) or analysis.get('blockers') or analysis.get('status') not in ('analyzed','warning'):raise ValueError('analysis_failed')
   pixels=analysis.get('pixel_dimensions')
   if not pixels or not all(isinstance(n,int) and n>0 for n in pixels):raise ValueError('invalid_dimensions')
   ready={**work,'pixels':pixels,'image':f'/api/artworks/{id}/preview','status':'ready','verified':True}
   record={'work':ready,'source_sha256':analysis['checksum_sha256'],'image_id':work['imageId'],'download_url':master_url,'download_kind':download_kind,'rights_evidence':current,'source':work['source'],'prepared_at':time.time(),'production_approved':False}
   temp=folder/'catalog-record.tmp';temp.write_text(json.dumps(record,ensure_ascii=False,indent=2),encoding='utf8');temp.replace(folder/'catalog-record.json')
   self.update(id,status='ready',stage='Tu obra está lista',work=ready)
  except Exception as error:
   # A partial download has never been accepted as an immutable master.
   try:(self.folder(id)/'download.part').unlink(missing_ok=True)
   except OSError:pass
   try:(self.folder(id)/'failure.json').write_text(json.dumps({'type':type(error).__name__,'reason':str(error)[:180],'time':time.time()}),encoding='utf8')
   except OSError:pass
   self.update(id,status='failed',stage='No pudimos preparar esta obra. Probá nuevamente en un momento.',error='preparation_unavailable')
  finally:self.slots.release();self.capacity.release()
 def preview(self,id):
  if not self.cached(id):raise ValueError('not_ready')
  return (self.folder(id)/'preview.jpg').read_bytes()
 def selection(self,id,data):
  record=self.cached(id,verify=True)
  if not record:raise ValueError('analysis_required')
  option=next((o for o in self.options if o['group']==data.get('group') and o.get('model')==data.get('model') and o['finish']==data.get('finish') and 'x'.join(map(str,o['size']))==data.get('size')),None)
  if not option:raise ValueError('invalid_selection')
  quality=self.metrics(record['work']['pixels'],option['size'],data.get('orientation'),data.get('fit'))
  if quality['effective_ppi']<200:raise ValueError('insufficient_resolution')
  selection_id=uuid.uuid4().hex;result={'selection_id':selection_id,'work_id':id,'source_sha256':record['source_sha256'],'option_id':option['id'],'size_cm':option['size'],'orientation':data['orientation'],'fit':data['fit'],'quality':quality,'source':record['source'],'production_approved':False,'commerce_activated':False}
  (self.folder(id)/('selection-'+selection_id+'.json')).write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf8')
  return {'selection_id':selection_id}

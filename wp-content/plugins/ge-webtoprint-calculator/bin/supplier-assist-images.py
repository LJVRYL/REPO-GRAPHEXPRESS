#!/usr/bin/env python3
"""Bounded private Poppler page previews. Originals remain private."""
import base64,json,sys,re,subprocess,tempfile
from pathlib import Path
path=Path(sys.argv[1])
if path.stat().st_size>20*1024*1024:raise ValueError('PDF demasiado grande')
info=subprocess.run(['pdfinfo',str(path)],capture_output=True,text=True,timeout=5,check=True).stdout
pages=int(re.search(r'^Pages:\s+(\d+)',info,re.M).group(1));images=[];size=0
with tempfile.TemporaryDirectory(prefix='ge-cost-pages-') as temp:
    for page in range(1,min(pages,8)+1):
        prefix=str(Path(temp)/('page-'+str(page)))
        subprocess.run(['pdftoppm','-f',str(page),'-l',str(page),'-scale-to','1600','-jpeg','-jpegopt','quality=70',str(path),prefix],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8,check=True)
        file=next(Path(temp).glob('page-'+str(page)+'-*.jpg'))
        encoded=base64.b64encode(file.read_bytes()).decode()
        if size+len(encoded)>700000:break
        images.append('data:image/jpeg;base64,'+encoded);size+=len(encoded)
print(json.dumps({'images':images,'pages_total':pages,'pages_sent':len(images),'partial':len(images)<pages}))

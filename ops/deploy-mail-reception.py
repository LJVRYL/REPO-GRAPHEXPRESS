"""Run from the private release after copying candidate files and manifest."""
import hashlib,json,os,shutil,subprocess
from pathlib import Path
root=Path('/home/graphexpress/releases/mail-reception-20261013')
site=Path('/home/graphexpress/public_html')
manifest=json.loads((root/'manifest.json').read_text())
for entry in manifest['files']:
 target=site/entry['path'];candidate=root/'candidate'/target.name
 actual=hashlib.sha256(target.read_bytes()).hexdigest() if target.exists() else None
 if actual!=entry['before']:raise RuntimeError('Production source changed: '+entry['path'])
 if hashlib.sha256(candidate.read_bytes()).hexdigest()!=entry['after']:raise RuntimeError('Candidate checksum changed')
 subprocess.check_call(['/opt/php7-4/bin/php','-l',str(candidate)])
for entry in manifest['files']:
 target=site/entry['path'];candidate=root/'candidate'/target.name
 if target.exists():
  backup=root/(target.name+'.'+entry['before']+'.backup');shutil.copy2(str(target),str(backup))
  if hashlib.sha256(backup.read_bytes()).hexdigest()!=entry['before']:raise RuntimeError('Backup verification failed')
 temp=target.with_name(target.name+'.reception-tmp');shutil.copyfile(str(candidate),str(temp));os.chmod(str(temp),0o644)
 os.chown(str(temp),501,501);os.replace(str(temp),str(target))
 if hashlib.sha256(target.read_bytes()).hexdigest()!=entry['after']:raise RuntimeError('Deployment verification failed')
manifest['deployed']=True;(root/'deployment-proof.json').write_text(json.dumps(manifest));print(json.dumps({'deployed':True,'files_verified':len(manifest['files'])}))

import hashlib,json,os,shutil,subprocess
from pathlib import Path
release=Path('/home/graphexpress/releases/newsletter-control-20261012-d19620c1c410');root=Path('/home/graphexpress/public_html');old=json.loads((release/'manifest.json').read_text());new=json.loads((release/'manifest-next.json').read_text())
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
for relative,record in old['files'].items():
    if sha(root/relative)!=record['after']:raise RuntimeError('Live source drift')
for relative,record in new['files'].items():
    candidate=release/'candidate'/relative
    if sha(candidate)!=record['after']:raise RuntimeError('Candidate drift')
    if old['files'][relative]['after']==record['after']:continue
    for runtime in ('/opt/php7-4/bin/php','/opt/php8-1/bin/php'):
        result=subprocess.run([runtime,'-l',str(candidate)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if result.returncode:raise RuntimeError('Syntax failed')
    target=root/relative;owner=target.stat();backup=release/'before-step2'/relative;backup.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(str(target),str(backup))
    if sha(backup)!=old['files'][relative]['after']:raise RuntimeError('Backup failed')
    temporary=target.with_suffix('.newsletter-next');shutil.copy2(str(candidate),str(temporary));os.chown(str(temporary),owner.st_uid,owner.st_gid);os.chmod(str(temporary),0o644);os.replace(str(temporary),str(target))
for relative,record in new['files'].items():
    if sha(root/relative)!=record['after']:raise RuntimeError('Published drift')
shutil.copy2(str(release/'manifest.json'),str(release/'manifest-step1.json'));os.replace(str(release/'manifest-next.json'),str(release/'manifest.json'))
print(json.dumps({'published':True,'commit':new['commit'],'verified_files':len(new['files'])}))

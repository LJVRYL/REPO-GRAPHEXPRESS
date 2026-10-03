from pathlib import Path
import hashlib,json,tarfile,shutil
root=Path(__file__).resolve().parent;package=root/'package';package.mkdir(exist_ok=True)
rows=[]
for f in (root/'changed-files.txt').read_text().splitlines():
    rel='wp-content/plugins/ge-webtoprint-calculator/'+f;source=root/'repo'/rel;old=root/'live'/rel
    dest=package/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,dest)
    rows.append(dict(path=rel,before=hashlib.sha256(old.read_bytes()).hexdigest()if old.exists()else None,after=hashlib.sha256(source.read_bytes()).hexdigest()))
rel='wp-content/mu-plugins/ge-request-size-guard.php';dest=package/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(root/'ge-request-size-guard.php',dest)
rows.append(dict(path=rel,before=hashlib.sha256((root/'live-size-guard.php').read_bytes()).hexdigest(),after=hashlib.sha256(dest.read_bytes()).hexdigest()))
(package/'manifest.json').write_text(json.dumps(rows,indent=2))
with tarfile.open(root/'external-links-deploy.tar.gz','w:gz')as tar:
    for row in rows:tar.add(package/row['path'],arcname=row['path'])
    tar.add(package/'manifest.json',arcname='manifest.json')
print('PACKAGE_FILES',len(rows))

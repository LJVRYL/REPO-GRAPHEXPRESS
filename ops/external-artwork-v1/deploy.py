from pathlib import Path
import json,hashlib,shutil,os,subprocess,datetime,fcntl
site=Path('/home/graphexpress/public_html');staging=Path('/root/qa-graphex-external-links-v1-20261002/package')
rows=json.loads((staging/'manifest.json').read_text())
rows.sort(key=lambda r: (0 if r['before'] is None else 1 if r['path'].endswith(('.js','.css')) else 2 if r['path'].endswith('class-ge-wtp-quote-artwork-v2.php') else 3))
assert subprocess.check_output(['hostname'],universal_newlines=True).strip()=='vps-3673733-x.dattaweb.com'
assert os.getuid()==0 and (site/'wp-config.php').is_file()
lock=open('/root/ge-backups/.selective-deploy.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()if p.is_file()else None
for row in rows:
    p=site/row['path'];assert str(p.resolve()).startswith(str(site)+'/wp-content/'),row['path']
    assert digest(p)==row['before'],'SOURCE_CHANGED '+row['path']
    assert digest(staging/row['path'])==row['after']
    if p.suffix=='.php':subprocess.run(['/opt/php7-4/bin/php','-l',str(staging/row['path'])],check=True,stdout=subprocess.DEVNULL)
backup=Path('/root/ge-backups/external-links-v1-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'));backup.mkdir(mode=0o700)
for row in rows:
    if row['before']:
        dst=backup/'originals'/row['path'];dst.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(site/row['path'],dst);assert digest(dst)==row['before']
(backup/'manifest.json').write_text(json.dumps(rows,indent=2));shutil.copy2(Path(__file__).parent/'rollback.py',backup/'rollback.py')
# Exercise guarded rollback against a private rehearsal copy before publishing.
rehearsal=backup/'rehearsal';rehearsal.mkdir()
for row in rows:
    dst=rehearsal/row['path'];dst.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(staging/row['path'],dst)
subprocess.run(['python3',str(backup/'rollback.py'),'--rehearsal'],check=True)
for row in rows:assert digest(rehearsal/row['path'])==row['before']
(backup/'ROLLBACK_REHEARSED').write_text('All restored hashes match source baseline. New files preserved privately.\n')
written=[]
try:
    for row in rows:
        dst=site/row['path'];assert digest(dst)==row['before'],'SOURCE_CHANGED '+row['path']
        temp=dst.with_name(dst.name+'.external-links-new');shutil.copyfile(staging/row['path'],temp);os.chmod(temp,0o644)
        # Preserve existing ownership; new source follows its containing directory.
        stat=dst.stat()if dst.exists()else dst.parent.stat();os.chown(temp,stat.st_uid,stat.st_gid);os.replace(temp,dst);written.append(row)
    for row in rows:assert digest(site/row['path'])==row['after']
except Exception:
    for row in reversed(written):
        dst=site/row['path'];assert digest(dst)==row['after'],'Concurrent change; manual rollback required'
        if row['before']:shutil.copy2(backup/'originals'/row['path'],dst)
        else:shutil.move(dst,backup/('aborted-'+dst.name))
    raise
subprocess.run(['systemctl','reload','php74-php-fpm'],check=True)
(backup/'DEPLOY_COMPLETED').write_text(datetime.datetime.now(datetime.timezone.utc).isoformat())
print('SELECTIVE_BACKUP='+str(backup));print('DEPLOY_COMPLETED_FILES='+str(len(rows)))

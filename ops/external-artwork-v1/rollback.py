from pathlib import Path
import json,hashlib,shutil,sys,datetime,os
import subprocess
backup=Path(__file__).resolve().parent;rows=json.loads((backup/'manifest.json').read_text());rehearsal='--rehearsal'in sys.argv
site=backup/'rehearsal'if rehearsal else Path('/home/graphexpress/public_html')
def digest(p):return hashlib.sha256(p.read_bytes()).hexdigest()if p.is_file()else None
for row in rows:
    assert digest(site/row['path'])==row['after'],'ROLLBACK_BLOCKED_LATER_CHANGE '+row['path']
    if row['before']:assert digest(backup/'originals'/row['path'])==row['before']
retired=backup/('rehearsal-retired'if rehearsal else 'rollback-retired');retired.mkdir(exist_ok=True)
for row in reversed(rows):
    dst=site/row['path']
    if row['before']:
        temp=dst.with_name(dst.name+'.rollback-new');shutil.copy2(backup/'originals'/row['path'],temp)
        if not rehearsal:
            stat=dst.stat();os.chown(temp,stat.st_uid,stat.st_gid)
        os.replace(temp,dst)
    else:shutil.move(dst,retired/dst.name)
for row in rows:assert digest(site/row['path'])==row['before']
if not rehearsal:subprocess.run(['systemctl','reload','php74-php-fpm'],check=True)
print('ROLLBACK_REHEARSAL_OK'if rehearsal else'ROLLBACK_COMPLETE_DATA_AND_ARTWORK_PRESERVED')

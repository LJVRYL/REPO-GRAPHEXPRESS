#!/usr/bin/env python3
import hashlib,json,os,pathlib,shutil
r=pathlib.Path(__file__).resolve().parents[1];m=json.loads((r/'manifest.json').read_text());root=pathlib.Path(m['root'])
ui='wp-content/mu-plugins/ge-crm/class-ge-crm-ui.php';loader=root/'wp-content/mu-plugins/ge-crm-agent.php'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
if sha(root/ui)!=m['files'][ui] or sha(r/'backup/crm-ui.php')!=m['backup_sha256']:raise SystemExit('Concurrent change or invalid backup; abort rollback')
if loader.exists():
 if sha(loader)!=m['files']['wp-content/mu-plugins/ge-crm-agent.php']:raise SystemExit('Loader changed; abort rollback')
 os.replace(loader,r/'backup/disabled-loader.php')
shutil.copyfile(r/'backup/crm-ui.php',root/ui)
print('Agent disabled; CRM UI restored. Private credentials, budget and draft history preserved.')

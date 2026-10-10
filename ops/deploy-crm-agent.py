#!/usr/bin/env python3
"""Selective release, no DB writes, no credentials and no external API calls."""
import hashlib,json,os,pathlib,pwd,shutil,socket,subprocess,sys
root=pathlib.Path('/home/graphexpress/public_html')
release=pathlib.Path(__file__).resolve().parents[1]
ui='wp-content/mu-plugins/ge-crm/class-ge-crm-ui.php'
files=['wp-content/mu-plugins/ge-crm-agent.php','wp-content/mu-plugins/ge-crm-agent/budget.php','wp-content/mu-plugins/ge-crm-agent/agent.php',ui]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def blob(p):
 b=p.read_bytes();return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
if socket.gethostname()!='vps-3673733-x.dattaweb.com' or not root.is_dir():raise SystemExit('Wrong host/root')
if blob(root/ui)!='e25f1dc78de9f7cddec666372de2105cc91d0fa0':raise SystemExit('CRM UI changed since baseline; preserve concurrent work')
for f in files[:3]:
 if (root/f).exists():raise SystemExit('New module already exists; review before replacement')
private=pathlib.Path('/home/graphexpress/crm-agent')
if private.exists():raise SystemExit('Private agent directory already exists; preserve it')
for f in files:subprocess.run(['/opt/php7-4/bin/php','-l',str(release/f)],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
for t in ['crm-agent-budget.php','crm-agent-adapter.php']:
 p=subprocess.run(['/opt/php7-4/bin/php',str(release/'tests'/t)],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
 print(p.stdout.strip())
backup=release/'backup';backup.mkdir(mode=0o700)
saved=backup/'crm-ui.php';shutil.copy2(root/ui,saved);os.chmod(saved,0o600)
if sha(saved)!=sha(root/ui):raise SystemExit('Backup failed')
# Confirm backup can reconstruct exactly the pre-deploy bytes before touching production.
restore=backup/'restore-check.php';shutil.copy2(saved,restore)
if blob(restore)!='e25f1dc78de9f7cddec666372de2105cc91d0fa0':raise SystemExit('Restore verification failed')
manifest={'backup_sha256':sha(saved),'files':{f:sha(release/f) for f in files},'root':str(root),'private':str(private)}
(release/'manifest.json').write_text(json.dumps(manifest,indent=2));os.chmod(release/'manifest.json',0o600)
apache=pwd.getpwnam('apache');private.mkdir(mode=0o700);os.chown(private,apache.pw_uid,apache.pw_gid)
try:
 # Loader last: until then the agent is inactive.
 for f in files[1:]+files[:1]:
  target=root/f;target.parent.mkdir(parents=True,exist_ok=True)
  tmp=release/('install-'+target.name);shutil.copyfile(release/f,tmp);os.chmod(tmp,0o644);os.replace(tmp,target)
  if sha(target)!=manifest['files'][f]:raise RuntimeError('Installed hash mismatch')
except Exception:
 loader=root/files[0]
 if loader.exists():os.replace(loader,backup/'disabled-loader.php')
 shutil.copyfile(saved,root/ui)
 raise
print(json.dumps({'deployed':True,'files':len(files),'backup_verified':True,'api_key_installed':False,'paid_calls':0}))

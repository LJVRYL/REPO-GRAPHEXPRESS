"""Private QA instance factory. No copied database, credentials, users or files."""
import pathlib, os, re, secrets, subprocess, json, shutil
task=pathlib.Path(__file__).resolve().parent.parent
repo=task/'work/repo'
repository_root=pathlib.Path(__file__).resolve().parents[2]
if (repository_root/'wp-content/plugins/ge-webtoprint-calculator').is_dir():repo=repository_root
source=pathlib.Path(os.environ.get('GE_QA_CORE','/home/leo/graph-org-foundation-v1-minimal/site'))
org=os.environ.get('GE_QA_ORG','empresa-qa-v1')
url=os.environ.get('GE_QA_URL','http://localhost:19050');assert re.fullmatch(r'http://localhost:[0-9]{4,5}',url)
assert re.fullmatch(r'[a-z][a-z0-9-]{2,40}',org)
root=pathlib.Path('/home/leo/ge-organizations')/org
site=root/'site'
db='ge_org_'+org.replace('-','_');dbuser='georg_'+secrets.token_hex(4)
socket='/home/leo/graph-restore-test-20261001T204908Z/run/mysql.sock'
assert not site.exists(), 'Instance already exists; never overwrite'
assert re.fullmatch(r'ge_org_[a-z0-9_]+',db)
password=secrets.token_hex(32)
sql=f"CREATE DATABASE `{db}` CHARACTER SET utf8mb4; CREATE USER '{dbuser}'@'localhost' IDENTIFIED BY '{password}'; GRANT ALL PRIVILEGES ON `{db}`.* TO '{dbuser}'@'localhost';"
subprocess.run(['mariadb','--socket='+socket,'-uroot'],input=sql.encode(),check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
site.mkdir(parents=True,mode=0o700)
core_files=('index.php','license.txt','readme.html','wp-activate.php','wp-blog-header.php','wp-comments-post.php','wp-cron.php','wp-links-opml.php','wp-load.php','wp-login.php','wp-mail.php','wp-settings.php','wp-signup.php','wp-trackback.php','xmlrpc.php','wp-config-sample.php')
for name in core_files:
    p=source/name
    if p.is_file():(site/name).write_bytes(p.read_bytes())
for name in ('wp-admin','wp-includes'):shutil.copytree(source/name,site/name,symlinks=False)
content=site/'wp-content';content.mkdir();(content/'plugins').mkdir();(content/'mu-plugins').mkdir()
for p in (source/'wp-content/plugins').iterdir():
    target=repo/'wp-content/plugins/ge-webtoprint-calculator' if p.name=='ge-webtoprint-calculator' else p.resolve()
    (content/'plugins'/p.name).symlink_to(target,target_is_directory=True)
(content/'themes').symlink_to((source/'wp-content/themes').resolve(),target_is_directory=True)
for name in ('ge-organization-foundation.php','ge-organization'):
    (content/'mu-plugins'/name).symlink_to(repo/'wp-content/mu-plugins'/name,target_is_directory=name=='ge-organization')
old_loader=source/'wp-content/mu-plugins/ge-gestion-v3.php'
if old_loader.exists():(content/'mu-plugins/ge-gestion-v3.php').write_bytes(old_loader.read_bytes())
(content/'mu-plugins/qa-transport.php').write_text("<?php\nadd_filter('pre_http_request',function(){return new WP_Error('qa_network','QA sin integraciones externas');},-999);\nadd_filter('pre_wp_mail','__return_true',-999);\n")
config="<?php\n"
for k,v in {'DB_NAME':db,'DB_USER':dbuser,'DB_PASSWORD':password,'DB_HOST':'localhost:'+socket,'DB_CHARSET':'utf8mb4','DB_COLLATE':'','GE_ORGANIZATION_INSTANCE_ID':org,'WP_HOME':url,'WP_SITEURL':url}.items():config+=f"define('{k}', '{v}');\n"
for k in ('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'):config+=f"define('{k}','{secrets.token_hex(48)}');\n"
config+="define('DISABLE_WP_CRON',true); define('WP_DEBUG',true); define('WP_DEBUG_DISPLAY',false); define('WP_DEBUG_LOG',true); define('GE_ORGANIZATION_QA',true);\n$table_prefix='wp_';\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');\nrequire_once ABSPATH.'wp-settings.php';\n"
(site/'wp-config.php').write_text(config);os.chmod(site/'wp-config.php',0o600)
(root/'instance.json').write_text(json.dumps({'organization_id':org,'database':db,'db_user':dbuser,'root':str(site),'url':url,'isolation':'dedicated-database-instance','secrets_exported':False},indent=2))
print('QA_INSTANCE_RESOURCE_CREATED')

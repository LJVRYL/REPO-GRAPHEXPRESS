import json,os,subprocess
from pathlib import Path
root=Path('/home/graphexpress/job-flow-qa-20261007')
test='/home/graphexpress/releases/mail-reception-20261013/tests/mail-reception-native.php'
def php(script,extra):
 env=dict(os.environ,**extra);p=subprocess.run(['/opt/php7-4/bin/php',script],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=90)
 text=p.stdout.decode('utf-8','replace');start=text.find('{')
 if p.returncode or start<0:raise RuntimeError('QA call failed; inspect private QA log')
 return json.loads(text[start:])
php(test,{'GE_MAIL_QA_PREPARE':'1'})
results={}
for suffix in ('unmatched','unmatched-duplicate','known','partial'):
 packet=suffix.replace('-duplicate','')
 results[suffix]=php('/home/graphexpress/releases/mail-reception-20261013/tools/mail-reception-native.php',{'GE_MAIL_SITE':str(root/'site'),'GE_MAIL_INPUT':str(root/('mail-reception-fixture-'+packet+'.json'))})
(root/'mail-reception-qa-results.json').write_text(json.dumps(results));os.chmod(str(root/'mail-reception-qa-results.json'),0o600)
print(json.dumps(php(test,{})))

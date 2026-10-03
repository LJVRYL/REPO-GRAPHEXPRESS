"""HTTP integration tests, not a browser driver. Credentials remain in private QA storage."""
import json,re,pathlib,requests,html
task=pathlib.Path(__file__).resolve().parent.parent
secret=json.loads(pathlib.Path('/home/leo/ge-organizations/empresa-qa/auth-test.json').read_text())
base='http://localhost:19050';session=requests.Session();checks={}
def check(value,name):
 checks[name]=bool(value)
 if not value:raise RuntimeError(name)
session.get(base+'/wp-login.php')
r=session.post(base+'/wp-login.php',data={'log':secret['login'],'pwd':secret['password'],'wp-submit':'Log In','testcookie':'1'},allow_redirects=False)
check(r.status_code==302,'real_login_success')
r=session.get(base+'/gestion/?section=company')
check(r.status_code==200 and 'Mi empresa' in r.text and 'Taller QA' in r.text,'real_organization_shell')
def field(name):
 forms=[x for x in re.findall(r'<form\b.*?</form>',r.text,re.S) if 'ge-company-form' in x]
 form_text=next((x for x in forms if 'value="save"' in x),forms[0])
 m=re.search(r'<input[^>]*name=["\']'+name+r'["\'][^>]*value=["\']([^"\']*)',form_text)
 if not m:raise RuntimeError('missing_field_'+name)
 return m[1]
revision=field('revision');nonce=field('_wpnonce')
r=session.post(base+'/wp-admin/admin-post.php',data={'action':'ge_org_action','organization_id':'empresa-qa','operation':'save','tab':'general','revision':revision,'_wpnonce':nonce,'values[display_name]':'Empresa QA Independiente'},allow_redirects=False)
print('SAVE_RESPONSE',r.status_code,re.sub('<[^>]+>',' ',r.text)[-900:] if r.status_code!=302 else 'redirect')
check(r.status_code==302,'authenticated_settings_save')
r=session.get(base+r.headers['Location'].replace(base,''));check(r.status_code==200 and 'Cambios guardados.' in r.text,'settings_save_confirmed')
check(int(field('revision'))==int(revision)+1,'settings_revision_incremented')
r=session.get(base+'/gestion/?section=company&tab=portability');nonce=field('_wpnonce');revision=field('revision')
r=session.post(base+'/wp-admin/admin-post.php',data={'action':'ge_org_action','organization_id':'empresa-qa','operation':'export','tab':'portability','revision':revision,'_wpnonce':nonce},allow_redirects=False)
check(r.status_code==200 and 'attachment' in r.headers.get('Content-Disposition',''),'authenticated_export_download')
bundle=r.json();check(bundle['manifest']['source_organization']=='empresa-qa' and not bundle['manifest']['contains_secrets'],'download_correct_organization')
check(not re.search(r'"(?:password|token|private_key|credentials_ref|cert_ref|api_key)"\s*:',r.text,re.I),'download_secret_scan')
r=session.get(base+'/gestion/?section=orders&organization_id=graph-express');check(r.status_code==403,'foreign_organization_http_denied')
r=session.get(base+'/wp-content/ge-private/organizations/empresa-qa/documents/anything.pdf');check(r.status_code==403,'private_static_file_http_denied')
guest=requests.get(base+'/wp-admin/admin-post.php?action=ge_commercial_quote_pdf&quote_id=986',allow_redirects=False);check(guest.status_code in (400,403),'anonymous_foreign_quote_pdf_denied')
f=json.loads((task/'outputs/tenant-acceptance-results.json').read_text());r=session.get(base+'/gestion/?section=quotes&quote_id='+str(f['quote_id']));check(r.status_code==200 and 'Taller QA' in r.text,'real_quote_detail')
customer_secret=json.loads(pathlib.Path('/home/leo/ge-organizations/empresa-qa/customer-auth-test.json').read_text());customer=requests.Session();customer.get(base+'/wp-login.php');customer.post(base+'/wp-login.php',data={'log':customer_secret['login'],'pwd':customer_secret['password'],'wp-submit':'Log In','testcookie':'1'})
preview=customer.get(base+'/cliente-markcom/?seccion=pedidos&pedido='+str(f['order_id']));match=re.search(r'href="([^"]*action=ge_quote_order_pdf[^"]*)"',preview.text);check(bool(match),'portal_order_pdf_link_present')
download=customer.get(html.unescape(match[1]));check(download.status_code==200 and download.headers.get('Content-Type','').startswith('application/pdf') and b'Taller QA' in download.content,'authenticated_portal_order_pdf')
(task/'outputs/http-tenant-qa-results.json').write_text(json.dumps({'checks':checks,'status':'passed','base_url':base},indent=2))
print('HTTP_QA_PASS',len(checks))

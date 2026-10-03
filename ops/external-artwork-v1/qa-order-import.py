import json,urllib.request,urllib.parse
from pathlib import Path
root=Path(__file__).resolve().parent;base='http://127.0.0.1:18785';ctx=json.load(urllib.request.urlopen(base+'/context'));row=json.loads((root/'qa-order-import.json').read_text())
data=dict(action='ge_external_artwork',nonce=ctx['nonce'],op='import',ref_id=row['ref_id'],order_id=row['order_id'])
with urllib.request.urlopen(urllib.request.Request(base+'/wp-admin/admin-ajax.php',urllib.parse.urlencode(data).encode()),timeout=70)as r:result=json.load(r)
assert result['success'],result
assert result['data']['local']['file_analysis_ref'] and result['data']['local']['order_item_id']
print('PASS real import initiated from order with analyzer and correct item')
with urllib.request.urlopen(base+'/inspect?quote_id='+str(row['quote_id']))as r:stored=json.load(r)
assert len(stored['files'])==2
assert stored['files'][0]['imported_file_id']==result['data']['local']['id']
assert stored['files'][0]['url'].endswith('w3c_home.png')
print('PASS order import synchronizes quote provenance and local version')
with urllib.request.urlopen(urllib.request.Request(base+'/wp-admin/admin-ajax.php',urllib.parse.urlencode(data).encode()),timeout=70)as r:retry=json.load(r)
assert retry['data']['local']['id']==result['data']['local']['id']
print('PASS order import retry creates no duplicate version')

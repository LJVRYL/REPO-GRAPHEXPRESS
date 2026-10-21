"""Exercise actual PHP response headers/body with synthetic records on loopback only."""
from pathlib import Path
import csv, io, json, os, socket, subprocess, time, urllib.request, urllib.parse, urllib.error

php = Path('C:/Users/Leo/Documents/Codex/runtimes/php-8.4.26-nts-win64/php.exe')
root = Path(__file__).resolve().parent
with socket.socket() as s:
    s.bind(('127.0.0.1', 0))
    port = s.getsockname()[1]
process = subprocess.Popen([str(php), '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
checks = 0

def check(value, label):
    global checks
    if not value:
        raise AssertionError(label)
    checks += 1

def request(query='', fields=None):
    url = f'http://127.0.0.1:{port}/business-export-http-fixture.php' + ('?' + query if query else '')
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    try:
        response = urllib.request.urlopen(urllib.request.Request(url, data=data), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.headers, response.read()

try:
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                break
        except OSError:
            time.sleep(.1)
    fields = {'domain': 'all', 'format': 'json', '_wpnonce': 'synthetic-nonce'}
    status, headers, body = request(fields=fields)
    check(status == 200, 'json status')
    check(headers['Content-Type'] == 'application/json; charset=UTF-8', 'json MIME')
    check(headers['Content-Disposition'].endswith('.json"'), 'attachment')
    check('no-store' in headers['Cache-Control'] and 'private' in headers['Cache-Control'], 'private no-store')
    check(headers['X-Content-Type-Options'] == 'nosniff', 'nosniff')
    check(int(headers['Content-Length']) == len(body), 'exact response length')
    result = json.loads(body)
    count = sum(x['exported'] for x in result['result']['record_counts'].values())
    check(len(result['records']) == count == 1837, 'complete multipage HTTP download')
    check(b'SECRET_' not in body, 'no secrets/private notes')
    check(result['result']['complete'], 'completion marker')
    status, headers, body = request(fields=dict(fields, format='csv'))
    check(status == 200 and body.startswith(b'\xef\xbb\xbf'), 'CSV UTF-8 BOM')
    rows = list(csv.reader(io.StringIO(body.decode('utf-8-sig')), delimiter=';'))
    csv_manifest = json.loads(rows[-1][-1])
    check(len(rows) == csv_manifest['csv_data_rows'] + 2 and csv_manifest['total_records'] == count and rows[-1][0] == 'export_manifest', 'CSV completeness')
    check(rows[1][7].startswith("'="), 'Excel formula neutralized')
    for query, payload, expected in [('actor=0', fields, 403), ('actor=2', fields, 403), ('foreign_binding=1', fields, 403), ('', dict(fields, _wpnonce='wrong'), 403), ('', None, 405), ('', dict(fields, domain='secrets'), 400), ('', dict(fields, customer_id='1 OR 1=1'), 400)]:
        status, headers, body = request(query, payload)
        check(status == expected, f'rejected {query or payload}: {status}')
        check('Content-Disposition' not in headers and b'SECRET_' not in body and b'wp_ge_' not in body, 'safe failure without partial file')
    for source, domain in [('customers', 'customers'), ('crm', 'crm'), ('ge_commercial_quote', 'quotes'), ('ge_quote_request', 'requests'), ('ge_customer_invoice', 'invoices'), ('orders', 'orders')]:
        for page in (1, 2):
            status, headers, body = request(urllib.parse.urlencode({'fail_source': source, 'fail_page': page}), dict(fields, domain=domain))
            check(status == 400 and 'Content-Disposition' not in headers and b'complete' not in body and b'PRIVATE' not in body, 'failed empty query has no successful partial download')
    print(json.dumps({'status': 'PASS', 'http_checks': checks, 'records': count, 'data': 'synthetic_only', 'real_mutations': 0}))
finally:
    process.terminate()
    process.wait(timeout=10)

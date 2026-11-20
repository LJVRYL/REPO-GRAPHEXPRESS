#!/usr/bin/env python3
"""Sign an approved Graphex release and emit its Coolify console command."""
import argparse
import base64
import json
from pathlib import Path
import re
import subprocess


def build_command(manifest_json, signature):
    return """python3 - <<'GRAPHEX_RELEASE'
import base64
import hashlib
import json
import subprocess
import urllib.request

manifest = json.loads(%r)
signature = %r

def broker(request):
    run = subprocess.run(
        ['/usr/local/sbin/graphex-code-broker'],
        input=json.dumps(request).encode(), capture_output=True, timeout=90,
    )
    return json.loads(run.stdout)

def inspected():
    result = broker({'op': 'inspect', 'paths': [f['path'] for f in manifest['files']]})
    if result.get('status') != 'READ_ONLY':
        raise SystemExit('Inspección rechazada: ' + str(result.get('code')))
    return {f['path']: f['sha256'] for f in result['files']}

current = inspected()
expected = {f['path']: f['after_sha256'] for f in manifest['files']}
if current == expected:
    print('La producción ya coincide con el manifiesto. Verificar el sitio.')
    raise SystemExit(0)
if current != {f['path']: f['before_sha256'] for f in manifest['files']}:
    raise SystemExit('La línea de base cambió. Revisar antes de firmar otra publicación.')

source = 'https://raw.githubusercontent.com/LJVRYL/REPO-GRAPHEXPRESS/' + manifest['local_commit'] + '/'
contents = {}
for path, sha in expected.items():
    with urllib.request.urlopen(source + path, timeout=15) as response:
        data = response.read(1_000_001)
    if len(data) > 1_000_000 or hashlib.sha256(data).hexdigest() != sha:
        raise SystemExit('Los bytes canónicos no coinciden: ' + path)
    contents[path] = base64.b64encode(data).decode('ascii')
result = broker(dict(op='deploy', manifest=manifest, contents=contents, rollback_of=None, signature=signature))
print({key: result[key] for key in ('status', 'code', 'backup_verified', 'local_commit') if key in result})
if result.get('status') != 'COMMITTED' or not result.get('backup_verified') or inspected() != expected:
    raise SystemExit('Publicación sin verificar. Revisar antes de reintentar.')
print('Respaldo y hashes verificados. Ejecutar la prueba funcional en producción.')
GRAPHEX_RELEASE
""" % (manifest_json, signature)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest', type=Path, help='Manifiesto aprobado con hashes de producción y Git')
    parser.add_argument('--signer', type=Path, default=Path.home() / '.ssh/graphex-code-release-signer_ed25519')
    args = parser.parse_args()
    manifest = json.loads(args.manifest.read_text(encoding='utf-8'))
    if (manifest.get('operation'), manifest.get('project'), manifest.get('resource')) != (
        'deploy', 'graph-express', 'donweb-cloud-6443807'
    ) or not re.fullmatch(r'[a-f0-9]{40}', manifest.get('local_commit', '')):
        parser.error('El manifiesto debe corresponder al VPS y al commit autorizado de Graphex.')
    if not manifest.get('files') or not manifest.get('authorization_ref'):
        parser.error('Faltan archivos o referencia de autorización.')
    canonical_json = json.dumps(manifest, sort_keys=True, separators=(',', ':'), ensure_ascii=True)
    signed = subprocess.run(
        ['ssh-keygen', '-Y', 'sign', '-f', str(args.signer), '-n', 'graphex-code-release-v1'],
        input=canonical_json.encode('ascii'), stdout=subprocess.PIPE, check=True,
    )
    signature = base64.b64encode(signed.stdout).decode('ascii')
    print(build_command(canonical_json, signature), end='')


if __name__ == '__main__':
    main()

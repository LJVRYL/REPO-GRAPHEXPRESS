"""Prepare a reproducible candidate from committed Git bytes, without deployment."""
import hashlib, io, json, pathlib, subprocess, tarfile

root = pathlib.Path(__file__).resolve().parents[1]
out = root.parents[1] / 'outputs' / 'crm-replies-20261010'
out.mkdir(parents=True, exist_ok=True)
head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
base = subprocess.check_output(['git', 'rev-parse', 'FETCH_HEAD'], cwd=root, text=True).strip()
files = subprocess.check_output(['git', 'diff', '--name-only', base, head, '--', 'wp-content/mu-plugins'], cwd=root, text=True).splitlines()
files += ['tests/crm-outbound-wp.php', 'tests/crm-send-policy.php']
manifest = []
with tarfile.open(out / 'candidate.tar', 'w') as archive:
    for name in files:
        data = subprocess.check_output(['git', 'show', head + ':' + name], cwd=root)
        entry = tarfile.TarInfo(name); entry.size = len(data); entry.mode = 0o644
        archive.addfile(entry, io.BytesIO(data))
        manifest.append({'path': name, 'sha256': hashlib.sha256(data).hexdigest(), 'deploy': name.startswith('wp-content/')})
state = {'status': 'candidate', 'commit': head, 'canonical_before': base, 'canonical_synced': False,
         'tests': {'policy_checks': 28, 'integration_checks': 16, 'real_outbound': 0},
         'pending': ['canonical merge authorization', 'deployment', 'private sender credentials', 'real send verification', 'Meta advanced WhatsApp access'],
         'files': manifest}
(out / 'manifest.json').write_text(json.dumps(state, indent=2), encoding='utf-8')
print(json.dumps({'commit': head, 'candidate_files': len(files), 'output': str(out)}))

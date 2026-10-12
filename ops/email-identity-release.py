#!/usr/bin/env python3
"""Selective Graphex code release. Manifest and backups remain private, outside Git."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import stat
import time

ROOT = Path('/home/graphexpress/public_html')
HOST = 'vps-3673733-x.dattaweb.com'
PREFIXES = ('wp-content/plugins/ge-webtoprint-calculator/', 'wp-content/mu-plugins/ge-crm/', 'wp-content/mu-plugins/ge-organization/')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def target(name):
    if not name.startswith(PREFIXES) or '..' in Path(name).parts:
        raise RuntimeError('Path outside authorized custom roots')
    path = ROOT / name
    try:
        path.resolve().relative_to(ROOT.resolve())
    except ValueError:
        raise RuntimeError('Target escapes document root')
    return path


def binding():
    if socket.gethostname() != HOST:
        raise RuntimeError('Unexpected host')
    vhost = Path('/opt/ferozo/conf/vhosts.d/160-graphex.conf').read_text()
    if 'ServerName graphex.ar' not in vhost or str(ROOT) not in vhost or '/opt/php7-4/var/run/php-fpm/graphex.sock' not in vhost:
        raise RuntimeError('Domain/runtime binding changed')


def reload_runtime():
    masters = []
    for item in Path('/proc').iterdir():
        if not item.name.isdigit():
            continue
        try:
            command = (item / 'cmdline').read_bytes().replace(b'\x00', b' ').decode(errors='replace')
            if 'php-fpm: master process (/opt/php7-4/etc/php-fpm.conf)' in command:
                masters.append(int(item.name))
        except OSError:
            pass
    if len(masters) != 1:
        raise RuntimeError('Cannot identify unique verified PHP runtime')
    os.kill(masters[0], signal.SIGUSR2)


def atomic_copy(source, destination, original=None):
    destination.parent.mkdir(parents=True, exist_ok=True)
    temp = destination.with_name(destination.name + '.email-identity-staged')
    if temp.exists():
        raise RuntimeError('Existing staged file must be investigated')
    shutil.copyfile(source, temp)
    permissions = original or destination.parent.stat()
    os.chown(temp, permissions.st_uid, permissions.st_gid)
    os.chmod(temp, stat.S_IMODE(permissions.st_mode) if original else 0o644)
    os.replace(temp, destination)


def backup(release, manifest):
    backup_root = release / 'backup'
    if backup_root.exists():
        raise RuntimeError('Backup already exists; never overwrite it')
    backup_root.mkdir(mode=0o700)
    evidence = {}
    for row in manifest['files']:
        path = target(row['path'])
        raw = path.read_bytes() if path.is_file() else None
        normalized = hashlib.sha256(raw.replace(b'\r\n', b'\n')).hexdigest() if raw is not None else None
        if normalized != row['base_normalized_sha256']:
            raise RuntimeError('Unexplained production drift: ' + row['path'])
        evidence[row['path']] = {'before': digest(path), 'after': row['sha256'], 'existed': raw is not None}
        if raw is not None:
            copy = backup_root / row['path']
            copy.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, copy)
            if digest(copy) != evidence[row['path']]['before']:
                raise RuntimeError('Backup verification failed')
    (release / 'backup-manifest.json').write_text(json.dumps(evidence, indent=2))
    # Restore drill into a private scratch tree, without changing the site.
    drill = release / 'restore-drill'
    drill.mkdir(mode=0o700)
    for name, row in evidence.items():
        if row['existed']:
            restored = drill / name
            restored.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(backup_root / name, restored)
            if digest(restored) != row['before']:
                raise RuntimeError('Restore drill failed')
    return {'backup_verified': True, 'restore_drill_verified': True, 'files': len(evidence)}


def deploy(release, manifest):
    evidence = json.loads((release / 'backup-manifest.json').read_text())
    for name, row in evidence.items():
        if digest(target(name)) != row['before']:
            raise RuntimeError('Concurrent change: ' + name)
        if digest(release / 'payload' / name) != row['after']:
            raise RuntimeError('Payload mismatch: ' + name)
    maintenance = ROOT / '.maintenance'
    if maintenance.exists():
        raise RuntimeError('Another maintenance operation is active')
    marker = '<?php $upgrading = ' + str(int(time.time())) + '; // email-identity-release\n'
    with maintenance.open('x') as handle:
        handle.write(marker)
    changed = []
    try:
        # New dependencies first; stop new requests until all callers and cache agree.
        names = sorted(evidence, key=lambda name: (evidence[name]['existed'], name))
        for name in names:
            path = target(name)
            if digest(path) != evidence[name]['before']:
                raise RuntimeError('Concurrent change during deployment: '+name)
            original = path.stat() if path.exists() else None
            atomic_copy(release / 'payload' / name, path, original)
            changed.append(name)
        reload_runtime()
        for name, row in evidence.items():
            if digest(target(name)) != row['after']:
                raise RuntimeError('Post-deploy hash mismatch: ' + name)
    except Exception:
        # Restore only files changed by this execution; newly added files stay inert.
        for name in reversed(changed):
            if evidence[name]['existed']:
                path = target(name)
                atomic_copy(release / 'backup' / name, path, path.stat())
        reload_runtime()
        raise
    finally:
        if maintenance.exists() and maintenance.read_text() == marker:
            maintenance.unlink()
    return {'deployed': True, 'files_verified': len(evidence), 'database_modified': False}


def rollback(release):
    evidence = json.loads((release / 'backup-manifest.json').read_text())
    for name, row in evidence.items():
        if digest(target(name)) != row['after']:
            raise RuntimeError('Rollback would overwrite a concurrent change: ' + name)
    maintenance = ROOT / '.maintenance'
    if maintenance.exists():
        raise RuntimeError('Another maintenance operation is active')
    marker = '<?php $upgrading = ' + str(int(time.time())) + '; // email-identity-rollback\n'
    with maintenance.open('x') as handle:
        handle.write(marker)
    try:
        for name, row in evidence.items():
            if row['existed']:
                path = target(name)
                atomic_copy(release / 'backup' / name, path, path.stat())
                if digest(path) != row['before']:
                    raise RuntimeError('Rollback hash mismatch')
        reload_runtime()
    finally:
        if maintenance.exists() and maintenance.read_text() == marker:
            maintenance.unlink()
    return {'rolled_back': True, 'new_files': 'retained inert', 'options_contacts_campaigns': 'never restored automatically'}


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('backup', 'deploy', 'verify', 'rollback'))
    parser.add_argument('release')
    args = parser.parse_args()
    release = Path(args.release).resolve()
    try:
        release.relative_to(Path('/home/graphexpress/releases'))
    except ValueError:
        raise RuntimeError('Unexpected private release directory')
    if not release.name.startswith('email-identity-'):
        raise RuntimeError('Unexpected private release directory')
    binding()
    manifest = json.loads((release / 'manifest.json').read_text())
    if args.mode == 'backup':
        result = backup(release, manifest)
    elif args.mode == 'deploy':
        result = deploy(release, manifest)
    elif args.mode == 'rollback':
        result = rollback(release)
    else:
        for row in manifest['files']:
            if digest(target(row['path'])) != row['sha256']:
                raise RuntimeError('Canonical hash mismatch: ' + row['path'])
        result = {'canonical_files_verified': len(manifest['files']), 'commit': manifest['commit']}
    print(json.dumps(result))


if __name__ == '__main__':
    main()

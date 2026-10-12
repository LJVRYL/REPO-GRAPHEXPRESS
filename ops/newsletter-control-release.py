"""Localized source release with verified private backups; rollback preserves all mail ledgers."""
import argparse, hashlib, json, os, shutil, sqlite3, subprocess
from pathlib import Path

ROOT=Path('/home/graphexpress/public_html')
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def php(script,env=None):
    p=subprocess.run(['/opt/php7-4/bin/php',str(script)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,timeout=90)
    if p.returncode:raise RuntimeError('Private native operation failed')
    text=p.stdout.decode('utf-8','replace');return json.loads(text[text.index('{'):])
def main():
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['publish','rollback']);parser.add_argument('release',type=Path);args=parser.parse_args();release=args.release.resolve()
    if not str(release).startswith('/home/graphexpress/releases/newsletter-control-'):raise RuntimeError('Wrong release root')
    m=json.loads((release/'manifest.json').read_text());os.chmod(str(release),0o700)
    if args.mode=='rollback':
        # Restoring the previous newsletter entry point disables this dispatcher. Ledgers stay intact.
        for relative,record in m['files'].items():
            target=ROOT/relative
            if record['before'] is not None:
                if digest(target)!=record['after']:raise RuntimeError('Live source drift; forward fix required')
        restore_order=sorted(m['files'],key=lambda x:x.endswith('/class-ge-wtp-newsletter.php'))
        for relative in restore_order:
            record=m['files'][relative]
            if record['before'] is not None:shutil.copy2(str(release/'before'/relative),str(ROOT/relative))
        print(json.dumps({'source_rollback':True,'ledgers_preserved':True}));return
    for relative,record in m['files'].items():
        target=ROOT/relative;candidate=release/'candidate'/relative
        if digest(candidate)!=record['after']:raise RuntimeError('Candidate hash mismatch')
        if record['before'] is not None and digest(target)!=record['before']:raise RuntimeError('Live source drift')
        if record['before'] is None and target.exists():raise RuntimeError('New helper already exists')
        for runtime in ['/opt/php7-4/bin/php','/opt/php8-1/bin/php']:
            p=subprocess.run([runtime,'-l',str(candidate)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            if p.returncode:raise RuntimeError('PHP syntax failed')
        if target.exists():
            backup=release/'before'/relative;backup.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(str(target),str(backup))
            drill=release/'restore-drill'/relative;drill.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(str(backup),str(drill))
            if digest(drill)!=record['before']:raise RuntimeError('Source restore drill failed')
    backup=php(release/'newsletter-control-backup.php',dict(os.environ,GE_RELEASE=str(release)))
    data=json.loads((release/'native-before.json').read_text());db=sqlite3.connect(':memory:')
    for name,rows in data['tables'].items():
        if not rows:continue
        keys=list(rows[0]);db.execute('CREATE TABLE '+name+' ('+','.join(k+' TEXT' for k in keys)+')')
        db.executemany('INSERT INTO '+name+' VALUES ('+','.join('?' for k in keys)+')',[tuple(None if r[k] is None else str(r[k]) for k in keys) for r in rows])
        if db.execute('SELECT COUNT(*) FROM '+name).fetchone()[0]!=len(rows):raise RuntimeError('Native backup decode drill failed')
    if digest(release/'native-before.json')!=backup['sha256']:raise RuntimeError('Native backup hash mismatch')
    backup['source_restore_drill']=True;backup['native_decode_restore_drill']=True;(release/'backup-verified.json').write_text(json.dumps(backup,indent=2))
    publish_order=sorted(m['files'],key=lambda x:(m['files'][x]['before'] is not None,not x.endswith('/class-ge-wtp-newsletter.php')))
    for relative in publish_order:
        record=m['files'][relative]
        target=ROOT/relative;temp=target.with_suffix('.newsletter-next');shutil.copy2(str(release/'candidate'/relative),str(temp));os.chmod(str(temp),0o644);shutil.chown(str(temp),user='graphexpress',group='graphexpress');os.replace(str(temp),str(target))
        if digest(target)!=record['after']:raise RuntimeError('Published hash mismatch')
    print(json.dumps({'published':True,'files':len(m['files']),'backup':backup,'commit':m['commit']}))
if __name__=='__main__':main()

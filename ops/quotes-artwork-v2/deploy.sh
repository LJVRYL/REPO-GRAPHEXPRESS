#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
test "$(hostname)" = vps-3673733-x.dattaweb.com
stage=/root/qa-graphex-quotes-artwork-v2-20261002
site=/home/graphexpress/public_html
plugin=$site/wp-content/plugins/ge-webtoprint-calculator
backup=/root/ge-backups/quotes-artwork-v2-20261002
exec 9>/root/ge-backups/quotes-artwork-v2-deploy.lock
flock -n 9
test ! -e "$backup/DEPLOY_COMPLETED"
mkdir -p "$backup/code" "$backup/config"
chmod 700 "$backup"
python - "$stage" "$plugin" <<'PY'
import sys,json,hashlib,os
stage,plugin=sys.argv[1:]
for rel,expected in json.load(open(stage+'/expected.json')).items():
 p=plugin+'/'+rel
 if expected is None:
  if os.path.exists(p):raise SystemExit('ABORT concurrent new file '+rel)
 elif not os.path.isfile(p) or hashlib.sha256(open(p,'rb').read()).hexdigest()!=expected:raise SystemExit('ABORT concurrent source '+rel)
print('EXACT_SOURCE_VERIFIED')
PY
cp -a /opt/ferozo/conf/vhosts.d/150-graphexpress.conf /opt/ferozo/conf/vhosts.d/160-graphex.conf "$backup/config/"
cp -a /opt/php7-4/etc/php-fpm.d/0.conf "$backup/config/0.conf"
cp -a "$site/wp-content/ge-private/markcom/.htaccess" "$backup/config/markcom.htaccess"
test ! -e /opt/php7-4/etc/php-fpm.d/graphex-artwork-v2.conf
test ! -e "$site/wp-content/mu-plugins/ge-request-size-guard.php"
while IFS= read -r rel; do
 mkdir -p "$backup/code/$(dirname "$rel")"
 if test -f "$plugin/$rel";then cp -a "$plugin/$rel" "$backup/code/$rel";fi
 /opt/php7-4/bin/php-cli -l "$stage/plugin/$rel" >/dev/null 2>&1 || { test "${rel##*.}" != php; }
done < <(python -c 'import json;print("\n".join(json.load(open("'$stage'/expected.json"))))')
tar -czf "$backup/code-originals.tar.gz" -C "$backup" code config
gzip -t "$backup/code-originals.tar.gz"
tar -tzf "$backup/code-originals.tar.gz" >/dev/null
(cd "$backup" && sha256sum code-originals.tar.gz > SHA256SUMS && sha256sum -c SHA256SUMS)
cp "$stage/rollback.sh" "$backup/rollback.sh"
cp "$stage/expected.json" "$backup/expected.json"
# Install only the reviewed delta. File ownership follows production.
while IFS= read -r rel; do
 install -o apache -g webusers -m 0644 "$stage/plugin/$rel" "$plugin/$rel"
done < <(python -c 'import json;print("\n".join(json.load(open("'$stage'/expected.json"))))')
install -o apache -g webusers -m 0644 "$stage/ge-request-size-guard.php" "$site/wp-content/mu-plugins/ge-request-size-guard.php"
install -o root -g root -m 0644 "$stage/graphex-artwork-v2.conf" /opt/php7-4/etc/php-fpm.d/graphex-artwork-v2.conf
test ! -L /opt/php7-4/var/log/graphex-php.log
if ! test -f /opt/php7-4/var/log/graphex-php.log; then install -o apache -g webusers -m 0600 /dev/null /opt/php7-4/var/log/graphex-php.log; fi
python - <<'PY'
from __future__ import print_function
for path in ['/opt/ferozo/conf/vhosts.d/150-graphexpress.conf','/opt/ferozo/conf/vhosts.d/160-graphex.conf']:
 s=open(path).read();old='/opt/php7-4/var/run/php-fpm/default.sock';assert old in s
 open(path,'w').write(s.replace(old,'/opt/php7-4/var/run/php-fpm/graphex.sock'))
p='/home/graphexpress/public_html/wp-content/ge-private/markcom/.htaccess'
s=open(p).read();s='\n'.join(line for line in s.splitlines() if line.strip().lower()!='deny from all')+'\n';assert 'Require all denied' in s;open(p,'w').write(s)
PY
if ! /opt/php7-4/sbin/php-fpm -t || ! /opt/ferozo/bin/httpd -t;then bash "$backup/rollback.sh";exit 1;fi
systemctl reload php74-php-fpm
test -S /opt/php7-4/var/run/php-fpm/graphex.sock || sleep 1
test -S /opt/php7-4/var/run/php-fpm/graphex.sock
systemctl reload ferozo
python - "$stage" "$plugin" "$backup" <<'PY'
import sys,json,hashlib
s,p,b=sys.argv[1:];d={}
for rel in json.load(open(s+'/expected.json')):
 expected=hashlib.sha256(open(s+'/plugin/'+rel,'rb').read()).hexdigest();assert hashlib.sha256(open(p+'/'+rel,'rb').read()).hexdigest()==expected;d[rel]=expected
json.dump(d,open(b+'/deployed.json','w'),indent=2)
PY
date -u +%FT%TZ > "$backup/DEPLOY_COMPLETED"
echo "SELECTIVE_DEPLOY_OK backup=$backup"

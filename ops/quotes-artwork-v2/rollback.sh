#!/usr/bin/env bash
set -Eeuo pipefail
backup=/root/ge-backups/quotes-artwork-v2-20261002
plugin=/home/graphexpress/public_html/wp-content/plugins/ge-webtoprint-calculator
mkdir -p "$backup/retired"
if test -f "$backup/deployed.json";then
 python - "$backup" "$plugin" <<'PY'
import json,sys,hashlib
b,p=sys.argv[1:]
for rel,expected in json.load(open(b+'/deployed.json')).items():
 if hashlib.sha256(open(p+'/'+rel,'rb').read()).hexdigest()!=expected:raise SystemExit('ABORT rollback would overwrite later deployment '+rel)
PY
fi
while IFS= read -r rel;do
 if test -f "$backup/code/$rel";then cp -a "$backup/code/$rel" "$plugin/$rel";else mkdir -p "$backup/retired/$(dirname "$rel")";if test -f "$plugin/$rel";then mv "$plugin/$rel" "$backup/retired/$rel";fi;fi
done < <(python -c 'import json;print("\n".join(json.load(open("'$backup'/expected.json"))))')
cp -a "$backup/config/150-graphexpress.conf" /opt/ferozo/conf/vhosts.d/150-graphexpress.conf
cp -a "$backup/config/160-graphex.conf" /opt/ferozo/conf/vhosts.d/160-graphex.conf
cp -a "$backup/config/markcom.htaccess" /home/graphexpress/public_html/wp-content/ge-private/markcom/.htaccess
for file in /opt/php7-4/etc/php-fpm.d/graphex-artwork-v2.conf /home/graphexpress/public_html/wp-content/mu-plugins/ge-request-size-guard.php;do if test -f "$file";then mv "$file" "$backup/retired/$(basename "$file")";fi;done
/opt/php7-4/sbin/php-fpm -t
/opt/ferozo/bin/httpd -t
systemctl reload php74-php-fpm
systemctl reload ferozo
echo ROLLBACK_OK_BYTES_AND_DB_PRESERVED

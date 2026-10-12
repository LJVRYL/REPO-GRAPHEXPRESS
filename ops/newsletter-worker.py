"""Private Graphex worker. Correlates local Exim evidence; never blindly retries SMTP."""
import argparse, hashlib, json, os, re, subprocess, time, email.policy
from email.parser import BytesParser
from pathlib import Path

MID = re.compile(r'\bid=<?(ge-nl-[a-f0-9]{64}@graphex\.ar)>?')
QUEUE = re.compile(r'^[a-zA-Z0-9]{6}-[a-zA-Z0-9]{6}-[a-zA-Z0-9]{2,6}$')
TRUSTED_PEERS={'200.58.125.43','200.58.116.136','200.58.116.111'}

def delivery_reports(raw_messages, lines, deliveries):
    by_mid={r['message_id'].strip('<>'):r for r in deliveries};trusted=set()
    for line in lines:
        if ' <= <> ' not in line:continue
        peer=re.search(r'\[([0-9.]+)\]',line);mid=re.search(r'\bid=<?([^\s<>]+)>?',line)
        if peer and mid and peer.group(1) in TRUSTED_PEERS:trusted.add(mid.group(1))
    result=[]
    for raw in raw_messages:
        msg=BytesParser(policy=email.policy.default).parsebytes(raw)
        if msg.get_content_type()!='multipart/report' or str(msg.get('Message-ID','')).strip('<>') not in trusted:continue
        if str(msg.get_param('report-type','')).lower()!='delivery-status':continue
        originals=[];reports=[]
        for part in msg.walk():
            if part.get_content_type()=='message/rfc822':
                for original in part.get_payload() if isinstance(part.get_payload(),list) else []:originals.append(str(original.get('Message-ID','')).strip('<>'))
            elif part.get_content_type()=='text/rfc822-headers':
                original=BytesParser(policy=email.policy.default).parsebytes(part.get_payload(decode=True) or b'');originals.append(str(original.get('Message-ID','')).strip('<>'))
            elif part.get_content_type()=='message/delivery-status':reports.extend(part.get_payload() if isinstance(part.get_payload(),list) else [])
        for original_mid in set(originals):
            row=by_mid.get(original_mid)
            if not row:continue
            for report in reports:
                recipient=str(report.get('Final-Recipient','')).split(';')[-1].strip().lower();action=str(report.get('Action','')).lower();status=str(report.get('Status',''))
                if action not in ('failed','delayed') or not status.startswith(('4.','5.')) or hashlib.sha256(recipient.encode()).hexdigest()!=row['recipient_sha256']:continue
                result.append({'delivery_id':int(row['id']),'message_id':row['message_id'],'recipient_sha256':row['recipient_sha256'],'kind':'temporary_bounce' if status.startswith('4.') else 'hard_bounce','diagnostic':'Informe del relay de correo, estado '+status[:20],'evidence_ref':'dsn:'+hashlib.sha256(raw).hexdigest(),'queue_id':'','freeze':False})
    return result

def feedback(lines, deliveries):
    by_mid = {r['message_id'].strip('<>'): r for r in deliveries}
    incoming = {}
    for line in lines:
        fields = line.split()
        match = MID.search(line)
        if len(fields)>4 and fields[3]=='<=' and match and match.group(1) in by_mid:
            incoming[fields[2]] = by_mid[match.group(1)]
    events=[]
    for line in lines:
        fields=line.split()
        if len(fields)<5 or fields[2] not in incoming or fields[3] not in ('**','=='):continue
        row=incoming[fields[2]];recipient=fields[4].strip('<>').lower()
        if hashlib.sha256(recipient.encode()).hexdigest()!=row['recipient_sha256']:continue
        events.append({'delivery_id':int(row['id']),'message_id':row['message_id'],'recipient_sha256':row['recipient_sha256'],
                       'kind':'hard_bounce' if fields[3]=='**' else 'temporary_bounce',
                       'diagnostic':'Fallo permanente' if fields[3]=='**' else 'Entrega diferida; dirección suspendida',
                       'evidence_ref':'exim:'+hashlib.sha256(line.encode()).hexdigest(),'queue_id':fields[2],
                       'freeze':fields[3]=='=='})
    return events

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--release',type=Path,required=True);parser.add_argument('--once',action='store_true');args=parser.parse_args()
    release=args.release.resolve();manifest=json.loads((release/'manifest.json').read_text())
    for relative,expected in manifest['private_worker'].items():
        if hashlib.sha256((release/relative).read_bytes()).hexdigest()!=expected:raise RuntimeError('Worker source drift')
    def call(packet):
        env=dict(os.environ,GE_WP_LOAD='/home/graphexpress/public_html/wp-load.php')
        proc=subprocess.run(['/opt/php7-4/bin/php',str(release/'newsletter-worker.php')],input=json.dumps(packet).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,timeout=90)
        if proc.returncode:raise RuntimeError('Native worker failed; no replay of a claimed attempt')
        output=proc.stdout.decode('utf-8','replace');return json.loads(output[output.index('{'):])
    while True:
        try:
            inventory=call({'operation':'inventory'})
            if time.time()>inventory['policy_expires_at']:break
            # Exim rotates this local trusted log; no email body is interpreted as authority.
            lines=Path('/var/spool/exim/log/mainlog').read_text(errors='replace').splitlines()
            events=feedback(lines,inventory['deliveries'])
            mailbox=Path('/home/graphex/mail/graphexpress.com.ar/servicio/Maildir')
            candidates=[]
            for folder in ('new','cur'):
                for path in (mailbox/folder).glob('*'):
                    if path.is_file() and path.stat().st_mtime>time.time()-86400 and path.stat().st_size<2*1024*1024:candidates.append(path)
            # Only inspect bounded recent reports in the configured service mailbox; do not mark/read/delete mail.
            raws=[]
            for path in sorted(candidates,key=lambda p:p.stat().st_mtime,reverse=True)[:200]:
                with path.open('rb') as stream:headers=stream.read(8192).split(b'\n\n',1)[0].lower()
                if b'report-type=delivery-status' in headers or b'report-type="delivery-status"' in headers:raws.append(path.read_bytes())
            events.extend(delivery_reports(raws,lines,inventory['deliveries']))
            for e in events:
                if e['freeze'] and QUEUE.fullmatch(e['queue_id']):
                    subprocess.run(['/opt/exim/bin/exim','-Mf',e['queue_id']],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=15)
            reconciled=call({'operation':'reconcile','events':events})
            result=call({'operation':'process'})
            print(json.dumps({'at':int(time.time()),'feedback':reconciled,'dispatch':result}),flush=True)
        except Exception:
            print(json.dumps({'at':int(time.time()),'state':'review_required'}),flush=True)
        if args.once:break
        time.sleep(10)

if __name__=='__main__':main()

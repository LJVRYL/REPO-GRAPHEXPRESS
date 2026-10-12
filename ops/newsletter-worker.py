"""Private Graphex worker. Correlates local Exim evidence; never blindly retries SMTP."""
import argparse, hashlib, json, os, re, subprocess, time
from pathlib import Path

MID = re.compile(r'\bid=<?(ge-nl-[a-f0-9]{64}@graphex\.ar)>?')
QUEUE = re.compile(r'^[a-zA-Z0-9]{6}-[a-zA-Z0-9]{6}-[a-zA-Z0-9]{2,6}$')

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

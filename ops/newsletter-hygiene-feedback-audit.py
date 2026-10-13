"""Private read-only audit; emits counts, never recipients or email bodies."""
import collections, importlib.util, json, os, subprocess, time, re
from pathlib import Path
release=Path('/home/graphexpress/releases/newsletter-hygiene-20261013-0310')
spec=importlib.util.spec_from_file_location('worker',release/'newsletter-worker.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
env=dict(os.environ,GE_WP_LOAD='/home/graphexpress/public_html/wp-load.php')
p=subprocess.run(['/opt/php7-4/bin/php',str(release/'newsletter-worker.php')],input=b'{"operation":"inventory"}',stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,check=True)
output=p.stdout.decode();inventory=json.loads(output[output.index('{'):])
lines=[]
for logname in ('mainlog.01','mainlog'):
 logpath=Path('/var/spool/exim/log')/logname
 if logpath.is_file():lines.extend(logpath.read_text(errors='replace').splitlines())
mailbox=Path('/home/graphex/mail/graphexpress.com.ar/servicio/Maildir');raws=[]
for folder in ('new','cur'):
 for path in (mailbox/folder).glob('*'):
  if not path.is_file() or path.stat().st_mtime<time.time()-172800 or path.stat().st_size>2*1024*1024:continue
  with path.open('rb') as stream:headers=stream.read(8192).split(b'\n\n',1)[0].lower()
  if b'report-type=delivery-status' in headers or b'report-type="delivery-status"' in headers:raws.append(path.read_bytes())
report_ids={str(m.BytesParser(policy=m.email.policy.default).parsebytes(raw).get('Message-ID','')).strip('<>') for raw in raws}
peers=collections.Counter();arrival_ids=set()
for line in lines:
 midmatch=re.search(r'\bid=<?([^\s<>]+)>?',line)
 if ' <= <> ' in line and midmatch and midmatch.group(1) in report_ids:
  peer=re.search(r'\[([0-9.]+)\]',line);peers[peer.group(1) if peer else 'local']=peers[peer.group(1) if peer else 'local']+1;arrival_ids.add(midmatch.group(1))
events=m.feedback(lines,inventory['deliveries'])+m.delivery_reports(raws,lines,inventory['deliveries'])
rank={'review_bounce':0,'temporary_bounce':1,'policy_bounce':2,'hard_bounce':3};by_delivery={}
for e in events:
 old=by_delivery.get(e['delivery_id'])
 if old is None or rank[e['kind']]>rank[old['kind']]:by_delivery[e['delivery_id']]=e
contexts={};by_mid={r['message_id'].strip('<>'):r for r in inventory['deliveries']}
for raw in raws:
 mids=set(mid.decode() for mid in re.findall(br'ge-nl-[a-f0-9]{64}@graphex\.ar',raw))
 if len(mids)!=1:continue
 row=by_mid.get(next(iter(mids)))
 if not row:continue
 report_id=str(m.BytesParser(policy=m.email.policy.default).parsebytes(raw).get('Message-ID','')).strip('<> ')
 contexts[m.hashlib.sha256(report_id.encode()).hexdigest()]={'campaign_id':int(row['campaign_id']),'delivery_id':int(row['id']),'recipient_sha256':row['recipient_sha256'],'reference_only':True}
path=release/'crm-contexts.json';path.write_text(json.dumps(contexts));os.chmod(str(path),0o600)
path=release/'verified-feedback.json';path.write_text(json.dumps(list(by_delivery.values())));os.chmod(str(path),0o600)
print(json.dumps({'report_arrival_peer_count':len(peers),'crm_reference_contexts':len(contexts),'reports_without_arrival':len(report_ids-arrival_ids),'reports_with_campaign_reference':sum(bool(re.search(br'ge-nl-[a-f0-9]{64}@graphex\.ar',raw)) for raw in raws),'distinct_campaign_references':len(set(mid for raw in raws for mid in re.findall(br'ge-nl-[a-f0-9]{64}@graphex\.ar',raw))),'reports_inspected':len(raws),'correlated_events':len(events),'distinct_deliveries':len(by_delivery),'classification':dict(collections.Counter(e['kind'] for e in by_delivery.values())),'codes':dict(collections.Counter(e['diagnostic'] for e in by_delivery.values()))}))

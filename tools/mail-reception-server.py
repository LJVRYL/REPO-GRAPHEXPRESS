"""Read the existing Dovecot mailbox locally; durable CRM before completion.

No IMAP credentials, SMTP, Seen changes, deletion, or external requests.
"""
import argparse, base64, datetime, email, fcntl, hashlib, json, os, re
import subprocess, time
from email import policy
from email.utils import getaddresses, parsedate_to_datetime
from pathlib import Path

ROOT=Path('/home/graphexpress/mail-reception')
MAILDIR=Path('/home/graphex/mail/graphexpress.com.ar/servicio/Maildir')
ACCOUNT='servicio@graphexpress.com.ar'
WEB_GID=Path('/home/graphexpress/public_html/wp-load.php').stat().st_gid
MAX_RAW=16*1024*1024
PREFIX=256*1024

def run(args):
    return subprocess.check_output(args,timeout=60).decode('utf-8','replace')

def atomic(path,data):
    temp=path.with_suffix('.tmp');temp.write_bytes(json.dumps(data,ensure_ascii=False).encode());os.chmod(str(temp),0o600);os.replace(str(temp),str(path))

def dovecot(args):
    return run(['/usr/bin/doveadm']+args)

def identity(validity,uid):
    key=base64.urlsafe_b64encode(json.dumps(['graphex','INBOX',str(validity),uid],separators=(',',':')).encode()).decode().rstrip('=')
    return key,hashlib.sha256(key.encode()).hexdigest()

def source_path(storageid):
    if not re.fullmatch(r'[A-Za-z0-9_.-]{1,240}',storageid):raise ValueError('Invalid mail storage identity')
    candidates=[]
    for folder in ('cur','new'):
        candidates.extend((MAILDIR/folder).glob(storageid+'*'))
    candidates=[p for p in candidates if p.name.split(':',1)[0]==storageid and not p.is_symlink()]
    if len(candidates)!=1:raise ValueError('Mail file mapping ambiguous')
    p=candidates[0].resolve()
    if p.parent not in [(MAILDIR/'cur').resolve(),(MAILDIR/'new').resolve()]:raise ValueError('Mail path outside mailbox')
    return p

def parse_message(raw,truncated=False):
    msg=email.message_from_bytes(raw,policy=policy.default)
    headers={k.lower():str(msg.get(k,''))[:2000] for k in ('From','To','Subject','Date','Message-ID','References','In-Reply-To','Auto-Submitted','Precedence','List-ID','Return-Path','Content-Type','Reply-To')}
    parts=[];texts=[]
    for index,part in enumerate(msg.walk()):
        if part.is_multipart():continue
        if part.get_filename() or part.get_content_disposition()=='attachment':
            payload=None if truncated else part.get_payload(decode=True)
            parts.append({'index':index,'filename':str(part.get_filename() or 'Adjunto')[:200],'content_type':part.get_content_type(),'payload':payload})
        elif part.get_content_type()=='text/plain':
            try:texts.append(str(part.get_content())[:12001])
            except Exception:texts.append('[Texto pendiente de lectura]')
    if not texts:
        from html.parser import HTMLParser
        class Text(HTMLParser):
            def __init__(self):super().__init__(convert_charrefs=True);self.bits=[];self.skip=0
            def handle_starttag(self,tag,attrs):
                if tag in ('script','style','head'):self.skip+=1
                elif tag in ('p','br','div','li'):self.handle_data('\n')
            def handle_endtag(self,tag):
                if tag in ('script','style','head') and self.skip:self.skip-=1
            def handle_data(self,data):
                if not self.skip:self.bits.append(data)
        for part in msg.walk():
            if part.get_content_type()=='text/html' and not part.get_filename():
                parser=Text();parser.feed(str(part.get_content())[:256000]);texts.append(''.join(parser.bits)[:12001]);break
    body='\n'.join(texts)
    body=body.encode()[:12000].decode('utf-8','ignore')
    return headers,body,parts,truncated or sum(len(t.encode()) for t in texts)>12000

def packet(validity,uid,headers,body,parts,truncated,received):
    key,eid=identity(validity,uid)
    senders=getaddresses([headers['from']]);sender=senders[0][1] if len(senders)==1 else 'unresolved-'+eid[:16]+'@message.invalid'
    roots=re.findall(r'<[^<>\s]{1,300}>',headers['references'] or headers['in-reply-to'] or headers['message-id'])
    return {'organization_id':'graph-express','channel':'email','account_ref':'graphex','external_id':eid,'conversation_id':hashlib.sha256((roots[0] if roots else key).encode()).hexdigest(),'source_ref':'mail:'+key,'received_at':datetime.datetime.fromtimestamp(received,datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),'from':sender,'to':'servicio@graphex.ar','subject':headers['subject'].encode()[:180].decode('utf-8','ignore'),'body':body,'headers':headers,'attachment_refs':['mail-part:'+key+':'+str(p['index']) for p in parts[:20]],'historical_backfill':False,'body_truncated':truncated or len(headers['subject'].encode())>180 or len(parts)>20,'reply_ambiguous':bool(len(senders)!=1 or (headers['reply-to'] and getaddresses([headers['reply-to']])!=senders)),'external_reply_suppressed':True}

def deliver(data):
    target=ROOT/'state/input.json';atomic(target,data)
    env=dict(os.environ,GE_MAIL_INPUT=str(target))
    result=subprocess.run(['/opt/php7-4/bin/php',str(ROOT/'mail-reception-native.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,timeout=90)
    output=result.stdout.decode('utf-8','replace');start=output.find('{')
    if result.returncode or start<0:raise RuntimeError('CRM receiver rejected packet')
    receipt=json.loads(output[start:])
    if not receipt.get('committed'):raise RuntimeError('CRM did not confirm committed receipt')
    return receipt

def scan(only_uid=None,inspect=False):
    status=dovecot(['mailbox','status','-u',ACCOUNT,'uidvalidity uidnext messages','INBOX'])
    validity=re.search(r'uidvalidity=(\d+)',status).group(1)
    found=dovecot(['search','-u',ACCOUNT,'mailbox','INBOX','since','2026-10-11'])
    uids=sorted(set(int(line.split()[-1]) for line in found.splitlines() if line.strip()))
    if only_uid is not None:uids=[u for u in uids if u==only_uid]
    done_path=ROOT/'state/receipts.json'
    done=json.loads(done_path.read_text()) if done_path.exists() else {}
    pending_path=ROOT/'state/pending.json'
    pending=json.loads(pending_path.read_text()) if pending_path.exists() else {}
    validity_path=ROOT/'state/uidvalidity.json'
    if validity_path.exists() and json.loads(validity_path.read_text())['validity']!=validity:
        raise RuntimeError('Mailbox generation changed; reconcile source identities before importing')
    if not inspect and not validity_path.exists():atomic(validity_path,{'validity':validity})
    uids=sorted(set(uids+[int(v['uid']) for v in pending.values()]))
    summary={'checked_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'runtime':'VPS','seen':len(uids),'new':0,'duplicates':0,'failed':0,'external_sent':0,'pending_events':0,'receipts':[]}
    for uid in uids:
        key,eid=identity(validity,uid)
        if eid in done:continue
        try:
            meta=dovecot(['fetch','-u',ACCOUNT,'uid storageid date.received.unixtime','mailbox','INBOX','uid',str(uid)])
            storage=re.search(r'^storageid: (.+)$',meta,re.M).group(1)
            received=int(re.search(r'^date.received.unixtime: (\d+)',meta,re.M).group(1))
            path=source_path(storage);truncated=path.stat().st_size>MAX_RAW
            with path.open('rb') as fh:raw=fh.read(PREFIX if truncated else MAX_RAW+1)
            headers,body,parts,truncated=parse_message(raw,truncated)
            if inspect:
                summary['receipts'].append({'uid':uid,'subject':headers['subject'],'body':body[:3000],'attachments':[{'filename':p['filename'],'type':p['content_type']} for p in parts]});continue
            attachments=[]
            for p in parts[:20]:
                blob=p['payload'];entry={k:v for k,v in p.items() if k!='payload'}
                if blob is not None:
                    digest=hashlib.sha256(blob).hexdigest();dest=ROOT/'attachments'/eid/(digest+'.blob');dest.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
                    os.chown(str(dest.parent),0,WEB_GID);os.chmod(str(dest.parent),0o750)
                    if not dest.exists():dest.write_bytes(blob)
                    os.chown(str(dest),0,WEB_GID);os.chmod(str(dest),0o640)
                    if hashlib.sha256(dest.read_bytes()).hexdigest()!=digest:raise RuntimeError('Attachment checksum mismatch')
                    entry.update(sha256=digest,size=len(blob),stored=True)
                else:entry.update(stored=False,size=None)
                attachments.append(entry)
            receipt=deliver({'packet':packet(validity,uid,headers,body,parts,truncated,received),'attachments':attachments})
            if not receipt.get('record_id'):raise RuntimeError('Missing committed CRM record')
            done[eid]={'uid':uid,'record_id':receipt['record_id'],'saved_at':time.time()};atomic(done_path,done)
            pending.pop(eid,None)
            summary['duplicates' if receipt.get('duplicate') else 'new']+=1
            summary['receipts'].append(dict(uid=uid,**receipt))
        except Exception:
            summary['failed']+=1
            pending.setdefault(eid,{'uid':uid,'created_at':time.time(),'error_code':'message_processing_failed'})
    summary['pending_events']=len(pending)
    summary['oldest_pending_age_seconds']=int(max([time.time()-v['created_at'] for v in pending.values()] or [0]))
    if not inspect:
        atomic(pending_path,pending)
        deliver({'operation':'health','receiver_running':True,'pending_events':len(pending),'oldest_pending_age_seconds':summary['oldest_pending_age_seconds'],'poll_failed':bool(pending),'error_code':'message_processing_failed' if pending else ''})
        atomic(ROOT/'state/health.json',summary)
    return summary

def main():
    p=argparse.ArgumentParser();p.add_argument('--uid',type=int);p.add_argument('--inspect',action='store_true');p.add_argument('--watch',action='store_true');a=p.parse_args()
    os.umask(0o077);(ROOT/'state').mkdir(parents=True,exist_ok=True,mode=0o700)
    with (ROOT/'state/worker.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        while True:
            try:result=scan(a.uid,a.inspect)
            except Exception:
                result={'checked_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'runtime':'VPS','failed':1,'error_code':'receiver_scan_failed','external_sent':0}
                if not a.inspect:
                    atomic(ROOT/'state/health.json',result)
                    try:deliver({'operation':'health','receiver_running':True,'pending_events':1,'poll_failed':True,'error_code':'receiver_scan_failed'})
                    except Exception:pass
            if not a.watch:print(json.dumps(result,ensure_ascii=True));return
            time.sleep(60)

if __name__=='__main__':main()

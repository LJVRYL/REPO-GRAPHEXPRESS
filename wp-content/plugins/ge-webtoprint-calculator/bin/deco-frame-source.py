"""Read-only public frame adapter; generated from the reviewed pricing-v1 parser."""
from urllib.request import Request,urlopen
from urllib.parse import urlsplit
from decimal import Decimal
import json,re,hashlib,os

def parse(html):
 marker='LS.variants =';start=html.find(marker)
 if start<0:raise ValueError('variant_data_missing')
 variants,_=json.JSONDecoder().raw_decode(html[start+len(marker):].lstrip())
 if not isinstance(variants,list) or not variants:raise ValueError('empty_variants')
 out={}
 for v in variants:
  ident=int(v['id']);raw=v.get('price_number_raw')
  decimal_cents=Decimal(str(raw)) if raw is not None else Decimal(str(v['price_number']))*100
  if not decimal_cents.is_finite() or decimal_cents!=decimal_cents.to_integral_value():raise ValueError('invalid_price_precision')
  cents=int(decimal_cents)
  if cents<=0 or ident in out:raise ValueError('invalid_price_or_duplicate')
  if type(v.get('available')) is not bool or any(k in v and type(v[k]) is not bool for k in ('is_visible','contact')):raise ValueError('invalid_availability_schema')
  out[ident]={'id':ident,'price_cents':cents,'available':bool(v.get('available')) and v.get('is_visible',True) and not v.get('contact',False),'options':[v[k] for k in ('option0','option1','option2') if v.get(k) is not None]}
 return out

def fetch(url):
 try:
  allowed=json.loads(os.environ.get('GE_DECO_FRAME_SOURCE_HOSTS','[]'))
  if not isinstance(allowed,list) or not allowed or any(not isinstance(h,str) or not re.fullmatch(r'[a-z0-9.-]+',h) for h in allowed):raise ValueError('source_hosts_not_configured')
  parsed=urlsplit(url)
  if parsed.hostname not in allowed or parsed.scheme!='https' or parsed.username or parsed.password or parsed.port not in (None,443):raise ValueError('unexpected_source')
  with urlopen(Request(url,headers={'User-Agent':'GraphexCatalogPriceReview/1.0','Accept':'text/html'}),timeout=35) as response:
   if urlsplit(response.url).hostname not in allowed or urlsplit(response.url).scheme!='https':raise ValueError('unexpected_redirect')
   body=response.read(4_000_001)
  if len(body)>4_000_000:raise ValueError('oversize_response')
  html=body.decode('utf8');currencies=re.findall(r'"priceCurrency"\s*:\s*"([A-Z]+)"',html)
  if not currencies or any(c!='ARS' for c in currencies):raise ValueError('currency_not_confirmed_ARS')
  return {'source':url,'sha256':hashlib.sha256(body).hexdigest(),'variants':parse(html)}
 except Exception as e:return {'source':url,'error':type(e).__name__+': '+str(e)[:160]}

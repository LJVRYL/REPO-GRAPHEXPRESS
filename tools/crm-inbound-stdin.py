"""Root-authenticated private bridge; no public endpoint or stored credentials."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

mode = sys.argv[1] if len(sys.argv) == 2 else ""
if mode not in {"qa", "production"}:
    raise SystemExit("Explicit verified environment required")
site = "/home/graphexpress/public_html" if mode == "production" else "/home/graphexpress/job-flow-qa-20261007/site"
raw = sys.stdin.buffer.read(65537)
if len(raw) > 65536:
    raise SystemExit("Envelope too large")
json.loads(raw)
runtime = Path("/home/graphexpress/crm-inbound/runtime") if mode == "production" else Path("/home/graphexpress/job-flow-qa-20261007")
runtime.mkdir(mode=0o700, parents=True, exist_ok=True)
fd, filename = tempfile.mkstemp(prefix="crm-event-", dir=str(runtime))
try:
    with os.fdopen(fd, "wb") as handle:
        handle.write(raw)
    env = os.environ.copy()
    env.update(GE_CRM_SITE=site, GE_CRM_INPUT=filename)
    run = subprocess.run(["/opt/php7-4/bin/php", "-d", "memory_limit=" + ("256M" if mode == "qa" else "128M"), str(Path(__file__).with_name("crm-inbound.php"))], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=80)
    output = run.stdout.decode("utf-8", "replace")
    start = output.find('{"record_id"')
    if start < 0:
        start = output.find('{"committed"')
    if start < 0:
        print('{"committed":false,"error":"invalid_consumer_response"}')
        raise SystemExit(1)
    result = json.loads(output[start:])
    print(json.dumps(result))
    raise SystemExit(0 if result.get("committed") else 1)
finally:
    os.unlink(filename)

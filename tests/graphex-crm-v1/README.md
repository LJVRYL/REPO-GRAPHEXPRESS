# CRM v1 verification

Evidence lives in the task Result Pack. These are WordPress integration tests, not mocks: use a fresh isolated database, canonical GE plugin + WooCommerce + Organization Foundation, and the CRM MU loader. Never run the fixture scripts on production. The scripts reject databases whose name does not start with `graphex_crm_v1_qa_`.

Run backend tests first in a task harness with `qa/site/wp-load.php`, synthetic issuer/tax settings, owner membership and localhost configuration. Backend writes `qa/fixtures.json`; advanced tests consume it. Browser tests expect that synthetic site on localhost:18820, Chrome and Playwright. Set the Playwright import/runtime path for the host; the checked-in copy records this task's runtime. The local router used during QA authenticates a synthetic owner only, rejects non-loopback requests, and is never part of the production package.

Tests cover real canonical quote creation and conversion, contact review, optimistic revisions, tasks, events, email draft approval, tenant restrictions, CSV and permissions. Advanced checks exercise transaction rollback, idempotency, custom stages, module flag and the current landing request payload. Browser checks exercise HTML forms/nonces, REST drag save and reload, existing Workspace, global search and mobile overflow/touch controls. External HTTP/mail transport is blocked by the QA fixture environment.

Do not embed credentials, wp-config, production customers or uploaded files in the harness or evidence. Keep deployment smoke scripts read only.

# Customer Tax Resolver v1

Project: graph-express. Resource: graph-wordpress-prod, ferozo. Integration: ARCA WSCI/WSAA. This release provides lookup and fiscal guidance, not fiscal issuance.

## Data and workflow

Reuse `_ge_billing_profile` and `_ge_billing_profiles`; no parallel customer entity. Lookup is an explicit authenticated action. An expiring server-side preview is bound to actor, customer and selected profile. Confirmation applies the returned identity to that profile. Posted verification claims never establish official verification. Manual changes retain a manual/pending status.

Persist source, checked time, evidence and verification state. Cache valid lookups for 24 hours and failures briefly, retaining the last valid response separately. A stale or incomplete response is not presented as current official verification. Loading a page never queries ARCA.

Readiness requires recent evidence: customer 24 hours and issuer 30 days by default, configurable only through the internal `ge_wtp_tax_evidence_ttl` filter. Missing, expired, future or invalid dates require review. The saved historical evidence and previously captured snapshots are retained.

Domestic document suggestions depend on actual issuer condition, receiver condition and declared issuer capabilities. RI to RI/monotributo normally requires A; RI to explicit final consumer/exempt requires B; monotributo/exempt issuer requires C. Export requires a separate E flow and remains unknown here. Recipient exemption does not establish exemption of the sale, and document class does not determine an operation's VAT rate or price policy. A to monotributo and consumer transparency rules have additional display requirements. Unknown data never defaults to final consumer.

Issuer suggestion reuses the current catalog and prioritizes compatible, verified issuers over pending defaults. Pending verification, missing relationship, incompatible capability and stale evidence require review. Staff confirms new operations; replacing an issuer requires an authorized role and a reason. Catalog edits do not replace a historical issuer.

Quotes and orders freeze profile, issuer and decision evidence. PDF, portal and email use that snapshot. Commercial totals continue through the existing billing/pricing flow. No CAE request, fiscal emission or real payment is enabled.

## Activation

Option `ge_customer_tax_stage_v1`: 0 disabled, 1 lookup/preview, 2 suggestions, 3 new quote/order integration. Deploy code at 0, verify each phase, then advance sequentially. Returning to 0 disables this feature without deleting evidence or replacing the database.

## Secure ARCA provisioning

Pending credential reference: `arca-graphex-customer-lookup`. Obtain an authorized certificate and delegation for `ws_sr_constancia_inscripcion`; authentication and certificates are environment specific. Do not reuse an emission authorization or assume another issuer's representation.

Set `GE_WTP_ARCA_CONFIG_FILE` in the secure server bootstrap to an absolute PHP configuration file outside WordPress, DocumentRoot and every Git repository. The file returns an array with these keys:

| Key | Required value |
| --- | --- |
| environment | `homologation` or `production` |
| represented_cuit | CUIT explicitly authorized by the delegation |
| certificate_path | Absolute readable external certificate path |
| private_key_path | Absolute readable external private key path |
| runtime_dir | Existing external writable ticket/temp directory |
| private_key_passphrase | Optional; resolved only inside the secure configuration |

Configuration and private key must have no group/other permissions on Unix; runtime directory must also be private. Ownership must permit the PHP service account to read the files and write runtime tickets. Certificate, key, passphrase and tickets must never enter Git, Pages, logs or release archives. The adapter refuses public or repository paths. SOAP tracing is off and exceptions are sanitized. Audit records contain actor, status, source, timestamp, profile reference and a salted CUIT digest rather than raw responses or credentials.

WSCI uses `https://aws.arca.gob.ar/sr-padron/webservices/personaServiceA5?WSDL` (homologation: `awshomo.arca.gob.ar`). WSAA uses the endpoints explicitly published on ARCA's WSAA page: `https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL` (homologation: `wsaahomo.afip.gov.ar`). Do not globally substitute AFIP/ARCA domains. The adapter signs a CMS ticket and securely caches its token/sign until expiration.

## Verification and limits

Tests cover the domestic matrix, malformed/not-found/partial CUIT, outage and cache preservation, capabilities, permissions, spoofing, preview expiration/replay, multiple profiles, overrides, centavo calculations, new quotes, order inheritance and immutable snapshots. Offline authentication tests verify the CMS signature, service name, TLS configuration and rejection of expired/unsafe ticket XML. Mutating QA must run only in the isolated database.

Exempt/no-tax IDs are not inferred from undocumented numbers or missing taxes. Ambiguous official records remain pending. Consumer-final is an explicit manual declaration, not a lookup fallback. An authenticated real lookup still requires the external credentials and delegation. Existing Ayala/Mardones verification and relationship gates remain effective; this module does not verify issuer authorization by querying a customer's CUIT.

## Sources

- [Official WSCI v4.1, March 2026](https://www.arca.gob.ar/ws/WSCI/manual_ws_sr_ws_constancia_inscripcion.pdf)
- [Official WSAA authorization and published endpoints](https://www.arca.gob.ar/ws/documentacion/wsaa.asp)
- [Official document classes and issuer authorizations](https://www.arca.gob.ar/facturacion/regimen-general/comprobantes.asp)
- [Monotributo document classes](https://www.arca.gob.ar/facturacion/monotributo/comprobantes.asp)
- [Consumer fiscal transparency, Law 27.743](https://biblioteca.arca.gob.ar/dcp/LEY_C_027743_2024_06_27)

Rules checked 2026-10-02. Review official rules and actual issuer authorizations before extending to emission or new operation types.

# Registered-user design references

## Behaviour

The design library at `/plantillas-de-diseno/` gains 83 Graphex reference PDFs for current display/textile options. Product pages link by their existing exact option keys: 116 of 164 options have a compatible reference. This does not change prices, quantities, product records or commercial configuration. Existing general guides and validated paper bases retain their current behaviour.

Anonymous visitors see a sign-in action. Every download also requires a valid WordPress session and a per-template nonce. Encoded PDFs live in guarded PHP files, not public `.pdf` paths; direct requests to those files return 404 without content. Successful downloads use no-store/private cache headers, Cookie variance, attachment disposition and nosniff. Model selection follows changes to the existing product option selector without changing that commercial selection. The library works without JavaScript; product panels link to that fallback library.

Customer-facing PDFs, titles and new download surfaces use Graphex identity only. Reseller adaptation/publication permission was explicitly confirmed by Leo in the 2026-10-28 task. Detailed provenance remains in the private/local research inventory, outside deployment.

## Technical references and exclusions

The source collection has 91 unique PDFs and 106 source pages. The adapted set retains 105 meaningful pages, dropping a malformed extra page that contained only a brand-header strip. Technical vector paths, page dimensions and body content are retained; the separately reviewed gazebo 3x3 uses reconstructed paths verified at native coordinates. These are references requiring measurement/finishing validation, not newly certified production bases.

Seven source associations remain held for model or construction inconsistencies: references 016, 020, 024, 028, 035, 061 and 074. Reference 091 belongs only to an inactive product. None is in the serving registry or deployable PDF assets. The associated product options show a request/review message instead of a wrong download. Missing references are never synthesized from photographs or nearby sizes.

## Validation

- 258 PHP fixture checks: all 83 PDF decodes and SHA256 values, exact product mappings, anonymous/member rendering, traversal/unknown/held IDs and supplier-name absence in the library.
- 95 local HTTP checks with explicitly synthetic sessions: anonymous redirects, nonce failures, all 83 authenticated PDF responses and hashes, protected direct file URLs, array input rejection and HEAD semantics.
- All 105 adapted pages rendered with Poppler for visual review; native body path comparisons and source text checks recorded outside Git.
- Production PHP syntax is checked independently by the existing signed code broker at deployment. Live probes must verify anonymous library/product output, private routes and HTTP cache headers.

## Release and rollback

Canonical branch: `master`; follow the repository release policy. Deploy assets first in bounded signed batches, then the registry, JS/CSS, class and final bootstrap include. Each batch is inert until the final loader is active. The broker verifies baseline hashes, stores and verifies a private selective backup, and rejects concurrency drift before writing. No DB or application configuration is replaced.

Record every operation ID in the external Result Pack. Roll back in reverse order: restore the previous bootstrap first, then remove only the new files from the corresponding signed transactions if their hashes still match. Do not restore an old database or roll back unrelated code. Reinspect deployed hashes against the canonical commit before declaring synced.

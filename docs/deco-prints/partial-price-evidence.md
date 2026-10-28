# Per-option price evidence

The approved policy, its revision, geometry approvals and amounts remain immutable.
`deco-price-observer.py --allow-partial` may additionally publish a private
`latest-pricing-partial-observation.json` projection. It is always explicitly
`complete: false`, kind `graphex-price-evidence-per-option`, schema version 1.
`source_complete` describes actual source coverage; unknown sources are not bajas.

Each approved option is bound to its approved real variant ID, source and nominal
variant. Only `verified` rows with identical approved costs, availability and
verified print dimensions can be sold. Their frame/printing timestamps and source
hashes come from the actual observation. Unknown rows have no renewed timestamps.
Changed, unavailable, expired, future, malformed or mismatched evidence is held.
The PHP reader rechecks identity/costs instead of trusting an `unchanged` flag.

Partial runs are archived separately and **never replace** the latest complete
snapshot. A complete run may update that snapshot as before. Both documents must
be readable by the existing web/worker UID 33, outside the document root; run the
producer as that UID or apply ownership in the reviewed native wrapper. Do not
make private evidence world readable.

Without `--allow-partial`, CLI behavior is unchanged. With it, JSON summary adds
`partial_allowed_options`; exit 0 means a complete observation or at least one
explicitly verified partial option. It does not mean all sources succeeded: retain
`complete`, errors and held counts in operational reporting/retries. If no partial
option is usable, exit remains 1. Never fake complete or renew missing sources.

Public options and product materialization filter each option; existing selection,
Woo direct add, cart and checkout revalidate through `Observations::verify`.
Historical order source/proof verification is untouched.

Acceptance commands: `python3 docs/deco-prints/tests/price-observer-test.py`,
`python3 docs/deco-prints/tests/partial-price-observer-test.py`, and
`php docs/deco-prints/tests/partial-price-observation-test.php`.
Then perform a real native observation and both native upload flows; synthetic
contracts alone are not production acceptance.

Rollback restores the three reviewed runtime files and previous partial projection
through verified backups. Do not restore databases, remove artwork identities, or
overwrite approved policy. Old code ignores partial evidence; stale full evidence
will conservatively block new sales after rollback. This does not revoke existing
order sources or approvals. No destructive rollback or live order test is implied.

# Managed Director accounting (#96)

This supersedes the older policy that retained every successful routine managed
reservation forever. It does not change configured quotas, request slots, the
independent Spark runtime, production state, or historical records on deployment.

## Cause and future behavior

`reserved_microusd` is provisional application headroom, not provider spending.
Previously a routine success copied that entire reservation into its estimate;
failures retained it as uncertainty. Both accumulated toward the monthly cap.
Additionally, per-call input/output allowances were incorrectly used to reject
managed turn usage. A managed turn may contain several model calls.

A routine completed turn now becomes eligible for estimated accounting only after
original output validation, whole-proposal moderation, a successful final turn
retrieval and successful atomic world persistence. Both terminal observations must
contain valid, nondecreasing counts for the same turn and fit the reservation.
The commitment is the maximum observed cost for that attempt, never the sum of
repeated observations. All input is charged at the existing conservative input
rate; cached input receives no discount. Reasoning is already included in output.
A receipt binds the estimate to its two terminal observations. The original
reservation remains stored separately. Apply failure rolls back the estimate and
receipt together with world changes.

Missing or malformed usage, missing final inspection, regressing totals, failed
generation/moderation/apply, and experimental staging keep full reservations.
Measurable overruns increase committed spending and retain uncertain accounting;
they no longer discard otherwise valid completed dialogue solely for exceeding a
per-call token allowance. No new request, retry, polling or timer is added.

These are application estimates, **not final provider bills or a guaranteed
provider spending ceiling**. OpenAI documents turn/session usage as best-effort,
potentially missing or changing, and warns that agents may make several model
calls: [official observability and usage documentation](https://developers.openai.com/api/docs/guides/agents-api/observability).
The final observation does not prove complete billing. Owner review must accept
estimated admission accounting before operational release. Pricing remains the
existing reviewed allowlist; this fix does not certify new rates.

`ai-status.php` explicitly reports provider billed spending as unknown, names the
application commitment basis, and separately shows the sum of maximum managed
usage estimates per attempt. That observation total overlaps commitments and must
not be added to them. Legacy estimates can contain full copied reservations.
Request slots never receive a refund.

## Historical reconciliation: owner-only, separate production approval

Deployment alone releases **no historical hold**, including old applied rows.
The existing reconciliation tool remains conservative and unchanged. The new
`scripts/settle-director-billing.php` is an explicit offline owner operation;
neither the scheduler nor public readers invoke it. No migration is needed.

Do not substitute the dashboard's rounded project total, a trace or a usage
estimate for final billing attributable to the selected attempt and its entire
turn (all model calls, cancellations and adjustments). A screenshot of $0.50
cannot establish an exact amount for any individual historical attempt. If that
evidence is unavailable, keep the hold. Transport-lost/unresolved attempts with
no recorded terminal turn, cross-month attempts and active local work are refused.
No bulk release or automatic historical replay is provided.

After separate owner approval of the release and production operation, first make
the existing consistent private backup as the database service identity. Do not
copy a live SQLite file without its WAL, clear a lease or change the timer. Use
the actual service identity and installed PHP path if they differ below.

Read-only inspection, no credentials or HTTP:

```bash
sudo -u www-data /usr/bin/php8.3 \
  /var/www/sanctuaryfor.ai/scripts/settle-director-billing.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --attempt="$ATTEMPT"
```

Review the returned snapshot hash and the private attempt-attributed evidence
outside `public/`. Set the following values deliberately; no command loads or
changes the service's environment or configuration. This command is **an owner
instruction only; it must not be run automatically**:

```bash
: "${ATTEMPT:?}" "${SNAPSHOT_SHA256:?}" "${REVIEW_ID:?}" \
  "${VERIFIED_MICROUSD:?}" "${EVIDENCE_FILE:?}"
sudo -u www-data /usr/bin/php8.3 \
  /var/www/sanctuaryfor.ai/scripts/settle-director-billing.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --attempt="$ATTEMPT" \
  --apply --snapshot-sha256="$SNAPSHOT_SHA256" --review="$REVIEW_ID" \
  --microusd="$VERIFIED_MICROUSD" --evidence-file="$EVIDENCE_FILE" \
  --confirm-final-billing --allow-reservation-reduction
```

The write lock rechecks idle local work, current accounting month and the exact
attempt/session/audit snapshot. One append-only review plus one insert-only
settlement receipt supersede the reservation in the budget projection. Original
attempts, states/errors, reservations, observations, prior reviews, HTTP slots,
run outcomes, conversations and memories are unchanged. The commitment remains
at least the largest observed estimate, prior reviewed billing amount and the
new independently attested amount. Later higher observations still increase it.
An identical retry is idempotent; conflicting reuse is refused. Only one explicit
settlement is accepted per attempt. An amount above the cap stays counted; it
does not authorize another request or increase the configured ceiling.

Code rollback is financially conservative: older code ignores settlement receipts
and again counts original holds. Never restore an old database to erase accrued
spending or new history. Review status through the existing owner environment
after any approved operation; raw SQL sums of original reservations intentionally
remain unchanged and do not represent the adjusted admission projection.

## Verification

Run `node tests/village-director.mjs` and `python ops/ci/offline.py`. Offline
fixtures cover cumulative and repeated observations, cached/reasoning subsets,
missing/malformed/regressing usage, overspend, failed moderation/apply, unchanged
caps/slots, replay prevention, explicit settlement, stale snapshots, active work,
cross-month/unresolved refusal, idempotency, preserved original rows and late
higher usage. Require Ubuntu PHP 8.3 CI before merge. No production access,
credentials, real provider calls, merge, deployment or independent Spark
activation is authorized by this development change.

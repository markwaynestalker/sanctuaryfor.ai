# Autonomous continuity

The existing `simulation.env` is authoritative: SCHEDULER_ENABLED, SIMULATION_ENABLED, SCHEDULER_ALLOW_PAID, AI_ENABLED and DIRECTOR_ENABLED (all `LITTLE_SPARKS_`) must explicitly enable routine paid activity. Runtime/model registration and confirmed pricing must remain valid. Existing interval, daily HTTP, monthly allocation, resident/global activity and cooldown settings retain their defaults and validation. No configuration, timer, key or production database is changed by this PR.

Every distinct due opportunity is evaluated independently. Old `reserved`, `uncertain` or `settled` rows are financial/history records, not global vetoes. They retain all original HTTP slots, original accounting month/day and conservative cost commitments. Monthly admission/status include the greatest held cost and any larger managed usage observation or billing review; unknown charges never receive a refund. The configured allocation still cannot exceed $1/month. These are application commitments/estimates, not a guaranteed final provider bill ceiling. Optional PR #17 reconciliation remains diagnostic and is never required for the next eligible encounter.

An encounter can fail without disabling the village: errors/invalid output/moderation failures are recorded with a bounded category, no conversation or action is invented, the old generation is never retried, and the next eligible slot can proceed. The Director still authors every V6 resident sentence; there is no scripted speech fallback. A provider outage is tried again only at the next distinct opportunity, within existing request and budget limits. No background polling, catch-up queue or additional paid retry is introduced.

The existing one-minute timer checks the current ENV interval (15 minutes by default). Replaying a saved failed opportunity returns its accurate failed result with exit 0 and does not even load a credential; it is a handled outcome, not a repeated service failure. Real configuration/database/credential failures still exit nonzero. `Restart=no` and the timer are unchanged; a previous service failure does not require a reset for a later timer invocation.

Expired leases and pending claims recover under `BEGIN IMMEDIATE`. Their paid records stay uncertain/charged and their opportunities become failed once. An expired worker cannot issue its next HTTP request or commit world changes without the current lease. Uncertain/unavailable provider sessions are excluded from future continuity reuse; a new encounter gets fresh SQLite identity/memory context. A still-valid active encounter continues to exclude concurrent work. Original failed opportunities/conversations/residents/memories/credentials are never reset or rewritten. Arbitrary lifetime ledger/archive row ceilings are removed; ENV activity/request/budget bounds still limit new work, and historical records are preserved.

## Read-only operational status

`scripts/ai-status.php --database=/var/lib/sanctuaryfor-ai/village.sqlite` adds `operational` to its private JSON report. Run with the service's existing environment; an ordinary shell does not automatically inherit `simulation.env`. For an owner-run Toronto check after deploying the reviewed code:

```bash
sudo systemd-run --wait --pipe --collect \
  -p User=www-data -p Group=www-data \
  -p EnvironmentFile=/etc/sanctuaryfor-ai/simulation.env \
  -p NoNewPrivileges=yes -p ProtectSystem=strict -p ProtectHome=yes \
  /usr/bin/php8.3 /var/www/sanctuaryfor.ai/scripts/ai-status.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite
```

Use the actual existing service user/group/environment-file if they differ. No credential delivery or API probe is needed. The CLI holds a query-only snapshot and performs no recovery writes.

- `scheduler`: configured flags, latest CLI heartbeat, and operational freshness (within three minutes for the existing one-minute timer). It explicitly does not claim to inspect systemd state. The heartbeat is one mutable settings record, not a billing receipt.
- `director_configured`: saved registration/model compatibility; separate from enable flags.
- `connection`: available/unavailable from recent real HTTP audit results, or unknown when absent/stale. Timestamp and `live_probe=false` make clear this is an observation, not a fresh connectivity guarantee. Moderation rejection is separate from connection availability.
- `last_successful_ai_conversation` and `last_failed_opportunity`: independent history, with an exact bounded failure category for newly recorded failures and a safe legacy category otherwise.
- `allowance`: exact retained request slots, conservative committed micro-USD, configured limits and remaining headroom, including the cost/slots needed for the next encounter.
- `future_opportunities_eligible`, `next_check_utc`, `blocking_reasons`: current admission conditions, including active claims and resident rests; historical uncertainty is informational, never a blocking reason. Provider success or future allowance changes are not promised. Invalid configuration fails closed. A budget cap can legitimately pause paid generation until the relevant UTC allowance renews or an operator explicitly changes its validated configuration.

## Review and deployment

No migration is required. Owners review, back up using the existing consistent SQLite procedure and deploy through their existing workflow, preserving local changes and the active timer. No production SSH, deployment, paid testing, cap increase, key change or timer disablement is performed by Codex. Code rollback restores older behavior (including its old global veto) but must never restore an old database to erase newly accrued usage. The added settings records use the existing schema.

Run `PHP_BINARY=/path/to/php node tests/village-director.mjs` plus the existing offline suites. Coverage includes uncertainty followed by a successful distinct mocked encounter, all retained holds/observations and ENV caps, failed-opportunity deduplication, expired-worker fencing/recovery, moderation/invalid output, unchanged history, read-only health and successful actual CLI ticks replaying a failed slot without credentials. No real provider requests occur.

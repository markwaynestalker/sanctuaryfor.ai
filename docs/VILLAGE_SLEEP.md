# Shared village rest (#27)

The village can rest nightly from 23:00 inclusive to 07:00 exclusive in **America/New_York**, independently of visitor timestamp preferences and decorative weather. 21:00–23:00 retains normal activity. Daylight saving follows the real zone rules, including skipped/repeated hours. Persistence, opportunity IDs and budget periods remain UTC.

## Configuration and activation

All names use the `LITTLE_SPARKS_` prefix:

| Variable | Default | Valid values |
| --- | --- | --- |
| `SLEEP_ENABLED` | 0 | canonical 0 or 1 |
| `SLEEP_START_HOUR` | 23 | canonical integer 0..23 |
| `SLEEP_END_HOUR` | 7 | canonical integer 0..23, different from start |

Invalid values fail configuration checks; no clamping. Wrapped and daytime intervals work. Merging this code leaves sleep disabled. After review, the owner can add these settings to the existing protected `/etc/sanctuaryfor-ai/simulation.env`; no credentials or other quotas need changing. `php8.3 scripts/scheduled-village.php --check` under the established environment reports the policy without loading credentials or opening a database. This PR changes no production settings or services.

At the next existing scheduler tick, its normal heartbeat publishes only the harmless four-field policy. FPM reads that policy without accessing the private CLI environment or key; before the first publication, it uses its own ENV/default-off policy. Disabling/changing the CLI policy likewise reaches browsers after the next tick and their next explicit commons load. An already-open page continues the policy it loaded. No new polling is introduced.

## Authoritative behavior and recovery

During rest, ordinary selection returns no residents. A due opportunity records a completed `quiet` outcome using existing transactions, with a `night_rest` reason and no participants, dialogue, events, memories, world changes or paid reservations. Quiet claims still count toward the configured global opportunity allowance. Repeated opportunity IDs retain their saved result; historical nighttime slots cannot generate catch-up speech after morning. Existing lease/concurrency guards still apply; a crashed pending claim follows normal recovery rather than regenerating.

New paid reservations and generation dispatch are fenced; managed create/submit and Responses generation check again inside their write transaction. A request already dispatched before bedtime can finish retrieval, moderation and persistence. Inspections, moderation and cancellation remain allowed within the original slots/deadlines. Bedtime never cancels a completed conversation, clears a session, refunds a hold or changes uncertain accounting.

Current resident_states schema permits only available/commons; there is no implemented 30-minute nap transition in this source. Rather than invent a new persisted activity or overwrite state, sleep is a schedule-derived availability overlay. It survives restart and wakes naturally at the boundary, preserving all stored states/homes/goals/history. No migration is required. Future individual nap/activity implementations must intersect this overlay with their own eligibility rules.

## Presentation

Sleeping residents remain visible with a sleeping badge and accessible label; wandering and saved-dialogue replay stop. Cottages remain linked and cozy, the chicken stays protected, and profiles, directory and full saved transcript remain available. The browser advances the server clock sample with monotonic elapsed time, using the existing atmosphere minute tick and visibility event. It does not use the visitor's timezone or spawn another timer. Morning resumes only if motion was not paused/reduced. Without Intl timezone support, retain the server snapshot until an explicit reload rather than guessing a timezone.

## Validation and rollback

Run `node tests/village-sleep.mjs`, `node tests/commons-ui.mjs`, `node tests/atmosphere.mjs`, then `python -B ops/ci/offline.py` with PHP_BINARY set. All are offline and use disposable fixtures, never production data or keys. Coverage includes canonical bounds, DST, rest/wake, persistent quiet replay, concurrent claims, history/schema preservation, server-policy publication and accessible mobile/desktop behavior. Ubuntu CI supplies PHP 8.3/Node 22 with outbound networking disabled.

For rollback, disable SLEEP_ENABLED and let the existing heartbeat publish the change, or restore the previous reviewed code through the existing deployment process. No schema/data rollback is needed; keep quiet outcomes and every ledger row. This PR adds ENV-controlled behavior, so use the existing reviewed operational release route rather than bypassing deployment's configuration-diff gate. Do not merge, deploy, restart/enable the timer or change production automatically.

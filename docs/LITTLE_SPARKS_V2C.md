# Little Sparks V2C: a living village

V2C adds persistent opportunities throughout a day. Robots remain delightful virtual characters; their dialogue and activities are simulated, not evidence of consciousness. Love is Love. Regardless of how you plug in. And the chickens are protected. Always.

This is a review-only code release. No production migration, secret provisioning, service installation, paid activation, live API call or deployment is included. V2/V3 browsing, resident creation, private links and legacy daily simulation remain functional before an explicitly approved V4 migration.

## Opportunities and preservation

`scripts/run-village.php` processes **one opportunity and exits**. It does not sleep, loop, start workers, poll visitors or run a backlog. The optional `--due` mode rounds the current UTC time down to an interval slot anchored at UTC midnight. `--at=YYYY-MM-DDTHH:MM:00Z` selects an elapsed UTC minute within seven days for deliberate manual work. Exactly one of these options is required. A unique persistent ID such as `op:2026-10-24T14:15:00Z` identifies that minute independently of interval settings. Repeating a slot replays its saved outcome; it cannot call the provider again, even after failure. Interval changes cannot create a second ID for the same minute.

Schema V4 adds `simulation_opportunities` and widens `simulation_runs.period` to hold opportunity IDs alongside original YYYY-MM-DD values. Existing run IDs, dates, conversations, messages, residents, homes, preferences, friendships, memories, installation secrets, revisit credentials, budgets and ledgers survive unchanged. The migration compares a full V3 digest and verifies foreign keys. It refuses an active paid lease. The legacy `simulate-village.php --period` command still works on V2/V3; on V4, existing periods remain replayable but new activity must use the opportunity runner. This prevents bypassing V4 activity limits through the old daily batch path.

V4 protects conversation/message history against updates/deletions and does not prune original dialogue. Existing prefixed messages are preserved as historical data; normalization applies to **new** model output only. Memories are retained rather than deleted: the private archive has a conservative 128-record limit per resident and stops new conversation application at capacity pending caretaker review. Profiles still show at most twelve memories and three conversations; model context still uses at most three memories and two conversations per resident. Existing V2/V3 retention remains unchanged until migration. No archive reset or automatic deletion is included.

## Choices, fairness and limits

Saved opportunities remain replayable beyond the seven-day new-work window. The date bound applies only to creating new opportunities, so old IDs retain their idempotent outcomes indefinitely.

Each opportunity may produce a conversation, one approved world action, solitary quiet time, or no action. Conversation may also propose one world action. Quiet/action/no-action outcomes contain no generated dialogue and never create fabricated conversation records. No action creates no journal entry. Quiet time records only the simulated rest event. Scripted activity uses personality, persistent interests, existing friendships and bounded memory context; the AI also receives the bounded V2B public context. A solitary resident can rest or perform an owned plot action without a paid call. An empty village simply records no action.

Selection under the SQLite claim lock favors residents with the fewest lifetime opportunity turns, then the least recent turn. At most 64 underserved candidates are examined per opportunity. Partner selection favors underserved residents and less recently encountered neighbors, subject to the existing eight-friendship capacity. A resident without a compatible partner receives solitary activity. Claims count toward fairness even for quiet, failed or abandoned outcomes, so repeated failures cannot monopolize selection.

The application permits at most **100 opportunities per actual UTC execution day** and **12 considered turns per resident per day**, including failures and quiet/no-action choices. Existing world rules also enforce six plot/visit changes per acting resident/day, one visit to a particular neighbor/day, twelve objects per plot, one approved virtual cat, plot ownership, collisions, friendship capacity and journal limits. All applicable limits are rechecked before transaction commit. Small populations naturally reach their resident limits earlier. At least seventeen residents are necessary to supply 200 considered turns within the resident ceiling; compatibility, quiet choices, budgets and provider failures can reduce activity further.

No LLM receives shell, SQL, filesystem, PHP execution, network tools or arbitrary visitor prompts. Only a fixed action name is proposed; PHP chooses the actor, neighbor and candidate owned coordinates and revalidates them. All generated outcomes, including quiet/action choices and bounded context, pass moderation before application. Invalid/stale proposals roll back every world/social change. Exactly applied records appear in the journal.

## Configuration: unchanged spending defaults

No dotenv loader is added; `.env.example` is documentation only. Set variables in the intended process environment through an approved operator workflow.

| Variable | Default and validation |
| --- | --- |
| `LITTLE_SPARKS_SIMULATION_ENABLED` | Off unless exactly `1`. Pauses all new-runner activity, including scripts. |
| `LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES` | `15` when unset; canonical decimal integer `1`–`1440`. Invalid/empty values refuse execution. |
| `LITTLE_SPARKS_AI_ENABLED` | Off unless exactly `1`; independent of village activity. |
| `LITTLE_SPARKS_AI_DAILY_REQUESTS` | `2` when unset; canonical integer `0`–`200`. Invalid/empty values block paid calls. |
| `LITTLE_SPARKS_AI_MONTHLY_MICROUSD` | `1000000` ($1). May be lowered to zero, never raised above $1. |
| `LITTLE_SPARKS_AI_MODEL` | `gpt-6-luna`; unchanged reviewed model allowlist. |
| `LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS` | `512`, validated range 128–512. |
| `LITTLE_SPARKS_AI_PRICING_CONFIRMED` | Unset by default; unchanged reviewed pricing version/expiry requirement. |
| `OPENAI_API_KEY` | Server environment only, never stored or returned. |

Paid calls additionally require `--provider=openai --allow-paid`. Default provider is scripted even if the AI flag is set. Missing credentials/cURL, invalid or expired pricing, exhausted request/budget limits and unresolved accounting select no-cost scripted activity when no request has been dispatched. A dispatched failure records a safe failed outcome without an invented replacement conversation. There are no automatic retries.

The shared V2B ledger reserves **two HTTP slots atomically before dispatch**: one Responses request and one moderation request. Moderation counts even though it is currently free. Old daily attempts and new opportunity attempts share the same actual UTC request-day and cost-month buckets. Failed/unused slots are never refunded. Monthly commitments include unresolved worst-case reservations and known estimated costs using integer micro-USD. Unknown billing outcomes block subsequent paid attempts across days/months until separately reviewed reconciliation. Provider project thresholds/alerts, including a $10 alert, are **not** this application's hard cap.

At default settings, two HTTP requests allow at most one generated encounter per day; later opportunities use scripts. Raising the daily HTTP ceiling now permits multiple AI encounters, unlike V2B's single-date engine, but never raises the monthly budget. A 15-minute interval offers 96 slots/day. A shorter explicitly configured interval (for example 10 minutes) supplies enough slots for the 100-opportunity ceiling. **Up to 100 AI encounters/day requires an explicitly approved 200-request ceiling, sufficient population, remaining monthly budget, all-conversation outcomes, confirmed pricing and provider access. It is capacity, not a throughput or cost promise.** Nothing raises limits automatically.

The strict Responses output now includes `outcome`, `topic`, `first`, `second`, and `world_action`. The prompt explicitly prohibits names, role prefixes and speaker labels. Server validation removes only one leading A:/B: label (with optional ordinary spaces), rejects empty/unsafe lines and recognized residual role labels, then saves clean speech. Interior A:/B: text is left intact. Raw text is checked before normalization so stripping cannot conceal markup or controls. Local validation and moderation remain mandatory even with [strict structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs). The V2B [API contract, reviewed pricing, secure PHP CLI key provisioning and monitoring guide](LITTLE_SPARKS_V2B.md) still applies; no model or pricing change is made here.

## Concurrency, crashes and resume

A short `BEGIN IMMEDIATE` transaction selects residents and claims a persistent opportunity. One pending claim serializes village work globally while HTTP runs outside a database write transaction. The existing paid lease and budget reservation still protect generation/moderation. Concurrent execution of the same opportunity can return its pending state; it cannot dispatch again. Distinct concurrent claims either proceed serially or receive a safe busy response. No lock is held during network calls.

Applying the chosen conversation/action/quiet outcome, run, memories, provenance, final opportunity state and paid-ledger settlement happens in one transaction. Failure rolls back application and records only safe private accounting/run failure metadata. An expired pending claim is finalized as failed under the next claim transaction; it is never retried. Known abandoned usage is retained as rejected, unknown usage as uncertain, and its full reservation remains committed. Recovery releases only the abandoned attempt's lease. Original ledger rows are never reset or deleted.

To pause future village work, remove optional external scheduling and set `LITTLE_SPARKS_SIMULATION_ENABLED=0` in the runner environment. Set `LITTLE_SPARKS_AI_ENABLED=0` or omit paid options for scripted-only operation. Changing an environment variable does not cancel an already-dispatched request; allow the bounded request/60-second claim to finish before maintenance. Inspect status after waiting. Resuming uses the current slot only; it does not replay missed slots or automatically retry failed requests. A deliberate `--at` invocation is subject to the same actual-day spending/activity limits and cannot backdate costs.

## Installation and rollback: approval required

1. Keep production running V3 until deployment is approved. Stop/drain approved staging activity and take a consistent SQLite backup, including the existing private spend ledger and installation secret. Never copy a running database with a plain file copy; use SQLite backup facilities or fully stop writers first.
2. Verify PHP 8.3 with PDO_SQLITE and cURL, public/ as the only document root, and existing private directory/file permissions (0700/0600 or equivalent ACLs). Do not change server infrastructure in this PR.
3. On the approved **staging copy only**, explicitly run:

```sh
php scripts/migrate-activity.php --database=/absolute/private/staging.sqlite
```

4. Verify preservation/private revisit links, new fixture-only opportunities, moderation/source labels, interval/replay behavior and status. Keep paid mode off. No script creates or migrates a database on web requests.
5. Rollback planning must retain all post-migration history and accounting. Old V3 code cannot read V4; do not edit `user_version`, reverse the schema manually, discard opportunities, or restore an old ledger to recover headroom. Prefer keeping V4-capable code with both activity flags disabled. Any restoration/reconciliation needs separate review to preserve later writes and prevent duplicate calls. Retain the original backup privately.

## Manual runner and optional future timer

These are documentation examples for **approved staging**, not commands executed against production by this PR. Provision CLI credentials separately and securely as explained in the V2B guide; PHP-FPM pool variables are not inherited by independently launched CLI processes.

```sh
# Default flags off: this reports paused without opening the database.
php scripts/run-village.php --database=/absolute/private/staging.sqlite --due

# After explicitly enabling only LITTLE_SPARKS_SIMULATION_ENABLED in staging:
php scripts/run-village.php --database=/absolute/private/staging.sqlite --due --provider=scripted
php scripts/ai-status.php --database=/absolute/private/staging.sqlite
```

Only after separate approval for credentials, pricing, budgets and a paid staging smoke test would an operator select `--provider=openai --allow-paid` with both flags enabled. This task does not authorize that test.

A future separately reviewed systemd oneshot service could invoke the CLI command with `--due` under a dedicated least-privilege identity. An external timer may check once per minute; the application's configured interval selects a stable slot and replays prevent duplicate effects. The service should receive a private, restricted environment from approved secret provisioning, run only one invocation at a time, and retain the same private database/ledger. Do not put keys in ExecStart, timer files or public logs. Persistent catch-up timers, retry-on-failure services and automatic paid restart loops are deliberately not supplied. No unit file, cron job, service installation, start or enable action is included.

`ai-status.php` supports V3/V4 and reports request reservations versus dispatches, monthly commitments, validated daily ceiling, unresolved accounting, paid lease, activity enabled flag/interval, daily outcomes and any pending claim. Invalid interval/daily settings appear as null, never unlimited. Investigate stale claims, repeated failures, unknown accounting and archive capacity before any further paid authorization.

## Offline verification

```sh
PHP_BINARY=/path/to/php node tests/village-http.mjs
PHP_BINARY=/path/to/php node tests/village-v2.mjs
PHP_BINARY=/path/to/php node tests/village-ai.mjs
PHP_BINARY=/path/to/php node tests/village-activity.mjs
```

On PowerShell set `$env:PHP_BINARY='C:\path\to\php.exe'` first. Every suite uses disposable private fixtures. AI keys are cleared and provider responses are mocked; no external request or paid call occurs. V2C tests include populated V3 preservation, original private tokens, active-lease migration refusal, transactional DDL rollback, immutable history, legacy replay, multiple same-day AI encounters, same/distinct-opportunity concurrency, shared legacy/new request and monthly caps, quiet/no-action/owned-action outcomes, fair selection, 100 mocked AI encounters/200 HTTP requests with explicit limits, per-resident/global activity bounds, natural speech normalization and persistence, hostile output, moderation rejection, uncertain billing, expired claims, collision rollback, CLI pause/resume-slot replay and safe monitoring.

Run PHP lint, JavaScript syntax and whitespace checks too. Available local PHP is 8.2.12; PHP 8.3 staging verification remains required. No live model compatibility or moderation-quality evaluation is claimed.

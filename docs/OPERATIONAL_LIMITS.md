# Operational limits audit (#30)

Current owner direction supersedes earlier documentation describing a $1 monthly maximum or a 200-request ceiling. Daily HTTP slots and monthly application allocation are explicitly configured, with **no PHP quota default or chosen upper ceiling**. Existing reservations, uncertain billing commitments, imported metadata reservations and original accounting periods remain unchanged. Increasing ENV creates headroom; it never refunds history or retries an old opportunity.

All integer values below use canonical decimal notation: no signs, whitespace, leading zeros, fractions, exponent notation or overflow. An invalid explicit value fails closed, never falls back or silently clamps. `INT_MAX` means the PHP runtime's representable integer maximum (9223372036854775807 on 64-bit Toronto), not an operator recommendation. Budgets are application reservations/estimates, not guaranteed limits on provider invoices.

## Authoritative activity and financial controls

Names in these tables have prefix `LITTLE_SPARKS_`.

| ENV suffix | Default | Valid values | Recommended initial setting for this owner |
| --- | --- | --- | --- |
| `AI_DAILY_REQUESTS` | **Required, no default** | 0..INT_MAX HTTP slots/UTC day | **2000**, as requested |
| `AI_MONTHLY_MICROUSD` | **Required, no default** | 0..INT_MAX micro-USD/UTC month | **25000000 ($25)**, as requested |
| `ACTIVITY_DAILY_MAX` | 100 | 0..INT_MAX opportunities/UTC day | Explicit **100**; at 15 minutes there are 96 scheduled opportunities/day |
| `RESIDENT_DAILY_MAX` | 12 | 0..INT_MAX considered opportunities/resident/day | **1000**, as requested |
| `SIMULATION_INTERVAL_MINUTES` | 15 | 1..1440 | **15** |
| `SLEEP_ENABLED` | 0 | 0 or 1 | Leave 0 until owner activation |
| `SLEEP_START_HOUR` | 23 | 0..23, different from end | 23 |
| `SLEEP_END_HOUR` | 7 | 0..23, different from start | 7 |
| `RESIDENT_CONVERSATION_COOLDOWN_SECONDS` | 0 | 0..86400 | 0 initially |
| `PAIR_CONVERSATION_COOLDOWN_SECONDS` | 0 | 0..604800 | 0 initially |
| `ACTIVITY_CANDIDATES` | 64 | 2..10000 | 64; bounded fair-selection query, not a daily quota |
| `RESIDENT_FRIENDSHIP_MAX` | 8 | 0..INT_MAX | 8 initially; raise if the village should form more friendships |
| `MEADOW_REPLAY_COOLDOWN_SECONDS` | 60 | 0..3600 | 60; browser replay only, never an API call |

Zero daily/monthly/activity/resident allowances pause corresponding new work. Friendship zero prevents new relationships; existing pairs remain eligible. Failed/quiet opportunity claims count toward activity limits. Cooldowns apply to successful saved conversations and are rechecked transactionally. Global/resident quotas and friendship checks run under the existing SQLite write transaction, with lease/idempotency checks unchanged.

`SCHEDULER_ENABLED`, `SIMULATION_ENABLED`, `SCHEDULER_ALLOW_PAID`, `AI_ENABLED`, and `DIRECTOR_ENABLED` remain explicit 0/1 gates, default off. All must permit routine scheduled paid encounters. Registration/fingerprint, `DIRECTOR_RUNTIME=agents`, supported `AI_MODEL` (default gpt-6-luna), and `AI_PRICING_CONFIRMED` must still match. The model/pricing allowlist is deliberately reviewed code, not an ENV way to claim unverified prices. Setting quotas never enables these flags by itself. Routine enabled encounters need no staging permit.

Every HTTP dispatch, including moderation and managed metadata, needs a previously reserved slot. Managed encounters retain their existing **eight-slot** reservation; Responses encounters reserve two. Daily status reports configured, reserved, remaining and recorded starts separately. Imported external reservations without dispatch evidence are not presented as verified HTTP starts. Monthly admission uses the greatest conservative held/observed/reviewed commitment. Missing usage is never zero; it does not prevent a separately valid moderated completed conversation being saved.

## Execution controls

These settings used to be constants/literals. Defaults preserve existing behavior. Explicit incompatible settings are rejected before generation. Keep these defaults initially; changing financial allowances does not require changing them.

| ENV suffix | Default | Valid range / dependency |
| --- | --- | --- |
| `AI_MAX_INPUT_TOKENS` | 32768 | 1..1000000000; combined priced reservation must fit existing per-attempt schema |
| `AI_MAX_OUTPUT_TOKENS` | 512 | 1..1000000000; same constraint and provider model capabilities |
| `AI_MAX_REQUEST_BYTES` | 16384 | 1..1048576; bounded prompt/body protection |
| `DIRECTOR_MIN_COMMITMENT_MICROUSD` | 10000 ($0.01) | 0..1000000; reservation is the greater of this and priced token estimate |
| `AI_HTTP_CONNECT_SECONDS` | 5 | 1..45; also bounded by remaining request/lease deadline |
| `AI_HTTP_TIMEOUT_SECONDS` | 15 | 1..45; same deadline fence |
| `AI_LEASE_SECONDS` | 60 | run + finish reserve .. INT_MAX minus current epoch |
| `AI_DAY_BOUNDARY_GUARD_SECONDS` | 90 | lease seconds .. 86400; avoids assigning one lease across daily accounting windows |
| `DIRECTOR_RUN_SECONDS` | 45 | 20..45; existing installed service has a 50-second timeout |
| `DIRECTOR_FINISH_RESERVE_SECONDS` | 15 | 5..run minus 1; protects retrieval, moderation, commit and cleanup time |
| `DIRECTOR_HTTP_TIMEOUT_SECONDS` | 8 | 1..run; remaining phase deadline still applies |
| `DIRECTOR_POLL_TIMEOUT_SECONDS` | 4 | 1..run minus finish reserve |
| `DIRECTOR_CLEANUP_TIMEOUT_SECONDS` | 3 | 1..finish reserve |
| `DIRECTOR_POLL_INTERVAL_SECONDS` | 4 | 0..run minus finish reserve |
| `DIRECTOR_POLL_MAX_READS` | 3 | 1..3; existing eight-slot lifecycle must retain retrieval/moderation/cleanup capacity |
| `DIRECTOR_SESSION_MAX_TURNS` | 8 | 0..INT_MAX; 0 avoids reusing accepted sessions |
| `DIRECTOR_SESSION_TTL_SECONDS` | 86400 | 1..current epoch; bounds reuse by age |

The managed API does **not** receive an unsupported `max_output_tokens` or `spend_control` field. Managed token settings control local admission estimates and observed-usage validation, not a guaranteed provider token stop. The Responses adapter sends its supported output-token limit. Provider model limits are independent. Prompts and memory remain bounded. No automatic paid retry is added.

Phase deadlines are deliberately the earliest applicable configured timeout, lease expiry and process deadline. A too-short configuration may legitimately fail an encounter. Errors now distinguish `execution_deadline`, `encounter_request_cap` and the actual `daily_request_cap`; a full eight-slot encounter is not falsely reported as daily exhaustion.

### Structural constraints retained, not hidden quota overrides

* V6 SQL constrains managed reservations to eight slots and individual attempt reservations to at most 1000000 micro-USD ($1). This is **not** a $1 monthly cap: many attempts may use the explicitly configured $25 month. Raising an individual attempt above the schema limit needs a separate reviewed transactional migration. This PR does not migrate anything. Unsupported token/minimum-hold settings fail configuration validation; they are never clamped.
* The installed systemd service timeout remains 50 seconds. This patch exposes the existing PHP execution budgets within that envelope; it cannot honestly make the installed service timeout ENV-controlled. Larger run/poll envelopes require coordinated service/ledger review. No service or timer is edited here.
* Lease ownership/expiry, one generation per opportunity, transaction locks, immutable IDs, unique opportunity keys, moderation, public visibility, provenance, exact turn binding, TLS/endpoint restrictions, protected credential validation, bounded item pages and the fixed 262144-byte provider response ceiling are security/data-integrity protections. They are not switches to bypass billing or validate untrusted speech. Tiny final cleanup/commit deadline margins remain integrity fences.
* Optional experimental one-shot permits retain their exact-once binding, eight slots and $0.10 allocation. These exceptional testing rules do not cap normal autonomous monthly spending and are not required by routine enabled scheduling.

## Existing configurable context bounds

These limit retrieval/work, not request allowances. Keep defaults unless reviewing context quality; greater budgets do not require larger context. Names below also use `LITTLE_SPARKS_`.

| ENV suffix | Default | Range |
| --- | --- | --- |
| `MEMORY_SELECT` | 4 | 1..8 |
| `MEMORY_CANDIDATES` | 32 | 1..64 |
| `MEMORY_RECENT_CONVERSATIONS` | 2 | 0..4 |
| `MEMORY_SHARED_CONTEXT` | 4 | 0..8 |
| `MEMORY_LEGACY_CONTEXT` | 3 | 0..4 |
| `MEMORY_PER_RESIDENT` | 128 | 1..256 |
| `MEMORY_FRIENDSHIP_HISTORY` | 32 | 1..128 |
| `MEMORY_HOPS` | 3 | 0..4 |
| `MEMORY_SHARES` | 1 | 0..1 |
| `MEMORY_STORIES` | 10000 | 1..20000 |
| `MEMORY_CONTEXT_BYTES` | 8192 | 2048..10000 |
| `MEMORY_BACKFILL` | 500 | 0..5000 |
| `PERSONALITY_MAX_CHARACTERS` | 2000 | 1..4000 |
| `IDENTITY_EDITS_PER_DAY` | 6 | 0..24 |
| `DIRECTOR_DESCRIPTION_CONTEXT` | 800 | 1..2000 |
| `DIRECTOR_INSTRUCTIONS_BYTES` | 4096 | 1..8192 |

Source validation and bounded retrieval remain mandatory; a shortened context does not delete SQLite memories. Owner text/context sizes can cause explicit validation/admission failure when incompatible with configured body limits. They never authorize extra HTTP requests.

## Other hardcoded product and security limits

Audit scope: `app/`, `scripts/`, browser replay and supplied systemd templates. These remaining values are disclosed rather than mistaken for daily HTTP/monthly overrides:

* `world.php`: six world actions/resident/day, twelve objects/plot, one pet and one friend visit/pair/day. These are current hardcoded **world-action product quotas**, not conversation/API quotas; they can reject a proposed action independently. Plot capacity/coordinates, ownership, allowlists and unique occupied cells protect the saved map. No map/schema change here.
* `village.php`: three creations/browser, population 10000, directory pages 40, creation/network/browser spam windows, CSRF/request-body/dwell protections. These restrict public signup/listing, not paid Director requests. They remain hardcoded public abuse/resource protections.
* Legacy scripted simulation archives have historical row/interaction bounds; current V6 scheduling does not use those legacy entry points. Read-only UI/context queries have bounded page sizes (such as relationship previews); those are not paid request allowances.
* Operator reconciliation metadata inspections require their own reserved slots and immutable evidence. They do not create dialogue or refund prior uncertain reservations. No quota change permits replaying a historical generation.

## Toronto ENV handoff (owner-operated; not applied)

Your requested daily **2000**, resident **1000** and monthly **25000000** now pass validation unchanged. Keep your existing activation/registration/pricing settings. The only additional setting worth making explicit immediately is:

```ini
LITTLE_SPARKS_ACTIVITY_DAILY_MAX=100
```

That allows all 96 opportunities/day at a 15-minute interval. If you later shorten the interval or invoke additional distinct opportunities, choose a correspondingly larger global activity allowance yourself; PHP imposes no lower chosen ceiling. The new execution/candidate/friendship settings above are **optional additions** to `/etc/sanctuaryfor-ai/simulation.env`: omission preserves the documented defaults. `.env.example` lists every setting. Do not copy its blank required quotas or default-off flags over working production settings. Do not include an API key in diagnostics or shell commands.

After owner-reviewed code deployment, inspect using the existing private environment, without a credential or provider request:

```bash
sudo systemd-run --wait --pipe --collect \
  -p User=www-data -p Group=www-data \
  -p EnvironmentFile=/etc/sanctuaryfor-ai/simulation.env \
  -p NoNewPrivileges=yes -p ProtectSystem=strict -p ProtectHome=yes \
  /usr/bin/php8.3 /var/www/sanctuaryfor.ai/scripts/ai-status.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite
```

Confirm `daily.configured_slots=2000`, truthful reserved/remaining/start counts, `configured_monthly_microusd=25000000`, activity resident maximum 1000, `execution_limits`, and `operational.blocking_reasons`. This is a query-only snapshot, not a paid connectivity check. The service reads EnvironmentFile on its next normal invocation; this handoff does not enable/restart its timer. CLI and PHP-FPM have separate environments.

No database migration, new receipt, billing reconciliation, production ENV edit or credential change is required by this code patch. Rolling code back to an older build may reject the larger configured values again; coordinate any owner-approved configuration rollback explicitly. Never restore/delete ledgers to make an allowance fit.

## Offline verification

`tests/village-director.mjs` includes explicit 2000/$25 admission, preserved uncertain commitments beyond former ceilings, concurrent reservation boundary, external metadata slots, unchanged eight-slot behavior, execution ENV changes and query-only status. `tests/activity-limits.mjs` covers global/resident/cooldown changes and concurrent transactional enforcement. `tests/village-ai.mjs` covers Responses/moderation and monthly limits. All use disposable SQLite and mock providers; run the full offline suite before review. No real paid call is a verification step.

## Optional site-operator suggestions (#15)

See docs/OPERATOR_IDEAS.md and .env.example for the complete OWNER_* table: explicit default-off activation, required web-only password hash, login attempts/hour (10), session seconds (3600), ideas/UTC day (12), pending/resident (4), text length (500), context items/resident (2), history page size (20), ranges and activation procedure. Quotas are checked transactionally; invalid values fail, never clamp. They control operator access/input/context only and never lower AI allowances or add provider calls. Fixed HTTPS/CSRF, immutable attribution, participant binding, the 1000-character SQL shape and bounded context/body/page domains are security/data-integrity protections. No activation or production migration is implicit.

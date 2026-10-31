# Owner-run reconciliation of an uncertain managed attempt

This workflow is optional diagnostics, not a scheduler prerequisite. Distinct eligible encounters proceed while historical uncertainty retains its cost/request commitments. See [autonomous continuity](AUTONOMOUS_VILLAGE.md). No migration, generation, cancellation, dialogue import, retry, timer change or accounting reset is involved. This workflow is for a failed V6 managed encounter with a saved provider session; an explicit final-billing review can resolve the selected uncertainty without altering its failed history.

The reported production target is attempt `20c638ec0a26a1898941c6db83b69e4c`, session `sess_0f4bf01502ac2f4d006ade71594744819682bfd9f36975b016`, opportunity `op:2026-10-25T21:15:00Z`. Its failed run and `usage_unknown` error remain historical evidence. A completed provider trace does not establish a final charge and will not create a successful Sanctuary conversation.

## Before running

After reviewing and installing this PR yourself, inspect the checkout for local modifications and use the existing consistent SQLite backup procedure (`scripts/backup-village.php`). Do not reset the checkout or copy a live SQLite file without its WAL. Keep the existing scheduler state and configuration unchanged. Commands below run as the existing database service identity, avoiding root-owned SQLite sidecars.

First obtain independent **final provider billing evidence attributable to this session**, including any billed completion/cancellation and adjustments, and its exact amount in integer micro-USD ($0.01 = 10000). Save the private evidence file, at most 1 MiB, readable by the service user outside `public/`. Do not substitute an application reservation, best-effort usage estimate, or trace for final billing evidence. If attribution/finality is unavailable, retain the hold. No honest reconciliation can manufacture the missing amount.

Provider usage is best-effort and can change; it is not final billing ([official observability guide](https://developers.openai.com/api/docs/guides/agents-api/observability)). Inspection uses only the documented [session GET](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/methods/retrieve) and [turn-list GET](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/subresources/turns/methods/list). It never requests message items or sends generation/cancellation inputs.

## 1. Local inspection — zero HTTP requests

Run these in one owner shell on Toronto. These commands read service metadata, not key contents:

```bash
set -euo pipefail
CODE=/var/www/sanctuaryfor.ai
DB=/var/lib/sanctuaryfor-ai/village.sqlite
ATTEMPT=20c638ec0a26a1898941c6db83b69e4c
SERVICE_USER=$(systemctl show sanctuary-village.service -p User --value)
SERVICE_GROUP=$(systemctl show sanctuary-village.service -p Group --value)
test -n "$SERVICE_USER" && test "$SERVICE_USER" != root
test -n "$SERVICE_GROUP"
BACKUP="/var/lib/sanctuaryfor-ai/village-before-reconciliation-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
sudo -u "$SERVICE_USER" -g "$SERVICE_GROUP" /usr/bin/php8.3 \
  "$CODE/scripts/backup-village.php" --database="$DB" --output="$BACKUP"
sudo -u "$SERVICE_USER" -g "$SERVICE_GROUP" /usr/bin/php8.3 \
  "$CODE/scripts/reconcile-director-attempt.php" --database="$DB" --attempt="$ATTEMPT"
```

If the backup refuses an active writer/lease, wait for the existing invocation to finish; do not clear it. Verify the attempt/session match above, the run is failed, and the original requests/reservations remain. The default inspection command enforces SQLite `query_only`; it neither loads credentials nor contacts OpenAI. Do not pass `--resolve` yet.

## 2. Explicit GET-only inspection

This step makes at most **three authenticated metadata GETs**, not inference requests. It reserves all three daily slots atomically before credential loading or HTTP dispatch. Existing eight-slot encounter reservations remain untouched, and unused/failed inspection slots are not refunded. No nominal generation charge is added for these GETs. Current configured daily allowance must have three slots available. Never raise a limit simply to force reconciliation through.

The reported production allowance is 200 requests/day and $1/month. Confirm those are still the actual configured limits; if the operator has lowered either, use the lower value below. The transient process alone has all generation flags disabled. It uses the existing protected credential source from unit metadata; no secret is printed or sourced into the shell.

```bash
LOAD_CREDENTIAL=$(systemctl show sanctuary-village.service -p LoadCredential --value)
# Fail if this isn't the single existing absolute-path credential we expect.
[[ "$LOAD_CREDENTIAL" =~ ^openai_api_key:/[a-zA-Z0-9_./-]+$ ]]
INSPECTION=$(openssl rand -hex 16)
printf 'Inspection ID: %s\n' "$INSPECTION"
sudo systemd-run --wait --pipe --collect \
  --unit="sanctuary-reconcile-$INSPECTION" \
  -p "User=$SERVICE_USER" -p "Group=$SERVICE_GROUP" \
  -p "LoadCredential=$LOAD_CREDENTIAL" \
  -p NoNewPrivileges=yes -p ProtectSystem=strict -p ProtectHome=yes \
  -p "ReadWritePaths=/var/lib/sanctuaryfor-ai" -p UMask=0077 \
  -p RuntimeMaxSec=35 \
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 \
  --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  --setenv=LITTLE_SPARKS_AI_DAILY_REQUESTS=200 \
  --setenv=LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000 \
  /usr/bin/php8.3 "$CODE/scripts/reconcile-director-attempt.php" \
  --database="$DB" --attempt="$ATTEMPT" --fetch --inspection="$INSPECTION"
```

Keep the safe JSON output's `inspection_sha256`. It must say `ready_for_billing_review=true`. It does **not** resolve the attempt. The same inspection ID cannot be dispatched again, even after a crash. Failures retain their receipts/slots; no automatic retry exists.

The inspector verifies a uniquely owned session, attempt metadata, saved agent/model, disabled tools/subagents/sandbox, one identifiable terminal root turn, consistent idle/failed session observations, and usable usage totals. Reused/ambiguous sessions, active work, missing usage, mismatches, changed local records or HTTP failures remain blocked with a safe reason. Concurrent scheduled work can cause `simulation_busy`; let that existing invocation finish rather than clearing its lease. If the session cannot be identified unambiguously, stop for review; do not substitute the newest unrelated turn.

## 3. Explicit offline final-billing resolution

Set these **to real reviewed values**, then resolve within 15 minutes of the successful inspection. The required shell checks deliberately refuse unset values. `EVIDENCE_FILE` must be the private final-billing evidence described above, not a key, environment file or dialogue trace. The command hashes it; it never stores its contents.

```bash
# Set INSPECTION_SHA256, VERIFIED_MICROUSD and EVIDENCE_FILE yourself first.
: "${INSPECTION:?}" "${INSPECTION_SHA256:?}" "${VERIFIED_MICROUSD:?}" "${EVIDENCE_FILE:?}"
REVIEW=$(openssl rand -hex 16)
sudo -u "$SERVICE_USER" -g "$SERVICE_GROUP" env \
  LITTLE_SPARKS_AI_ENABLED=0 LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  LITTLE_SPARKS_SIMULATION_ENABLED=0 LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000 \
  /usr/bin/php8.3 "$CODE/scripts/reconcile-director-attempt.php" \
  --database="$DB" --attempt="$ATTEMPT" --resolve \
  --inspection="$INSPECTION" --inspection-sha256="$INSPECTION_SHA256" \
  --review="$REVIEW" --microusd="$VERIFIED_MICROUSD" \
  --evidence-file="$EVIDENCE_FILE" --confirm-final-billing
sudo -u "$SERVICE_USER" -g "$SERVICE_GROUP" env \
  LITTLE_SPARKS_AI_DAILY_REQUESTS=200 LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000 \
  /usr/bin/php8.3 "$CODE/scripts/ai-status.php" --database="$DB"
```

Resolution atomically rechecks the idle lease/claims, same UTC accounting month, report hash, freshness and exact original-record snapshot. It appends terminal status, usage, final-billing evidence hash and original-state resolution receipts. The commitment becomes the **maximum** of the original hold, previous estimates/reviews, observed estimate and verified final amount. No request slot or commitment is reset/refunded. If the resulting monthly total exceeds the configured allocation/$1 maximum, the higher commitment is retained and the attempt stays uncertain. Otherwise it becomes `rejected`, meaning a failed but accounted encounter; its run/opportunity/error stay failed/unchanged. The uncommitted remote session becomes unavailable for future context reuse.

An identical review ID/arguments is idempotent and makes no further changes; conflicting reuse is refused. Expired inspection, cross-month attribution or missing final evidence do not allow this optional review to clear uncertainty. Future distinct opportunities do not depend on this review; normal configuration, concurrency and retained budget/request commitments determine admission. This procedure never replays 21:15, 21:30 or 21:45. Do not manually clear holds, rewrite a run as completed, raise caps, or import the provider's dialogue.

## Verification and rollback

Inspection uses two short `BEGIN IMMEDIATE` transactions: admission reserves all three GET slots, then a separate transaction saves the report. Scalar read cursors are explicitly closed before the admission commit; neither a write transaction nor an unfinished read cursor is retained across provider GETs. With rollback journaling, an unfinished cursor could otherwise hold a shared lock while a competing writer waits to commit, creating a lock cycle at the inspector's second BEGIN. WAL also requires avoiding a retained stale read snapshot. See [SQLite locking](https://www.sqlite.org/lockingv3.html) and [native/extended result codes](https://www.sqlite.org/rescode.html).

The existing 5000 ms connection busy timeout is unchanged. Native SQLite BUSY/LOCKED codes (including extended codes) become `simulation_busy`, without retrying HTTP, reserving again, or attempting rollback when BEGIN acquired no transaction. Other database errors are not reclassified as contention. Refusal before admission commits creates no inspection slots or GETs. Contention after admission retains all three slots, the consumed inspection ID, original uncertain cost/request holds, and any HTTP receipts already committed. If final report storage is refused, there is no review-ready report; rerunning the consumed inspection ID cannot redispatch GETs. This is conservative accounting, not permission to refund or reset the inspection.

`tests/director-reconciliation-contention.php` runs through the Director suite. DELETE and WAL fixtures exercise a competing writer during each mocked GET and pipe-synchronized separate PHP workers at both BEGIN boundaries. Releasing the held writer permits acquisition; retaining it forces a bounded refusal with safe JSON rather than an uncaught PDO fatal. Tests verify zero GETs before refused admission, exactly three after admission, original world/accounting snapshots, retained three-slot reservations, report absence on final refusal, and no redispatch on consumed-ID reuse. Only disposable worker fixtures use a shorter busy timeout to make refusal deterministic and fast.

Check `ai-status.php`: all prior request slots remain, three inspection slots are added for the inspection's UTC day, the original minimum cost remains committed, and the selected attempt is rejected only after a valid review. Local inspection shows original HTTP/cancellation evidence plus the new append-only billing/outcome rows. Conversations/events/memories, residents and credentials are untouched.

No schema upgrade is required. A code rollback alone does not undo a reconciliation. Do not restore an old production database to erase newly accrued usage; retain the private backup and receipts for operator review. GET reservations use the existing metadata-request record format and remain counted by the preceding release. Existing timer state is not changed by these commands.

Offline verification: `PHP_BINARY=/path/to/php node tests/village-director.mjs`. All provider responses are mocked, including concurrent inspection reservations, bad identity/status/usage, stale hashes, budget quarantine, idempotent resolution, failed-history preservation and a subsequent distinct mocked encounter.

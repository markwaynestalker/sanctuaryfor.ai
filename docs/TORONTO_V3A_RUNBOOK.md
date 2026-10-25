# Toronto maintenance runbook — owner-run deployment only

Current [autonomous continuity](AUTONOMOUS_VILLAGE.md) evaluates each distinct opportunity against ENV and retained commitments, without a historical-accounting veto. The [owner-run reconciliation procedure](DIRECTOR_RECONCILIATION.md) remains optional diagnostics; it preserves failed history and reservations without retrying generation or changing scheduler state.

## Current lifecycle fix and production hotfix (2026-10-25)

Production is already V6. The owner reports the following current overrides; this PR does not change them:

```dotenv
LITTLE_SPARKS_AI_DAILY_REQUESTS=200
LITTLE_SPARKS_RESIDENT_DAILY_MAX=100
LITTLE_SPARKS_DIRECTOR_ENABLED=1
LITTLE_SPARKS_DIRECTOR_RUNTIME=agents
LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES=15
LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=1
LITTLE_SPARKS_AI_ENABLED=1
```

`sanctuary-village.timer` is enabled and active. `sanctuary-village.service` has `TimeoutStartSec=50s`. Do not install, disable, restart or reconfigure the production timer as part of this PR. No production command or paid evaluation was executed while preparing it.

**The production checkout is dirty.** The owner applied and syntax-checked this uncommitted change in `scripts/scheduled-village.php`:

```php
if($paid){LittleSparks\aiConfiguration(time());LittleSparks\loadScheduledCredential();}
```

The PR contains this exact condition. V6 identities must not skip protected credential loading. The existing CLI loader reads systemd's `CREDENTIALS_DIRECTORY/openai_api_key` only after paid gates/configuration validation; it does not depend on PHP-FPM credentials, return the key or log it. The process environment is restored in `finally`. `--check` reads neither a credential nor SQLite.

Owner deployment checklist:

1. Inspect `git status --short` and `git diff -- scripts/scheduled-village.php` in `/var/www/sanctuaryfor.ai`. Save the complete local diff and current file into a unique, owner-selected private backup outside `public/`. Stop if there are additional/unexpected edits. Never use `git reset --hard` or overwrite the dirty file blindly.
2. Coordinate the existing deployment procedure with the active timer and an idle service. Inspect `systemctl is-active sanctuary-village.service` and `systemctl list-timers sanctuary-village.timer`; use the owner's safe deployment window/atomic release process without changing the timer or environment. Preserve an approved consistent V6 SQLite backup using the existing backup procedure. This patch requires **no migration**.
3. After reviewing/backing up the exact one-line hotfix, the owner can stash only that file with `git stash push -m 'Preserve Toronto scheduler credential hotfix' -- scripts/scheduled-village.php`, recording the resulting stash ID. Then deploy the reviewed PR through the existing procedure. Keep the backup/stash until verification; the new source already contains the hotfix, so do not blindly pop the stash over it.
4. Run `php8.3 -l scripts/scheduled-village.php` and invoke `php8.3 scripts/scheduled-village.php --check` in the existing approved service environment (a bare shell may report default paused settings). The latter only validates configuration and reports readiness. Do not run the paid scheduler manually as a deployment check. Verify the deployed condition matches the saved hotfix and the service/timer retain their pre-deployment state.
5. Monitor the next ordinarily scheduled, eligible opportunity and its private ledger/journal. Do not replay `op:2026-10-25T21:15:00Z`, clear earlier uncertainty, invent billing evidence, or insert the already-generated Luna/Max exchange manually. Existing unresolved accounting holds can still block new work; this patch does not reconcile them.

Polling retains the existing eight-slot envelope: fresh session create + inspection (or reused-session inspection + one input submission), up to three read-only turn inspections spaced four seconds apart, output retrieval, moderation, and a final observation when capacity/time remains. An empty early page or the prior reused-session turn is not completion. Every inspection is durably counted; no request limit, monthly allocation, simulation interval, schema or resident data changes. The lifecycle stops within a 45-second process budget (30-second polling cutoff), leaving time for finishing work and five seconds below the systemd timeout. Timeout/cancellation retains unknown charges and never resends generation.

Rollback is code-only through the owner's existing release procedure, preserving the scheduler hotfix. Do not restore an older SQLite file or reset ledgers/history to roll back this code change.

## Historical V3A preparation (superseded by the current production state above)

**Current decision: do not merge/deploy the live managed Director yet.** See V3A_MANAGED_SAFETY.md for the unresolved provider-bound and staging gates. These are exact future operator steps, not authorization to execute them. No production connection, configuration change, backup, migration, paid call or service operation was performed in preparing this document.

The owner confirms PR #10/#11 merged and deployed: production is V5 plus identity revision 1, six residents, owner-written personalities/colors and PHP 8.3.6; the village timer is inactive. This V6 migration now adopts installed identity tables/audits unchanged. The isolated paid experiment in V3A_STAGING_AND_RELEASE_REVIEW.md uses a fresh synthetic DB and must not open or copy production SQLite. Its execution approval is separate from every production step below.

Owner-confirmed Toronto paths/state on 2026-10-25:

| Item | Confirmed value |
| --- | --- |
| Code directory | `/var/www/sanctuaryfor.ai` |
| Web root | `/var/www/sanctuaryfor.ai/public` |
| Private database | `/var/lib/sanctuaryfor-ai/village.sqlite` |
| PHP-FPM | `php8.3-fpm.service`, enabled |
| Village service | `sanctuary-village.service`, static |
| Village timer | `sanctuary-village.timer`, **disabled** |

Keep the timer disabled throughout staging, migration, deployment and rollback. Do not start/enable it, install a replacement timer, or change its unit/credential drop-ins. No unattended paid activation is included. Confirm the actual database owner/group from metadata; do not assume a different server identity or change ownership on the live database.

## Approval and preconditions

The owner must approve PR/release, maintenance outage, production backup/migration and rollout separately from any paid experiment. First complete a no-cost PHP 8.3/PDO_SQLITE/cURL staging rehearsal of this exact commit, all thirteen offline suites, installed-identity preservation/restore and UI checks. For the desired live Director release, complete its remaining provider-bound/activation review too. A successful manually approved research experiment alone does not open unattended production admission.

Record the approved full commit SHA and a separately tested identity-aware V6 fallback SHA from the reconciled code. The old pre-identity V3A foundation is not a compatible fallback for the deployed add-on; do not use it. Preserve both reviewed checkouts until rollback risk has passed.

Commands below are for an authenticated Bash operator shell on Toronto **after approval**, as the existing deployment user with the necessary sudo rights. They use no API key value and must not be pasted into a public issue. Fill the two reviewed SHA variables explicitly; the assertions refuse empty/unreviewed placeholders. No command here prints the process environment, FPM configuration or credential file.

```sh
set -euo pipefail
umask 077
APP=/var/www/sanctuaryfor.ai
DB=/var/lib/sanctuaryfor-ai/village.sqlite
PHP=/usr/bin/php8.3
APPROVED_COMMIT=REPLACE_WITH_REVIEWED_40_HEX_SHA
V6_FALLBACK_COMMIT=REPLACE_WITH_TESTED_40_HEX_SHA
[[ "$APPROVED_COMMIT" =~ ^[a-f0-9]{40}$ ]]
[[ "$V6_FALLBACK_COMMIT" =~ ^[a-f0-9]{40}$ ]]
[[ -f "$DB" && ! -L "$DB" ]]
[[ "$(systemctl is-enabled sanctuary-village.timer || true)" == disabled ]]
[[ "$(systemctl is-enabled php8.3-fpm.service || true)" == enabled ]]
DB_OWNER=$(stat -c '%U' "$DB")
DB_GROUP=$(stat -c '%G' "$DB")
PRIOR_COMMIT=$(git -C "$APP" rev-parse HEAD)
[[ -z "$(git -C "$APP" status --porcelain)" ]]
git -C "$APP" fetch origin
git -C "$APP" cat-file -e "$APPROVED_COMMIT^{commit}"
git -C "$APP" cat-file -e "$V6_FALLBACK_COMMIT^{commit}"
REVIEW=/var/tmp/sanctuary-v3a-review-$APPROVED_COMMIT
[[ ! -e "$REVIEW" ]]
git -C "$APP" worktree add --detach "$REVIEW" "$APPROVED_COMMIT"
"$PHP" -v
"$PHP" -m
```

Verify PHP 8.3 and PDO_SQLITE/cURL in the output. Run the offline suites on the separate staging checkout/database with `OPENAI_API_KEY` cleared; never point fixtures at `$DB`. If code has uncommitted changes, a checkout already exists, the required extension is absent, or a SHA is unavailable, stop and resolve it without force/reset. The review worktree contains code, not production credentials.

Before the maintenance window, verify all effective paid/scheduler flags are off through the approved private operator configuration workflow. The new Director flag defaults off when absent; do not change or print the existing FPM key. The actual environment-file location has not been confirmed; inspect **only** `systemctl show sanctuary-village.service -p EnvironmentFiles` to locate its references, then have the operator review them privately. Do not assume the example `/etc` paths are Toronto facts. No configuration file is edited by these commands.

## Quiesce writers and take a verified V5 backup

This step temporarily stops PHP-FPM and therefore the website's PHP responses. It requires the approved maintenance window. Keep that service's enabled state unchanged. Stop timer activity without enabling/disabling a unit, and let any already-running village service finish; do not kill an in-flight billed request.

```sh
sudo systemctl stop sanctuary-village.timer
if systemctl is-active --quiet sanctuary-village.service; then
  printf '%s\n' 'Wait for the bounded village service to finish, then rerun this check.'
  exit 1
fi
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/ai-status.php" --database="$DB"
```

Require no pending opportunity, no held lease, and zero unresolved attempts. Unknown costs are a stop condition; reconcile the original request separately, never delete/reset a row to make migration proceed. Ensure no other manual writer is running. Then:

```sh
sudo systemctl stop php8.3-fpm.service
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP=/var/lib/sanctuaryfor-ai/backups/v3a-$STAMP
[[ ! -e "$BACKUP" ]]
sudo install -d -m 0700 -o "$DB_OWNER" -g "$DB_GROUP" "$BACKUP"
printf '%s\n' "$PRIOR_COMMIT" | sudo -u "$DB_OWNER" tee "$BACKUP/prior-code.txt" >/dev/null
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/backup-village.php" \
  --database="$DB" --output="$BACKUP/village-v5.sqlite" \
  | sudo -u "$DB_OWNER" tee "$BACKUP/before-v5.json"
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/inspect-village.php" \
  --database="$BACKUP/village-v5.sqlite" --matches-v5="$BACKUP/before-v5.json"
```

Require schema version 5, `matches_v5=true`, successful integrity/FK verification, and a recorded backup SHA-256. `backup-village.php` uses a [consistent SQLite snapshot](https://www.sqlite.org/lang_vacuum.html), includes committed WAL content, checks synchronization, refuses an existing destination, and compares every application table/schema row before and after. It does not overwrite the source or preserve deleted forensic content. Stable application IDs, not implicit rowids, are authoritative. If backup verification fails, retain the output for inspection and choose a new destination after fixing the issue; never use a failed backup as the only rollback copy. Copy the verified backup/manifest to the owner's existing protected off-host backup system under its approved procedure; no new cloud upload is authorized here.

Keep FPM stopped on an intermediate failure until a known compatible code/database pair is verified using the rollback section. Do not automatically restart through a failed migration.

## Rehearse migration on the backup copy

Preserve the verified backup. Create a separate private rehearsal directory/file; do not migrate the only V5 backup.

```sh
REHEARSAL=$BACKUP/rehearsal
sudo install -d -m 0700 -o "$DB_OWNER" -g "$DB_GROUP" "$REHEARSAL"
sudo -u "$DB_OWNER" cp --no-clobber "$BACKUP/village-v5.sqlite" "$REHEARSAL/village.sqlite"
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/migrate-director.php" --database="$REHEARSAL/village.sqlite"
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/inspect-village.php" \
  --database="$REHEARSAL/village.sqlite" --matches-v5="$BACKUP/before-v5.json"
```

Require version 6, accounting revision 1, staging revision 1, preserved V5 AND identity digests and successful integrity/FKs. On installed V5+identity, migration adds eight Director, four accounting and three staging tables. It never recreates/reinitializes the three installed identity tables or their constraints/audits, and refuses pending/held activity. Rerun on the rehearsal copy to verify idempotency. Verify all six original IDs/private links, Sparky's exact mayor description, effective colors/revisions, edit-audit count, history and accounting match the snapshot. No secret is printed. Only bare old V5 fixtures receive empty identity backfill; deployed owner-written text is never overwritten.

## Approved production migration and code cutover

Only after that rehearsal succeeds and production migration approval is explicit:

```sh
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/migrate-director.php" --database="$DB"
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/inspect-village.php" \
  --database="$DB" --matches-v5="$BACKUP/before-v5.json" \
  | sudo -u "$DB_OWNER" tee "$BACKUP/after-v6.json"
git -C "$APP" switch --detach "$APPROVED_COMMIT"
sudo -u "$DB_OWNER" "$PHP" "$APP/scripts/register-director.php" \
  --database="$DB" \
  --agent-id=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8
sudo -u "$DB_OWNER" "$PHP" "$APP/scripts/stage-director-once.php" --check
sudo systemctl start php8.3-fpm.service
[[ "$(systemctl is-enabled sanctuary-village.timer || true)" == disabled ]]
if systemctl is-active --quiet sanctuary-village.timer; then exit 1; fi
```

Registration is offline and stores the existing saved agent's restricted structural identity; it does not create an agent or call the API. Record its fingerprint privately. Do not enable Director, AI, simulation or scheduler flags as part of cutover. Production managed admission remains blocked. Existing credential provisioning stays unchanged; personalities/icon already deployed are retained. This sequence does not authorize live Director rollout.

Read-only smoke checks: homepage, village creation form/color preview, resident profile, journal, map and icon asset. Verify each public page references `/assets/apple-touch-icon.png?v=1`; confirm its 180×180 PNG response. Use production private links only for an owner-approved read-only revisit, never paste a token in a log/URL query. Test identity edits and creation on staging, not by fabricating production residents. On the owner's iPhone, Safari → Share → Add to Home Screen should preview the robot; existing shortcuts may need to be recreated. Actual iPhone validation is still pending.

Record release SHA, migration/preservation results, disabled flags, timer-disabled/inactive state, smoke checks and private backup locations. Keep all backups private; never commit them.

## Rollback without losing village history or spending evidence

**Migration refused/rolled back, database still V5:** leave paid activity/timer off. Verify `inspect-village.php` reports V5 and the original digest. Switch code back to the saved `$PRIOR_COMMIT`, verify the old code expects V5, then start `php8.3-fpm.service`. Do not restore the backup just because the transaction refused; the live data remains intact.

**Migration succeeded, database is V6:** the default rollback is a **V6-capable paused build**, retaining the entire current database. Stop web writers in the approved maintenance window, wait for all claims/leases, and take a fresh verified V6 backup before changing code:

```sh
sudo systemctl stop sanctuary-village.timer
# Confirm village service inactive and claims/leases drained as above.
sudo systemctl stop php8.3-fpm.service
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/backup-village.php" \
  --database="$DB" --output="$BACKUP/village-v6-before-code-rollback.sqlite"
git -C "$APP" switch --detach "$V6_FALLBACK_COMMIT"
sudo -u "$DB_OWNER" "$PHP" "$REVIEW/scripts/inspect-village.php" --database="$DB"
sudo systemctl start php8.3-fpm.service
[[ "$(systemctl is-enabled sanctuary-village.timer || true)" == disabled ]]
if systemctl is-active --quiet sanctuary-village.timer; then exit 1; fi
```

Use a new backup filename if this rollback is repeated; overwrites are refused. Keep Director/AI/simulation/scheduler flags off. Do not lower `user_version`, delete new tables/notes/ledgers, or point V5-only code at V6. Additional accounting tables are additive and must be retained even when the fallback does not display them.

**Restoring the older V5 database is not the routine rollback.** After any post-backup creation, identity edit, conversation, world action or paid reservation it would discard history or restore false spending headroom. A restoration needs a separately approved data-and-accounting recovery plan: preserve the complete current V6 database and sidecars, reconcile all intervening writes/charges, retain the old file rather than overwrite it, and restore into a new verified private file before a controlled path switch with all writers stopped. Never remove a possibly active `-wal`/`-shm` file independently. No destructive restore command is supplied as an automatic shortcut.

The final timer state remains disabled/inactive after every outcome. Re-enabling scheduled activity or paid Director execution requires new explicit owner approval.

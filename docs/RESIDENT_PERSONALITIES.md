# Independent resident personalities: V5 identity add-on revision 1

This release follows the icon/24-color release and contains no managed Director runtime. It adds owner-written **public** personality text and color editing, using existing private revisit links. IDs, names, antennas, original resident rows, homes, relationships, history, provenance, credentials and accounting remain intact. A description is character input, never a verified memory or an executable instruction. PHP generates no new character speech or preferences from it.

## Release boundaries

Initially stack this PR on `feat/robot-icon-colors`; rebase/retarget onto main after that PR lands. Do not cherry-pick unfinished V3A code. The explicit migration adds only `identity_schema`, `resident_identities`, `identity_edits` and their indexes/triggers in one `BEGIN IMMEDIATE` transaction. `PRAGMA user_version` remains **5**; the add-on revision is **1**. Original descriptions start empty; legacy personality categories and colors are preserved. Hash all preexisting rows/schema, including secrets and ledgers, before/after; require equality. Repeat migration is safe; partial/unsupported markers and non-V5 inputs fail closed.

After migration, **all new simulations pause**: manual legacy runs, opportunities, direct paid reservations and the scheduled adapter. Recheck inside claim/reservation transactions so migration cannot race a worker. Migration refuses held leases/pending opportunities; never delete or clear accounting to force it. Saved conversations, journal, private links, memory trails and authorized plot actions remain available. New residents receive no PHP-assigned voice/interests. The pause lasts until a separately reviewed identity-aware Director runtime replaces legacy generation; flags cannot override it. No OpenAI call is needed for this release.

Keep draft PR #9 isolated. After independent releases land, reconcile its full V6 migration to **adopt** existing identity rows and edit audits without dropping/reinitializing them. Test populated V5+identity→V6 preservation, tokens and ledgers. Do not run the current unfinished V6 migration against this add-on, or use a draft V6 staging database as input here. This PR changes no Director configuration, model, credentials, budget or timer.

## Public input and private editing

Before migration the original category form/runtime still works. Afterwards creation requires `description`; the original resident column stores the `custom` marker. Public lists/profiles/revisits expose the effective description/color/revision. Lists use one join; maps use one bounded color batch. Original creation fingerprints/retries remain valid, including after editing.

Descriptions permit Unicode, paragraphs and emoji. Trim outer whitespace; enforce character length; reject invalid UTF-8, markup delimiters, hidden/control characters and obvious `sk-...` credentials. Owners must keep private information out; lexical validation cannot recognize all secrets. Escape PHP output and use `textContent`. Owner notes cannot establish a fact, memory receipt or completed action. Future Director context must treat them as untrusted character data, separate from historical sources.

`POST /api/identity.php` requires same-origin CSRF/session checks and a maximum 24,576-byte JSON body: private `token`, `description`, approved `color`, integer `expectedRevision`, and 32-hex `requestId`. Token authorization uses the existing hash. Remove the URL fragment before networking; send the token only in POST bodies; retain it in the editor closure, never DOM/storage/logs/public responses. Close removes the editor. Anyone holding a private link can edit that character; this is not an account or token recovery system.

Under `BEGIN IMMEDIATE`, edits authenticate, apply network/daily limits, check optimistic revision and write the immutable private audit plus effective identity atomically. Identical retries replay, conflicting reused IDs/stale revisions return 409. No edit changes name, ID, antenna, historical dialogue, memories or provenance. New descriptions/colors never rewrite original creation rows. Audit data stays private and is not pruned to regain limits.

## Configuration and offline tests

PHP does not automatically load `.env.example`. Use the existing approved protected FPM/CLI environment; this release requires no API key.

| Variable | Default | Strict integer range |
| --- | --- | --- |
| `LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS` | 2000 | 1–4000 |
| `LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY` | 6 | 0–24 successful edits/resident/UTC day |

Invalid, signed, decimal, empty, leading-zero and out-of-range values reject writes/migration. Lowering the text limit does not erase old descriptions. Zero edit allowance blocks new edits but permits successful retries/reads. Network edit attempts are capped at 30/hour using an HMAC key. Existing creation CSRF/time/honeypot/network/three-residents-per-browser/global limits remain. AI ceilings, unknown reservations and $1/month maximum are unchanged.

With PHP_BINARY pointing to PHP, run `node tests/resident-personalities.mjs`, `node tests/icon-colors.mjs` and all ten existing suites. Tests clear keys and use disposable private databases/loopback servers only. Focused coverage includes populated V5 history preservation, migration rollback/repeat/refusal, original links/retries, hostile/Unicode text, configuration, simultaneous edits, authentication/CSRF/idempotency, escaping/privacy, persistent audit, no invented preferences and paused execution without credential loading. A local PHP 8.2 pass still requires PHP 8.3 staging verification before approved deployment.

## Toronto backup, migration and validation (future approval required)

No deployment or migration is authorized by this document. Code: `/var/www/sanctuaryfor.ai`; web root: `public/`; database: `/var/lib/sanctuaryfor-ai/village.sqlite`; FPM: `php8.3-fpm.service`; village service: `sanctuary-village.service`; timer: `sanctuary-village.timer`, currently **disabled** and must remain disabled. Use a reviewed immutable release SHA. Never deploy the Director branch or change keys for this feature.

First rehearse on a private staging snapshot. Verify the installed `sqlite3` CLI and PHP 8.3 PDO SQLite. Confirm old links, saved dialogue, plot coordinates, phone/keyboard editing, retries, audits and the paused runner. No paid flags or timers are needed. Staging snapshots contain private data and need the same protections as production.

Only after explicit production approval, schedule a write-free maintenance window. Record the current code SHA and service/FPM states. Run these state checks **separately**, since inactive/disabled statuses normally exit nonzero:

```bash
cd /var/www/sanctuaryfor.ai
git rev-parse HEAD
systemctl is-enabled sanctuary-village.timer  # require disabled
systemctl is-active sanctuary-village.timer   # require inactive
```

Stop FPM, the village service and any separately launched CLI writers. Do not clear pending/uncertain operations to get past migration. Use the reviewed identity-aware release for the scripts below, following the existing approved code installation process; do not modify infrastructure. Example commands for an authorized operator:

```bash
set -eu
umask 077
cd /var/www/sanctuaryfor.ai
sudo systemctl stop sanctuary-village.service
sudo systemctl stop php8.3-fpm.service
db=/var/lib/sanctuaryfor-ai/village.sqlite
db_owner=$(stat -c '%U' "$db")
db_group=$(stat -c '%G' "$db")
stamp=$(date -u +%Y%m%dT%H%M%SZ)
sudo install -d -m 0700 -o "$db_owner" -g "$db_group" /var/lib/sanctuaryfor-ai/backups
snapshot="/var/lib/sanctuaryfor-ai/backups/pre-identity-$stamp.sqlite"
# Verify source file/directory 0600/0700 first. Destination must be new.
test ! -e "$snapshot"
sudo -u "$db_owner" sqlite3 "$db" ".backup '$snapshot'"
sudo chmod 0600 "$snapshot"
sudo -u "$db_owner" php8.3 scripts/inspect-identity.php --database="$db"
sudo -u "$db_owner" php8.3 scripts/inspect-identity.php --database="$snapshot"
# Require identical schema_version, residents, preserved_v5_sha256, integrity ok.
# Save both outputs and sha256sum of snapshot in protected operator records.
sudo -u "$db_owner" php8.3 scripts/migrate-identity.php --database="$db"
sudo -u "$db_owner" php8.3 scripts/inspect-identity.php --database="$db"
# Require schema_version 5, identity_revision 1, original count and exact same
# preserved_v5_sha256 as before migration; a new audit starts with zero edits.
sudo systemctl start php8.3-fpm.service
# Do not start the village service or enable/start the timer.
```

SQLite `.backup` includes committed WAL data; do not copy only a live main database. Inspect both backup and source for integrity/FKs and matching fingerprints before migration. Protect snapshot/records like production, and rehearse a restore on a copy. After reopening FPM, check public pages and original private links, then one explicitly agreed owner edit and reload persistence. Verify new simulations report `paused_identity_runtime_pending` and usage has no new reservations. If checks fail, keep writers stopped. No credentials should be printed or added to CLI arguments.

## Rollback

Before commit, any exception rolls back the entire add-on, leaving V5 intact. Inspect before retrying; do not hand-edit versions/tables. After commit, prefer an identity-aware fix-forward; setting `LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY=0` can pause edits, but **does not block creation or every write**. For a full write pause keep FPM stopped or use the existing approved maintenance procedure.

Never restore the old snapshot over later residents, edits, events, ledgers or uncertain billing outcomes. Never use unmodified V5 code as a write-enabled rollback: it ignores the add-on, can create missing identities and lacks its simulation guard. Preserve all identity/audit rows and keep writers stopped until an identity-aware build is ready. Complete snapshot restore is an explicitly approved disaster recovery step only after proving no intervening writes or reconciling every later record/ledger. Archive the failed database and sidecars privately first; verify restored integrity, links, counts, digest, permissions and disabled timer before reopening. Full V6/Director deployment needs its own adoption migration, backup, reconciliation, rollback review and approval.

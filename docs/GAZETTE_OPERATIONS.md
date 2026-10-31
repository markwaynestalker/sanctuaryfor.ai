# Gazette operations and helper integration (#82/#84)

**Activation is BLOCKED pending final review and separate owner setup/staging/activation approval.** #83 integrates the keyless helper from #85; review the helper PR first. No database ACL, group permission, ownership or application-guard change is needed. Do not work around this with root execution, a shared UID for the API worker, direct SQLite access, sudo or a direct-storage fallback.

This runbook describes future owner operations, not commands executed by development. No production access, installation, migration, credentials, ENV/service/timer change or paid call is authorized by the implementation PRs. Neither PR merges or deploys automatically. Actual installed systemd credential/sandbox validation and separately bounded live evaluation remain operational gates after code review.

## Components and identities

- `sanctuary-gazette-editor.service`: dedicated `sanctuarygazette` UID with its own protected systemd API credential. All of `/var/lib/sanctuaryfor-ai` is inaccessible; no ReadWritePaths, SQLite argument or database connection is used.
- `sanctuary-gazette-db.socket` and `sanctuary-gazette-db@.service`: root-controlled pathname socket, kernel-authenticated worker UID and short-lived keyless operation under the existing non-root database owner. No credential, IP network, Director ENV, review or publication endpoint.
- `ops/gazette/run.py`: root-installed launcher of the fixed physical-release `generate-gazette-brokered.php`. Modes: scheduled, explicit `--once`, or metadata-only/read-only `--check`. There is no client date/database option.
- `ops/systemd/gazette-editor.env.example`: explicit disabled worker limits and invalid release placeholder.
- `ops/systemd/gazette-db-config.json.example`: disabled authoritative helper policy, actual editor UID, database path, physical release and optional exact owner-approved date. Values are examples, never auto-installed.

Read [GAZETTE_DATABASE_HELPER.md](GAZETTE_DATABASE_HELPER.md) for the protocol, capability/state boundary and limits. The API worker remains a different UID from DB-owning FPM/Director principals. Those principals must not read the worker's credential; the helper gets none. The helper itself remains trusted code with full technical database access. No claim of SQLite per-table users or authenticated provider billing is made.

## Root-controlled provisioning: approval first

1. Identify actual production DB owner/group, modes, journal mode/runtime SQLite version, effective unit configurations and every database writer without dumping ENV/secrets. Do not assume historical www-data templates describe current production. Refuse root-owned DBs or identical worker/helper UIDs.
2. After explicit installation approval, provision the dedicated non-login editor UID/group without DB-owner/FPM/deploy membership or sudo. Do not change the existing DB account, modes, ACLs or guard.
3. Review/install root-owned helper scripts under `/usr/local/lib/sanctuary-gazette/`, root-owned units and root-owned protected configuration. The helper config's ancestors must be root-owned and nonwritable by group/others. Its nonsecret JSON is readable by the DB helper; no API credential goes there. Replace example UID and every release placeholder with verified values.
4. Separately provision `/run/sanctuary-gazette-db` root:editor-only-group 0710. Its root:editor-only-group 0660 socket is managed by systemd. Review `ops/systemd/sanctuary-gazette-db.tmpfiles.conf` for owner installation under `/etc/tmpfiles.d/` so boot recreates the directory with those exact settings before socket activation. Do not rely on DirectoryMode alone to select its group. Other users must not replace the socket, traverse the directory or join the group.
5. Provision the Gazette-only API key source root-private under `/etc/sanctuary-gazette/` through an approved secret-handling procedure. Never paste/read it into a command, ENV, repository or journal. LoadCredential delivers fixed `openai_api_key` only to the editor unit. Do not copy the Director key or grant the helper/FPM source-file access. See [SYSTEMD_CREDENTIAL_VALIDATION.md](SYSTEMD_CREDENTIAL_VALIDATION.md).
6. Inventory the DB directory: the keyless helper needs limited journal/SHM directory writes; unrelated private configuration and publication/deploy paths are denied in its namespace. Confirm no extra writable sensitive paths are introduced. Keep worker/helper NoNewPrivileges, empty capabilities, private temp/devices, core-dump denial and bounded resources. Keep cgroup metadata visible for credential validation. Do not weaken the validator to make a unit start.

Only the worker timer has an Install target. The socket must be explicitly owner-started after validation, and a reviewed boot dependency must keep the socket directory correctly provisioned before a later timer activation. Installing files or daemon-reload does not enable the timer. Keep worker and helper ENABLED=0 through setup.

## Exact code and policy pins

Use the Toronto release helper and exact tested main SHA with Ubuntu CI/manifest/health evidence from [TORONTO_DEPLOYMENT.md](TORONTO_DEPLOYMENT.md). Separate local operational activation is still required; GitHub approval is insufficient. Pin worker and helper to the same physical release, matching marker and current deployment pointer. Both refuse service-writable release directories. The marker is not manifest verification; the owner verifies the private deployment evidence without granting either service deploy-private access.

No moving entry-point script or automatic root-helper/config update is used. Later normal deployments intentionally block the old pin until the owner reviews/updates both sides. Already-started work stays on its original physical release. Protocol and policy digests must agree before reservation. Client ENV cannot raise the helper's budgets, choose dates or submit source packs.

## Limits and prices

Both disabled examples explicitly configure: $0.05/day (50000 micro-USD), $1/month (1000000), two daily HTTP slots, zero-dollar moderation allowance, 96000 input tokens, 12000 output tokens and 90000 request bytes. The reviewed pricing identifier is `gazette-luna-standard-2026-10-31`, model `gpt-6-luna`, default/standard route. Recheck current official [model](https://developers.openai.com/api/docs/models/gpt-6-luna) and [prices](https://developers.openai.com/api/docs/pricing) and actual project/route before confirmation; documentation does not establish account access.

At existing conservative $0.25 input / $0.50 output per million, reserve $0.024 + $0.006 = **$0.03/edition**, including the explicit zero moderation dollar allowance. Thirty-one ordinary retained reservations total $0.93. Both HTTP slots remain charged even if moderation is never dispatched, and cheap completion never refunds a hold. UTC dispatch-day/month budgets differ from Eastern edition dates. Actual overruns can increase commitments and stop later work. A 160000/16000 profile reserves $0.048 and permits only 20 such reservations under $1/month.

The full serialized request must fit 90000 bytes and wire bytes plus 4096 must fit the 96000 allowance. Whole source records are dropped honestly; this does not promise full coverage of a busy day. API responses remain capped at 262144 bytes. Input admission is not a certified tokenizer/provider input cap; usage checks happen after charging. The owner must accept this residual or request a separately reviewed counting change before paid work. Remote counting would add an operation beyond the two-slot ledger. No guaranteed invoice cap or cached-token discount is claimed. See [GAZETTE_EDITOR_INTEGRATION.md](GAZETTE_EDITOR_INTEGRATION.md).

## Calendar, no backfill and readiness

The nonpersistent timer targets 05:15 America/New_York. The launcher and authoritative helper admit the normal schedule only at 05:15–05:19:59 and use calendar yesterday, including 23/25-hour DST days. No boot catch-up, delayed backlog, revision permit, automatic retry or publication occurs. Synchronize the host clock; existing SQL leases/unique dispatches fence concurrency independently of the Director.

Normal disabled launcher invocation exits before code resolution, socket access, credential inspection or subprocess. `--check` deliberately validates protected credential metadata and calls only the helper's readonly readiness operation, without reading credential contents or making HTTP requests. The obsolete direct-DB preflight is removed. Readiness checks protocol/policy/schema, not write permissions, available budget, model access or quality. Test sidecar writes separately on disposable Linux data.

After separately approved installation, parse the actual units with `systemd-analyze verify`, including both helper units, editor service and timer. Inspect the effective sandbox/argv/identities without dumping ENV or credentials. Check `systemd-analyze calendar '*-*-* 05:15:00 America/New_York'`. Use metadata and denial tests to prove FPM/Director/deploy/helper cannot read the Gazette key, and editor cannot access SQLite/sidecars or publication files.

First use stand-in credential delivery in isolated Linux staging. The existing validator binds the delivered mount to the actual .service cgroup; do not hide that metadata or bypass a refusal. Check normal ENABLED=0 invocation causes no dispatch. A separately reviewed temporary Gazette-only ExecStart override can reset ExecStart to the same root-installed launcher with `--check`; restore the normal scheduled argv afterwards. Do not modify the village unit.

Diagnostics contain only sanitized status, attempt/draft identifiers and static refusal text. No raw observation, capability, source/draft copy, tokens, credential, ENV or exception enters the journal. Inspect selected private ledger evidence for token/cost diagnosis. A timeout/lost IPC response may have committed dispatch: never restart it to retry, refund or regenerate. Let an in-flight paid operation finish safely when disabling new admissions.

## SQLite migration maintenance window

There is **no new #84/#83 migration**. #80's ledger revision 1 is still separately required. If already installed, inspect it; never drop/recreate it.

1. Record exact reviewed release, user_version and addon state, plus original active/enabled states and configurations of all writers: village timer/service, FPM pools/mutating HTTP routes, registration/identity/operator idea writes, manual CLI jobs, cron/backup/reconciliation/bulletin/editor jobs.
2. Obtain separate approval for a server-specific maintenance interval. Prevent every new mutating request/job, suspend new scheduled admissions using the reviewed procedure and let in-flight writers finish. Do not kill a paid worker. Preserve the Director's five-minute cadence, owner-reported 21:00–06:00 rest, budgets and unit definitions; record temporary state changes for exact restoration.
3. Verify the actual inventoried writers are drained using process/open-handle/unit/lock evidence. Stopping one timer, an idle AI lease or a successful BEGIN IMMEDIATE alone is not proof no future writer can arrive. Keep the admission barrier through migration/checks.
4. Capture a private SQLite-consistent backup using reviewed backup API/tooling, not a main-file-only live copy. Back up the sibling `village.sqlite.gazette` tree with ownership/modes/checksums as the same documented recovery point. Inspect a restored disposable copy: integrity ok, empty FK errors, schema/version, old rows/history/accounting and publication bindings. Never test restore over production or copy credentials into fixtures.
5. As the actual database owner, use the physical reviewed release (not root leaving root-owned sidecars):

```sh
/usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/migrate-gazette-editor.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --confirm-drained=yes
```

6. The flag only asserts the drain; it neither stops nor inspects writers. While still drained require user_version 6, integrity ok, no foreign-key errors and `SELECT revision FROM gazette_editor_meta WHERE singleton=1` = 1. Compare pre-existing schema/data/digests against backup; only reviewed additive editorial objects may differ. Verify ownership/private modes/sidecars.
7. Restore exactly the recorded writer states/admission barriers. Verify normal history/reader/accounting and original Director timer/settings, observing the next natural opportunity without generating/replaying a test production conversation. Keep Gazette generation disabled.
8. On failure preserve evidence and verify transactional rollback/integrity before restoring writers. After village writes resume never replace the shared DB with an old backup just to undo this feature. Disable generation and use a reviewed forward repair; disaster restore is a separate whole-village decision.

## Bounded staging, first draft and publication

All development calls are mocked. A real evaluation needs a separate explicit allowance specifying generation/moderation call counts, total micro-USD, exact isolated fixture/date, model/project and unresolved tokenizer-risk acceptance. The provisional production ceilings are not paid authorization; earlier Director trials do not authorize Gazette calls. No production database mutation or conversation is an evaluation target.

Use disposable private data and a separate staging identity/key/helper config, with the same strict peer/state/permission controls. Start with synthetic sources and stand-in delivery. Export real authorized public sources only after a separately reviewed transfer; do not copy the full private production DB. Exercise malformed/incomplete/refused/delayed/uncertain responses with mocks, not paid fault injection. Check actual Responses/schema/model/default-tier and moderation acceptance only under the later bounded allowance. Record nullable tokens, request/response IDs and estimates privately; compare with reservations and independently review actual project/billing evidence. Human-review journalistic quality, names/quotes, source coverage, proposals/imagination versus events, chronology and headline entailment.

For the first separately approved production date, keep the timer disabled, set an exact finished `approved_date` in root-controlled helper config, and enable both components only after policy/setup approval. Use a separately reviewed Gazette-only temporary ExecStart override to invoke launcher `--once` inside the credential-bearing service. The API worker cannot choose a date. One invocation; unknown outcome means inspect, not retry. Restore normal scheduled argv and set helper approved_date back to null before any final timer enablement.

Every edition still needs manual review and publication from the protected owner session; these commands make no API request:

```sh
sudo /usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/review-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --action=show --attempt=ATTEMPT_ID

sudo /usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/review-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --action=review --attempt=ATTEMPT_ID \
  --sha256=EXACT_DRAFT_SHA --decision=approved --confirm-reviewed=sources-and-claims

sudo /usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/review-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --action=publish --attempt=ATTEMPT_ID \
  --sha256=EXACT_DRAFT_SHA --expected-current=none
```

Inspect complete private source/draft output before approval; rejected is supported. For a correction, use a separately reviewed new draft and the exact current publication-manifest SHA from show instead of none. Never substitute draft SHA for manifest SHA or overwrite history. Verify the date-specific public article, original revision/citation links and unchanged natural village behavior.

Only a final separate owner decision enables the morning timer. Neither generating a draft, moderation nor a successful CI run authorizes publication or timer activation. An approved newspaper by 06:00 still depends on human availability.

## Rollback and remaining operational checks

Disable new Gazette admissions/timer, allow in-flight work to finish or retain uncertainty, then drain/stop only the Gazette socket/helper if required. Preserve SQLite, holds, drafts, approvals, source evidence, Gazette files and compatible #81+ readers. Never alter Director scheduling/accounting, remove tables, restore old shared data, delete manifests or fall back to a direct-DB credential-bearing process. Lost/corrupt/withdrawn evidence withholds editions; recover exact verified evidence or approve a new correction.

Offline suites cover the integrated launcher, disabled mode, pins/calendar/argv and no DB fallback, plus helper lifecycle/protocol and real Linux UID/socket/PHP mock generation/moderation. They install no service and consume no paid tokens. Actual installed systemd activation/sandbox/credential mounts, intended project/API availability, quality and production operational setup still require the separate approvals above. No UI change requires new screenshots.

The additional [actual-systemd CI suite](GAZETTE_SYSTEMD_TESTS.md) exercises temporary runtime-linked units and a real readonly LoadCredential stand-in mount inside a disposable root tree, including concurrency and worker/socket restarts. No production service is installed or activated. Toronto's actual setup, credentials, API acceptance and paid operation remain separately gated. Stop/restart of the required socket propagates to an in-flight worker; drain the worker first during planned maintenance, and never treat restart as permission to replay an uncertain call.


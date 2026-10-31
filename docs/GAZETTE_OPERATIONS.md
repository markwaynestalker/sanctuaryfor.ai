# Gazette operations preparation (#82)

**Activation is BLOCKED.** The owner explicitly chose to review shared-database access separately. The templates run as `sanctuarygazette`, a different non-root UID from the existing database owner. `databasePath()` requires private 0700/0600 directory/file permissions. A different UID cannot use those paths; named ACLs change the mask bits and also fail the existing permission guard. This PR deliberately changes neither permissions nor that guard. Do not install/start the production generator or enable its timer until a separately reviewed access design is implemented and tested. Do not work around this with root, the database owner's UID, `www-data` membership, recursive chmod/chown, ACLs, capabilities, or a relaxed guard.

This is a **draft operational preparation PR**, not completion of every #82 acceptance criterion. Dedicated-account production SQLite access, actual systemd delivery, privileged owner tooling and paid evaluation are outstanding. Nothing here installs a unit, migrates production, edits ENV/credentials, accesses Toronto, calls a provider, changes the Director, or enables generation. Merging/deploying code does none of those things. All commands below are owner instructions for separate review, not a request to execute them now.

## Files and release binding

- `ops/systemd/sanctuary-gazette-editor.service`: non-root oneshot; fixed root-installed Python helper and PHP 8.3; dedicated protected ENV/credential; no restart, publication or service install target.
- `ops/systemd/sanctuary-gazette-editor.timer`: 05:15 America/New_York, nonpersistent; installed disabled. No village unit dependency or change.
- `ops/systemd/gazette-editor.env.example`: all required policy values explicit, editor disabled, invalid release placeholder.
- `ops/gazette/run.py`: only scheduled, explicit finished date, or metadata preflight modes. No shell, retries, backfill, review, permit or publication caller.
- `scripts/check-gazette-editor.php`: same private-path guard, validated credential metadata (never contents), read-only SQLite schema inspection and reservation compatibility. It does not prove write access, future budget availability, source coverage, model availability or successful API acceptance. SQLite may need existing WAL/SHM cooperation; test this on a disposable clone, never change journal mode to make a check pass.

The owner installs a reviewed copy of the helper outside releases at `/usr/local/lib/sanctuary-gazette/run.py`, root:root 0644, with root-owned 0755 parent directories. It is not self-updated by deployment. The service uses Python isolated mode and fixed `/usr/bin/php8.3`; no executable/script path comes from ENV. The only release selector is an exact lowercase 40-hex `LITTLE_SPARKS_GAZETTE_EDITOR_RELEASE_SHA`. The helper requires physical `/var/lib/sanctuaryfor-ai-deploy/releases/SHA`, matching `.release-commit`, and that the current deployment pointer still resolves to it. It refuses a release directory writable by the service UID. A subsequent normal deployment therefore blocks generation until an owner reviews/pins the new SHA. No moving `/var/www/.../scripts` entry point or automatic pin update is used. A switch after validation can leave the already-started worker on its original physical release, consistent with Toronto's retained immutable releases.

The marker is **not manifest verification**. Before pinning, verify exact reviewed main, Ubuntu CI, the Toronto helper's verified deployment/manifest and health receipt, and root-controlled unit/helper/config ownership. The service cannot read the deploy-private manifest and is not granted that access. Use [TORONTO_DEPLOYMENT.md](TORONTO_DEPLOYMENT.md), including separate owner operational activation; GitHub approval is insufficient. Later helper/config/unit changes need their own review and owner installation. Keep the Director's deployed entry points and settings unchanged.

## Identity and filesystem boundary: pending decision

Inventory existing effective UIDs/GIDs, file modes, mount policy and all writers **without printing ENV or secret contents**. Do not assume an old `www-data` template describes current production. A dedicated UID is required by this issue, so substituting the database owner is not an accepted solution. Verify denial for PHP-FPM, the deploy UID and other untrusted accounts against the Gazette credential/config sources; never grant FPM the new credential. Review existing Director credential exposure too before introducing any group relationship.

The template needs writable `/var/lib/sanctuaryfor-ai` for SQLite locking/journal sidecars, not just the database inode. `ProtectSystem=strict` restricts other filesystem writes; existing Gazette publication files, Director configuration and deployment-private data are inaccessible in its namespace. The generator does not need publication files: source/draft state is SQLite, and owner publication runs separately. Credential delivery uses the fixed root-private `/etc/sanctuary-gazette/openai_api_key` source through `LoadCredential`, with per-unit validation from [SYSTEMD_CREDENTIAL_VALIDATION.md](SYSTEMD_CREDENTIAL_VALIDATION.md). `ProtectControlGroups=true` leaves metadata readable; do not add a cgroup-hiding namespace or disable credential validation. Core dumps are disabled. No broad group membership, sudo or capabilities are granted.

**These namespace paths are only a proposed envelope.** No account/permissions installation command is supplied while the access decision is blocked. Inventory other files in the writable directory and narrow/segregate them in the future design; a writable parent can permit unlink/replacement. Linux filesystem permission does not restrict SQLite by table: direct write access technically exposes all shared tables, even though this job only writes its editorial ledger. An actual table-isolation requirement needs a separate storage/broker architecture, not chmod. Journal/SHM creation, ownership, concurrent readers/writers and both identities' future connections must be tested on disposable data. Preserve 0700/0600 checks until a reviewed replacement exists.

## Independent limits and price review

Rechecked official [Luna model](https://developers.openai.com/api/docs/models/gpt-6-luna) and [pricing](https://developers.openai.com/api/docs/pricing) on October 31, 2026. The existing adapter stays on `gpt-6-luna`, standard/default tier, short context, global `api.openai.com`; no tools or managed Agent sessions. Public documentation is not verification of the intended key's project, permissions, billing route or actual response. Recheck before signing off `gazette-luna-standard-2026-10-31`; a changed price/category requires a reviewed adapter policy update. No cached-token discount is assumed. The adapter conservatively reserves $0.25 per million input and $0.50 output; standalone moderation's example dollar allowance is zero, with its HTTP slot still retained.

The disabled example proposes:

| Setting (prefix `LITTLE_SPARKS_GAZETTE_EDITOR_`) | Value | Meaning |
| --- | --- | --- |
| ENABLED | 0 | No paid invocation, even with an installed timer |
| RELEASE_SHA | placeholder | Owner-reviewed current deployed main; invalid until replaced |
| PRICING_CONFIRMED | gazette-luna-standard-2026-10-31 | Requires fresh route/price review |
| DAILY_MICROUSD | 50000 | $0.05 editorial allocation per UTC day |
| MONTHLY_MICROUSD | 1000000 | $1 editorial allocation per UTC month |
| DAILY_REQUESTS | 2 | Generation plus whole-draft moderation |
| MODERATION_MICROUSD | 0 | Explicit allowance, no omitted default |
| MAX_INPUT_TOKENS | 96000 | Conservative local admission allowance |
| MAX_OUTPUT_TOKENS | 12000 | Responses output ceiling, including non-visible tokens |
| MAX_REQUEST_BYTES | 90000 | Full generation and moderation request bounds |

Reservation: `ceil(96000*250000/1000000) + ceil(12000*500000/1000000) + 0 = 30000 micro-USD` ($0.03). Thirty-one normal reservations retain $0.93; no cheap-completion refunds. Larger actual observations can increase commitments and block future work. Two HTTP slots are reserved even when an attempt never reaches moderation. One unpermitted attempt per finished date; no automatic revision permit. Rejected/uncertain attempts retain all holds. Daily/monthly budgets are UTC dispatch windows, not Eastern edition dates. These limits are additional to the Director's allocation, not a project invoice cap.

For comparison, 160000 input / 16000 output reserves $0.048: within $0.05/day, but only 20 such reservations fit $1/month. The example's lower envelope may drop whole source records from the bounded source pack; it does not promise coverage of every village conversation. `90000+4096 <= 96000` fits the existing whole-wire guard, leaving room for schema/instructions inside the request limit. Confirm actual retained coverage using a staging source plan before selecting production settings. Large days must report reduced coverage; never truncate speech or exceed reviewed limits to fill the newspaper. Responses are independently bounded at 262144 bytes by PR #81.

The byte guard is **not a certified input tokenizer or provider input cap**. Post-response usage can detect an overrun only after charging. The owner must accept this residual explicitly or request a separate reviewed tokenizer/counting change. Remote input counting would be a third HTTP request outside the current two-slot ledger. This PR neither adds one nor claims a guaranteed maximum invoice. See [GAZETTE_EDITOR_INTEGRATION.md](GAZETTE_EDITOR_INTEGRATION.md).

## Calendar, idempotency and diagnostics

The timer's timezone is explicit: the host's local timezone is irrelevant. Calendar yesterday is computed with `ZoneInfo('America/New_York')`, not subtracting 86400 seconds; March/November transitions and year rollover are tested. Production needs installed IANA timezone data. `Persistent=false`, no boot/startup trigger and no retry prevent missed-day backfill. The scheduled helper admits only 05:15:00 through 05:19:59 Eastern; a substantially delayed activation refuses rather than generating later. This intentionally skips an edition during extended downtime. Clock synchronization remains an owner prerequisite. Same-day repeated activation reaches the existing ledger duplicate guard, never a second automatic submission. systemd serializes this named oneshot; the independent SQL editorial lease also fences direct competing jobs. Neither uses the Director lease.

The normal helper exits successfully with `status=disabled, paid_requests=0` before release, SQLite, credential metadata or subprocess access when ENABLED is not exactly 1. `--check` deliberately performs protected metadata and read-only schema/limit validation even while disabled. Failures leave a generic sanitized refusal and a nonzero exit. Do not change `Restart=no` or add `SuccessExitStatus` to hide refusals. The helper captures PHP output/stderr and only emits allowlisted status, attempt ID and draft SHA; token counts, copy, source evidence, ENV, secrets and vendor errors do not enter the journal. Accounting observations remain private in SQLite. A killed/timeout worker may have dispatched a request: inspect ledger, never infer zero spend or retry.

After separate setup review, use `journalctl -u sanctuary-gazette-editor.service --since today` for sanitized status and private owner tooling for the draft. Review financial/token evidence using explicit selected columns from the ledger, not a whole-table/context dump. Budget/duplicate/lease/input failures are reasons to diagnose; restarting the service is not a remedy. Disabling the editor flag prevents future admissions; an already dispatched worker may still finish or remain uncertain. Do not kill it merely to switch the flag or refund its hold.

## Reviewed SQLite migration window (separate owner operation)

Do this only after migration approval, never as a deployment hook. #81/#82 introduce no new schema beyond #80's ten tables/revision 1. The generator must stay disabled and the Gazette timer stopped throughout setup.

1. Confirm exact reviewed current main deployed via Toronto's helper; record health SHA, schema `PRAGMA user_version` and whether `gazette_editor_meta` exists. Inspect rows/metadata only, not secrets. If installed, require revision 1 and all ten tables; do not drop/recreate them. The migration is repeatable but still needs the maintenance procedure.
2. Record actual enabled/active states and effective settings of `sanctuary-village.timer/service`, FPM pools, any other PHP workers, cron, admin scripts, backup tools and manual sessions that can write SQLite. Inventory registration, private identity/operator idea writes and any reconciliation/bulletin/editor jobs too. Merely stopping the five-minute timer or seeing no active AI lease is not a drained database.
3. Choose and separately approve a maintenance interval. Prevent new requests to every mutating web/CLI entry point, then temporarily suspend new scheduled writers using the reviewed server-specific procedure; let in-flight work finish. Do not terminate a paid worker or alter a timer definition, rest (currently owner-reported 21:00–06:00 Eastern), cadence or budget. Record the exact original state for restoration. No generic stop/start block is provided because the full production writer inventory is unverified.
4. Prove all inventoried writers are drained; check processes/open handles, units and database locks under the actual accounts. A successful short `BEGIN IMMEDIATE` alone does not prove a future writer cannot appear. Keep the admission barrier in place until checks/migration finish. If uncertain, abort this window and restore recorded service states.
5. Take a private SQLite-consistent backup using the existing reviewed backup tooling/SQLite backup API, not copying just a live `.sqlite` inode with WAL omitted. Preserve the sibling `village.sqlite.gazette` tree, modes/ownership, publication manifests and a checksum inventory. With all writers drained, capture both as one documented recovery point. Do not expose paths/content in a public artifact. Existing backup commands must be inspected, not assumed to include Gazette files.
6. Inspect a **restored disposable copy**: integrity `ok`, foreign keys empty, expected user_version, old/add-on schema, conversation/history/accounting counts and Gazette revision bindings. Test a restore into an isolated private directory, never over production. Keep key/ENV backups separately protected; do not copy credentials into fixtures. Preserve backup hashes and result metadata privately.
7. As the actual database owner, using a physical reviewed release path, run the command below. Do not run a writable migration as root and leave root-owned sidecars. The owner-arranged execution method must preserve DB ownership/private modes. `--confirm-drained=yes` asserts steps 2–4; it does not perform them.

```sh
# Instructions only, after the separately approved drain/backup.
/usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/migrate-gazette-editor.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --confirm-drained=yes
```

8. While still drained, verify `PRAGMA user_version=6`, `PRAGMA integrity_check` returns `ok`, `PRAGMA foreign_key_check` returns no rows, and `SELECT revision FROM gazette_editor_meta WHERE singleton=1` returns 1. Compare every pre-existing table's schema and data/count/digest against the captured baseline; allow only the reviewed new editorial schema. Confirm private ownership/modes and no root-owned journals. Do not run integrity scans routinely on every timed invocation.
9. Restore exactly the recorded writer/service states and remove only the temporary maintenance barrier. Verify the Director's original timer active/enabled status, five-minute cadence/rest/budgets and resident/history/accounting/Gazette reading. Observe the next naturally scheduled outcome; do not generate a test production conversation or replay an old period. The Gazette remains disabled. Record migration and post-check evidence privately.

Migration failure should roll back its transaction. Confirm integrity/schema and restore the recorded writer states only after diagnosis. Preserve failed evidence. Once normal village writes resume, never restore an old shared database to undo editorial code/schema: it would lose newer activity/accounting. Disable generation, retain additive tables/files, use compatible code and a separately reviewed forward repair. A disaster restore requires a distinct whole-village recovery decision, not this runbook.

## Staging verification and paid evaluation plan

No paid budget is authorized by #82. Before any network evaluation, the owner must specify the maximum generation calls, moderation calls, total micro-USD, exact synthetic/authorized public fixture, model/project and date. The provisional production ceilings above are not that authorization. Do not use the Windows ENV key as a delivery workaround or reuse prior Director trial approvals.

After the access design is reviewed, build a separate non-root staging identity, private disposable database and publication tree, reviewed physical checkout and root-protected staging key. Never point evaluation at production SQLite or the Director key. Do not grant that identity production directory access. Use an independently reviewed staging unit adapted to the **actual fixture paths**; the production helper deliberately fixes its database path and cannot be redirected by ENV/arguments. Do not edit a production unit to test staging. Record the staging unit name/credential mount relationship; the existing credential validator binds dynamically to its real `.service` cgroup. Test stand-in delivery first, following SYSTEMD_CREDENTIAL_VALIDATION.md, with no credential contents or HTTP calls. Positive privileged delivery is not established by offline mocks.

Run the existing offline Gazette tests first for malformed/incomplete/refused/timeout/delayed/ambiguous/wrong-model/tier/overrun responses, duplicate/fencing/concurrent admissions, missing usage, immutable drafts, source changes/withdrawals and corrections. Do not intentionally spend money inducing faults or repeat an uncertain request. Check with disposable fixture data that disabled job, preflight and owner/public reads cause zero API requests. Test future journal/SHM permissions under both writer identities; read-only preflight alone is insufficient.

For the separately authorized live trial, freeze full **publicly authorized** transcripts and notices with no owner tokens, descriptions, private memory or credential rows. Prefer synthetic fixtures first. Copying/exporting real public source material into isolated staging also needs an explicit reviewed transfer; a full production database copy is not required or authorized. Use the same schema/instructions/route, exact reviewed bounded policy and two-slot accounting. Review the source pack's full serialized bytes/coverage privately before dispatch, and pin the immutable snapshot/price policy. One generation plus one moderation should be the first proposed trial, bounded by the $0.03 reservation example; it is a proposal, not authorization or a guaranteed provider charge cap. A rejected/uncertain generation may never reach moderation. Do not auto-repeat.

Verify actual endpoint/schema/parameter/model/default-tier acceptance; record sanitized request/response IDs and nullable input/output tokens. Compare observed tokens/cost with the full retained reservation; reconcile actual project dashboard/billing independently (estimates are not invoices). If the provider resolves an unexpected model identifier, refuse and investigate; never broaden the allowlist or fabricate success from a 200. Confirm moderation covers the whole stored draft and source quotations. Measure byte-guard behavior on representative large/Unicode sources and decide whether the unresolved token-estimation assumption is acceptable. Account/project availability and bill categories cannot be established offline or from public documentation.

Assess headline and paragraph entailment, accurate names/quotes, chronological background, natural voice, useful article length and diversity, source coverage and clearly marked proposals/imagination. Citations/JSON/moderation do not prove truth. Compare at least a thin day and a busy day using mocks first; additional live editions require a new explicit allowance, not an automatic quality reroll. Every live result remains draft evidence. The production activation review must include all remaining unknowns and the owner decision on tokenizer risk.

## Owner review and publication

Run protected owner commands from a trusted Toronto owner session against the exact physical release. The Linux entry point requires initial root, then permanently drops to the database owner's account before opening SQLite/files. It is separate from `sanctuarygazette`; do not grant the generating account sudo or access to owner entry-point execution as root. Replace every placeholder with the actual reviewed value. These commands make no API call.

```sh
sudo /usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/review-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --action=show --attempt=ATTEMPT_ID
```

Privately inspect the complete source/draft output, headlines and every claim. Record the displayed `draft_sha256` and `current_publication_sha256`. To approve (or use `--decision=rejected`):

```sh
sudo /usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/review-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --action=review --attempt=ATTEMPT_ID \
  --sha256=EXACT_DRAFT_SHA --decision=approved --confirm-reviewed=sources-and-claims
```

Publication is another deliberate step, only after moderation/approval:

```sh
sudo /usr/bin/php8.3 /var/lib/sanctuaryfor-ai-deploy/releases/REVIEWED_SHA/scripts/review-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite --action=publish --attempt=ATTEMPT_ID \
  --sha256=EXACT_DRAFT_SHA --expected-current=none
```

`none` is only for the first v2 manifest. Corrections require a new separately permitted/paid/reviewed draft and `--expected-current=EXACT_CURRENT_MANIFEST_SHA` from `show`; never substitute the draft SHA for the manifest SHA. Existing v1 files stay intact. Verify `https://sanctuaryfor.ai/gazette.php?date=YYYY-MM-DD` and original `&revision=1` links, exact citations and no unapproved copy. Sources are reauthorized at approval/publication/read, and original revisions are immutable. A failed hash/source gate is not permission to edit SQLite or old manifests. Every edition requires human approval; the timer only prepares drafts and cannot promise publication by 06:00.

## Conditional installation and activation gates

**STOP at gate 1 today.** This is not a copy/paste installer and contains no permission workaround.

1. Resolve/review the dedicated-account shared-database access design. Verify on disposable Linux fixtures including concurrency/sidecars and unchanged existing application access. Preserve publication directory and Director isolation. Approve the concrete resulting policy before any production permission changes.
2. Review exact main/CI/deployment receipt and the full helper/units/config diff; separately approve the migration window above if needed. Verify backup/restore evidence.
3. Separately approve owner provisioning: dedicated non-login `sanctuarygazette` UID/GID (not database/FPM/deploy UID, no supplementary privileged groups), root-owned helper/config/unit paths, source key root-only and dedicated to the intended project. Keep editor ENABLED=0 and timer disabled/inactive. Copy the reviewed service/timer from the physical release only after these decisions; do not overwrite existing units/drop-ins without review. No automatic installation hook is supplied.
4. Pin the verified release SHA and explicitly review every ENV integer/price identifier. Do not `cat` secret files or show effective ENV in logs. Validate metadata with `stat`, `namei` and selected unit properties; verify credential denial under FPM/deploy identities using `test -r` without reading the file.
5. Validate unit syntax/calendar on Ubuntu 24.04 using `systemd-analyze verify /etc/systemd/system/sanctuary-gazette-editor.service /etc/systemd/system/sanctuary-gazette-editor.timer` and `systemd-analyze calendar '*-*-* 05:15:00 America/New_York'`. Use `systemd-analyze security sanctuary-gazette-editor.service` to review the actual assembled sandbox; scores aren't proof. Inspect all drop-ins/identity/paths without dumping ENV/secrets. Run `systemctl daemon-reload` only after explicit owner installation approval. It does not enable the timer.
6. With ENABLED=0, separately approved `systemctl start sanctuary-gazette-editor.service` must print only the disabled receipt and make zero provider requests. This checks the launcher, not credential mount or DB admission. Then, under an owner-reviewed **temporary Gazette-only** drop-in, reset ExecStart and set it to the same helper's `--check` mode. It must return `preflight_ready` with credential contents/API calls zero. Do not bypass failed checks. Restore the exact reviewed normal `--scheduled` ExecStart, remove only that temporary drop-in and daemon-reload. Reverify effective argv. No modification to the village service is involved. The unresolved access gate currently prevents a positive real production check.
7. Complete separately authorized isolated staging delivery/API/quality evaluation above; review evidence and limitations. No production paid call is part of staging.
8. Obtain explicit production paid activation approval for the concrete configured policy/finished date. Only then set Gazette ENABLED=1 in its protected configuration. Keep the timer disabled. To run the first approved date outside the morning window, use a separately reviewed temporary Gazette-only ExecStart override to invoke the same helper with `--date=YYYY-MM-DD`. A bare shell invocation lacks validated systemd delivery and is not supported. One invocation only; inspect ledger on any failure and never restart it to retry. Restore the scheduled ExecStart afterward, reverify effective configuration and keep timer disabled.
9. Inspect/approve/publish using the protected owner workflow; verify public reader and unchanged natural Director operation. No automatic publish action exists.
10. Only after a **final separate owner decision**, `systemctl enable --now sanctuary-gazette-editor.timer`. Confirm next Eastern run, enabled/active state, configured nonpersistent behavior, exact reviewed service argv and original village timer state. Enabling the timer is the final operational action, never a PR/deploy side effect. Do not use `enable --now` while merely preparing/reviewing this PR.

## Stop and recovery

After owner authorization, disable/stop **only** `sanctuary-gazette-editor.timer` to prevent future timer admissions and set Gazette ENABLED=0 to block manual future admissions too. Let an already dispatched service finish safely; a stop/kill can create uncertainty. Do not restart or stop the Director, change sleep/cadence, edit holds, remove tables, reset sessions, or alter past review evidence. Preserve SQLite and all `.gazette` files/backups. Stop an in-flight job only under a separate incident decision that accounts for uncertain charges.

Published papers remain available with a compatible #81+ reader while generation is disabled. Code rollback below #81 can hide v2 papers; retain a compatible reader instead of restoring old shared data. A newly deployed current SHA will deliberately fail the old pin until reviewed; an owner can leave the editor off during that review. Lost/corrupt manifests or withdrawn sources withhold affected editions; recover exact verified files/evidence or prepare a separately reviewed correction, never reconstruct a paid result or silently replace historical copy. Complete loss of all manifests is indistinguishable from never published, making the joint backup essential.

## Evidence and outstanding acceptance

Offline tests cover DST/finished-date boundaries, delayed-start refusal, pinned physical paths/marker mismatch, disabled/no-subprocess behavior, sanitized log receipts, fixed unit policy and example reservation math. Existing Gazette suites cover ledger/publication failure paths and historical compatibility; all offline suites and final Ubuntu CI are required. Unit syntax verification and actual identities/mounts/permissions still require separately approved Linux staging verification; Windows mocks cannot establish them. No UI behavior changed, so this PR requires no new screenshots.

Remaining blocked items: dedicated-account shared SQLite access and sandbox positive-path tests; actual credential mount/privileged owner verification; account/project/endpoint acceptance, usage and quality evaluation; production backup/drain/migration/setup; paid activation and final timer enablement. This PR prepares their review and deliberately does not claim they happened. No live API tokens or cost were incurred.

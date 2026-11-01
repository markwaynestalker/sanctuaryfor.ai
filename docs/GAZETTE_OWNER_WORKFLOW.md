# Gazette owner workflow

This supersedes the manual date overrides and hand-calculated revision permits in the earlier operations runbook. One reviewed installation gives the local owner a `gazette` command. It uses the existing credential-isolated editor service and keyless Unix broker; it never reads the key, gives the login account database/key permissions, publishes during generation, or changes the Director.

## Routine use

```bash
sudo gazette generate --date 2026-10-31 --approve-paid
sudo gazette review --attempt ATTEMPT_ID
sudo gazette publish --attempt ATTEMPT_ID
```

Generation runs a no-network credential-metadata and read-only broker check inside the real editor service, then starts **one** paid attempt. It returns the attempt ID, outcome, observed nullable token/cost estimates, retained reservation and current budget totals. Copy the ID, not hashes or permits. Review opens the complete private draft and source evidence in a private root-owned pager; type `approve` or `reject` after reading. Publication is a separate command and still requires completed moderation, exact-copy factual approval and current-source verification. Nothing publishes automatically. Use a private, unrecorded terminal for review; the draft never enters application/systemd logs.

After a **confirmed rejection**, run the generate command again with a fresh explicit `--approve-paid`. The broker calculates the new source/policy hashes, ordinal and bound permit under the same write lock as admission, and records the root authorization once. Owner-rejected finished drafts are also eligible. A finished unreviewed/approved draft is not silently replaced: review/reject it before regenerating. A publication correction requires separately reviewed generation and explicit `gazette publish --attempt ID --replace-current`; the existing manifest hash is obtained internally and compared atomically. Historical files are never overwritten.

```bash
sudo gazette status --date 2026-10-31
sudo gazette preview --date today --approve-paid
```

Preview is an optional **private owner newspaper**, clearly labeled “In-progress preview — partial coverage, not a completed daily edition.” Its source window ends at the captured as-of timestamp. It consumes normal budgets and moderation, can be reviewed, and cannot be published as a completed daily edition even after midnight. Generate the actual finalized date separately after the day ends. Preview and finalized-date eligibility are separate lanes, but all reservations share the same spending/request limits and concurrent-worker lease.

## Spending policy and unknown requests

**Proposed profile, pending explicit owner approval:** $1/day, $20/month and 20 HTTP requests/day. Installation/deployment does **not** apply it; the existing environment settings and ENABLED flags are preserved. To approve these proposed values explicitly:

```bash
sudo gazette configure --approve-limits
```

Or supply your own integer micro-USD/request values:

```bash
sudo gazette configure --approve-limits --daily-microusd 1000000 --monthly-microusd 20000000 --requests 20
```

The command updates the two nonsecret policies together while the editor is idle; a partial/interrupted update fails policy matching closed. It never changes credentials or timers. If generation is deliberately disabled, `gazette configure --approve-enable` explicitly enables both components without changing limits or starting a job/timer. None of these approvals is inferred from deployment.

Generation has a default **$0.05 per-attempt admission cap**, independently selectable with `--max-attempt-microusd`. The ordinary 96000/12000-token profile still reserves $0.03 and two HTTP slots per attempt. Scheduled jobs retain their explicit model/token policy (structurally bounded by the existing 200000/16000-token envelope); the owner command's per-job cap never raises those settings. Limits admit based on the maximum of each retained reservation and observed cost, not a cheap completed estimate. The earlier $0.006 observation and $0.03 reservation both remain intact; no refund is made. Status calls the figures observed estimates, not final provider invoices. Null usage stays unknown. UTC dispatch-day/month accounting remains distinct from Eastern newspaper dates.

20 slots allow up to ten two-slot attempts per UTC day, including failed attempts. Every dispatched generation/moderation has a unique immutable request row. The root request ID is consumed atomically with its attempt, so repeated service starts or socket/worker restarts cannot reuse it. **Missing/uncertain receipts, absent outcomes or potentially in-flight work cannot enter the routine revision path**, even with another approval. All previous holds remain. The single global editorial lease also prevents simultaneous different-date jobs. Expired leases retain uncertain evidence; they are not erased to obtain a retry. Reboots remove only ephemeral admission files, not the SQLite evidence.

## One deployment and activation procedure

After normal PR review/merge and your protected exact-SHA release deployment, run the following once. Substitute the new **deployed** release SHA; do not use an old source checkout. The Gazette timer must remain disabled and the editor drained. The installer refuses an active editor, an enabled/active Gazette timer, a root/shared editor database UID, wrong 0700/0600 protections or a non-current release. Existing installation/configuration/key provisioning must already be present.

```bash
SHA=REVIEWED_DEPLOYED_MAIN_SHA
CODE=/var/lib/sanctuaryfor-ai-deploy/releases/$SHA
sudo /usr/bin/python3 -I "$CODE/ops/gazette/gazette.py" install --release "$SHA" --approve-install

# Run this only if you approve the proposed $1/day, $20/month, 20-request profile.
sudo gazette configure --approve-limits
sudo gazette check
sudo gazette generate --date 2026-10-31 --approve-paid
sudo gazette review --attempt ATTEMPT_ID
sudo gazette publish --attempt ATTEMPT_ID
```

The installer takes a SQLite-consistent WAL-inclusive private backup as the existing DB account, runs the explicit transactional Gazette-only ledger upgrade, installs the root-reviewed wrapper/helpers/units, and pins both components to this release. It clears the old manually configured `approved_date` so only root-owned per-command requests select historical dates. It leaves existing budgets, activation flags, key source, DB permissions/ownership and Gazette timer state unchanged. It neither stops nor reconfigures the Director. It creates no account or credential. There is no automatic migration on ordinary deployment, and no installed helper self-update.

Ledger revision 2 rebuilds **only the ten Gazette tables** within one write transaction, copying original fields directly in SQLite. All old attempt IDs, requests, receipts, holds, drafts, moderation, reviews, source snapshots and publication bindings survive. Original attempts gain `is_preview=0`; the editorial metadata revision becomes 2. The old lifetime eight-attempt constraint is removed; an immutable jobs table records each root authorization and exact resulting attempt. Existing V6 tables/user_version remain unchanged. Unknown external schema dependencies are refused for review. The migration is idempotent, rolls back transactionally, and requires no deletion/reset/refund. Existing publication-manifest history and its eight-correction reader bound remain unchanged.

`sanctuary-gazette-db.socket` now requires an ordered oneshot runtime provisioning unit using the installed fixed tmpfiles policy. It recreates the root:editor 0710 socket parent and root-owned owner-request directory before listening, including after reboot. The scheduled editor already requires the socket. `gazette check/generate` can recover a missing/stopped socket only while the editor is idle; they never restart a running paid worker. The socket keeps peer UID validation, 0660 permissions, bounded connections/frames and keyless helper isolation. Its 64-activation/minute fuse accommodates the proposed HTTP allowance without removing the fuse.

After a successful draft/review/publication, the **optional separate owner action** below enables future scheduled drafts. Do not run it merely to test the command; it is not needed for the October 31 edition. Scheduled generation still never publishes.

```bash
sudo systemctl enable --now sanctuary-gazette-editor.timer
```

On any unknown result, use `gazette status` and keep the evidence; do not replay or clear SQL. A confirmed rejected attempt can receive a new approval through the same generate command. The command never automatically repeats an uncertain operation. If installation fails, retain its private backup and use a reviewed forward repair or code rollback compatible with revision 2; never restore an old shared database after new village writes to erase usage. No production installation, migration, policy approval or paid operation is performed during development.

## Verification

All 27 offline suites remain required. Tests cover populated-ledger upgrade/rollback, unchanged shared world/history, old approved publications, rejected revisions beyond eight, consumed authorizations, uncertain/in-flight fencing, daily/monthly/request/per-attempt limits, source verification, previews and mandatory moderation/manual publication. Owner-command tests cover explicit approvals, one service invocation, private review, no key reads, preserved installation limits and safe socket recovery. Ubuntu additionally exercises real distinct UIDs, systemd credential isolation, WAL concurrency, missing-directory boot provisioning, authenticated owner revisions and no duplicate dispatch on repeated requests/restarts. All provider traffic in tests is mocked.

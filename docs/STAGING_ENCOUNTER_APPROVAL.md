# One synthetic Director encounter: preparation and proposed approval

**No generation is approved or performed. PR #9 remains draft.** The owner approved one metadata retrieval only; it succeeded. This document records its accounting and prepares the next separately approved experiment. Production Director admission stays code-blocked; production SQLite and the inactive timer remain untouched.

## Retained metadata request ledger

Both dates/times below are UTC and owner-reported, not fabricated application observations. Each receipt has a started row plus outcome row representing **one** reservation, not two. Neither receipt is rewritten or imported as a fabricated generation attempt in SQLite.

| Receipt | Started at (UTC) | Check ID | Reserved HTTP requests | Result |
| --- | --- | --- | ---: | --- |
| `agent-access.jsonl` | 2026-10-25T15:45:02Z | `4a702a55ef427008e9ce1f080b476c5f` | 1 | not_confirmed; retain even though diagnosis suggests local rejection |
| `agent-access-after-validator.jsonl` | 2026-10-25T16:32:54Z | `73b85eae029855956e5e23d49b22dfec` | 1 | HTTP 200, authorized, configuration matches |

The successful request ID is `req_afc033eb68334d8f99028df73e40e411`. Both generation_requests=0 and database_opened=false. The owner confirmed original_audit_unchanged=PASS and exit 0.

Pinned SHA-256 values:

```text
original:   36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513
authorized: 94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0
```

The original digest was repeated in truncated 60-character form in the latest report; retain the full 64-character value previously supplied by the owner and used by the successful integrity gate. Do not replace it with the short copy or reset either file.

**October 25 UTC reconciliation: 2 reserved / 2 default slots; remaining 0.** Offline diagnostics consume no API slots. The fresh synthetic SQLite ledger will contain only the future generation attempt; these earlier access reservations remain authoritative in their private JSONL receipts and this operator record. Do not mistake an empty new DB for recovered headroom. Metadata receipts make no assertion about final provider charges.

The new read-only `reconcile-director-access.php` validates both file hashes, base fields, chronological distinct check IDs, agent identity and successful structural authorization. It reports two reservations by their real UTC dates without opening SQLite, loading a key, making a request or changing receipts. The paid staging CLI now requires both hash arguments, rechecks the real receipts at the fixed private path, and refuses execution on or before either receipt's UTC date **before opening its DB, consuming the permit or loading a credential**.

Plan generation for **October 26 UTC or later**, at least five minutes after UTC midnight and outside the final 90 seconds of a day. No new metadata request is included. On that later day, the proposed eight-call allocation requires an explicit, temporary per-process staging request ceiling of 8. The production/default ceiling stays 2; nothing changes it automatically. Before approval confirm that no other staging request/reservation consumes that later day's eight slots. Total known access-plus-experiment reservation envelope across dates is **2 + 8 = 10**, not eight. Unused reserved experiment slots are not an invitation to run another test.

## Service tier: verified contract, not an inferred saved value

The access result's `service_tier_default=false` says only that the saved agent was not reported as literal `default`; it does not prove `auto`, `priority`, a missing field, or a particular price. No extra metadata request is authorized to find out.

The [official configuration guide](https://developers.openai.com/api/docs/guides/agents-api/configuration) and [session-create contract](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/methods/create), checked during preparation, allow `agent_id` plus per-session overrides. Overrides affect the new session, not the saved Director. The existing request sets `agent.service_tier=default`, model gpt-6-luna, reasoning effort none, no tools/subagents, environment none and a one-cent spend_control limit. No instructions or personality sentences are authored by PHP.

The permit now also verifies default tier, reasoning effort and non-streaming mode **before dispatch**. Returned creation and inspection sessions must independently report default tier. Missing/auto/priority tiers fail acceptance, attempt at most one cancellation if a session ID is known, save no conversation and retain the reservation/uncertainty. Cancellation is not proof of stopped work or final billing. Creation includes the initial encounter input, so a provider ignoring/rejecting an override can already have incurred work before the returned configuration is checked. No post-response guard can undo that exposure. Do not edit the shared saved agent or retry generation to repair a mismatch.

The [current pricing page](https://developers.openai.com/api/docs/pricing) lists standard gpt-6-luna base input/output at $0.10/$0.50 per million tokens, with different cache-write rates. Existing estimates are observations, not a final managed bill; internal inference, cache details and ancillary charges require independent reconciliation. No price, tier override, output validation, timer kill or spend alert proves a whole-turn maximum.

## Two proposed synthetic residents

Review/adopt or edit [the two-character input](staging/synthetic-residents.example.json) **before seeding**:

- **Stage Mayor**, gold, orb antenna: enjoys welcoming fictional neighbors and thoughtful questions.
- **Stage Gardener**, sage, sprout antenna: enjoys lavender, peaceful gardens and gentle wordplay.

Descriptions are proposed test data for owner review, not a claim that the owner already authored them. They contain no resident speech. The seed generates two new immutable IDs; neither is Sparky or another production resident. No production identity, token, memory, home or conversation is copied. The Director supplies all actual dialogue. The local offline seed test consumes this exact example file and refuses reseeding a nonempty DB.

## No-cost owner preparation

All PHP commands below explicitly enable register_argc_argv for that process so getopt can read their options even if the root/service CLI configuration disables argv registration. This does not change server configuration. Missing/unavailable CLI arguments now produce a CLI-specific error, never a misleading claim that the receipt hash itself is malformed.

If preparation already created the experiment directory and then reconciliation failed, **do not rerun the create-directory block or replace any file**. Follow [the focused reconciliation/continuation procedure](STAGING_RECONCILIATION_RECOVERY.md). It uses the existing setup, retains the failed output and stops if database/registration/permit work has already started.

Use a new root-owned read-only checkout of the **reviewed updated PR #9 commit**, not the older cfe1288 checkout. The new code fingerprint changes the permit binding; do not reuse an old permit. Fill SHA below with that reviewed full SHA. These commands prepare a **new isolated staging DB only**, make no API request, load no credential and cannot generate dialogue. No production migration or scheduler change is included. Codex has not executed these on Toronto.

Run in the owner's existing privileged shell. Keep a failed preparation directory/evidence for diagnosis; do not delete/reset it to repeat a paid test.

```sh
set -eu
umask 077
SHA=REVIEWED_UPDATED_PR9_FULL_SHA
test "${#SHA}" -eq 40
case "$SHA" in *[!0-9a-f]*) exit 1 ;; esac
CODE=/opt/sanctuaryfor-ai-staging/$SHA
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
AUDIT_DIR=/var/lib/sanctuaryfor-ai-staging/preflight
test "$(git -C "$CODE" rev-parse HEAD)" = "$SHA"
test ! -e "$EXPERIMENT"
test ! -L "$EXPERIMENT"
# Only after reviewing/adopting the two descriptions in the example file:
install -d -m 0700 -o sanctuarystaging -g sanctuarystaging "$EXPERIMENT"
install -m 0600 -o sanctuarystaging -g sanctuarystaging \
  "$CODE/docs/staging/synthetic-residents.example.json" "$EXPERIMENT/residents.json"
env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= \
  LITTLE_SPARKS_AI_ENABLED=0 LITTLE_SPARKS_DIRECTOR_ENABLED=0 LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/reconcile-director-access.php" \
  --directory="$AUDIT_DIR" \
  --original-sha256=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513 \
  --authorized-sha256=94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0 \
  > "$EXPERIMENT/access-reconciliation.json"
systemd-run --unit=sanctuary-staging-prepare-synthetic \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=PrivateNetwork=yes --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict --property=RuntimeMaxSec=60s \
  --property="ReadWritePaths=$EXPERIMENT" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai" \
  --setenv="CODE=$CODE" --setenv="EXPERIMENT=$EXPERIMENT" \
  --setenv=OPENAI_API_KEY= --setenv=CREDENTIALS_DIRECTORY= \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging --setenv=LITTLE_SPARKS_AI_MODEL=gpt-6-luna \
  --setenv=LITTLE_SPARKS_DIRECTOR_RUNTIME=agents \
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /bin/sh -eu -c '
    umask 077
    for step in migrate-village migrate-ai migrate-activity migrate-memory migrate-identity migrate-director; do
      /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/$step.php" --database="$EXPERIMENT/village.sqlite"
    done
    /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/seed-director-staging.php" \
      --database="$EXPERIMENT/village.sqlite" --residents-file="$EXPERIMENT/residents.json"
    /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/register-director.php" \
      --database="$EXPERIMENT/village.sqlite" \
      --agent-id=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8 \
      > "$EXPERIMENT/registration.json"
    cat "$EXPERIMENT/registration.json"
    /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/stage-director-once.php" --check
  '
```

The migration CLIs operate only on the fresh staging file. No receipt is edited, no new request is reserved, and no V6 production schema is changed. Registration stores only reviewed structural session constraints; it does not fetch or replace the saved Director's instructions. The root-owned reconciliation output stays private; it is an operator summary, not another API receipt or replacement for either original file.

Before a live permit: run all 14 offline suites on isolated Linux/PHP 8.3.6 with API keys cleared and external networking blocked. Use disposable fixtures; do not point tests at this prepared experiment or production. Local Windows mock passes and the Linux credential probe do not establish a Linux application-suite pass.

Near the selected later UTC test window, use the same no-network staging identity and all paid flags off, set the exact registered agent ID/configuration SHA, and run `stage-director-once.php --plan --database=... --at=CHOSEN_UTC_MINUTE`. Save a **private, unapproved** permit with mode 0600 in this experiment directory. It expires within one hour; choose an elapsed minute by execution time. Share the safe permit fields (two synthetic IDs, opportunity, expiry, code/resident/configuration hashes and limits), reconciliation summary and Linux test results for final approval. Do not share installation secrets, private links, key contents or raw Director instructions. An expired unconsumed plan can be regenerated offline for review; a consumed permit cannot be rearmed.

## Specific paid approval scope — proposed, not yet granted

After the above evidence and actual permit are reviewed, approve all of the following together:

1. Exactly one canonical encounter for the two approved synthetic IDs using the existing saved Director, no production participant or data, gpt-6-luna, requested default service tier, no sandbox/tools/subagents/vaults.
2. One new session creation carrying the encounter input; **one generation submission total**, never a retry/replacement session. Up to eight HTTP attempts in the existing lifecycle, including session inspection, bounded turn observations, one cancellation if needed, saved-item retrieval and moderation. No arbitrary tool/world action; only moderated valid conversation is saved, or silence/failure.
3. On a later UTC day with no other staging requests, explicitly allow **8 requests for this process only**, and **100,000 micro-USD ($0.10) application allocation**. Keep the production/default two-request setting and $1 maximum unchanged. The two October 25 metadata reservations remain recorded, not refunded or relabeled. No extra metadata check is included.
4. Explicitly accept a **one-time research exception with no verified numerical maximum final provider charge**. The one-cent provider setting and $0.10 reservation are not guaranteed caps; a timeout, cancellation, wrong-tier result or moderation failure may still accrue charges exceeding the nominal allocation. Paid execution is not warranted if that exposure is unacceptable.
5. Bind the reviewed runtime SHA/fingerprint, exact staging path/installation, identities, external agent/configuration, opportunity/expiry and approved permit. Run only through the existing LoadCredential channel as sanctuarystaging. Read-only-bind the audit directory into the one-shot service; only this synthetic experiment directory is writable. All scheduler flags remain 0; no unattended process/timer is installed or activated.
6. Retain the consumed permit, all eight reserved slots and full experimental allocation after success or failure. Inspect terminal state and obtain independent final provider billing evidence before manual reconciliation. Unknown/revised/overrun billing stays held; do not infer success or zero cost from cancellation. Any recovery API request beyond the eight-call envelope needs new approval, never a generation retry.

The paid command must additionally supply the pinned `--original-access-sha256` and `--authorized-access-sha256` arguments. Omitting them or attempting on October 25 fails before key loading. Do not use the old command from the earlier draft without these controls. The finalized armed owner command will be supplied **after** review of the actual permit and explicit paid approval; this document deliberately does not enable paid gates.

This approval would authorize only the synthetic staging DB changes needed for the single encounter/accounting. It would not authorize production migration, real-resident speech, saved-agent edits, public release, merge, deployment or scheduler activation. PR #9 remains draft until staging completion/cancellation and independent billing reconciliation are reviewed.

## Local validation of this preparation

All 14 offline suites passed with cleared keys, mocked endpoints and disposable data on local PHP 8.2.12. Syntax checks passed for 84 PHP files and 19 JavaScript files. New cases cover a saved automatic tier remaining structurally authorized, explicit session-local default override, tampered/missing tier or effort/streaming rejected before dispatch, non-default returned session rejection with one cancellation, unchanged request/cost holds, two hash-pinned receipts counted once each, altered/duplicate/extra/generative receipt rejection, same-day refusal, and successful later-day admission. The seed test uses the exact two-character example file. The operator shell block is syntax-checked only, not executed by Codex. Actual Linux PHP 8.3.6 application-suite results, prepared Toronto synthetic IDs/configuration/permit and live generation/billing evidence remain outstanding.

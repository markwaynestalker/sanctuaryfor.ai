# Proposed next step: one real-key agent retrieval

**Completed historical plan:** the owner explicitly approved and ran its one metadata GET. Authorization passed with zero generation/database opens and unchanged original audit. Do not rerun this command or reuse that approval. Both October 25 UTC reservations and the separate, not-yet-approved generation plan are recorded in [staging encounter review](STAGING_ENCOUNTER_APPROVAL.md). The approval-needed language below describes the original review, not a new outstanding metadata check.

**Prepared for owner review; not authorized or executed.** PR #9 stays draft. The owner will run the privileged command, only after explicitly approving this exact metadata request. This is not approval for a paid encounter, a scheduler, a production migration or deployment.

## Evidence now established

Toronto's owner-run non-secret stand-in passed the repaired validator in `run-u207.service`: UID/GID 999/988, root:root `0440`, 10 bytes, readable, valid=true, failure_code=null. PHP read zero credential contents; network requests/database opens were zero; the original audit stayed unchanged. Actual systemd delivery is now established. The real key's formatting and project access are still unknown.

The original attempt remains `not_confirmed`, with null HTTP status/request ID. Preserve `/var/lib/sanctuaryfor-ai-staging/preflight/agent-access.jsonl` and its **one reserved request**. The owner supplied the complete SHA-256 for that unchanged receipt:

```text
36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513
```

The earlier copied value was truncated to 60 characters; the owner subsequently supplied the full 64-character digest above. The pin is now resolved without changing/resetting the receipt. Execution still requires explicit owner approval and confirmation of request headroom.

The reviewed runtime is commit `cfe12884b2a6beb459b036590c3db3e67d4d4886`. This preparation changes documentation only. No further credential-validator change is needed to run this plan.

## Exact authorization requested

Approve **one owner-run authenticated GET**, using the dedicated staging key delivered by systemd, to:

```text
https://api.openai.com/v1/agents/agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8
```

The [official retrieval contract](https://developers.openai.com/api/reference/resources/beta/subresources/agents/methods/retrieve), rechecked during preparation, retrieves a reusable agent scoped to the caller's project. This request creates no session/response, submits no resident context and generates no model text. It proves retrieval permission only; it does not prove future session/moderation permissions or a managed billing ceiling. Do not claim a price guarantee from this access check.

The existing transport allows only the fixed GET, strict TLS, no redirects or retries, a 5-second connection/15-second total timeout and 256 KiB response bound. It reads the delivered key only after validation and keeps it in process memory. It discards instructions, metadata and error bodies, exposing only safe status/request ID/configuration booleans/failure codes.

Use the fixed new receipt `agent-access-after-validator.jsonl`; never overwrite the original or invent another filename after failure. This reviewed plan links the two receipts through the original path/hash and fixed successor path. It does not mutate the original receipt to add a link. The successor is exclusively created and fsyncs its **one new request reservation before** loading the key/network. Existing successor receipts refuse another attempt, including after partial failure. A fixed transient unit name additionally refuses overlapping launches while the unit exists; the receipt remains the durable duplicate guard after collection.

Accounting: retain the original reservation and add one new reservation, **two access-check reservations total**. Conservatively budget both in this review even if their UTC dates differ. Do not refund the original because the diagnosis suggests a local failure. Under the default two-request daily allowance, if both attempts fall on the same UTC day, no other request fits that day. This shell procedure is a manually reviewed access envelope, not an SQLite ledger update or an automatic account-wide quota check. Before approval the owner must confirm no other staging requests consume that day's remaining allowance; if that is unknown, stop. Keep both receipts for later experiment reconciliation, counting intent/check IDs once, not the two JSONL rows as two requests. The separate eight-slot encounter is not authorized and does not fit a two-request daily cap; never silently increase limits or count eight plus metadata as eight.

## Proposed owner command — blocked until approval

Run only in the owner's existing root shell. The checkout must already exist, be root-owned/read-only to staging, and match the pinned runtime. No production checkout, FPM credentials or SQLite file is opened. No timer is installed or changed. The source-key file is not edited; systemd reads it to deliver a private per-unit copy. Networking is necessary for this single GET, so unlike the stand-in this unit does **not** use PrivateNetwork=yes.

The approval gate below intentionally fails by default. Change it only after the owner explicitly approves this plan and confirms the request headroom described above. Do not run the launch now merely because the stand-in passed.

```sh
set -eu
umask 077
SHA=cfe12884b2a6beb459b036590c3db3e67d4d4886
CODE=/opt/sanctuaryfor-ai-staging/$SHA
AUDIT_DIR=/var/lib/sanctuaryfor-ai-staging/preflight
ORIGINAL=$AUDIT_DIR/agent-access.jsonl
NEXT=$AUDIT_DIR/agent-access-after-validator.jsonl
EXPECTED=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513
APPROVAL=NOT_APPROVED
test "$APPROVAL" = APPROVED_ONE_METADATA_GET_WITH_REQUEST_HEADROOM
test "${#EXPECTED}" -eq 64
case "$EXPECTED" in *[!0-9a-f]*) exit 1 ;; esac
test "$(git -C "$CODE" rev-parse HEAD)" = "$SHA"
test -f "$ORIGINAL"
test ! -L "$ORIGINAL"
test ! -e "$NEXT"
test ! -L "$NEXT"
BEFORE=$(sha256sum "$ORIGINAL" | cut -d ' ' -f 1)
test "$BEFORE" = "$EXPECTED"
/usr/bin/php8.3 "$CODE/scripts/check-director-access.php" --check
RESULT=0
systemd-run --unit=sanctuary-staging-agent-access-reviewed \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict \
  --property=RuntimeMaxSec=30s \
  --property="ReadWritePaths=$AUDIT_DIR" \
  --property="BindReadOnlyPaths=$ORIGINAL" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai" \
  --property=LoadCredential=openai_api_key:/etc/sanctuaryfor-ai/staging-openai-key \
  --setenv=OPENAI_API_KEY= \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging \
  --setenv=LITTLE_SPARKS_AI_DAILY_REQUESTS=2 \
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/php8.3 "$CODE/scripts/check-director-access.php" --audit="$NEXT" || RESULT=$?
AFTER=$(sha256sum "$ORIGINAL" | cut -d ' ' -f 1)
test "$BEFORE" = "$AFTER"
printf 'original_audit_unchanged=PASS\naccess_check_exit=%s\n' "$RESULT"
test "$RESULT" -eq 0
```

`LITTLE_SPARKS_AI_DAILY_REQUESTS=2` explicitly preserves the existing default; it is not an assertion that the metadata verifier updates SQLite or automatically reconciles other receipts. No generation gate is enabled. `InaccessiblePaths` hides the raw key-source directory inside the service; systemd's manager supplies LoadCredential before the service uses its sandbox. The original audit is mounted read-only within the unit and hashed before/after from the owner's shell. Only the successor receipt is intended to be created/appended in the existing private audit directory.

## Results and stop conditions

Success requires HTTP 200, `authorized=true` and `configuration_matches=true` (exact agent/model, empty tools, disabled subagents). `service_tier_default` is informative; a later encounter independently requires its reviewed configuration. Success does not start anything else.

For 401/403/404, redirects, timeout, null status/request ID, invalid key formatting or configuration mismatch: retain both receipts; stop and inspect safe failure codes. Do not rerun, change keys, change agent configuration, reset receipts or start a session. A timeout/process failure may leave only a durable started row; keep that reservation. If no successor exists because launch failed before the verifier ran, inspect the unit failure before any renewed request approval. Never infer a refund from missing provider evidence.

Share only the verifier's safe JSON, process exit status and `original_audit_unchanged`. Do not share key contents, raw agent response/instructions, systemd environment dumps or debug cURL output. Codex will not execute the command or root SSH. Paid synthetic generation, final billing reconciliation, production migration/activation and timer changes require separate decisions.

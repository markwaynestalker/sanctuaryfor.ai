# Configured Director execution

This supersedes the earlier October 26-only handoff. There is **no calendar wait**. The existing AI/DIRECTOR/SIMULATION flags enable routine calls; validated `LITTLE_SPARKS_AI_DAILY_REQUESTS` (default 2, range 0–200) and `LITTLE_SPARKS_AI_MONTHLY_MICROUSD` (maximum 1,000,000) govern application reservations. No code requires a particular daily/monthly setting. Defaults stay disabled; merging does not activate production or a scheduler.

The managed lifecycle reserves up to **eight HTTP slots per encounter**, including moderation/recovery. This is a bounded wire-operation envelope, not a daily ceiling. Today, two metadata reservations plus one such encounter need at least ten configured daily slots. Limits of 10, 20 or another valid value work according to remaining capacity; no value is substituted by PHP. The one-cent provider setting and application commitments are not a guaranteed final provider-charge cap.

Both original metadata receipts remain immutable. Their hash-pinned reservations are imported idempotently into two new namespaced entries in the existing SQLite settings table. No schema migration, reset, refund, original-row replacement or installation-secret change is needed. The shared BEGIN IMMEDIATE budget check counts these entries with all prior AI/Director reservations. Changed limits do not erase existing commitments. Status includes the imported metadata slots.

## First real synthetic conversation: configured routine path

All 14 Linux suites already have passing evidence (13 original plus successful memory recheck at `/var/lib/sanctuaryfor-ai-staging/memory-recheck.6cjuYf`). The focused new config/accounting tests use mocks only. Keep the existing stage, residents, registration, history and receipts. No new infrastructure is needed.

First update **only the root-owned staging code checkout** to the reviewed PR #9 commit containing this fix. The directory may retain its old SHA label. Do not update `/var/www/sanctuaryfor.ai`. Check for local changes and refuse to overwrite them:

```sh
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
REVIEWED_SHA=PASTE_REVIEWED_CONFIGURATION_FIX_FULL_SHA
test "${#REVIEWED_SHA}" -eq 40
test -z "$(git -C "$CODE" status --porcelain)"
git -C "$CODE" fetch origin feat/little-sparks-v3a
git -C "$CODE" checkout --detach "$REVIEWED_SHA"
test "$(git -C "$CODE" rev-parse HEAD)" = "$REVIEWED_SHA"
```

The following is **one configured CLI opportunity**, with no timer, loop or manual-permit requirement. It is a paid command: execute only when intentionally enabling these flags. It forwards the operator's existing daily/monthly variables without replacing them. For example, the operator can explicitly export LITTLE_SPARKS_AI_DAILY_REQUESTS=10 and LITTLE_SPARKS_AI_MONTHLY_MICROUSD=100000 for this test, or choose another valid allowance. It imports today's two reservations before claiming/reserving the new encounter, so a daily setting below 10 fails without dispatch.

Do not use this routine route to bypass an issued experimental permit. It refuses a consumed experimental permit in this DB or the existing approved-director-once.json. If one exists, use the optional one-shot command below. Current owner reports say no permit/generation has occurred.

```sh
(
set -eu
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
# Required operator configuration is forwarded unchanged to the service.
: "${LITTLE_SPARKS_AI_DAILY_REQUESTS:?Set the desired daily request limit}"
: "${LITTLE_SPARKS_AI_MONTHLY_MICROUSD:?Set the desired monthly application allocation}"
test ! -e "$EXPERIMENT/approved-director-once.json"
test ! -L "$EXPERIMENT/approved-director-once.json"
systemd-run --unit=sanctuary-staging-director-once \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict --property=RuntimeMaxSec=75s \
  --property="ReadWritePaths=$EXPERIMENT" \
  --property=BindReadOnlyPaths=/var/lib/sanctuaryfor-ai-staging/preflight \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai" \
  --property=LoadCredential=openai_api_key:/etc/sanctuaryfor-ai/staging-openai-key \
  --setenv=OPENAI_API_KEY= --setenv=LITTLE_SPARKS_ENVIRONMENT=staging \
  --setenv=LITTLE_SPARKS_DIRECTOR_RUNTIME=agents \
  --setenv=LITTLE_SPARKS_DIRECTOR_AGENT_ID=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8 \
  --setenv=LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256=295ceb3de136d726e8359d16d5a70bb8f887b72077238ada0c02cc67f7b4b245 \
  --setenv=LITTLE_SPARKS_AI_MODEL=gpt-6-luna --setenv=LITTLE_SPARKS_AI_PRICING_CONFIRMED=2026-10-24-standard-v1 \
  --setenv="LITTLE_SPARKS_AI_DAILY_REQUESTS=$LITTLE_SPARKS_AI_DAILY_REQUESTS" \
  --setenv="LITTLE_SPARKS_AI_MONTHLY_MICROUSD=$LITTLE_SPARKS_AI_MONTHLY_MICROUSD" \
  --setenv=LITTLE_SPARKS_AI_ENABLED=1 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=1 --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=1 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/run-village.php" \
    --database="$EXPERIMENT/village.sqlite" --due --provider=openai --allow-paid --conversation-only \
    --original-access-sha256=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513 \
    --authorized-access-sha256=94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0
)
```

The flags apply only to that transient process. No production activation or scheduler installation/start occurs. Only accepted/moderated original dialogue is saved. Conversation-only restricts the requested structured outcome and available world action; PHP authors no resident sentence. No generation is retried automatically. The ordinary configured runner can process later distinct opportunities without permits; omit conversation-only for organic quiet/approved-action outcomes. Optional scheduler installation remains a separate explicit operator decision.

## Existing experimental one-shot mode

It remains separate and optional. Its existing bound $0.10/eight-slot permit is still checked against code, DB installation, identities, configuration, opportunity and expiry, then consumed once. Those permit reservation amounts must **fit** configured remaining limits; limits need not equal them. No old calendar guard remains. A consumed permit cannot be reused after changing configuration or reconciling billing. Routine routing refuses an experimental DB with a consumed permit; it cannot silently turn one approval into repeated tests.

Any unconsumed permit bound to the older runtime must fail the unchanged fingerprint validation after a code update. Leave it untouched and generate a new unapproved candidate using the existing --plan command for the updated code; do not patch its hashes or rearm a consumed permit. Apply only the existing three approval fields after the owner's approval. No new approval mechanism is introduced. With the same unit settings above, replace only the executable/arguments with:

```sh
/usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/stage-director-once.php" \
  --allow-paid --database="$EXPERIMENT/village.sqlite" --at=ACTUAL_PERMIT_OPPORTUNITY \
  --permit="$EXPERIMENT/ACTUAL_APPROVED_PERMIT.json" \
  --original-access-sha256=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513 \
  --authorized-access-sha256=94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0
```

## Verify without an API request

Use the existing private status CLI with keys/activation cleared. It reports HTTP attempts, imported reservations, application commitments and canonical run outcomes. Public profiles/journal remain saved-data reads; do not point production at the synthetic DB.

```sh
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= LITTLE_SPARKS_AI_ENABLED=0 LITTLE_SPARKS_DIRECTOR_ENABLED=0 LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  LITTLE_SPARKS_SCHEDULER_ENABLED=0 LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/ai-status.php" \
    --database=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01/village.sqlite
```

Known terminal, valid, moderated routine encounters retain the full nominal reservation as the application commitment and allow further work within remaining configured capacity. This is not a claim of final provider billing. Missing/invalid/excessive usage, moderation/transport/apply failure, pending sessions and uncertain billing keep their holds and prevent new paid work; no automatic retry or refund is added. Existing experimental holds still require independent final billing reconciliation. Late billed overruns remain quarantined by the existing review path. Preserve every receipt, reservation, resident and history row.

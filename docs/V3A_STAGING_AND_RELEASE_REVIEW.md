# V3A supervised staging experiment — implementation and approval boundary

PR #10/#11 are merged/deployed, as confirmed by the owner: Toronto runs PHP 8.3.6, SQLite V5 plus identity revision 1, six preserved residents, working owner-written personalities/colors and an inactive village timer. PR #9 is reconciled onto main. Production was not accessed while preparing this work.

The manual staging lifecycle is implemented and tested with mocks. **No paid execution has been approved or performed.** Production managed admission remains blocked. This isolated experiment is a proposed one-time research exception, not unattended activation or proof of a strict final-charge cap. Keep PR #9 draft until the experiment/release decision is reviewed.

## Financial decision for approval

Use existing saved agent `agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8`, model `gpt-6-luna`, no sandbox, tools, subagents, public prompts or visitor chat. Reserve **100,000 micro-USD ($0.10)** in a fresh experimental ledger and **eight HTTP slots** before dispatch. Request a one-cent provider session limit. Permit exactly one generation submission and one canonical synthetic encounter, with no garden/world action.

**Neither $0.10 nor one cent is a guaranteed maximum final provider charge.** Charges could exceed the experimental allocation; no independently verified absolute maximum or proof of staying within the normal $1 production ceiling is claimed. Approval must explicitly authorize a separate one-time research exception. It does not increase production headroom or change its $1 application ceiling. Retain the experimental expense/uncertainty in protected finance records: deleting a staging DB cannot erase a charge.

Official contracts rechecked 2026-10-25:

- [Session creation](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/methods/create) supports the saved agent, environment type none and cent-based spend_control. A finite maximum total managed inference/output or spending overshoot was not established.
- [Turn retrieval](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/subresources/turns/methods/retrieve) distinguishes queued/in-progress/waiting/completed/failed/cancelled turns. Session idle alone is not success.
- [Cancellation](https://developers.openai.com/api/docs/guides/agents-api/sessions) sends agent.session.input.cancel through the session events endpoint. Acceptance is a request, not proof that work stopped or billing is final.
- [Usage](https://developers.openai.com/api/docs/guides/agents-api/observability) is best effort, may be missing/revised and is not a final bill. Internal calls, caching/reasoning and applicable ancillary charges matter. Post-response rejection cannot prevent accrued charges.

No project alert, key revocation, timeout or cheap example proves a maximum. Existing key/project reuse is not billing isolation. Do not change shared project settings, create a second Director or activate a scheduler.

## One-shot controls

`stage-director-once.php --check` reads no database/key, makes no HTTP call, and reports the runtime-code fingerprint and blocked production admission. `--plan` reads an explicitly named fresh synthetic V6 DB and emits an **unapproved** permit without loading credentials.

The permit binds the exact runtime/contract fingerprint, private DB path/installation digest, two immutable resident IDs and identity snapshot, saved agent/configuration fingerprint, model, one opportunity, expiry, eight slots and allocation. It requires an approval reference and explicit isolation/risk attestations.

Only after human approval may its private file set approved=true, a nonempty approval_reference and production_isolation_attested=true. The paid CLI also requires explicit staging flags, exact staging limits, and a separate non-root Linux identity unable to read the known production DB/private directory. The operator must verify complete filesystem/credential isolation; path checks cannot prove no other production copy exists. This PR creates no account, container, credentials, service or project settings.

Consume the singleton immutable permit record under BEGIN IMMEDIATE before loading credentials/dispatch. Tampering, expiry, changed code/residents/configuration, another installation/path, prior history or consumption refuses. Consumption survives failure/restart; no automatic refund/rearm occurs. A fresh DB cannot be used to repeat the approved experiment.

## Completion/cancellation envelope

All HTTP attempts are marked durably before dispatch and count even on failure. No redirect, automatic retry, replacement session or second generation is included.

| Successful path | Maximum calls |
| --- | ---: |
| Create one saved-agent session | 1 |
| Inspect session configuration/state | 1 |
| Observe its one turn | 3 |
| Retrieve one bounded unpaginated final message set | 1 |
| Moderate full context/proposal | 1 |
| Final usage/outcome observation when time permits | 1 |
| Reserved total | 8 |

The overall local deadline is 60 seconds; ordinary observations stop at 35 seconds, retaining cancellation/exit margin. HTTP timeouts respect remaining time. At most two-second pauses separate the three observations. For unfinished/unsafe work, use remaining slots for **one cancellation request** and, if possible, one terminal-state read instead of items/moderation. Only exact cancel input is allowed; no follow-up message may generate another encounter.

Accept only matching agent/session/turn/participants, completed status, valid exact output, usable usage observation and successful moderation. Save the canonical exchange unchanged once, only in synthetic SQLite. No dialogue can establish a confirmed fact or provenance receipt. Refused/invalid output stays silent. Measurable excessive usage is retained as unknown evidence, not settled.

Lost creation ID records session_lost; targeted cancellation is unavailable. Failed/unconfirmed cancellation and still-running work remain unresolved. A cancel acknowledgement is not cancelled status. Recovery beyond the eight-call envelope needs a new decision; never retry generation.

## Private rehearsal and eventual command

Owner-selected resources are **proposed, not created**: Linux account `sanctuarystaging`, private root `/var/lib/sanctuaryfor-ai-staging`, dedicated staging-only key source `/etc/sanctuaryfor-ai/staging-openai-key`, delivered by systemd `LoadCredential` as `openai_api_key`. The account must not join `www-data` or any production-DB access group. See V3A_STAGING_PROVISIONING.md for the reviewable no-cost setup/preflight; no provisioning has been performed. The dedicated key must belong to the existing Director's project and have only required endpoint permissions. Do not reuse the production FPM key, create a second Director or assume a new project can access this agent.

Before any paid execution, approve/rehearse that isolation and the exact checkout/fingerprint. The experiment uses a **new empty synthetic DB**, never a production snapshot. Provisioning approval and eventual paid-permit approval are separate.

In the separate staging checkout, initialize/migrate a private DB using the explicit CLIs in order: migrate-village, migrate-ai, migrate-activity, migrate-memory, migrate-identity, migrate-director. All paid/simulation gates stay off.

Set LITTLE_SPARKS_ENVIRONMENT=staging and run `seed-director-staging.php --database=... --residents-file=...` with a private owner-reviewed JSON array of two objects containing only name, color, antenna, description. It prints IDs only, not tokens, and refuses a nonempty V6 DB. A synthetic mayor may use the supplied owner description but never production Sparky's ID/memories.

Register the existing saved Director offline using `register-director.php --database=... --agent-id=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8` or the reviewed export. Set the returned configuration fingerprint and model/runtime only for staging. Verify the saved Director's current no-tool/no-sandbox configuration in the owner's Platform UI; the runner also checks it after creation.

Generate the pending permit in a 0700 directory/0600 file:

```sh
php8.3 scripts/stage-director-once.php --check
php8.3 scripts/stage-director-once.php --plan \
  --database=/PRIVATE-STAGING/village.sqlite --at=REVIEWED-UTC-MINUTE \
  > /PRIVATE-STAGING/pending-permit.json
```

The plan expires within one hour. Review it and obtain approval for that code/plan/isolation. Then, and only then, fill approval fields and expose the existing API key through the approved protected CLI mechanism. The single eventual command is:

```sh
php8.3 scripts/stage-director-once.php --allow-paid \
  --database=/PRIVATE-STAGING/village.sqlite --at=APPROVED-UTC-MINUTE \
  --permit=/PRIVATE-STAGING/approved-permit.json
```

Required staging-only variables (each prefixed LITTLE_SPARKS_): ENVIRONMENT=staging, DIRECTOR_RUNTIME=agents, DIRECTOR_ENABLED=1, AI_ENABLED=1, SIMULATION_ENABLED=1, AI_DAILY_REQUESTS=8, AI_MONTHLY_MICROUSD=100000, AI_MODEL=gpt-6-luna, the exact registered agent/configuration fingerprint and current pricing confirmation. SCHEDULER_ENABLED and SCHEDULER_ALLOW_PAID must both be 0. This does not change production defaults or raise production budgets. No flag alone authorizes egress.

PHP-FPM configuration is not inherited by CLI. Use the existing CREDENTIALS_DIRECTORY/openai_api_key loader with the new dedicated staging-only key, delivered by systemd LoadCredential. Do not read/print production FPM configuration or put key values in history, arguments, Git, logs, SQLite or context. Provisioning is an explicitly approved operator step, not performed automatically here.

After one attempt, turn staging paid flags off and retire the permit. Keep the DB/audits/evidence private. ai-status.php reports the consumed permit, HTTP calls, observations and outcomes. Confirm terminal state and obtain independently verified final provider billing evidence. review-director-billing.php requires its evidence-file hash, review ID and amount. It refuses nonterminal work; never refunds the full reservation into headroom; overruns/cross-month uncertainty remain blocked. Never settle from provisional usage or the model's assurance. Do not copy synthetic history/ledgers into production.

## Exact approval needed

Approve **one** synthetic-resident encounter with this saved Director/key, the reviewed code/permit and isolated staging identity, eight HTTP slots, a one-cent provider setting and nominal $0.10 **one-time research allocation**, explicitly accepting that final provider charges have no verified numerical maximum and could exceed that allocation. This approves no repeat test, production migration/activation, timer, merge or deployment. Execution approval has not yet been received.

## Validation and unfinished work

All thirteen offline suites passed after reconciliation; PHP syntax passed for 79 files and JavaScript syntax for 18 files on local PHP 8.2.12. The Director suite additionally covers six-resident installed-identity adoption/rollback, exact mayor text/original private links/audits, immutable billing evidence, one-shot concurrency, seed refusal, tampered/changed permits, delayed completion, lost IDs, deadline cancellation, failed/unconfirmed cancellation, moderation/invalid output, retained excessive usage and latest-terminal-outcome requirements. These are mocked tests, not live compatibility or final-billing evidence.

Unfinished before paid execution: explicit no-cost resource-provisioning approval, isolated account/directory/read-only checkout and dedicated project-key delivery, PHP 8.3.6/Linux hardening and no-secret preflight, fresh synthetic setup and actual unapproved permit review, then a separate paid approval. Afterwards inspect the real terminal outcome and independently reconcile final billing. Production migration, activation, timers and merge remain separate/unapproved; managed production cost-bound guarantees remain unresolved. No real Sparky encounter or production changes should be inferred from a synthetic test.

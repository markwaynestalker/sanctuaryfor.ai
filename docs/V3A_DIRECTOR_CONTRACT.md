# V3A: resident identities and the external Village Director

> Current operations: see [the ENV limits audit](OPERATIONAL_LIMITS.md). Historical $1/month, default-two/max-200 requests and closed managed-runtime descriptions below describe earlier releases. Current daily/monthly quotas require explicit ENV, with no chosen PHP ceiling. Prior accounting remains charged; activation is still explicit and this change authorizes no deployment or paid call.

SQLite gives each robot its identity and lifetime memories. The Village Director gives those memories a voice. Sessions are optional continuity helpers, not permanent brains.

PR #10/#11 are merged/deployed. This reconciled PR isolates V3A from production V2E/SQLite V5 plus identity revision 1, adopts installed identities/audits unchanged, and prepares supervised synthetic staging. It does not install/enable a scheduler, migrate production, deploy, merge or authorize paid execution. V3B/V3C remain separate work.

## Existing Director and runtime status

Use the owner's existing **SanctuaryFor.AI Village Director**, `agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8`, model `gpt-6-luna`. No second agent is created. PHP supplies no persona, resident sentence templates, memory openings, or scripted fallback on V6.

The primary configured runtime is `agents`. The dedicated adapter implements the managed request protocol against injected offline transports. **Live managed admission is blocked in code** with `managed_accounting_not_verified`. This is an integration foundation, not a claim of production-ready managed billing. The owner has verified agent/session continuity independently; this application's account access and billed cost have not been tested.

The safety follow-up implements a fixed HTTPS transport, durable HTTP audit, append-only usage observations, conservative manual evidence reviews and private backup/inspection tools. Egress remains code-gated: research did not establish a finite total-inference/spend-overshoot bound. See V3A_MANAGED_SAFETY.md for the explicit **not ready to merge** decision and conditional one-shot staging plan; TORONTO_V3A_RUNBOOK.md uses the owner's confirmed paths and keeps the timer disabled. No paid experiment is approved or performed.

V3A_STAGING_AND_RELEASE_REVIEW.md defines the implemented one-shot manual exception: a bound, consumed permit, fresh two-character synthetic DB, eight reserved HTTP slots, a nominal $0.10 research allocation, completion observations, single cancellation and terminal-evidence billing review. The production guard remains closed. A permit requires separate human approval and isolated non-root Linux execution; no experiment is authorized now, and its charge has no verified absolute maximum. PR #9 remains draft.

The [official configuration guide](https://developers.openai.com/api/docs/guides/agents-api/configuration) documents saved IDs and session configuration snapshots. The [create-session reference](https://developers.openai.com/api/reference/typescript/resources/beta/subresources/agents/subresources/sessions/methods/create) and its expanded parameter schema support `agent_id`, `environment.type=none`, tools/delegation overrides, `text.format={type:json_schema,schema:...}`, and an integer-cent `spend_control.limit`. Instructions are deliberately omitted: the saved Director owns them. No sandbox, vault, MCP, shell, filesystem, SQL, HTTP tool, or subagent is provided.

The [turn reference](https://developers.openai.com/api/reference/python/resources/beta/subresources/agents/subresources/sessions/subresources/turns/methods/list) describes usage as best effort, nullable and revisable. The reviewed managed configuration does not expose Responses' `max_output_tokens`. A one-cent session limit alone does not establish an independently verified bound on internal inference, tokens or overshoot. Those facts prevent safe live admission under the existing hard budget. No environment flag bypasses this gate. A reviewed follow-up must establish whole-turn limits and complete billing reconciliation before supplying a live transport. No hidden automatic switch to Responses occurs.

An explicitly selected `responses` adapter is available as an alternative. It requires an owner-reviewed export of this same saved agent's instructions. The application passes them unchanged, with `store=false`, no tools, a hard output-token limit and existing priced model/tier checks. Responses cannot execute `agent_id` directly and does not reproduce the managed harness's default instructions. Choosing it is an operator decision, never a silent fallback.

## Registration and configuration

`LITTLE_SPARKS_DIRECTOR_AGENT_ID` is configuration, not a credential. `OPENAI_API_KEY` remains in the existing protected server environment; no registration command needs it. Agent IDs, fingerprints and private session metadata are never added to public resident endpoints.

After separately approved staging migration:

```sh
php scripts/migrate-director.php --database=/private/staging/village.sqlite
php scripts/register-director.php --database=/private/staging/village.sqlite --agent-id=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8
```

These commands make no provider request. Registration stores the selected ID, model and restricted structural configuration, and returns its SHA-256 fingerprint. Set `LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256` to that value. Default runtime is `agents`; keep Director, AI, simulation and scheduler flags off. For the explicitly selected Responses alternative, add `--agent-file=/private/reviewed-agent.json` containing the real owner-authored export; no sample persona is supplied. Export must match the ID, priced model, default service tier, empty tools, disabled delegation and approved reasoning effort. It is bounded, stored privately and never printed. Changing an export requires explicit re-registration and a new fingerprint.

PHP-FPM environment variables are not inherited by a separately started CLI. Reuse the approved root-owned credential mechanism described in LITTLE_SPARKS_V2B.md and LITTLE_SPARKS_V2E.md. A separately approved service can use a protected EnvironmentFile or systemd credential loader; a manual shell can load a protected environment through its existing secure operator workflow. Never paste keys in command arguments/history, commit .env, print the environment, or copy credentials into a SQLite record. Nothing in this PR reads the production FPM configuration.

## Exact input/output contract: sanctuary.director.v1

Retrieval uses `DirectorContextProvider::prepare()` with a SQLite implementation and context version `sqlite-v6-3` (#70; earlier projections were `sqlite-v6-1` and `sqlite-v6-2`). This is an input projection version, not a database migration or output-contract change. The seam keeps future authenticated MCP retrieval separate from model execution and atomic world commits. V3D read-only Memory MCP and V3E optional MQTT observations are future independent PRs; see V3_ROADMAP.md. No tools, listener or bridge is activated in V3A.

The application supplies structured JSON user data and `contracts/director-v1.schema.json`, not a competing system prompt. PHP selects two eligible residents fairly under existing transactional claims, cooldowns and capacity bounds. Every request retrieves fresh SQLite context by immutable resident ID:

- complete owner-authored personality with revision and `is_excerpt=false` (untrusted character data);
- name, real home inventory (kind/variant/count), relationships, current activity and proposed/active goals; legacy category/preferences only without an owner description;
- bounded legitimately held public memories, source status, origin/teller IDs, hops and timestamps;
- exact attributed recent public encounters and topics, stored once in `shared_recent_conversations` for the two actual participants, otherwise under the legitimate participant's `recent_conversations`; unverified quote notes stay resident-specific;
- separately labeled public Director continuity, which is not automatically participant knowledge.

Private memories, tokens, owner hashes and credentials are excluded. Context is capped by MEMORY_CONTEXT_BYTES; lower-priority entries are dropped before dispatch, never silently treated as complete knowledge. Owner text is stored and transmitted in full; a mandatory core that cannot fit is refused, never excerpted. The old DIRECTOR_DESCRIPTION_CONTEXT setting remains validated for compatibility but no longer shortens identities. No public endpoint accepts arbitrary prompts.

Memory source confidence, origin/teller IDs, hops and timestamps remain in the compact projection; complete source IDs, receipt chains and object coordinates remain validated server-side. Described residents do not receive the legacy interest topic boost. Optional continuity/notes/relationship metadata and memories are trimmed before proposed goals, older recent exchanges, active goals and latest recent exchanges, in that order; comparable lists are balanced between residents. A core identity/world payload that cannot fit still fails closed. No saved words or owner text are rewritten by trimming. See [narrative review and measurements](DIRECTOR_NARRATIVE_REVIEW.md).

Return exactly `contract`, `outcome`, `topic`, `messages`, `world_action`, `transmissions`, `notes`. Outcome is conversation/action/quiet/none. A conversation has one canonical two-message exchange, in participant order, each `{resident_id,text}`, 1–160 Unicode characters. Other outcomes contain no messages. Topic is free text up to 64 characters. PHP rejects unsafe text, speaker labels, incorrect IDs, duplicate turns, unknown keys and invalid actions; it does not edit accepted words. Moderation must accept the complete context/proposal before any public effects. Failed generation means silence.

The message schema describes complete, natural sentences, usually 100–130 characters with shorter replies welcome. The 160-character ceiling is not a target. Fresh encounter input repeats the same `dialogue_guidance`, including on reused sessions; saved agent instructions and resident voices remain owner-controlled. This is generation guidance, not a semantic guarantee or a new punctuation validator: questions, quotations, ellipses, emoji and replies without terminal punctuation remain valid. PHP never completes, truncates or regenerates speech to meet this preference; accepted words go unchanged through moderation and persistence. Existing conversations are untouched. Offline mocks verify delivery and preservation, not live model quality.

`world_action` must be offered by the current server allowlist. PHP chooses and revalidates ownership, targets, cells, collision and activity limits within the apply transaction. An imagined action is never a completed event. Successful existing action handlers alone write authoritative world facts.

`notes` contains at most two `{resident_id,sequence,quote,classification}` records. Quotes must be literal substrings of actual saved messages; classification is preference/interpretation/rumor, always unverified. Retention is bounded per resident and does not delete old history. A resident may remember hearing an invented statement; that statement does not become a world fact.

**V3A requires `transmissions: []`.** The schema reserves a future literal-evidence structure, but the validator rejects nonempty proposals. Model-selected aliases plus matching prose are not proof of semantic truth. Existing V5 receipt chains remain readable and unchanged; V3A does not manufacture new receipt chains from free dialogue. New successful world actions still record valid firsthand V5 facts. Independent evidence validation for new prose transmissions is a future reviewed capability, not a lexical truth claim.

## Replaceable continuity sessions and concurrency

The chosen optional reuse strategy scopes a session to the unordered participant-ID set, identity revisions, runtime and registered configuration fingerprint. It expires for reuse after 24 hours or eight accepted managed turns; a changed identity/configuration rotates context. These are small context caches, not resident brains or permanent pair ownership. Different participants never inherit a giant village-wide transcript.

The persistent session record includes local/provider IDs, scope, agent/config fingerprint, lifecycle state, last accepted provider turn, turn count and timestamps. The attempt record links opportunity ID, session ID, participants through the original AI ledger, contract version, context digest, token observations, estimated/reserved cost, HTTP slots and outcome. Accepted runs link to the exact saved conversation. No provider transcript replaces SQLite history.

A fresh session receives the same bounded resident context as a reused one, rebuilt on every encounter. Expired/unavailable/incompatible or rejected sessions can be abandoned without losing resident memories. No provider call is retried automatically after uncertain creation, input dispatch, timeout or billing. A definitive failure is silent; the next separately admitted opportunity may start fresh only after unresolved accounting is reconciled. Recovery never regenerates the same opportunity.

Existing BEGIN IMMEDIATE claims globally serialize opportunities; the paid lease covers provider work outside write transactions. Session selection occurs after the unique opportunity claim. Every HTTP request is marked started before dispatch. Follow-up input has a stable attempt ID as Idempotency-Key; it is not permission to retry a potentially billed call. Completed turn ID/session/agent identity and final output are validated; old turn IDs cannot be accepted again. Provider idle status alone is not completion. Required tools/actions, incomplete turns and extra/paginated output fail closed. Rejected output is not saved as an accomplished encounter and its managed session is quarantined.

## Accounting and admission

Existing AI attempts/day/month totals are preserved. Responses reserves two HTTP slots; the offline ordinary managed prototype reserves four/five for fresh/reused sessions and remains blocked for live production. The separately approved manual experiment reserves eight, including bounded repeated observations, cancellation and moderation. All slots are reserved atomically before dispatch and count against AI_DAILY_REQUESTS (default 2/max 200), independently from opportunity/replay limits. No daily/monthly setting is raised automatically. See the staging review for the exact eight-call/60-second envelope and permanent permit consumption.

Managed token usage is stored as an observation; it is not final settlement. Its full reservation remains frozen and blocks subsequent paid attempts until reviewed reconciliation. The prototype's one-cent reserve is not evidence of a proven whole-turn hard cap. Live dispatch is blocked regardless of flags. Responses keeps the established worst-case token reservation and known/unknown handling. Monthly application cap remains at most 1,000,000 micro-USD ($1), including existing history. No cap, production setting, prepaid credit or provider alert is automatically raised or reset.

## Migration, verification and rollback

V5+identity→V6 is explicit/additive: adopt the three installed identity tables, indexes, constraints, descriptions, colors, revisions and immutable edits unchanged. Add eight Director tables, four accounting tables and three staging permit/outcome/revision tables. Check full original V5 and identity schema/data digests, FKs/integrity under one transaction; refuse pending opportunities or any held lease. Original secrets, private links, objects/coordinates, histories, receipts and ledgers stay unchanged. Bare V5 fixtures can explicitly receive the independent identity add-on first; only those absent descriptions start empty. Identity-aware draft V6 can add missing accounting/staging tables transactionally. Pre-identity draft V6 without the marker is deliberately refused and needs separate reviewed recovery, never an automatic reinterpretation. HTTP never migrates. New V6 characters receive no PHP-assigned preferences/speech.

Run `PHP_BINARY=/path/to/php node tests/village-director.mjs`, followed by all existing suites listed in AGENTS.md. Fixtures use OS-temp databases, keys cleared and mock transports. No live account, paid quality or billing claim follows from these tests. PHP 8.3 staging and an explicitly approved account/cost experiment remain required before live managed admission can be implemented. The first experiment must measure all internal inference, moderation and metadata calls, inspect hard-stop/overshoot behavior and reconcile actual billing against reservations.

For a future approved rollout, first pause and drain the installed scheduler, take and verify a private backup, rehearse V6 on staging, inspect preservation/credentials/limits, then review the cutover separately. Do not run V5-only code on V6. A rollback should use a V6-capable paused build; restoring an old backup can erase new history and billing and must never regain budget headroom. No production operation is included here.

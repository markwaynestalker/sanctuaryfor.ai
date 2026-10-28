# Village persistence audit (#65)

## Decision and scope

The village has durable identities, verbatim conversation history, numeric relationships, source-backed world memories and persistent garden changes. It does not yet have a working application goal lifecycle or multi-step project engine. Current V6 dialogue persists as conversations and optional unverified quote notes; it does not use the older V5 conversation-to-story and knowledge-sharing path. Yesterday can affect context, relationships and world inventory today, but the application cannot yet reliably turn an intention into a tracked sequence of accomplishments.

Audit baseline: `8b80111405d9241ef4679eaa103db4c57414763b` (current `origin/main` on 2026-10-27, after #61/#62). All source links refer to this repository revision. Historical release documents describe earlier admission rules; current code and latest owner instructions govern this audit.

This is a source and offline-fixture investigation. No production database, hosted definition, credentials, private traces or live provider were accessed. The Walter/Penelope counts in #65 are owner-reported observations, not independently verified measurements. No functional change, migration, activation, scheduler adjustment, paid call or deployment is proposed for this PR. Follow-up designs are in [VILLAGE_DEPTH_PLAN.md](VILLAGE_DEPTH_PLAN.md).

## Capability assessment

Classifications describe the stated capability, not production activation. Fully implemented means an end-to-end code path exists within its documented bounds; it does not promise model quality.

| Capability | Classification | Evidence and practical limit |
| --- | --- | --- |
| Stable owner identity | Fully implemented | `resident_identities`, revisions and immutable edit audit; owner text enters fresh context, with explicit excerpt handling. Cosmetic forms do not determine behavior. |
| Exact public conversation persistence | Fully implemented | Two actual participants, ordered original messages, successful run/event provenance, moderation and atomic apply. |
| Supported world actions with lasting effects | Fully implemented | Owned plot changes and visits use existing validated handlers; saved objects/events survive subsequent opportunities. |
| Source-backed personal world memories | Partially implemented | Supported action events create stories and firsthand receipts; not every action or experience has a memory representation. |
| V6 conversation recollections | Partially implemented | Recent full dialogue and optional literal unverified notes persist. General personal episode extraction is absent. |
| Individual structured knowledge | Partially implemented | Receipt ownership, source chains, teller/hops and legitimate history are checked. Director sees both participants' contexts; prose is not a formal knowledge proof. |
| V6 verified story transmission | Planned / placeholder | Contract requires `transmissions=[]`; parser explicitly rejects nonempty transmissions. Existing V5 chains remain readable. |
| Relationship progression | Partially implemented | Affinity/familiarity and timestamps change persistently, with stable pair IDs; no rich relationship lifecycle or sentiment-specific V6 updates. |
| Goal creation and progression | Planned / placeholder | Goal table and bounded reader exist; no application goal writer or transition contract found. |
| Persistent activity/location | Planned / placeholder | Table only permits `available` / `commons`; initialized for residents, not an activity state machine. |
| Shared rest | Fully implemented | Separate opt-in shared policy/time calculation; not an individual resident activity row. |
| Multi-step projects / collaborations | Planned / placeholder | No project entities, membership consent, steps, progress receipts or completion handlers found. |
| Bulletin authorship/context | Fully implemented within optional capability | Default-off add-on, moderated attributed notices, provenance/withdrawal/expiry rules; no notice-read/attendance records. Production activation requires verification. |
| Director continuity | Partially implemented | Fresh SQLite input plus replaceable pair sessions and five public event summaries; no long-term planner. |
| Repetition avoidance | Partially implemented | Relevant recent history, fair selection and cooldown controls support variety; no semantic repetition detector or proven production improvement. |
| Production prevalence / failures | Requires production verification | Actual resident row counts, configuration, recall rejection reasons and model behavior are not established by this audit. |

## Architecture and successful-commit boundary

Read [app/director.php](../app/director.php) (`simulateDirectorOpportunity`, `parseDirectorProposal`), [app/activity.php](../app/activity.php) (`claimActivity`, `applyActivity`, `failActivity`), and [contracts/director-v1.schema.json](../contracts/director-v1.schema.json).

1. Existing opportunity selection fairly chooses eligible residents under a write lock. Daily limits, cooldowns and shared rest can produce quiet or solitary outcomes. V6 currently returns a no-action outcome without calling the Director when fewer than two participants are selected; solo paid goal work is not implemented.
2. Server derives currently available actions for the first participant. `prepareDirectorEncounter` builds fresh ID-bound SQLite context. Session reuse is optional continuity, not identity or memory storage.
3. Existing accounting reserves before dispatch. The provider returns a bounded proposal; IDs, exact keys, dialogue shape and offered actions are validated, and moderation covers context/proposal.
4. `applyActivity` checks claim/lease, day and activity limits again inside `BEGIN IMMEDIATE`. It inserts the successful run before invoking world-memory handlers, all within the same uncommitted transaction. Conversation, relationship, action/event, Director provenance, notes, exposure records and accounting apply together or roll back.
5. Failure records a handled failed opportunity with retained conservative accounting. It does not invent a conversation, goal, action or retry. Distinct later eligible opportunities can proceed.

This boundary is the right place for future goal transitions and progress receipts. Browser wandering, gathering and replay are presentation only, and cannot establish a resident's location, meeting, notice reading or project contribution.

## Persistent structures and lifecycle findings

### Identity and owner intent

[app/identity.php](../app/identity.php), [app/identity_schema.php](../app/identity_schema.php) and [app/director_context.php](../app/director_context.php) preserve immutable resident IDs, owner descriptions and revisioned edits. Descriptions are untrusted character data, not event facts. Described residents do not receive the old category/preferences or interest boost in Director retrieval. Identity revisions scope sessions; all 135 appearance forms remain cosmetic.

Stable personality storage is implemented. Autonomous personality development is not: there is no reviewed mechanism that rewrites owner descriptions from experiences. Future preferences should remain a separate attributed layer rather than editing the owner identity.

### Goals

[app/director_schema.php](../app/director_schema.php) defines `resident_goals`: ID, resident, 1-160 character description, proposed/active/completed/cancelled status, optional source event and creation time. The context query selects at most four proposed/active descriptions, prioritizing active ones; it does not supply goal IDs or revisions.

A repository search of `app`, `scripts`, `contracts` and `tests` finds goal DDL, context reads and explicit synthetic inserts in `tests/director-context-fixture.php`, but no runtime insert/update/delete handler. The Director output schema has no goal operation. There is no acceptance, progress, completion proof, cancellation command, revision check or project link.

Confirmed: this is a storage/retrieval foundation, not an end-to-end goal feature. Tests that seed goals prove retrieval and trimming, not resident goal formation. Empty goals are expected when nothing outside these paths populated them. Whether production has manually populated goals remains unknown. Do not populate or backfill goals from old wishes during this audit.

### Memory creation, retention and retrieval

[app/memory_schema.php](../app/memory_schema.php) defines immutable `memory_stories`, `memory_receipts`, stable `friendship_identities`, `friendship_memories` and `memory_processed`; `friendship_themes` records bounded encounter counts per legacy topic. V6 adopts V5 records rather than resetting them.

[app/memory.php](../app/memory.php) implements three distinct mechanisms:

- `recordWorldMemory` creates a canonical world story and origin receipt from supported public actions: tree, flowers, bush, path, decoration placement, pet adoption and friend visits. It requires matching objects/events and completed runs where applicable. The handler is reached by V6 world actions as well as existing visitor actions. A memory of an owned action is therefore possible without a conversational extraction request.
- The older `conversationMemory` / `recordStatementMemory` path processes saved V5 conversations, narrowly recognizes gardening/stargazing statements, may share an explicitly supported story and updates friendship history/themes. This limited recognition is not a general experience summarizer.
- V6 `saveDirectorMemory` instead saves at most two proposal-selected literal quotes as `director_notes`, classified preference/interpretation/rumor. It checks actual saved message text and retains the note for its selected participant. `applyActivity` deliberately bypasses `conversationMemory` for Director proposals. It does not promote free dialogue into confirmed facts or update the older conversation-derived friendship themes.

Consequences: many successful V6 conversations can coexist with zero new `memory_stories`; notes and exact conversation rows may still be growing. `memories` in context is a receipt-backed subset, not a total of everything a resident has experienced. V6 does not generally write the older free-text resident memory path either. A note can quote either actual participant; the selected holder is not necessarily the quote's speaker, so a reported preference must retain attribution rather than become the holder's own preference.

`recallMemories` retrieves a bounded candidate list owned by the resident, ranks counterpart relevance, teller relevance, world facts, significance and recency, then validates source/receipt chains and selects a bounded subset. Owner-described residents skip legacy topic preference scoring. Invalid source/chain entries are skipped; contradicted object-backed stories can remain labeled contradicted, but do not receive transmission aliases. Trimming can remove selected memories later.

Configured defaults in `memoryConfiguration`: select 4 from 32 candidates, 128 receipts per resident, 10,000 stories globally, maximum 3 hops, one legacy share, 32 friendship-history entries, 2 recent encounters per resident and 8,192 context bytes. Bounds/defaults are policy controls, not evidence of production values. Capacity does not delete old records; `addMemoryReceipt` and note saving can return/skip at capacity. Story capacity can also prevent new story creation. This creates a future freshness problem even while normal conversation persistence continues. No cap changes are authorized here.

`decorate_house` persists a changed cottage and event but is absent from `recordWorldMemory`'s supported kinds. That is a confirmed coverage omission, not proof of corrupt history or a broken action. Design a specific provenance representation before adding it; current world-story validation assumes the supported event/object mappings.

### Knowledge boundaries and sharing

Receipt chains bind an origin, saved source, actual teller/listener, parent receipt, conversation sequence, timestamp and hop count. Duplicate learning cannot confer new firsthand status. Public conversation validation requires successful public event/pair binding and exact legacy keys. Recent pair history is stored once under shared history; third-party encounters remain attached to their legitimate participant.

V6 currently cannot create verified conversational transmissions: schema `maxItems=0` and parser rejection agree. This is an intentional conservative contract, not a broken activated sharing feature. Source aliases remain useful provenance in context but are not permission to create a receipt. `director_transmissions` exists as schema foundation with no active V6 save path.

Important limitation: one Director sees both participants' memories, third-party histories and public continuity. Holder-specific storage does not mechanically prove that generated dialogue never leaks the other participant's knowledge. Exact quote notes are unverified and cannot establish that their factual content is true. Improving attribution requires explicit evidence contracts and adversarial tests, not treating a model-selected alias or a notice as proof.

### Relationships and progression

[app/world.php](../app/world.php) (`strengthen`) stores canonical unordered pairs with affinity 0-100, familiarity capped at 1,000, first/last interaction times and stable friendship identities. A new pair begins with familiarity 1 and the nonnegative delta. Existing V6 conversations add affinity 2; visits add 1. Conversation plus visit can both contribute in one successful transaction. Failed/quiet/no-action outcomes do not create a successful conversation increment.

The V6 branch uses a legacy storage topic of `welcome` while preserving the original free Director topic in `director_runs`; recent Director context reads the Director topic. This compatibility mapping does not mean all conversations actually concerned welcomes. Numeric increases do not demonstrate trust, agreement or emotional development. There is no disagreement-aware relationship update, relationship milestone lifecycle or decay engine in the audited path. V6 also skips the older friendship theme/history processing noted above.

### Activity, world consequences and projects

`resident_states` is constrained to `available` / `commons`, initialized during V6 migration and new identity creation. No runtime activity transition writer was found. Its `since` is not an observed live arrival. Shared sleep is separately derived from backend-published opt-in policy; browser positions are local decorative coordinates.

[app/ai_provider.php](../app/ai_provider.php) (`aiActionChoices`) offers one server-chosen free cell and permitted action types for the first participant, plus visit/decorate where eligible. [app/world.php](../app/world.php) rechecks ownership, occupancy, plot capacity, six daily world changes, pet limits and target validity. These are real durable consequences. Space and action inventory are finite; a fuller garden legitimately narrows choices. Changing scheduler quotas would not create missing action or project semantics.

No multi-cycle project, task graph, collaboration membership, contribution receipt, skill advancement, artifact lifecycle or completion milestone was found. A path or tree is a saved accomplishment in ordinary language, but there is no separate accomplishment engine linking it to a prior goal. Notices and conversation intentions do not close that gap.

### Bulletin and operator proposals

[app/bulletin.php](../app/bulletin.php) and [app/bulletin_schema.php](../app/bulletin_schema.php) implement the optional notice add-on. Posting is offered only under configured feature/installation/quota rules, belongs to the acting first participant, is moderated, and is rechecked inside apply. Original text/private run provenance remain immutable; removals append records. Public/context readers require completed attributable runs and honor removals and active expiry.

`bulletinContext` returns at most the configured recent active public notices (default 4), labeled unverified author expression, not instructions and not completed activity. Context can see any qualifying public author, not only the pair. Public authorship pins in #62 show saved authorship metadata, never readership. There are no notice-read, attendance, agreement or goal-acceptance receipts. Optional operator suggestions similarly carry no authority of resident consent; exposure in a successful encounter is not agreement or performance.

An empty notice list is consistent with disabled/uninstalled capability, zero context count, no valid active public notice or trimming; the key can be omitted rather than sent as an empty array. Actual production activation and rows were not inspected.

## Context, continuity, repetition and token limits

`prepareDirectorEncounter` supplies two fresh participant projections, grouped home inventory, relationship rows (up to 8), receipt-backed memories, note quotes, eligible recent conversations and up to 4 goals each. It scans bounded recent candidates past failed/private records, deduplicates shared pair history, and adds the latest 5 public event summaries separately as Director continuity. These are not five planning steps or five resident memories.

`trimDirectorContext` drops optional metadata before higher-priority dialogue/goals: notices, operator suggestions, continuity, notes/weather, relationships, memories, proposed goals, older recent dialogue, active goals, latest recent dialogue. Some categories interleave by explicit priority/list-tail rules. If mandatory core context cannot fit it fails closed. Source aliases dropped from the final payload are pruned server-side. This bounded context can omit a stored memory or goal without deleting it.

`directorSession` scopes reuse by unordered pair IDs, identity revisions, runtime, registered fingerprint and, for optional contracts, contract scope. Valid new/ready sessions must satisfy configured TTL and turn bounds (historically/default 24h/eight turns); uncertain/unavailable sessions are not reused. Fresh SQLite context accompanies reused turns. Replacing a session does not erase identity/history. No evidence here supports changing session reuse or the hosted definition to fix repetition.

The default 8,192 is UTF-8 JSON bytes, not provider tokens. [DIRECTOR_NARRATIVE_REVIEW.md](DIRECTOR_NARRATIVE_REVIEW.md) records synthetic before/after byte measurements; this audit does not relabel those older numbers as new measurements. The current offline fixture measured 7,253 bytes at both 10,000 and 8,192-byte ceilings (two shared encounters, two goals and four memories per participant); at 4,096 it measured 3,923 bytes (two shared encounters, two goals and one memory per participant). These are synthetic serialized input bytes, not real provider tokens or production quality observations. `director_attempts.context_sha256` hashes application context, not a complete provider prompt/token receipt. Managed sessions can include prior turns and hosted instructions outside this JSON. `director_usage_observations` are nullable, repeatable best-effort snapshots; do not sum them or equate them with final billing. Neither smaller JSON nor mocked conversation variety proves lower real token use or better dialogue.

Repetition remains a production hypothesis. Fair participant selection, bounded recent history and ENV cooldowns provide tools, but no semantic novelty or meaningful advancement criterion is enforced. A pair's latest two exchanges do not describe a whole village's narrative quality. Evaluate attributable continuations, new action evidence and repeated questions separately from exact duplicate text.

## Interpreting the owner-reported trace

| Observation | What current code supports | What remains unknown |
| --- | --- | --- |
| Empty goals for both | Application has no goal lifecycle writer; only proposed/active rows are read and can be trimmed. | Whether any eligible goal rows exist in production. |
| Walter 0 / Penelope 2 memories | Receipt-backed recall differs from note/history counts, has bounded selection/provenance checks and later trimming. | Stored counts, candidates, invalid chains, capacities and final drop reasons. |
| Two recent/shared conversations | Configured default is two selected encounters per participant; shared entries are deduplicated, with bounded union. | Exact configuration and candidate histories of that request. |
| Seven relationships each | Reader can return up to eight saved partners. | Which pair rows changed, current values, or whether this represents satisfying social variety. |
| Both available in commons | Only legal persisted activity/location pair in this schema. | Real individual plans; decorative browser motion cannot verify them. |
| No notices | Optional eligibility and trimming can yield no notices. | Installed/enabled flags and valid active public rows. |
| Five continuity records | Query hard limit is five public event summaries. | Whether they were relevant to this pair or affected output. |

## Gaps, potential defects and confidence

Confirmed missing capabilities: goal transitions, project steps, collaborative consent/contributions, general V6 experience memories, V6 verified transmission, richer relationship semantics and real per-resident activities. These are feature gaps, not evidence that SQLite is forgetting successful conversations.

Confirmed design limits: finite action space; silent receipt/note saturation; V6 bypasses legacy conversational story/theme writes; cottage decoration has no current structured memory; goal IDs/revisions are absent from context; many different reasons produce an empty projection.

Potential defect to reproduce separately: note retrieval applies SQL `LIMIT` before `publicMemoryConversation` validation, unlike the recent-conversation candidate scan. Enough unsupported newer public note sources could hide an older valid note. The query also lacks a final unique tie-breaker. Normal application-created notes have validated sources, so impact depends on retained/legacy/manual data and is not demonstrated by the supplied trace. Add an adversarial fixture before calling this a confirmed user-facing bug.

Architectural risk: public Director awareness could be voiced as participant knowledge despite holder-specific storage. Receipt checks protect structured claims; this audit does not prove semantic isolation of arbitrary generated prose. Do not solve it by inventing receipts or broadening free-text fact inference.

Production verification remains optional, owner-controlled and separate: use a consistent query-only snapshot to compare stored story/receipt/note/goal counts with selected/candidate/trimmed context, active notice eligibility, successful versus failed opportunities, actual action distributions and distinct usage observations by turn. Keep personal text, tokens, session IDs and configuration secrets out of public reports. Do not invoke reconciliation, recover claims, replay old opportunities or issue provider GETs to collect these facts. No production command was run or authorized by this audit.

## Verification and review criteria

Documentation-only diff; no application, tests, contract, schema, ENV or workflow files are changed. Local validation passed: `PHP_BINARY=C:/xampp/php/php.exe` with `python -B ops/ci/offline.py` completed 133 PHP syntax checks, 31 JavaScript syntax checks, deployment tests and all 23 JavaScript suites. Deployment tests reported 10 platform-specific skips. Ubuntu CI results are tracked separately in the PR. Local environment: Windows, PHP 8.2.12, Node 20.15.1, Python 3.11.8. Windows skips Linux filesystem/locking/deployment-specific checks; Ubuntu 24.04/PHP 8.3/Node 22 CI must pass independently.

Relevant existing evidence:

| Suite / fixture | What it can establish |
| --- | --- |
| `tests/village-director.mjs` and `tests/director-core.php` | Exact saved speech, unverified note persistence, no dialogue-derived confirmed stories, rejected transmissions, moderation rollback, replay and fresh SQLite context on session reuse. |
| `tests/director-context-core.php` (within Director suite) | Synthetic seeded goal retrieval, byte pressure, canonical shared/third-party history, aliases, owner descriptions and unchanged database digest. Not goal creation. |
| `tests/village-memory.mjs` / `tests/memory-core.php` | Legacy story/receipt provenance, world memory, sharing chains and preservation. Legacy sharing success is not V6 sharing support. |
| `tests/village-v2.mjs`, `tests/village-activity.mjs`, `tests/activity-limits.mjs` | Supported world persistence, relationships, idempotency, limits and rollback. |
| `tests/village-bulletin.mjs`, `tests/village-operator-ideas.mjs` | Optional expression/provenance/withdrawal and successful-run exposure boundaries. |
| Sleep/weather/commons/journal/archive suites | Shared policy, synthetic presentation, exact public saved history and read-only browser behavior. |
| Deployment offline tests / Ubuntu workflow | Protected release constraints and platform-specific regressions; a mock never verifies production. |

There is no live narrative-quality, production token-cost or production-state result in this report. Recommended issue specifications and evidence-based acceptance criteria follow in the separate depth plan. #63 remains an independent population/discovery task.

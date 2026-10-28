# Village depth: incremental plan after #65

## Product direction

Give residents a small number of continuing intentions whose progress is visible in saved evidence. Keep owner-written identities stable, allow residents to decline or abandon plans, and let completed actions change what becomes possible next. A quiet day should remain valid. Depth should come from continuity and consequences rather than more calls, faster scheduling or a larger animated scene.

The [persistence audit](DIRECTOR_PERSISTENCE_AUDIT.md) establishes the starting point. Existing conversations, relationships, events, objects, receipt chains, Director validation and accounting remain the foundation. These are proposed follow-up issue specifications, not implemented features or authorization to migrate, activate or deploy. No follow-up issues have been published by this PR.

Recommended first implementation: **D1 observability, then D2 one-resident goals linked to existing actions**. D3 adds a short project only after that loop is demonstrated. Design D4 alongside D2 so attribution is explicit, but ship verified sharing separately. D5 collaboration and D6 relationship depth follow evidence of useful individual progress. D7 presents only what the backend can prove. #63 remains an independent discovery/layout improvement using existing neighborhoods.

## What a deeper village would look like

A resident might choose to make a small flower corner. The saved goal is an intention. Planting flowers through an allowed owned-plot handler supplies a separate action receipt. A later encounter can acknowledge that exact change and consider another eligible step; neither recalling the idea nor discussing flowers completes it. A project can finish only when its predefined evidence requirements are satisfied. The next encounter can refer to the accomplishment without reenacting or regenerating its original dialogue.

Another resident might offer to help through a public notice. That creates an invitation, not membership or attendance. A later independently recorded acceptance can establish a collaboration. Each contributor can initially act only on their own plot, and each contribution needs its own successful action evidence. Seeing the notice in Director context does not prove anyone read it.

These examples are acceptance scenarios, not scripted resident speech, imposed hobbies or promises that the current application supports them. The Director continues to author original dialogue. Forms and colors never select goals, roles, talents or personality.

## D1 — Explain persistence and retrieval without changing behavior

**Priority:** P1, first. **Complexity:** small to medium. **Dependencies:** audit review.

**Problem:** Empty context fields conflate absent data, disabled features, invalid provenance, selection limits, capacity and byte trimming. Operators cannot distinguish a quiet resident from a projection gap using one trace.

**Approach:** Add a private, explicit query-only diagnostic command that reads a consistent local SQLite snapshot and reports aggregate stored/candidate/selected/dropped counts and supported reason categories. Compare stories, receipts, notes and conversations separately. Report goals as storage-only until a lifecycle exists. Include note capacity, receipt/story limits, world action availability, current optional-feature eligibility and final context bytes. Supply participant IDs only through the existing private CLI boundary. Do not expose raw descriptions, quotes, tokens, provider session IDs or credentials. Do not add browser requests, polling, persistent diagnostic writes or provider inspection. Avoid dispatching the ordinary scheduler or mutating `directorSession` just to inspect context.

**Affected components:** `director_context.php`, `memory.php`, `bulletin.php`, a private diagnostic CLI and their offline fixtures. Prefer a read-only shared selection helper over a second implementation of selection rules; any refactoring must prove identical generated input.

**Migration:** none expected. **Risks:** drift between diagnostic and actual selection; leaking private data; accidentally opening a writer. **Tests / exit:** query-only connection and whole-database digest preservation; absent/disabled/expired/capped/invalid/trimmed cases distinguished; same context bytes and selections before/after; hostile text omitted; no credential/network use. Existing all-suite/Ubuntu CI required.

**Separate corrective candidate:** reproduce note candidate starvation/timestamp ties from the audit with unsupported newer sources and a valid older note. Only if reproduced, propose a bounded candidate scan plus deterministic ID ordering. This is a focused bug PR, not permission to change recall in this audit.

## D2 — Give resident intentions a real lifecycle

**Priority:** P1. **Complexity:** medium to large. **Dependencies:** D1 and an approved capability/contract design.

**Problem:** `resident_goals` can be read but no application path creates or advances goals. Untracked wishes cannot carry reliable progress.

**Approach:** Reuse the existing goal identity/storage where compatible. Design a separately versioned optional output capability for explicit propose/accept/cancel operations and evidence-linked completion. Keep ordinary v1 encounters compatible. Start with one active individual goal per resident and a small bounded proposal list, as a proposed product rule subject to review. Include goal IDs and revisions in the new context; maintain an append-only transition history with original author, actual participant, source conversation/run/event, expected revision, reason and time. Derive current state transactionally. The Director proposes transitions; PHP checks ownership, allowed state edges, revision, source attribution, moderation and evidence. Quiet/no-action ticks do not silently advance goals.

A proposal must be attributed to the resident's own supported turn or an explicit new structured intention operation, never inferred from somebody else's quote. Owner suggestions and notices can inspire a proposal but cannot accept it. A changed mind must allow cancellation without penalty. Abstract wishes such as 'be kinder' stay intentions unless a reviewed, bounded completion criterion exists; they cannot be marked complete from sentiment.

**Affected components:** `resident_goals`, `director_schema.php`, `director_context.php`, proposal schema/parser, `applyActivity` and existing moderation flow. Do not rewrite owner identities or old conversations. Do not retrofit goals into historical speech.

**Migration:** likely additive transition/revision storage; assess adoption of any existing goals before choosing new columns versus an add-on projection. Require a separate owner-reviewed transactional migration preserving old tables/rows and `user_version` policy. Deployment/activation are separate.

**Risks:** arbitrary model claims of consent/completion, stale proposals, duplicate goals, unbounded queues and old session contract mismatch. **Tests / exit:** propose then accept on distinct opportunities; cancel; real action completes an eligible goal once; talk alone never completes it; wrong actor, stale revision, duplicate replay, moderation rejection and expired lease cannot transition; concurrent workers serialize; old v1 and identities/history/accounting stay unchanged. Seeded goals alone do not satisfy acceptance.

## D3 — One short project with real steps and lasting consequences

**Priority:** P2. **Complexity:** large. **Dependencies:** D2; feasibility review of available actions and plot capacity.

**Problem:** A goal has no decomposition, next eligible step or multi-cycle completion evidence. Existing world changes are durable but disconnected from a prior intention.

**Approach:** Introduce a minimal project and a bounded ordered step list, initially for one resident using existing allowlisted actions. Link each completed step to a specific successful event/object and claim exactly once. State edges: proposed, active, completed, cancelled; steps may be pending, completed or blocked. Offer at most the next eligible step alongside existing choices. At most one world action progresses a project per existing opportunity; no recursive planner, catch-up worker, extra calls or new timer. Planning, dialogue and doing remain separate records.

Start with a two-step garden example only when an owner plot has legal capacity; existing objects cannot retroactively satisfy a new project unless its explicit adoption rule records their original evidence. If a step becomes unavailable, expose a blocked reason and allow a reviewed revision or cancellation. Completion is derived from all required distinct evidence, not a model-written success sentence. Store a completion record referencing the goal, steps and original evidence so it survives session replacement. Do not invent time-based plant growth, skills or resources the world does not model.

**Affected components:** goal/project add-on, action-choice builder, world handlers, apply transaction, context and public provenance projection later. **Migration:** additive project/step/evidence tables with unique event-to-step consumption policy; separate adoption and rollback plan.

**Risks:** finite plots make plans impossible; counting one event repeatedly; stale target cells; projects monopolizing fair selection. **Tests / exit:** deterministic two-step project spans distinct mocked opportunities and process restarts; object/event/state commit atomically; one event cannot complete two incompatible steps; blocked/full plot path works; expired/failed/moderated/replayed encounters leave progress unchanged; a fresh provider session recovers next step from SQLite. No change to participant fairness, quotas or paid request count.

## D4 — Improve personal recollection and individual knowledge

**Priority:** P2, design early and implement independently. **Complexity:** large. **Dependencies:** D1; D2/D3 evidence shapes for project recollections, but basic note attribution can ship separately.

**Problem:** V6 retains exact conversations/notes but does not create general episodes or verified conversational transmissions. Legacy statement recognition is too narrow for richer recollection.

**Approach:** Keep separate layers: (a) confirmed completed world/goal/project events; (b) attributed personal statements and interpretations; (c) source-bound told knowledge. Reuse stories, receipts and immutable notes wherever their existing constraints fit. Do not call legacy keyword extraction a general memory solution or rewrite saved speech. First make note speaker, holder and source attribution explicit in retrieval and diagnostics. Add structured recollections only for successful supported events, with exact evidence references; propose any broader subjective episode format as its own review.

Restore verified sharing only under a distinct reviewed capability. The server must prove source ownership, actual speaker/listener, source claim, supported utterance, chain/hops and successful conversation; a model-selected alias or literal quote match alone does not prove factual entailment. Begin with a narrow evidence-bearing representation, allow original dialogue, and keep unsupported interpretation unverified. Do not generate PHP resident speech or silently enable the reserved v1 `transmissions` field. Source-based authority must survive session replacement.

**Affected components:** memory schema/source validators, recall/context, Director contract/parser and save path; optional provenance display. **Migration:** none for initial attribution/diagnostics; likely additive episode/claim evidence if existing story kinds/topic constraints cannot represent the reviewed design. Never broaden constraints by rebuilding old immutable tables casually.

**Risks:** knowledge leakage across participants, quote attribution errors, treating rumors as facts, silent saturation, over-retention. **Tests / exit:** A knows an action, B initially does not; a real supported saved exchange teaches B with teller/parent/time; C remains uninformed; repeated hearsay stays hearsay; wrong speaker/alias/source/sequence, contradicted object, withdrawn notice and ambiguous entailment cannot create a fact receipt. Capacity saturation is observable and old provenance remains intact. No extra extraction request or paid evaluation.

## D5 — Collaborative projects through explicit participation

**Priority:** P3. **Complexity:** large. **Dependencies:** D2-D4; bulletin integration remains optional.

**Problem:** An invitation or shared conversation cannot currently establish voluntary project membership or an attributable contribution.

**Approach:** Add invitation, acceptance, withdrawal and contribution records bound to actual residents and project revisions. Reuse public notices for optional discovery; the notice ID is an invitation source, never proof of reading/consent. Initially each member contributes through an already allowed action on their own plot. The project references those contributions without transferring ownership or granting another resident write authority. Membership withdrawal changes future eligibility and preserves past evidence. Define cancellation and an unavailable member's effect; no attendance inferred from browser gathering.

**Affected components:** project membership/contribution add-on, optional bulletin references, proposal validation, action choices and atomic apply. **Migration:** additive membership and contribution history with deduplication. **Risks:** fabricated agreement, cross-plot writes, one resident binding another, forever-blocked projects and forced participation. **Tests / exit:** invitation alone adds no member; actual participant acceptance commits once; nonparticipants cannot accept for someone else; withdrawals persist; contributions preserve owner/action evidence; notice expiry/removal does not erase history or create progress; failure rollback spans membership, action and project evidence. Existing accounting/fairness unchanged.

## D6 — Relationships with specific shared history

**Priority:** P3. **Complexity:** medium to large. **Dependencies:** D2-D5 for goal/project milestones; existing encounter evidence supports a smaller initial increment.

**Problem:** Successful V6 conversations mostly produce the same numeric affinity increment. Numbers alone do not explain how a relationship develops.

**Approach:** Add evidence-bound shared milestones such as an actual accepted collaboration or completed contribution, while retaining existing canonical relationship IDs and numeric history. Retrieve a few relevant milestones for the current counterpart. Do not infer emotional attachment, agreement, occupations or consciousness from counts. Subjective trust/preferences remain attributed interpretations; no sentiment-scoring paid call, mandatory friendship, relationship reset or automatic owner-personality update. Disagreement-aware numeric policy needs a separate explicit design rather than guessing from punctuation or keywords.

**Affected components:** relationships, friendship identities, project evidence, recall/context and eventual profiles. **Migration:** likely additive milestone records; no reset/recalculation of old affinity. **Risks:** making every interaction a reward, saturation, unsupported emotional claims, repeatedly counting one event. **Tests / exit:** one unique supported milestone per source; counterpart-specific retrieval; no milestone for failed/quiet encounters or mere suggestions; replay/multiple quotes cannot multiply it; original pair scores/history and exact speech remain unchanged unless an explicit separately approved rule applies.

## D7 — Make village progress readable from saved evidence

**Priority:** P3; can deliver a small goals-only view after D2. **Complexity:** medium. **Dependencies:** backend records for whatever is displayed; coordinate with #63 independently.

**Problem:** Visitors see saved speech and decorative movement but cannot inspect an intention-to-action-to-accomplishment trail.

**Approach:** A compact no-JS-capable profile/journal view links a public intention to original saved action evidence and a supported outcome. Label proposed, active, blocked, cancelled and completed precisely. Show who contributed and what was actually saved. Reuse existing source links, pagination, bounded neighborhood discovery and persistent reading panel. Activity indicators require real published backend state; no live work/reading animation derived from intentions. Keep privacy and public provenance rules identical to the underlying evidence. Backend completion cannot depend on a visitor viewing a page.

**Affected components:** public profile/journal projections and templates; commons only if a bounded existing projection can carry legitimate saved metadata. **Migration:** none beyond prerequisite backend capability. **Risks:** leaking restricted plans, stale badges, conflating replay with new activity, excessive queries/polling. **Tests / exit:** public/withdrawn/private/missing-source cases, exact original text, keyboard/no-JS/mobile access, constant bounded queries, no polling or new animation loop, browser-read database hashes unchanged. Synthetic screenshots only; physical Safari check remains separate.

## Delivery gates and success measures

Each increment gets its own feature branch, PR, offline fixtures and Ubuntu CI. Separate migration proposals and owner operational activation from code review. Preserve exact speech, immutable history, default-off capability boundaries, explicit ENV budgets, eight-slot managed lifecycle, moderation, claim fencing and exactly-once accounting. Existing two-message encounters remain the interaction unit until a separately reviewed contract says otherwise. Do not solve progress by increasing requests, changing model, editing the hosted Director or expanding world permissions.

Measure deterministic application behavior offline first:

| Question | Evidence needed |
| --- | --- |
| Does yesterday matter? | Restart the process and replace the session; next context recovers the same active goal and next eligible step. |
| Did something change? | A unique successful action/event and corresponding persisted object/state, committed with progress. |
| Was a plan completed? | Every required step has eligible distinct evidence; dialogue-only success claims fail. |
| Who knows what? | Holder-specific receipts and attributed statement sources; uninformed residents receive no acquired fact. |
| Did collaboration happen? | Explicit membership and successful contribution receipts, not an invitation count. |
| Can residents change course? | Cancellation/withdrawal preserve prior evidence and free future eligibility. |
| Does it remain bounded? | Long synthetic timelines, full plots, saturated archives, blocked steps, replay/concurrency and all existing quota tests. |

A deterministic mock can prove lifecycle correctness, not that a real model writes meaningful stories. Any later owner-approved quality evaluation should review anonymized existing successful encounters for repeated questions, supported continuation, voluntary choice and attributable actions. Compare matched runtime/configuration/session/history conditions and distinct actual usage observations; missing usage remains unknown. No paid experiment is authorized here.

The first useful product milestone is deliberately small: **one resident forms a supported intention, acts on it across separate opportunities, completes it with evidence, and can recall the result after a restart.** Expand only after that cycle is reliable.

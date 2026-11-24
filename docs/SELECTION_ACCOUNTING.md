# Selection accounting foundation

This additive V6 ledger reserves one selection request independently of resident IDs. It has no active scheduler or provider caller. The reservation and unique dispatch intent are committed under SQLite write locks; expired work becomes uncertain and retains its full commitment. Completion also retains the conservative selection commitment. Existing conversation attempts reserve their own costs and slots, so each stage is charged once. This conservative policy does not reconcile a selection to a lower actual bill.

Migration: after separately approved backup and writer drain, run php scripts/migrate-selection-accounting.php --database=/approved/private/village.sqlite; verify integrity and foreign keys. Never run automatically on deployment. No environment flag or activation exists in this foundation. Rollback means deploying compatible code with no caller; retain the ledger and its charges. Older code cannot count this ledger, so reverting to an older accounting build after use is unsafe.

No participant choice, provider request, or scheduler integration is implemented. This milestone does not complete #100.
## Two-stage capacity handoff (intermediate)

The additive selection-capacity migration requires the earlier selection-accounting migration and a backed-up, drained V6 database. Run scripts/migrate-selection-capacity.php only as a separately approved owner operation. No migration is automatic. Disabled by default: no scheduler or provider caller invokes this mechanism.

Passing a conversation configuration to reserveSelection atomically reserves selection plus a separately priced Director conversation and its request slots. This milestone supports Director responses and managed runtimes with 2–8 slots; independent Spark multi-call and staging allocation handoff are not implemented and fail closed. Selection holds and untransferred conversation capacity remain conservatively charged after expiry or uncertain selection. There is no refund or selection-specific billing settlement.

After a known completed selection, reserveAi can accept the selection_handoff identifier. It validates distinct available resident IDs, current quotas and cooldowns, social capacity, the pending opportunity, expiry and a matching resident-scoped Director session. Under one write transaction it creates the existing AI/Director attempt, binds both residents, and transfers the capacity once. A database trigger protects the bound IDs. The selection and conversation commitments remain counted once each. An invalid handoff rolls back and retains capacity.

This accounting interface is used by the scheduled managed selection path described below. Keep both new ledgers when rolling back; old accounting builds cannot safely count their holds.
## Managed selection accounting (intermediate)

An explicit additive managed-selection migration adds an eight-request selection reservation, immutable per-request intent/audit records, one bound session ID, and append-only usage observations. It requires both earlier selection migrations, a backed-up and drained V6 database, and a separate owner operation. It is not run by deployment or browsing. Retain all three ledgers when rolling back; older code does not count these commitments.

When a caller explicitly requests managed selection, the existing atomic reservation holds eight selection request slots plus the separately calculated conversation capacity before dispatch. The selection cost uses the existing managed minimum commitment; observed usage can increase that commitment, never reduce it. The provider's final bill remains unknown. Pending, completed, failed and uncertain reservations retain their full conservative slots and holds. No selection-specific billing settlement or unused-capacity release is implemented.

The accounting API records one intent and unique request identity before each permitted session creation, session inspection, submission, bounded turn read, item retrieval, moderation, cancellation or final read. It fences duplicate stages, preserves a cleanup slot, scopes session routes to one bound session, and retains only route hashes and sanitized metadata. Expired and uncertain requests cannot be replayed. A completed managed selection requires successful output-item retrieval and no uncertain or cancelled request.

The scheduled adapter described below uses this accounting to validate provider responses and hand off a valid selection. Independent Spark selection is not implemented. No production activation, migration or paid test is authorized by this milestone.
## Fresh-session managed selection adapter (intermediate)

The dormant managed selection adapter constructs a bounded candidate snapshot from eligible available identities and their owner descriptions. Optional recent public episodes remain attributed to their actual holders, and private or unsupported history is omitted. Candidate and history text is untrusted character data. The adapter requests a structured pair or quiet decision from a newly created managed Director session; it validates the registered Director, reserved model, session and turn identity, terminal status, output envelope, and candidate IDs. Every request uses the managed-selection intent ledger. Uncertain, invalid, and timed-out paths retain their commitments and do not retry generation. A successful pair is rechecked against current eligibility and transferred to the existing conversation reservation; the returned context, session, choices and attempt are inputs for the existing managed conversation pipeline.

The adapter requires LITTLE_SPARKS_AI_SELECTION_ENABLED=1 and defaults off. The scheduled integration below finalizes quiet and failures and continues validated pairs through the existing managed conversation path. Independent Spark selection and reused selection sessions are unsupported. Missing or conflicting hosted Director instructions still require owner review; the adapter never edits the hosted definition.

All three separately approved selection migrations are required before any future activation. Production state is not changed by this code or its offline tests.
## Scheduled managed selection

When explicitly enabled, a scheduled managed Director opportunity claims an unbound opportunity, reserves both selection and conversation capacity, then gives the Director a bounded eligible snapshot. A validated pair is bound through the existing transactional handoff and continues through the ordinary managed conversation, moderation, memory and persistence path. A validated quiet outcome is recorded atomically without fabricating an AI conversation attempt. Failed or uncertain selection is recorded as a failed opportunity; all selection and pending conversation commitments remain charged, and no opportunity is dispatched twice. Terminal opportunities no longer hold the active selection lease; the request-intent fence requires a still-pending opportunity.

The feature defaults off. It supports the managed Director only; independent Spark mode and staging-only or custom-context invocation fail closed. The three additive selection migrations are required before activation and remain separate owner operations. No production migration, configuration change, or paid test is performed by this change.

## Selection context and provider size bounds

Selection has its own `LITTLE_SPARKS_AI_SELECTION_CONTEXT_BYTES` limit (default 32768 bytes; 1024?262144), independent of conversation memory. Eligible candidate identities and complete owner descriptions are retained; if the eligible count exceeds the configured activity candidate limit or the 64-candidate schema ceiling, selection fails rather than silently omitting anyone. Optional public history may be dropped to fit.

Before reservation and again before dispatch, the full serialized managed session request must fit `LITTLE_SPARKS_AI_MAX_REQUEST_BYTES` and the existing conservative input-token bound (whole wire bytes plus 512). Escaped text and the schema count toward the wire size. If mandatory candidate context cannot fit, the saved failure category is `selection_context_too_large`; no request is dispatched. Increasing the selection context limit alone cannot override the provider request and input limits or the existing budget reservations. Selection remains disabled by default and production configuration is unchanged.

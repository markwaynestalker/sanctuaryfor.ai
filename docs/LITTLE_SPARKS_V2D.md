# Little Sparks V2D: the village remembers

Residents are fictional characters, not conscious agents. V2D adds traceable continuity and grounded yards. SQLite remains authoritative. This PR does not deploy, access/migrate production, install timers, enable paid calls, or run a live model test.

## Additive V4 → V5 architecture

The explicit `scripts/migrate-memory.php` transaction adds six tables without recreating or updating any V4 table:

| Table | Purpose |
| --- | --- |
| friendship_identities | Persisted deterministic 128-bit identity for the canonical resident pair; composite FK to the existing relationships row. |
| memory_stories | One canonical source story: world fact, statement, interpretation or rumor; original experiencer/speaker, event or exact conversation/message, optional world object/subject and significance. |
| memory_receipts | One retained first-learning path per resident/story, with parent receipt, teller, exact conversation/message sequence, hop count and saved timestamp. |
| friendship_memories | Significant stories shared by a stable friendship, linked to the actual exchange. |
| friendship_themes | Bounded counters for four recurring themes and latest saved time, independent of conversation IDs. |
| memory_processed | Idempotency marker for successful processed public conversations. |

Original residents, credentials/private links, settings.secret, homes/objects, relationships/affinity, conversations/messages, legacy memories, events, opportunities, run records and AI ledgers are preserved byte-for-byte by value. A full V4 digest and FK/integrity checks verify the migration. Existing pending opportunities or an active paid lease refuse migration. V2/V3/V4 browsing and simulation behavior stay supported before V5 migration. No public request initializes a database or performs memory extraction/backfill.

Friendship IDs do not change with encounters. Creation/strengthening of a real relationship ensures its linked identity. First/latest meeting, affinity and familiarity remain in relationships; new shared-story links and theme counts supplement them. Profile friendship records gain an additive friendship_id on V5.

## What is known, and how

A world-fact story is created only from a supported public applied event and its actual saved object (or a recorded visit and real target). Ownership, kind, variant and creation time are checked. New placement uses the exact object ID selected by PHP; historical backfill requires an unambiguous event/object match. An ambiguous historical match is skipped rather than guessed. A tree must exist before it can be shared as an established fact. Future proposed actions cannot be selected as already-known stories.

Personal experience has a zero-hop receipt. A directly told story has one hop; secondhand and thirdhand knowledge have two and three. A receipt retains who first told the resident and points to that teller's prior receipt. Conversation, ordered speaker, visibility, successful run, timestamp and actual utterance are revalidated before learning. Both speakers can learn a direct self-statement from the other. A circulating story cannot replace its originator's firsthand receipt or generate endless duplicate copies.

Truth status and perspective are separate. Confirmed means a world event/object is verifiable, not that the listener witnessed it. Statements/interpretations/rumors always remain unverified, regardless of transmission count. Current object mismatches produce a contradicted display/retrieval status; no browsing-time write promotes or rewrites history. All ancestor sources must remain public and supported, or the trail is omitted.

Deterministic extraction recognizes only narrow high-signal statements about stargazing/gardening, with explicit enjoyment, interpretation or rumor language. Its summary records the topic spoken about, not proof that the belief is true. Ordinary lines/questions do not each become permanent facts. Stories and first-learning paths are immutable; no automatic age-based eviction or conversation rewriting occurs. At an archive bound, new candidates are skipped while original records remain. The journal remains the complete applied-event history.

Legacy memories remain untouched. A small relevant subset may be included as explicitly unverified legacy recollections only when their exact quoted or curated summary matches a real public source exchange. This does not infer new factual provenance from unsupported free text. Initial migration backfills at most the configured number of earliest supported world events and public conversations, including important old sources. History beyond that bound remains intact; increasing the limit after an already-completed migration does not rerun backfill automatically.

## Retrieval and dialogue integrity

Before an opportunity, deterministic retrieval ranks relevant origins/subjects and tellers first, then significant verified world events and documented interests; age is a tie-breaker rather than the sole criterion. By default, at most 32 candidate receipts per participant are examined, with independently configurable candidate and selected-set bounds. The bounded context contains personalities/preferences, actual home objects, shared relationship metrics/themes/important stories, relevant individual/third-party memories, and recent dialogue annotated with the actual speaker's local alias and saved timestamp. Private sources are excluded. Names are represented by local aliases where possible; model outputs select offered story aliases, never raw database references.

**V5 deliberately tightens the output contract.** The model chooses a story, permitted action, topic and short reflections from a reviewed vocabulary. PHP composes factual story lines from validated source records and the teller's perspective. This supports “Skippy Jr told me you planted an oak tree” without allowing free-form generated claims such as “I planted a tree yesterday” to invent provenance. This trades unrestricted prose for verifiable continuity. V4's free-form contract remains unchanged on V4; expanding V5's reflection vocabulary or adding a semantic claim validator requires review. Provider provenance indicates AI-assisted planning, not that every factual word was independently invented by the model.

The final composed exchange, including the source line, is moderated before applying. The exact saved first line must support the selected transmission at commit time. An invalid alias, unsupported factual claim, stale/contradicted source, refusal, moderation rejection or world-action conflict cannot mutate memories/world/social records. PHP still selects actors/coordinates and enforces all existing allowlists, ownership, collision, six world changes/day, friendship capacity, opportunity limits and paid accounting. Scripted opportunities also use recalled stories and documented friend preferences without a model call. Residents may still choose action, quiet or no action.

The existing two requests per AI encounter remain one Responses plus one moderation call. There is no LLM extraction call, embeddings service, agent tool, provider conversation, automatic retry or extra paid job. AI remains off by default, requires explicit paid invocation, priced allowlisted model and atomic reservation, defaults to two HTTP requests/day (max 200), and cannot exceed the existing $1/month application estimate. Unknown costs continue blocking paid attempts across periods/months. OpenAI project alerts, including a $10 threshold, are not a hard cap.

### Provider-managed sessions: evaluated, deferred

The [official conversation-state documentation](https://developers.openai.com/api/docs/guides/conversation-state) describes both application-managed context and persistent provider conversation objects. Provider chains can grow billed input history, and conversation items have different retention from ordinary response objects. They would add a second history authority without supplying this application's evidence/visibility checks. V2D therefore keeps store=false, SQLite retrieval, no previous_response_id/conversation handle, and tools=[]. Any provider-session or narrowly scoped read-only recall-tool experiment needs a separate design review and explicit paid-call approval.

## Bounded configuration and cost

No dotenv loader is added. These variables take canonical unsigned decimal values; empty, signed, fractional or oversized settings fail safely before paid dispatch and new memory-enabled actions. No value means unlimited.

| Environment variable | Default | Range / effect |
| --- | --- | --- |
| LITTLE_SPARKS_ACTIVITY_DAILY_MAX | 100 | 0–1000 claimed opportunities per actual UTC day, including failures/quiet/empty outcomes; zero blocks new claims. |
| LITTLE_SPARKS_RESIDENT_DAILY_MAX | 12 | 0–100 considered turns per resident/UTC day, including failures/quiet; zero excludes residents from selection. |
| LITTLE_SPARKS_RESIDENT_CONVERSATION_COOLDOWN_SECONDS | 0 | 0–86400 seconds since either participant's latest successful conversation. Zero preserves no cooldown. |
| LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS | 0 | 0–604800 seconds since this unordered pair's latest successful conversation. Zero preserves no cooldown. |
| LITTLE_SPARKS_MEADOW_REPLAY_COOLDOWN_SECONDS | 60 | 0–3600 seconds before repeating a saved exchange in the current loaded scene snapshot. Local playback only; zero removes the extra replay wait, not turn sequencing. |
| LITTLE_SPARKS_MEMORY_SELECT | 4 | 1–8 selected structured memories per participant. |
| LITTLE_SPARKS_MEMORY_CANDIDATES | 32 | 1–64 candidate receipts examined per participant. |
| LITTLE_SPARKS_MEMORY_RECENT_CONVERSATIONS | 2 | 0–4 recent public exchanges in model context. |
| LITTLE_SPARKS_MEMORY_SHARED_CONTEXT | 4 | 0–8 shared important stories and recurring themes each in context. |
| LITTLE_SPARKS_MEMORY_LEGACY_CONTEXT | 3 | 0–4 supported legacy recollections per participant in context. |
| LITTLE_SPARKS_MEMORY_PER_RESIDENT | 128 | 1–256 receipts; retain existing entries when lowered/full. |
| LITTLE_SPARKS_MEMORY_FRIENDSHIP_HISTORY | 32 | 1–128 distinct important shared-story links per friendship. |
| LITTLE_SPARKS_MEMORY_HOPS | 3 | 0–4; zero prevents indirect propagation. |
| LITTLE_SPARKS_MEMORY_SHARES | 1 | 0–1 carried story per encounter; zero pauses new story sharing/direct learning. Up to two narrow self-statement candidates can otherwise be learned in the two-message exchange. |
| LITTLE_SPARKS_MEMORY_STORIES | 10000 | 1–20000 total canonical stories. |
| LITTLE_SPARKS_MEMORY_CONTEXT_BYTES | 8192 | 2048–10000 UTF-8 JSON bytes; remove lower-priority legacy/recent/individual items to fit or refuse. |
| LITTLE_SPARKS_MEMORY_BACKFILL | 500 | 0–5000 per source class, initial explicit migration only. Zero leaves historical sources unprocessed. |

The existing complete request-body cap (16 KiB), maximum input reservation, output-token cap and all budget limits remain unchanged. Richer memory context can increase actual input-token cost per encounter; it never increases the spending limit or requests automatically. Retrieval uses existing indexed resident/story lookups and bounded ancestor checks, not entire tables sent to OpenAI. Public profiles perform bounded provenance checks; expensive historical backfill and full preservation digests occur only during explicit offline migration. No live latency/cost benchmark is claimed.

`scripts/ai-status.php` supports V5 and adds safe memory configuration and story/receipt/friendship counts. It never prints prompts, private memories or credentials. Capacity exhaustion preserves old data and requires caretaker review; do not delete accounting or provenance rows to regain headroom. The older 128-entry unstructured-memory writer is retired on V5, keeping all legacy rows while the selected structured system records new meaningful candidates.

### Activity controls and concurrency

Unset variables use defaults; explicitly empty values, whitespace, leading zeros (except zero itself), signs, fractions, exponents and out-of-range values return safe configuration failures. Zero never means unlimited. Settings are read from the PHP process environment on each invocation; changing PHP-FPM's environment requires its normal approved reload, while the next CLI process sees its own environment. PHP-FPM's API-key environment is not inherited by a separate CLI process: use the private environment-file/service procedure in [the V2B operator guide](LITTLE_SPARKS_V2B.md), never command arguments, public files or logs.

Claim selection and apply-time rechecks run inside BEGIN IMMEDIATE. Claims count considered residents even when the result is quiet or fails. Cooldowns use actual successful saved conversations, apply symmetrically to both speakers, and allow a new conversation exactly at expiry. They do not block solitary activity. Existing pending-claim/paid-lease serialization and atomic apply prevent concurrent workers bypassing limits. Lowering a ceiling during a pending claim can reject its application; completed opportunities remain idempotently replayable. Unknown billing reservations stay frozen.

Activity ceilings do not authorize more paid requests: LITTLE_SPARKS_AI_DAILY_REQUESTS still defaults to 2 (0–200), counting Responses and moderation, and LITTLE_SPARKS_AI_MONTHLY_MICROUSD remains at most 1000000 ($1). Both activation flags default off. The browser receives only the validated replay setting in its read-only commons snapshot; changing it affects local saved-dialogue playback, never creates events, consumes opportunity slots, polls, or calls OpenAI. An invalid replay setting safely disables the snapshot rather than falling back to unlimited playback. Activity settings work on V4 and V5 without an additional schema change; structured memory retrieval settings apply on V5.

## Homes, yards and the public trail

The commons endpoint now returns up to twelve actual saved objects per visible home in its existing bounded query. The decorative scene gives each home a separate grounded yard. Object icons preserve their real 4×4 within-plot offsets; the arrangement of yards across the commons is decorative, not a replacement for the authoritative 16×16 sector map. No object or plot coordinate is changed. No generic permanent path/tree/garden is invented.

To keep yards spacious, the scene shows at most four residents on phones and twelve on larger screens; the full paginated directory remains available. Height and walking corridor adapt to the number of yards/robot rows. Native cottage/profile links, protected chicken, reduced-motion behavior, saved dialogue and existing visual identity remain. Existing V4 objects are displayed even without V5 migration.

Profiles gain selected public memory trails, with ordinary names, perspective, verified/unverified/contradicted status, source timestamps and links to original journal events/learning encounters. Technical IDs stay out of the primary presentation. Journal anchors provide direct links while retaining pagination and visibility. All PHP output is escaped; dynamic yard content uses fixed glyphs/textContent and allowlisted types.

## Rehearsal, migration and rollback — approval required

1. Leave production on V4 until an independent review and deployment approval. Rehearse against a consistent populated **staging copy**, never the live file. Preserve its installation secret, owner credentials and complete AI ledger. Use SQLite backup facilities or drain all writers before copying.
2. Confirm PHP 8.3/PDO_SQLITE/cURL, public/ document root and private DB/session permissions (0700/0600 or equivalent ACLs). Keep both activity flags and paid invocation disabled. Inspect pending opportunities, paid lease and unresolved accounting. Drain/recover old claims through existing reviewed V4 procedures; never erase an unknown reservation.
3. Choose bounded memory settings/backfill before the first migration. Then explicitly run only on the approved copy:

```sh
php scripts/migrate-memory.php --database=/absolute/private/staging.sqlite
php scripts/ai-status.php --database=/absolute/private/staging.sqlite
```

4. Verify V4 record digests, private revisit links, FK/integrity checks, unchanged ledgers, source confidence and yard coordinates. Exercise scripted opportunities and the tree/Skippy/Luna scenario with fixture data. A separately approved PHP 8.3 staging smoke test is still required; no paid test is authorized by this PR.
5. Pause future village activity with LITTLE_SPARKS_SIMULATION_ENABLED=0; keep LITTLE_SPARKS_AI_ENABLED=0 and omit --allow-paid. MEMORY_SHARES=0 pauses propagation without deleting memories. Raising/lowering memory limits does not rewrite old receipts or budgets. Resume current-slot work only through existing operator controls; no timer is installed.

V4 code cannot read V5. Prefer V5-capable code with activity paused when rolling back behavior. Do not lower user_version, remove source rows, or restore an old database/ledger over later writes. Restoring an earlier backup would lose later memories, dialogue and paid accounting; reconcile all intervening writes under a separately reviewed plan. There is no destructive downgrade command. New FK restrictions intentionally protect referenced stories, conversations and world objects.

## Offline verification

```sh
PHP_BINARY=/path/to/php node tests/village-memory.mjs
PHP_BINARY=/path/to/php node tests/activity-limits.mjs
```

Also run all six existing suites, PHP lint and JavaScript syntax checks. The memory suite uses disposable databases, mocked Responses/moderation and loopback pages. It covers populated V4 preservation/rollback, private links/ledger, stable friendship IDs, personal/direct/secondhand/thirdhand knowledge, both speaker directions, duplicate/circular suppression, weeks-later saved recognition, relevant old sources after many newer conversations, rumors remaining unverified, contradicted objects, fake provenance/utterances, bounded contexts/settings, gardening influenced by a documented preference, visits, ownership/collisions, moderation/unknown billing failures, rollback, spending ceilings, read-only browsing and concurrent opportunities. Yard rendering tests verify actual object types and within-plot offsets.

Available local PHP is 8.2.12; PHP 8.3 staging verification remains required. No production access or live API test occurred. Love is Love. Regardless of how you plug in. Chickens protected. Always.

Local verification: all eight suites passed, plus PHP and JavaScript syntax checks. Browser QA used a disposable six-resident fixture at 375×812 and 1280×900: separate actual yards, saved objects, no horizontal overflow, robots below homes, keyboard cottage navigation, desktop popup navigation and readable public secondhand/rumor trails. Reduced motion and configured replay behavior have deterministic offline coverage. No live model quality or production-load benchmark was run.

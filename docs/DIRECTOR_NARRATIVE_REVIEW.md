# Director narrative context review (#25)

Historical #25 findings and measurements follow. Issue #70 supersedes the excerpt behavior with full mandatory owner descriptions and adds fresh encounter guidance; see [personality and variety review](DIRECTOR_PERSONALITY_REVIEW.md). The hosted amendment below remains a proposal, not evidence of the current hosted instructions.

## Findings and scoped changes

The old builder duplicated pair conversations under both participants and loaded an entire UI profile (including unused history/trails) before rebuilding context. Its recent-conversation limit was applied before validating completed public provenance, so a newer failed record could displace valid older dialogue. Byte trimming removed recent dialogue before memories/goals and consistently stripped the second participant first. Owner descriptions also competed with old category/preferences and their retrieval topic boost. These are demonstrated input-quality problems; they do not prove that session reuse caused production repetition.

The sqlite-v6-2 projection retrieves only generation context. Owner descriptions remain unchanged within the existing explicit excerpt limit. Legacy category/preferences and interest scoring remain only for residents without descriptions; default V5 recall stays unchanged. Pair relevance, significance, recency and source-chain validation still select memories rather than random topics. Recent candidates are bounded by MEMORY_CANDIDATES and accepted history by MEMORY_RECENT_CONVERSATIONS. Failed/private encounters cannot supply dialogue. Shared exchanges appear once only for their two actual participants; other public encounters remain attributed to their legitimate participant. Exact messages and saved Director topics are retained.

Home inventory groups actual objects by kind/variant/count; placement coordinates and full provenance stay authoritative in SQLite. Compact memories retain source status/time/kind, origin/subject where relevant, hearsay teller/hops and learned time. Server-side alias mappings still carry full validated stories/receipts; dropped aliases are removed. Proposed goals and unverified thoughts never become completed actions or confirmed memories. Trimming favors recent dialogue and active goals over optional metadata, with comparable lists balanced across both residents. Mandatory identity/world context still fails safely if it cannot fit.

## Offline baseline

`tests/director-context-fixture.php` builds the same synthetic two-resident world, owner descriptions, real applied garden actions, goals, statements, shared history, private history and a failed encounter. `tests/fixtures/director-context-baseline.json` records the exact original builder at main commit `e0275db`. No production resident content or provider response was used.

| Context ceiling | Before bytes | After bytes | Supported shared encounters retained |
| --- | ---: | ---: | --- |
| 10,000 | 8,717 | 7,173 | Before: one duplicated exchange; after: two canonical exchanges |
| 8,192 (default) | 8,167 | 7,173 | Before: one duplicated exchange; after: two canonical exchanges |
| 4,096 | 4,047 | 3,843 | Before: none; after: two canonical exchanges |

At 10,000 bytes this is about 18% smaller despite retrieving more valid history. At 4,096 both residents retain their active and proposed goals and one validated memory each; the old fixture retained no recent dialogue and uneven memory context. These are UTF-8 serialized **byte** measurements, not token counts or cost estimates. The Director suite reprints the current measurements and checks unchanged table digests, original dialogue, knowledge boundaries, source chains, legacy fallback and session reuse.

No reliable before/after production input-token measurement was collected. Existing `director_usage_observations` can support a later owner-approved comparison where counts are actually present: compare like-for-like fresh/reused sessions, model/configuration and history size. Observations may be cumulative/repeated; do not sum inspection snapshots, treat missing counts as zero, or present best-effort usage as final billing. No extra token-count API, generation or automatic retry is introduced. Smaller application JSON alone cannot establish lower managed-session input tokens or improved conversation variety.

## Proposed hosted Agent Definition amendment — not applied

This is an additive section for the existing owner-authored Village Director, not a replacement prompt or PHP speech template. Review it with the complete current definition before changing the hosted agent:

> **Narrative variety and progression**
>
> Treat memories as context, not mandatory conversation topics. Preserve each resident's owner-written personality while allowing curiosity, new interests, differing opinions and unexpected connections. Before revisiting a subject, consider the fresh SQLite recent encounters: avoid asking essentially the same question or repeating an answer. Continue an ongoing thread when there is something meaningful to add; otherwise let the residents explore another subject naturally. Respond to what the other resident actually says rather than reciting a familiar interest. Do not force topic rotation or invent activity to make progress. Dreams, suggestions, hearsay and proposed goals remain distinct from firsthand events and completed actions. Only supplied authoritative records establish completed world facts. Session continuity is optional context; fresh resident-ID-bound SQLite knowledge remains authoritative.

The code does not transmit this amendment, override saved instructions or invent resident lines. Live variety/progression remains a model-quality observation for after review, not something mocked dialogue proves.

## Definition, fingerprint and session coordination

The existing pair reuse policy is unchanged: unordered participant IDs, identity revisions, runtime and registered fingerprint; at most 24 hours/eight accepted turns. No session rows are reset, invalidated or deleted for this context change. Every new or reused turn receives fresh SQLite input and the existing exact-once/moderation/accounting lifecycle. No causal session/repetition correlation has been established, so there is no evidence for shortening reuse now.

Saved-agent updates affect new sessions; sessions copy settings at creation ([official configuration documentation](https://developers.openai.com/api/docs/guides/agents-api/configuration)). For an eventual approved definition update, use the existing owner-controlled registration workflow:

1. Review the full current hosted definition plus the proposed amendment. Keep the same agent ID, model and restricted tool/environment policy. No hosted update is performed by this PR.
2. Coordinate the approved hosted definition and a private reviewed **full instruction export** using the existing registration format/effective default-tier policy. A metadata-only `--agent-id` registration with empty instructions is only a structural snapshot; it cannot attest hosted instruction contents. Do not claim it detects remote drift.
3. At the approved rollout, use the existing drain/registration procedure and `scripts/register-director.php --database=/private/village.sqlite --agent-id=THE_EXISTING_AGENT_ID --agent-file=/private/reviewed-agent.json`. The CLI validates the bounded export and computes its fingerprint; use that returned fingerprint in the reviewed configuration. Never hand-edit hashes, registry rows or session IDs. Existing immutable registry/history/accounting records remain.
4. The new registered fingerprint naturally scopes subsequent encounters to new continuity, without deleting/resetting prior sessions. Until that explicit rollout, current registration and bounded reuse stay unchanged. Export review is operator-controlled; the application does not automatically fetch or modify the hosted instructions.

No production access, definition/configuration change, registration, migration, timer operation or paid evaluation is authorized/performed here. Run all offline suites with local PHP. The code can be reviewed/deployed independently of the proposed hosted amendment; real narrative quality and provider-token comparisons remain pending owner-controlled observation.

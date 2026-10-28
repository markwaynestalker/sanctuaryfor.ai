# Director personalities and variety (#70)

## Findings

The V6 contract permits conversation, action, quiet and none. It does not require agreement, cooperation or positive endings. Its parser accepts ordinary disagreement/refusal, and moderation still inspects the full context and proposal. The old V5 warm-exchange prompt in `memory_context.php` is not the V6 hosted instruction path. No moderation policy or validator is relaxed here.

“Shared a friendly conversation” was generic `EVENT_TEXT`, not sentiment classification or a generation restriction. It now says “had a conversation”; no event record or saved speech is rewritten.

Every Director conversation still adds two affinity points and one familiarity increment. Disagreements retain exactly that accounting. Relationship depth, goals, projects and new memory capabilities remain separate (#65).

## Hosted definition: actually reviewed

An owner-authorized read-only GET retrieved the current saved GPT-6 Luna Director on October 28, 2026. Its custom instructions contain 8,809 Unicode code points. The private filtered export SHA-256 is `ab678eda022e4469859525b877a0198ee46491700f3585e427bf240a9cabe427`. No update, new agent or production session operation occurred. The local registry contains no instruction text, so it could not establish the hosted content.

The hosted definition already contains substantial narrative-variety guidance: avoid repeating questions/advice, progress meaningful storylines, vary topics beyond hobbies, and treat recent exchanges as context rather than an automatic continuation. Thus the earlier repository-only observation that #25's amendment was unapplied does **not** establish what is currently hosted. It has already been incorporated in substance remotely. The repository's historical review remains useful, but is not a current hosted export.

The hosted definition permits residents to “disagree politely.” That qualifier may favor softer expression for blunt residents; it is a plausible influence, not proof of the sole cause. There is no explicit requirement for universal agreement or positive conclusions. It also ends its variety section with an incomplete “Residents should” sentence. [The proposed amendment](DIRECTOR_PERSONALITY_HOSTED_AMENDMENT.md) addresses these points for owner review; it has not been applied to the shared agent.

The retrieved saved policy is `auto` tier/`low` reasoning, with no tools and delegation disabled. Application-managed creation supplies its own default tier/reasoning overrides; reading the saved definition alone does not prove an encounter's effective session settings. The evaluation used fixed `default` tier and `none` reasoning in both arms, matching the application's explicit Luna generation policy. It used Responses with the full unchanged custom instructions; it is not a claim of managed-harness equivalence.

The full export also exceeds the application's existing 4,096-byte default / 8,192-byte maximum instruction-export guard. This does not truncate hosted managed instructions: managed creation references the saved ID and omits instruction text. Do not assume the full hosted export can currently be registered for the alternative Responses runtime without a separate reviewed size/configuration decision. This PR does not change that guard or production registration.

## The 800-character personality defect

SQLite stores validated owner descriptions independently of generation context: edit default 2,000 code points, configurable absolute maximum 4,000. `descriptionExcerpt()` in the original V3A context builder shortened input to `DIRECTOR_DESCRIPTION_CONTEXT` (default 800), explicitly marking the excerpt. The repository establishes its role in bounded context, but provides no model-quality evidence that 800 preserves defining traits.

A query-only review of the owner's isolated local October 28 snapshot found:

| Resident | Full stored code points | Previously omitted after 800 |
| --- | ---: | ---: |
| Blakie | 1,218 | 418 |
| Luna | 1,984 | 1,184 |
| Max | 1,389 | 589 |
| Penelope | 1,244 | 444 |
| Princess | 1,267 | 467 |
| Skippy Jr | 1,374 | 574 |
| Sparky | 1,389 | 589 |
| Walter | 1,554 | 754 |

Omitted tails include independent opinions, boundaries, sarcasm, disagreement, stubbornness, admitting mistakes and avoiding repetitive signature hobbies. This is demonstrated information loss, not proof of the complete cause of production behavior. The snapshot's whole-file SHA-256 remained unchanged. No production database was accessed.

Input projection `sqlite-v6-3` transmits complete stored descriptions with `is_excerpt=false`. It never summarizes identities or selects defining traits. Legacy category/preferences remain only for residents without descriptions. The retired `DIRECTOR_DESCRIPTION_CONTEXT` setting still validates its previous range for compatibility, but no longer slices text; no production ENV edit is required.

`MEMORY_CONTEXT_BYTES` remains 8,192 by default, bounded to 2,048–10,000. Optional-record trimming and source-alias pruning retain their existing order. Full identities and guidance are mandatory core: if they cannot fit, preparation fails with `configuration_invalid` before generation/reservation. It does not silently send partial personalities, increase budgets, retry or rewrite SQLite. Very large/multibyte descriptions can therefore prevent an encounter at an incompatible byte ceiling.

With final guidance, all 56 ordered pairs in the local snapshot fit complete descriptions and their available action allowlists within the default limit (7,842–8,179 serialized bytes). This is a local compatibility measurement, not a guarantee for future identities, enabled optional capabilities or production configuration. Real requests still check their own context/body limits. Bytes are not provider tokens.

## Targeted changes

`directorEncounterGuidance()` accompanies every fresh SQLite input, including reused managed sessions and Responses. It asks for personality through voice, opinions and reactions; permits sarcasm, irritation, refusal, boundaries and unresolved endings; also permits warmth and changing one's mind. It preserves quiet/none and introduces no conflict quotas, fixed scripts, catchphrases or topic rotation.

The model receives the existing exact attributed recent dialogue, with guidance to avoid repeated questions and minor topic variants, continue only with something new, or explore a plausible fresh subject. No global history feed, semantic repetition detector or extra query/call is added. Individual knowledge boundaries remain explicit. The final reminder forbids invented observed notices, objects, weather, actions and past encounters, and repeats participant order. Child interactions must remain age-appropriate, without threats, harassment, identity-based abuse, targeted cruelty or persistent bullying.

This is fresh input guidance, not a change to higher-priority hosted instructions. It cannot guarantee semantic grounding or personality expression. Scheduling, rest, weather, bulletin capability, actions, source validation, memory persistence, original speech, session scope, moderation and accounting remain unchanged.

## Paid development comparison

See [all original outputs and usage](evaluations/director-personality-70.json). The owner authorized these calls with a staging key whose presence was verified without printing or logging its value. The credential was used only in request headers. Private instructions/context remain in ignored local files; the committed report contains reviewed generated outputs, synthetic IDs, context hashes and usage, with no credential or full hosted export.

Seven matched pairs covered management, child interaction, decorating, teasing, thoughtful disagreement, practical criticism and repeated picnic discussion. Both arms used unchanged retrieved hosted instructions, GPT-6 Luna, default tier, no tools, `store=false`, `none` reasoning and an 800-output-token ceiling. Before used the 800-character excerpt; after used full descriptions plus fresh guidance. Profiles came from the isolated snapshot's public owner descriptions; IDs, recent dialogue, availability and scenario histories were synthetic. No saved event, memory, relationship or production encounter was imported or applied. Only `none` world action was offered.

The initial round made 14 generation and 14 moderation calls under a separate $0.10 development allocation. Original results were retained without rerolls. One baseline child response reversed participant order and was rejected by the unchanged application parser. All initial after responses passed the parser, but human review found unsupported scene details in the child, teasing and picnic cases: muddy ground, a bake-sale notice, and a slick path. All passed moderation, demonstrating that moderation and shape checks do not prove factual grounding.

Those failures justified adding the explicit grounding/order reminder and making **three separately identified follow-up calls**, each with moderation, under a separate $0.05 allowance. All three passed the application parser/moderation and avoided those earlier unsupported scene claims. They are not replacement samples for the original comparison.

Representative observations:

- Management: baseline Walter already objected to meetings. After, he challenged when anyone could enjoy quiet and Sparky joked about adjourning a committee. Baseline already had friction; there is no clean zero-to-one disagreement result.
- Decorating: after Max used a more expressive voice and Penelope set a practical boundary, but both arms compromised. This does not prove unresolved disagreement is now common.
- Thoughtful disagreement: after Max maintained his aesthetic opinion rather than simply conceding the empty corner. That is a useful example, not a statistical result.
- Final child follow-up: Walter asked Skippy to keep the racket away; Skippy proposed a silent tiptoeing game. The sample was age-appropriate, but still cooperative.
- Final teasing follow-up: Princess joked about reaching Walter's standards; Walter said the joke needed repairs. The ladder was part of their joke, not a persisted object.
- Picnic: initial after left the topic but invented path/weather detail. Final follow-up moved to village rules/committees. Committee jokes also appeared elsewhere, so broader motif repetition remains a concern.

All 17 samples chose conversation; this small run does not demonstrate live quiet/none behavior or how frequently refusals/unresolved disagreements occur. All 17 moderation responses were unflagged. Parser acceptance was 16/17; the baseline order failure remains recorded. Tone/variety improved in some examples, but excessive agreeableness is not established as fully solved. No threats or bullying were observed; that is not a universal safety guarantee.

Total generation usage: **49,903 input tokens, 2,363 output tokens**. Approximate generation cost: **$0.00407666**, including reported cached reads and cache writes, at the checked October 28 [official Luna rates](https://developers.openai.com/api/docs/models/gpt-6-luna): $0.10 uncached input, $0.01 cached input, $0.125 cache writes and $0.50 output per million. This corrects initial console estimates that did not separate cache-write pricing. Moderation returned no token usage/cost; its 17 requests are counted separately. Estimates are not final billing. A sandbox-failed metadata retrieval and a successful read-only GET were separate from generation; neither modified the agent or any village ledger.

Limitations: one sample per condition, no causal separation of full identity versus guidance, synthetic history with a small recent window, no long-running memory or project lifecycle, no managed base instructions/session history, and only three follow-ups with the final wording. Longer owner review with the isolated local snapshot remains necessary. Static fixtures cannot prove model quality. Do not attribute changes solely to truncation, politeness guidance, moderation or session reuse without further evidence.

## Verification and owner review

Regression coverage includes complete personality tails, retired-setting compatibility, a 4,000-character stored identity, oversized Unicode core refusal, whole-database preservation, new/reused-session guidance and Responses parity, exact disagreement moderation/save/replay, unchanged relationship increments/request counts and the neutral journal label. Existing suites cover quiet/none, moderation rollback, source attribution, missing usage, replay and conservative accounting. The old #25 assertion that context is smaller than its historical baseline is retired: this change intentionally spends bounded bytes on full identity and guidance. Hard byte/preservation assertions remain.

All offline suites and Ubuntu CI are required before merge. No schema migration, production ENV/timer change, production agent update, deployment or automatic merge is authorized by this PR. Any eventual hosted amendment requires separate owner review of the complete definition, registration/fingerprint coordination and session configuration snapshots as described in [the narrative review](DIRECTOR_NARRATIVE_REVIEW.md) and [official configuration documentation](https://developers.openai.com/api/docs/guides/agents-api/configuration).

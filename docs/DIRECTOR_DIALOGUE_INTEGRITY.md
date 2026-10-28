# Director dialogue integrity (#32)

## Findings and limits of the evidence

The current V6 path does **not** slice resident speech to 160 characters. It rejects overlong output before moderation/save. The copied examples in #32 are evidence of unfinished saved wording, not copies of the original provider item JSON. No matching provider output was supplied for these three encounters, and development made no production/API requests. Consequently this investigation cannot establish whether those historical endings came from the hosted model, its schema-constrained generation, earlier code, or an earlier import. Do not label that cause proven or repair the stored messages.

The issue's exact Luna/Max/Penelope quotations each contain 160 Unicode code points, but 164/162/166 UTF-8 bytes respectively. Offline fixtures send those exact words through extraction, validation, moderation, SQLite and public retrieval and prove unchanged output. Penelope's final `你` is a valid complete Unicode character, not a split UTF-8 byte sequence. These reproductions show how the current application handles the supplied text; they do not establish what the historical provider actually returned.

SQLite is not just an unconstrained TEXT column: `app/schema.php` creates `messages.body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 160)`. SQLite rejects excessive strings; it does not shorten them. No production migration or history rewrite is justified by the present evidence.

## Full pipeline audit

| Stage | Boundary / behavior | Does it shorten resident speech? |
| --- | --- | --- |
| Hosted saved Director | Owner instructions plus supplied encounter context; not edited by this PR | External behavior cannot be reconstructed from stored speech alone |
| `contracts/director-v1.schema.json` | Message text 1–160 characters; notes/transmission quotes also at most 160; topic 64 | A generation constraint, not a PHP substring. It does not establish grammatical completeness |
| `directorSchema()` / `prepareDirectorEncounter()` | Same advisory complete-sentence/usually 100–130 guidance sent to new and reused sessions | No. This PR emphasizes rephrasing an entire long thought before returning it |
| `managedDirectorPrototype()` | Sends the actual output schema; no unsupported `spend_control` or `max_output_tokens` fields | No |
| `directorRequest()` (Responses alternative) | Same schema; configured `AI_MAX_OUTPUT_TOKENS` (default 512) | Provider token budget can stop a response. It is not a character budget |
| HTTP transport | Configured request byte limit (default 16384); response ceiling 262144 bytes; bounded execution/lease/request envelopes | Rejects oversized/failed requests/responses; never saves a prefix |
| `stagingItems()` | Completed, turn-bound assistant answer only; at most 20 items, complete page, one final candidate; text parts joined exactly, maximum 4096 bytes of proposal JSON | Rejects ambiguity, incomplete status, pagination or excess bytes; never trims an item |
| `decodedAiProposal()` | Completed Responses envelope, one output text, at most 4096 JSON bytes | Now also rejects supplied incomplete message status or non-null `incomplete_details`; never repairs partial output |
| `parseDirectorProposal()` / `safeAiText(...,160)` | Ordered resident IDs, 1–160 Unicode code points, valid UTF-8, forbidden markup/controls/secrets/URLs, no leading labels or whitespace | Rejects, never clips, normalizes, prefixes or invents resident words |
| Moderation | Entire original proposal and bounded context | Rejection produces no saved dialogue or replacement |
| `applyActivity()` | Binds original strings directly into parameterized `INSERT INTO messages`; SQL ceiling is 160 | No substring/truncation. New integrity check compares both saved rows byte-for-byte to the validated provider messages before commit |
| `saveDirectorMemory()` | At most two structured unverified notes; quotes must literally occur in the saved speech; 160-character quote SQL constraint | No speech rewriting or promotion to confirmed world facts |
| `commons()` endpoint | Reads full message bodies, filtering unsupported lengths 1–160, visibility/success and speaker identity | Does not shorten bodies. Its `substr(...name,1,64)` is for names, not speech |
| Journal / resident profile | Read full saved bodies, escape with `htmlspecialchars` | HTML escaping changes encoding in markup, not the displayed words |
| `commons.js` / `village.js` | Validates 1–160 code points using `Array.from`; uses `textContent`, shows turns sequentially, wraps speech, remeasures each new bubble | No substring, ellipsis or line clamp applied to dialogue. Stationary transcript retains full speech under reduced motion |

Under #70, complete owner descriptions are mandatory context (`is_excerpt=false`). `trimDirectorContext()` drops whole optional context records under byte pressure; it does not shorten descriptions or retained quotes/dialogue. A core payload that cannot fit is refused before dispatch. Neither changes SQLite or generated sentences. Legacy V2–V5 providers use 120-character free lines, 128-character prefix-validation allowance, and at most 160 after an optional V5 factual opening. Those older adapters validate/reject and may remove a leading A:/B: label; the V6 Director path never uses those speech transformations. Memory summaries/quotes have their own 160-character storage bounds.

## Deterministic handling policy

* Valid, completed, moderated, in-bounds text is stored verbatim, including a line exactly at 160. Boundary length alone is not proof of truncation.
* Overlong, invalid UTF-8, markup/control-bearing or explicitly incomplete API output is rejected. The encounter stays silent, history is untouched, full existing accounting commitments remain, and there is no automatic paid regeneration.
* Syntactically valid text may still be an unfinished thought or unusual mixed-script wording. PHP cannot reliably judge that from punctuation or its last word. Such text is not repaired, padded, translated or rejected solely by a brittle grammar/English-only heuristic. For example, “Perhaps”, a question, an ellipsis, a quoted sentence and an ending emoji can all be intentional. This PR does **not** promise a grammatical-completeness detector.
* Model guidance asks for complete natural thoughts, usually 100–130 characters, and whole-sentence rephrasing rather than cutting to fit. It is advisory, not a guarantee; actual quality needs observation of future authorized encounters.

OpenAI documents incomplete outputs as a separate case from structured schema validity; managed message items explicitly expose `completed`/`incomplete` status. Schema compliance alone does not prove natural sentence completion. Sources: [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [managed turn items](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/subresources/turns/subresources/items/methods/list). No API evaluation was performed.

Unicode limits here count code points, consistently with the JSON schema, PHP's UTF-8 regex, SQLite `length()` and JavaScript `Array.from`. They are not UTF-8 bytes or perceived grapheme clusters. `é`, a combining accent sequence and an astral emoji have different byte/code-point counts; none is split or normalized. Existing forbidden control characters (including zero-width joiners) remain rejected rather than stripped. Multibyte Chinese text is not corrupt UTF-8 merely because it looks unexpected in an English sentence.

## Why the ceiling remains 160

Increasing to 240/300 might give the Director more room, but would require a preserving migration of message/quote constraints plus consistent changes to schemas, context retrieval and replay validation, and wider/taller/longer-lived bubble assessment. It would increase context/moderation/output volume and would not prove that a generation-side cutoff is fixed. Keep the existing ceiling and concise guidance until matching raw provider output establishes whether a larger limit is useful. The 160 maximum is a disclosed schema constraint, not a silently lower override of financial ENV settings; all daily/monthly/token/execution budgets are unchanged.

## Private forward evidence, not a new world memory

Each newly accepted Director conversation gets `settings.name=director_dialogue_integrity:<run_id>` inside the same SQLite transaction. It records conversation ID, per-speaker ID/sequence, code-point and byte counts, boundary flags and SHA-256 hashes of validated provider text and stored text. Both hashes must agree or the entire conversation/action/memory transaction rolls back. No raw speech, credential, prompt, private memory, provider-wide factual assertion or billing conclusion is added to this receipt. It does not claim grammatical completeness, nor compare original wire JSON escaping. Existing conversations receive no backfill.

The private read-only `scripts/ai-status.php` report adds the latest ten under `director.dialogue_integrity`; public pages/APIs and model memory retrieval never expose/use these diagnostics. Use the existing owner status procedure and protected environment. No new ENV key, migration, session reset, paid probe or scheduler change is needed.

For a future suspicious new line, compare this receipt with its already-available original provider **final item** for the same attempt/turn. Equal extracted-text/saved hashes rule out a PHP/SQLite cut for that encounter; a matching API item ending abruptly points upstream, without proving which model/configuration behavior caused it. An old encounter without a receipt remains unknown. Never replay it or treat a trace as verified billing evidence.

## Offline coverage

`tests/director-truncation-core.php` runs through the existing mocked Director suite: natural complete/exact-160/161-character messages, accent/combining/emoji boundaries, invalid UTF-8/markup, API incompleteness, unchanged unfinished wording, exact moderation/save/public replay text, retained historical rows/schema, no retry and a simulated storage truncation trigger that must roll back. Existing moderation and memory/provenance tests remain mandatory. `tests/commons-ui.mjs` verifies complete 160-code-point text through desktop/mobile playback and reduced-motion transcripts. These are mocked DOM/text checks, not proof of physical-device rendering or live generative quality. Run `ops/ci/offline.py` and require Ubuntu CI before review.

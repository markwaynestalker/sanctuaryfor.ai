# Village Conversation Archive (#38)

`/conversations.php` is a read-only historical reader, separate from the animated commons' latest-per-pair replay. `/api/conversations.php` exposes the same bounded public projection as JSON. Neither route starts a PHP session, creates cookies, calls a provider, claims an opportunity, loads credentials, or changes the database. They set `PRAGMA query_only=ON`; no schema migration or new ENV is required. The Director, accounting, memories, scheduling, commons API and journal are unchanged.

## Public projection and query behavior

Every exchange needs a public conversation, a completed simulation run, two ordered distinct speakers linked to their actual conversation participants, and a matching public conversation/quiet-time event for the same run and pair. Legacy social keys must match the exact conversation ID; modern keys still match run and both participants. Multiple conversations per run remain supported. AI attribution comes from ai_conversations, not the run/model; a scripted exchange in an AI run stays scripted. V6 topic metadata is joined only by matching conversation **and** run IDs. No private memories, hints, prompts, sessions, provider IDs, accounting or management credentials are selected.

Count and page selection share a SQLite read transaction so totals/rows agree within the request. Both queries use the same authorization and filters, with timestamp plus conversation ID as deterministic tie breakers. The reader uses the existing conversation/participant/message/resident indexes. Public-event authorization is materialized once per query, allowing SQLite's transient lookup rather than rescanning the full event journal per conversation. This uses SQLite 3.35+ (available in the tested PHP runtimes). No persistent index or data migration is added. Exact substring search and total counts necessarily inspect matching retained history; consider additional indexes only after profiling larger real datasets through a separately reviewed migration.

There are three SELECT/PRAGMA reads for a page, independent of page population. A fourth bounded resident-name lookup is used only when a selected resident is absent from the current result page. Responses include up to 100 conversations/two complete messages each, plus resident choices from that page and any selected resident. No entire-history response or browser cache is introduced. The existing 160-character message data shape remains unchanged; the reader never shortens saved messages.

## GET parameters

| Parameter | Default | Validation / meaning |
| --- | --- | --- |
| q | empty | Up to 128 Unicode characters/512 UTF-8 bytes; literal case-insensitive substring across either name, either complete message and available public topic. Invalid UTF-8/control characters rejected. Quoted Unicode PCRE, not SQL or regex input; `%`, `_`, quotes and punctuation stay literal. No mbstring/ICU dependency. |
| resident | empty | Immutable 32-hex resident ID; either speaker qualifies. Search a name first, then use its result link or page-local dropdown. No unbounded all-resident dropdown. |
| source | all | all, openai, scripted |
| from / until | empty | Valid YYYY-MM-DD dates, inclusive UTC days; start must not follow end. Display timezone preferences do not change date filtering. |
| order | newest | newest or oldest; fixed SQL allowlist, not a sortable SQL expression |
| size | 25 | 10, 25, 50 or 100; fixed response-shape safety bound |
| page | 1 | Positive canonical integer with overflow-safe offset. Valid pages beyond the final page normalize to the last page; empty results use page 1. New filter submissions reset pagination. |

Unknown, repeated-as-array, malformed or oversized parameters return safe 422 errors. Both routes reject non-GET requests. The HTML route alone accepts a bounded `open` conversation ID to expand a row without JavaScript. It never retrieves outside the authorized current page. Query values are bound; no user input becomes SQL. API failures contain no database or provider diagnostics.

## Interface

The desktop table becomes labeled cards on narrow screens, with explicit table roles for Safari accessibility. Speech previews are visually shortened by CSS; expanded messages remain complete and escaped, with each speaker's name. Native GET filters, pagination and Read exchange links work with JavaScript disabled. Optional JavaScript adds row-click/keyboard-anchor expansion and a loading status, restores status after back/forward caching, and never fetches or polls. Resident links/filter controls remain independently usable; text selection does not collapse an exchange. The page is static, so reduced motion adds no animation. The commons links to “Browse all conversations” beside its existing recent-exchange reader.

## Offline verification and release

Run `node tests/conversation-archive.mjs`, `node tests/archive-ui.mjs`, then `python -B ops/ci/offline.py` with PHP_BINARY set. Disposable V2/V3/V4/V6 fixtures cover history beyond commons, Unicode and literal punctuation, exact 160-character text, both speakers, source/topic/date filters, tie ordering/pagination, invalid inputs, missing/failed/private/misbound dialogue, constant query counts, escaped HTML, native no-JS expansion, keyboard/row interactions and desktop/mobile rules. Database-file hashes remain identical after backend and HTTP reads. Ubuntu CI runs all PHP/JS/deployment suites without external networking or production secrets. Physical iPhone/Safari acceptance remains an owner check.

Review and deploy code through the existing protected release workflow only after approval. No production migration, quota/configuration change or paid evaluation is needed. Rollback is the previous reviewed code release; never restore/reset village data for this reader.

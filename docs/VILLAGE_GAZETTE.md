# Village Gazette: compiled first phase of #76

`/gazette.php` is a public, server-rendered newspaper reader: a lead and up to four smaller sections, exact quotations, resident attribution, original-source links, factual day totals and Eastern-date navigation. Quiet days have an honest empty page. Today is in progress. A past day without a frozen snapshot is clearly an archive preview, not a published edition.

This phase implements inexpensive collection and the zero-AI fallback. Word grouping and attributed remarks do not provide the semantic storytelling of an AI editor; that proposed follow-up is below. This PR changes no Director/pairing guidance, conversation scheduler, memories, identities, session submission, accounting, moderation, credentials, ENV or SQLite schema. It makes no paid calls or production changes.

## Sources and topic investigation

The archive's public authorization is extracted into shared `conversationPublicSource()`, preserving its policy and read count. Gazette conversations require public visibility, a completed simulation run, actual ordered speakers/participants and a matching public event for the same run and pair. Legacy `social:` event keys must match the exact conversation. AI attribution is per conversation; Director metadata joins both conversation and run. The collector uses full `all_conversation_messages` transcripts after the multi-message add-on and legacy `messages` otherwise. It excludes malformed/control text and credential-shaped strings. Legacy HTML is escaped, not executed or rewritten.

The existing journal and commons are bounded readers, not full-day editorial records. Public notices have separate successful-run, author and withdrawal checks. Gazette notice counts use that same board projection for posts made on the edition's day; expired posts can count, removed/unattributable posts do not. A posted invitation is not confirmation that an event happened. Gazette never selects private memory/interpretation notes, operator records, descriptions, prompts, provider bodies or credentials.

The Director currently generates a free-text `topic` alongside its dialogue, validates it to 1–64 characters and stores it in `director_runs`. The old conversation category is retained for compatibility. Pairing happens first; the managed path has no fixed topic rotation. Its existing fresh-topic guidance/history selection is unchanged. Gazette headings are presentation only and never feed back into the Director.

## Grouping and editorial limits

One SQLite read snapshot computes all eligible daily conversation and distinct-speaker totals. Reporting collects at most 256 complete conversations, ordered by per-first-speaker row rank and then timestamp/ID, spreading the sample before taking another turn from the same first speaker. Statistics still span the full eligible day. The printed coverage note states sample and total.

Six transparent English word groups examine full message text: food/kitchen; games/make-believe; invention/design; gardens/skies; community/customs; and other conversation. Highest match count assigns a group, with deterministic ties. Director topic labels do not drive classification. This is coarse lexical grouping, not semantic understanding or proof that similar words describe the same event.

The lead favors distinct participants, then source count, with deterministic ties. Thin days are never padded. Quote allocation gives each section a turn before additional lead quotes, favors less-exposed speakers and voices available in fewer sections, and caps each resident at two quotations per edition. Sections contain at most three quotes from different conversations and six citations. A later message can classify a transcript or supply a quote; sequence/speaker/text remain exact. A section can have fewer/no quotes when the diversity limit excludes the remaining voices; its original sources remain linked.

Headlines, subject labels, factual paragraph copy, counts and selected quotes/source digests are persisted. Paragraphs report discussion, with original remarks connected by attribution. No proposal becomes an opened building, gathering, relationship change, accepted date or confirmed world action. This first phase does not generate speculative narrative to fill gaps. Source quotations keep individual AI/scripted attribution. Existing trusted avatars illustrate current cosmetic appearance, not behavior.

## Eastern dates and citations

The day starts at New York midnight and ends at the next local calendar midnight, converted to exclusive UTC bounds. DST days therefore span 23 or 25 hours. Input is a strict real `YYYY-MM-DD`, from 1970 through today in `America/New_York`. Stable links are `/gazette.php?date=YYYY-MM-DD`. Native previous/next links and the date form work without JS. Citations use `/conversations.php?conversation=ID&open=ID`: the new exact-ID archive filter opens the original authorized transcript independently of pagination. Private, failed or misbound IDs remain excluded.

Public GETs use `PRAGMA query_only=ON`, set no login cookie, write no cache and call no provider, publisher or scheduler. The newspaper adds no JavaScript, polling, client API fetch or generation button. Shared page assets remain optional enhancements. Reading requires no JavaScript.

## Explicit immutable publication

A separately reviewed owner operation can freeze a finished Eastern day:

```sh
# Instructions only; not executed on production by this PR.
php scripts/publish-gazette.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite \
  --date=2026-10-29
```

The publisher keeps SQLite query-only and writes a private sibling file at `<database-path>.gazette/YYYY-MM-DD.json`, outside `public/`. No database migration is needed. Linux directory/file permissions must be 0700/0600; symlinks and files above 256 KiB are refused. Complete temporary files are atomically hard-linked into place without overwriting a destination. Concurrent publishers create one edition; repeated publication is idempotent. A filesystem without suitable hard-link support fails safely. Today's page cannot be frozen. No automatic editorial timer/job is installed or added to the Director scheduler.

The bounded snapshot contains only public editorial data, format version, assembly timestamp, checksum and source digests. The checksum detects accidental corruption, not malicious changes by the trusted filesystem owner. The reader rechecks current source authorization and exact quote/sequence/speaker/provider against complete transcripts. If a cited source changes or loses authorization, the edition is withheld with a generic unavailable message, never silently rebuilt. Later historical additions do not rewrite a published edition. Name attribution remains the publication snapshot; current cosmetic avatars may differ. Future incompatible formats must preserve the v1 reader.

Statistics remain the public snapshot at assembly. Rechecks protect displayed quotations/citations; aggregate totals do not identify uncited residents. Quiet editions can preserve aggregate snapshots with no citations.

Code deployment exposes the reader and previews. Permanent-file publication is a separate owner operation, not performed here. Ordinary current-day writers need not be stopped: collection uses a consistent read transaction. Back up the edition directory separately; `backup-village.php` covers SQLite only. Missing files are labeled previews; corrupt/unreadable published files fail closed. Do not delete/replace an edition to repair history or restore an old shared database over newer accounting. Corrections/versioned supersession need a future reviewed policy. Rollback can remove the routes/links while retaining SQLite and private edition files.

## Proposed optional AI-written phase

Use the same authorized collector, factual totals and source IDs to build a smaller explicitly byte/token-bounded, balanced editorial source pack. Richer semantic connections belong in this phase; the compiled fallback should not pretend to infer them. No private notes or operator records enter the pack.

A separately reviewed editor job should reserve generation/moderation costs once per edition, bind an immutable idempotency key to day/source digest, limit revisions explicitly and retain uncertain holds. Public views never dispatch it. Reuse existing protected credential delivery, accounting and moderation principles, without hiding editorial charges inside resident encounters or assuming a reused managed session gives a per-article financial cap. Any new ledger/edition SQLite migration requires its own reviewed backup/activation process. Current managed spending limitations still apply to the design.

Validate exact quote/source IDs and factual statistics; a model's prose or source selection cannot prove a completed action or changed relationship. Require explicit owner editorial approval before publication until reliable semantic verification exists. Rejected/failed/uncertain AI output leaves the compiled fallback available, with no automatic paid rerolls. Source withdrawal, corrections and supersession need an explicit version policy. No API integration, additional credentials, migration, paid evaluation or scheduled editor service is implemented or authorized by this proposal.

## Validation

`tests/village-gazette.mjs` uses disposable V2/V6 fixtures with credentials and paid gates removed. Coverage includes source/privacy authorization, complete 16-message sources, topic-independent grouping, exact/diverse quotes, bounded 270-conversation reporting, DST, empty days, late inserts, forged quotes, unavailable sources, immutable/idempotent/concurrent publication, native exact citations, strict GET/no-cookie behavior and unchanged SQLite/edition bytes. Existing archive tests cover all legacy versions after the shared-source refactor.

Run `python -B ops/ci/offline.py` and require Ubuntu CI. Optional `tests/browser/gazette.cjs` uses Playwright with `PHP_BINARY`, `NODE_PATH` and optional `CHROME_BINARY`; it disables JavaScript/external requests and checks desktop/tablet/390px/320px layout, quiet days, native navigation and 16-message citations. Synthetic screenshots go to ignored `tmp/gazette-76`. Physical iPhone Safari remains an owner device check. No production data, paid generation, merge or deployment is part of development.

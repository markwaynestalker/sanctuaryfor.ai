# Gazette editorial ledger (#78, second PR)

This is an inactive storage/accounting foundation, not the paid editor integration. No public route, scheduler or existing publication command calls it. It makes no provider requests, changes no ENV, and does not publish an AI newspaper. The current Gazette and immutable v1 files continue working without this add-on. Installing the code does not install the schema.

## Additive migration for owner review

`app/gazette_editor_schema.php` adds ten namespaced tables, one budget index and append-only/gating triggers to SQLite V6. It does not recreate, update or delete old tables, change `user_version`, rewrite history or touch Director sessions, attempts, imported accounting, identities, resident relationships, opportunities, settings or schedules.

| Table suffix (`gazette_editor_`) | Purpose |
| --- | --- |
| `meta` | Add-on revision 1; V6 remains V6 |
| `permits` | Exact-source/policy, cost-bounded local owner authorization for an additional edition revision |
| `attempts` | Immutable snapshot, pricing/limit policy, UTC reservation, edition ordinal, idempotency digest and worker fencing identity |
| `lease` | Separate single editorial worker; never the Director's `ai_lease` |
| `requests` | One generation and one moderation dispatch maximum per attempt |
| `receipts` | Sanitized request/Responses identifiers, outcome, nullable observed tokens and approximate cost; no provider body |
| `drafts` | One structurally checked, exact-hash draft per attempt |
| `moderations` | Passed/flagged result bound to that exact draft and completed moderation request |
| `outcomes` | Finished, rejected or uncertain attempt outcome |
| `reviews` | Explicit local owner approved/rejected decision for the exact finished, moderated draft |

Every table except the current lease rejects UPDATE and DELETE. FK/unique/check constraints and insert gates bind drafts to completed generation, moderation to a completed moderation request and reviews to a finished draft with passed moderation. Application helpers additionally enforce authorization, lifecycle, leases, budgets and exact content. An operator with unrestricted database access is trusted; these records are not cryptographic proof against the filesystem/database owner.

The migration is transactional, repeatable and refuses old versions, partial schemas and active identity/AI writer leases through the existing idle guard. Schema installation is an **explicit owner operation** after separate approval, a verified private backup and a drained-writer window. The confirmation argument asserts that the owner arranged that window; it does not stop or inspect systemd and cannot prove all writers are drained. This PR neither executes nor schedules this operation:

```sh
# Owner instructions only; do not run just because code was deployed.
php scripts/migrate-gazette-editor.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite \
  --confirm-drained=yes
```

The script refuses an absent/empty database argument or missing confirmation, uses `BEGIN IMMEDIATE`, checks SQLite integrity/FKs and rolls back on failure. It never changes ENV, credentials or service/timer state. Owner review must choose the actual writer-drain and restore procedure; development does not authorize those production operations.

Unlike v1 edition files, source packs and drafts in this phase live inside the private SQLite database and its existing backup boundary. Continue backing up the separate v1 `.gazette` directory too. Preserve database permissions and private backups: drafts, review state, fencing handles and unapproved copy must not become a public API. Code rollback leaves the additive tables/history intact; the old Gazette ignores them. Do not drop the ledger or restore an older shared database over newer village data/accounting to roll back code. Test the backed-up database and version-compatible reader before any future production activation.

## Explicit independent financial policy

`reserveEditorialAttempt()` accepts a trusted policy array; it reads no ENV and inherits no Director limits or prices. All fields are mandatory:

- Model and pricing-version identifiers, input/output token allowances and conservative micro-USD-per-million-token input/output rates.
- Separately reserved moderation micro-USD, daily and monthly editorial micro-USD limits, and daily editorial HTTP request slots.
- An editorial lease of 1–900 seconds, long enough for the future bounded adapter but never crossing UTC midnight.

The financial integers are explicit nonnegative native integers; zero spending/request limits disable admission. Input/output allowances and generation rates must be positive. Arithmetic checks multiplication/addition overflow and rounds each token charge upward to whole micro-USD. There is no chosen production budget, model default, selected financial ceiling or price certification here. The later integration must verify current model/rates, all applicable charge categories, tokenizer/request-size limits and moderation pricing before dispatch. A caller must use an input rate conservative for every input category, without assuming cache discounts, and reserve the entire moderation allowance. These estimates are not provider invoice caps.

Admission reserves two HTTP slots and the full generation plus moderation cost atomically, against UTC dispatch-day/month windows. The Eastern publication date is separate. All reservations remain committed even after cheap success, rejection, no dispatch or missing usage. Generation usage, when both token counts are available, is conservatively priced from the immutable policy; a smaller caller estimate cannot reduce it. Larger observed costs increase subsequent budget commitments. Null usage/cost stays unknown rather than becoming zero verified usage. Sum/overflow failures refuse admission. Usage and approximate cost are observations, not verified final billing evidence. No refunds or reconciliation shortcuts are implemented.

Editorial charges never enter resident/Director attempts or alter their limits. This is an additional owner allocation, not a shared project-wide financial cap. A separate editorial lease and 100-ms SQLite busy wait bound admission contention; the previous connection timeout is restored afterward. Locks cover SQL validation and state transitions only, never network calls or human review time. Real retained-history performance still needs review before activation; a short busy wait is not an execution-time guarantee for source queries.

## Exactly-once dispatch and recovery

The attempt binds a finished Eastern date, exact source digest and full policy digest. Its idempotency digest also includes the optional owner revision permit. There is one unpermitted first attempt per date, regardless of source changes, changed policy, worker restart or UTC budget rollover. Identical repeat invocations refuse rather than submit again. Additional attempts require a separate CLI owner permit binding the next ordinal, exact source/policy digests, maximum reservation and expiry within 24 hours. A permit is consumed atomically through the unique attempt reference. There are at most eight explicitly authorized attempts per edition; this is a structural revision bound, not a production allowance or automatic retry policy.

The same source/policy may be tried again only through a distinct explicit owner permit, after a known terminal predecessor and available budgets. An uncertain or unfinished predecessor blocks further paid attempts for that edition even with a permit; it does not block a different eligible edition or any Director opportunity. There is no automatic paid revision, correction, reroll or historical catch-up job. A rejected draft remains intact, so a correction creates a new permitted attempt rather than rewriting an old draft.

`startEditorialRequest()` commits a unique generation/moderation dispatch record **before** the future adapter sends anything. SDK/HTTP retries must be disabled by that adapter; the ledger cannot stop hidden transport retries. Moderation cannot dispatch before a captured validated draft. A timeout, crash or uncertain receipt never opens another slot of the same kind. A generation refusal has no draft and therefore cannot be moderated. Completion receipts retain only allowed request IDs, token counts, cost observations and status, not vendor response text.

The worker identity and lease expiry fence every request, receipt, draft, moderation and finish operation. Late workers cannot apply output. When a later admission commits recovery of an expired lease, its predecessor becomes uncertain and retains the reservation/slots; recovery is rolled back if the new admission fails. Expiry itself still fences the old worker even before that bookkeeping is committed. Late-provider reconciliation and final invoice evidence are not implemented here; do not replay, import late dialogue or refund an uncertain attempt to work around it. A known rejection cannot be used to relabel a dispatched request whose outcome is missing/uncertain.

## Drafts, moderation and local owner evidence

The ledger reauthorizes source records against current public conversation/notice rules at reservation, draft capture and owner review, comparing complete original content/identities. Review rechecks cited sources rather than recomputing the reporting sample or frozen day counts. A later inserted conversation therefore does not invalidate an existing draft merely by changing totals. A source's withdrawal, changed content or changed attribution blocks approval until a new correctly reviewed draft exists. These are trusted collector envelopes, not an import API for arbitrary caller-supplied context.

`saveEditorialDraft()` uses the first PR's structural/evidence validator and stores the exact bounded JSON and digest. It cannot manufacture provider success or replace an existing draft. `saveEditorialModeration()` records an adapter-supplied verdict for the exact copy, only after the corresponding completed request receipt. This phase makes no actual moderation call and provides no command that fabricates one. Missing token usage remains nonblocking while full reservations stay held.

`reviewEditorialDraft()` is a trusted CLI helper for a later protected local-owner command. It checks the expected exact digest, complete source evidence, finished attempt and passed moderation. The owner must inspect the precise headline/prose/quotes and factual entailment before supplying a decision. The helper does not detect whether a human actually read an article; `local_owner` is the trusted caller boundary, not external authentication. No public/agent-facing approval endpoint, automatic approval caller or runnable approval CLI is installed by this PR. Citation checks and moderation still do not prove factual truth.

A stored approval is **not publication authority by itself**. The third PR must implement protected owner tooling, current source checks at publication/reading, versioned immutable edition output, atomic correction/supersession and v1 compatibility before any AI edition can be served. No publication pointer/table or public draft reader is added here. Nothing changes today's Gazette landing behavior. Owner review of that integration and paid activation remains separate from this migration.

## Offline evidence

The Gazette suite uses private disposable V6 copies, synthetic pricing and no credential/provider transport. Tests cover migration rollback/repeatability, old-version/partial-schema refusal, explicit CLI confirmation, preservation of every old table/row and V6, zero/missing limits, overflow, daily/monthly retained commitments, observed overruns, reserved moderation slots, UTC boundary guard, duplicate dispatch, wrong/expired workers, crash recovery, no uncertain regeneration, one-shot approved revisions, exact draft/moderation/approval binding, unavailable sources, immutable evidence and real two-process reservation contention. The existing V2/v1 Gazette reader and immutable file tests run unchanged alongside it.

Run all offline suites and require Ubuntu CI, including Linux-only deployment/locking checks. This phase changes no UI and requires no new screenshot claims. No paid evaluations or production accesses are authorized by these tests. Future concurrency and crash tests must also cover the actual transport, publication filesystem and owner tooling once those exist.

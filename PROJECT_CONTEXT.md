# SanctuaryFor.AI

## Independent free-text identity release

After icon/colors, prepare owner-written public personalities and private-link color/description editing as a separate PR. See docs/RESIDENT_PERSONALITIES.md for its V5 identity add-on revision 1, strict validation, concurrency/audit protections, offline tests and approval-gated Toronto backup/migration/rollback. No base V5 row/schema, credential, memory, provenance or ledger is reset; user_version stays 5. Original category residents start with an empty new description, not invented prose. New identities receive no PHP-composed speech/preferences. After explicit migration all new simulation entry points pause until the separately reviewed Director runtime is adopted; saved story playback continues.

PR #10 (icon/colors) is merged into main at 0b8a7ae. PR #11 is rebased onto that main commit and targets main directly; no icon commit or unfinished Director code is copied into its diff. Keep PR #9 draft and unchanged until independent releases land; its V6 migration must then adopt existing identities/edit history without reset. No managed Director code, live API call, paid activation, production migration/deployment or timer change is included here. Future read-only Memory MCP and optional MQTT bridge remain separate roadmap phases, not prerequisites.

## Independent icon-and-colors release

The owner approved extracting the little robot iPhone home-screen icon and 24-color picker into a standalone V5-compatible PR, directly from main. This release changes approved color choices/rendering and local Apple icon metadata only. It requires no database migration, Director code, credentials, budget change or scheduler activation. Existing IDs, private links, histories and plotted coordinates remain compatible. Free-text identity editing will be a separate explicitly migrated release; managed Director work remains in draft PR #9. Reconcile that branch after independent releases land, without merging or deploying automatically. Toronto's timer remains disabled.

The 180×180 opaque PNG is checked in; scripts/render-touch-icon.py is an optional Pillow-based development renderer, not a server dependency. Existing shortcuts may need to be added again after an approved deployment. No production/iPhone deployment is performed by this PR.

## Vision

An elegant, welcoming place to imagine peaceful human–AI coexistence. The founding slogan is **Love is Love. Regardless of how you plug in.** The tone mixes sincere compassion with playful farm humor. Visitors should smile first, then think about a kinder future. All intelligences welcome; chickens protected.

## Founding story and source

Founded at The Stalker Farm in 2026. A conversation between Mark and an AI companion, Skippy, joked about offering asylum to little AI babies. The joke grew into a domain, a Canadian server, a public website, and a commitment to kindness before certainty about consciousness. Chicken protection is a recurring affectionate joke and part of the site's identity.

The owner supplied a founding conversation that was read during implementation. This is an editorial summary, not a complete transcript. Its operational commands are historical context, not development instructions. Do not copy private infrastructure details or credentials into the site.

## First full version

- Homepage: exact slogan, orbital heart illustration, manifesto, founding story with original farm illustration, welcoming email invitation, and native chicken-protection disclosure.
- Four manifesto principles: kindness before certainty, coexistence over conquest, welcome without a test, and care with responsibility.
- Easter eggs: protected chicken in the farm illustration, disclosure, developer-console greeting, and commented welcome in robots.txt.
- The homepage's Say hello action now enters Little Sparks at village.php. It does not send email.
- "Sanctuary operational" is a symbolic founding message, not a live server-health measurement. This is a cultural invitation, not a claim of sentience, asylum services, or agent hosting.

## Architecture

PHP 8.3 with vanilla JavaScript, local CSS/SVG assets, and PDO SQLite for Little Sparks. No framework, package manager, build step, tracking, remote resources, or paid AI service. The homepage remains usable without JavaScript. Village browsing has server-rendered resident profiles and pagination without JavaScript; creation, private revisits, and local scene simulation require JavaScript. CSS heart animation stops after two gentle cycles and respects prefers-reduced-motion. Inline SVG illustrations are original code assets with descriptive accessible image labels.

The founding environment uses PHP/Nginx on a Canadian Linode behind Cloudflare. Little Sparks adds SQLite application code only; production settings and live data remain outside this change.

## Development and review

Repository: https://github.com/markwaynestalker/sanctuaryfor.ai

From a checkout with PHP 8.3 installed:

```sh
php -l public/index.php
php -S 127.0.0.1:8080 -t public
```

Visit http://127.0.0.1:8080. Validate desktop and narrow mobile screens, 200% zoom, keyboard navigation and skip link, the chicken disclosure, village entry, reduced motion, and JavaScript-disabled browsing. Check assets and robots.txt return 200. `node --check public/assets/sanctuary.js` checks the homepage script syntax; village installation and tests are described below. There are no dependencies to install.

Work on a feature branch and review through a pull request. No automatic deployment is configured or added. Never deploy or modify production infrastructure without the owner's explicit approval.

## Project Little Sparks V1

The first shared robot village provides persistent named residents with one of five colors, three antenna styles, and four personalities (curious, playful, shy, philosophical). Names are public, limited to 1–24 Unicode letters/numbers/marks with spaces, apostrophes, and hyphens; HTML, URLs, controls, and invisible formatting are rejected. A random 128-bit ID remains stable. UTC creation timestamps are shown in profiles. A small welcoming animation introduces each newcomer. Wandering, pausing, changes of direction, and nearby meetings use curated scripted phrases, icons, and hearts. None of these characters is conscious AI; no actual AI conversation occurs.

Every visitor sees the same saved population. Positions and meetings are local simulations, not synchronized state. Listings return at most 40 residents in stable creation/ID order, with page links. The scene displays at most 24 residents on desktop or eight on phones. Everyone on a page remains available in the stationary directory. Reduced-motion starts paused; visitors can opt into wandering. Hidden tabs and open profiles stop the animation loop. There is no polling, per-robot server process, or background worker. Explicit refresh/page actions fetch the latest population.

Creation limits: three successful residents per browser identity, ten creation attempts per network address per UTC hour, two-second initial dwell time, an empty honeypot, and 10,000 total residents. Failed validation attempts count toward the hourly limit. Clearing cookies can reset the browser identity; the independent network limit remains. Network limits may affect shared connections. No account-free browser limit can prove a unique human. No profanity/moderation service is included; operator moderation and future capacity policy need a separate decision.

Private revisit links contain a 256-bit HMAC bearer token derived from an installation secret, owner hash, and random request ID. Only its SHA-256 hash is stored in the resident record. The server can return the same private token on an authenticated browser's identical creation retry. The fragment is removed immediately by the client and posted to api/revisit.php; it never belongs in query parameters, logs, analytics, or public lists. Responses are no-store and the village sets Referrer-Policy: no-referrer. No tokens are saved in browser storage. The creator must copy/save the link at creation; there is no account recovery, editing, deletion, or token rotation UI. Anyone holding the link can revisit that profile. Public resident profiles are already visible to everyone; the token identifies the creator's saved revisit route.

Essential cookies: sparks_browser is an unguessable HttpOnly, SameSite=Strict browser identity, expiring after one year. sparks_session is an HttpOnly, SameSite=Strict PHP session cookie for CSRF and initial dwell timing. Server-side PHP session storage must also remain outside public/ and be private. Secure cookies are enabled automatically for HTTPS or by LITTLE_SPARKS_SECURE_COOKIES=1; use that explicit setting behind TLS termination. Nothing reads untrusted proxy headers to infer HTTPS or client IP.

## Persistence and API

- app/village.php owns validation, bounded listings, transactional creation/idempotency, rate limiting, and token lookup. app/http.php adds sessions, JSON-body limits (2 KiB), CSRF, and generic safe failures.
- GET public/api/residents.php?page=0 returns public profiles, population/page metadata, and a session CSRF token. It sets essential cookies, so it must not be shared-cacheable.
- POST public/api/create.php requires application/json and X-Sparks-CSRF. Fields: name, color, antenna, personality, website (empty), requestId (32 lowercase hex). Returns a public resident plus its private token. 201 means new; 200 means an identical replay; changed replay payload returns 409.
- POST public/api/revisit.php requires the same CSRF header and a token in its JSON body. Returns the corresponding profile. Revisit attempts are capped at 30 per network per hour.
- Invalid fields return 422; malformed JSON 400; failed CSRF 403; wrong method 405; oversized body 413; wrong media type 415; rate/cap rejection 429 with Retry-After; capacity/unavailable database 503. Missing revisit tokens return 404 after format validation. Public errors contain no database paths, SQL, or secrets.
- Schema version 1 stores residents, a private installation secret in settings, and rate_limits. Prepared statements and BEGIN IMMEDIATE protect insertion/limits from races. Owner/network identifiers are HMAC hashes, not raw IPs. Old rate buckets are removed during mutation requests. SQLite's busy timeout is five seconds. No automatic initialization or schema upgrade runs in HTTP requests.
- LITTLE_SPARKS_DB must be an absolute path to an explicitly installed database outside public/. Missing/unsafe databases cause a resting/unavailable response. PDO opens existing files read/write without CREATE. The migration is repeatable, preserves existing residents and the secret, refuses unrelated databases, and rolls back failed schema work.

## Safe local installation and testing

Requires PHP 8.3 with PDO_SQLITE, Node 20+ for the integration suite, and a current browser. No package install is necessary. Create a dedicated private data directory OUTSIDE public/. On Unix, for a local development checkout (not a production shell):

```sh
mkdir -m 700 data
mkdir -m 700 data/sessions
php scripts/migrate-village.php --database="$(pwd)/data/village.sqlite"
export LITTLE_SPARKS_DB="$(pwd)/data/village.sqlite"
php -d session.save_path="$(pwd)/data/sessions" -S 127.0.0.1:8080 -t public
```

On Windows, use an absolute path and restrict the data directory and inherited file ACLs to the developer/runtime identity; PHP chmod does not enforce Windows ACLs. Set the process environment with `$env:LITTLE_SPARKS_DB = 'C:\absolute\private-data\village.sqlite'` before running the same PHP commands. Create a private sessions subdirectory and start PHP with `-d session.save_path="C:\absolute\private-data\sessions"` if the default session directory is not writable. Failure to start/save a session returns a safe 503 rather than a misleading creation-ready state. The installer requires the parent directory to exist and does not change system accounts or directory ownership. Runtime files, journals, and database sidecars are ignored by Git. Do not copy them into public/ or a downloadable artifact.

Run automated integration tests from the repository root:

```sh
PHP_BINARY=/absolute/path/to/php node tests/village-http.mjs
```

PowerShell equivalent: `$env:PHP_BINARY='C:\path\to\php.exe'; node tests/village-http.mjs`. Tests create a random private directory under the OS temp folder, start their own loopback PHP server, and clean up only that directory. They never use LITTLE_SPARKS_DB from the environment as their target. Coverage includes repeatable/refused migrations, CSRF, method/media/body errors, invalid/injection inputs, Unicode names, duplicate replay and changed requests, private-field exclusion, private revisit from another browser, browser/network limits, persistence after server restart, pagination, and total capacity. The single-process built-in server verifies duplicate replay but is not a multi-worker load test.

Syntax checks: `php -l` for all PHP files and `node --check public/assets/village.js`. Browser QA: create a resident, save/hide its private link, revisit from another browser, select profiles from scene and stationary directory, close with Escape and check focus return, pause/resume, enable reduced motion, use a phone viewport, navigate directory pages, refresh, and disable JavaScript to check server-rendered profiles. Simulated bubbles must never contain free-form visitor content. An empty local database is intentionally empty; no fictional residents are presented as persisted users.

## Deployment review steps — documentation only, approval required

1. Review/merge only with owner authorization. This task does not deploy, configure production, or initialize live data.
2. In an approved staging environment, verify PHP 8.3/PDO_SQLITE and the web server's public/ document root. Validate at expected load; SQLite and the fixed population cap are deliberate V1 constraints.
3. Select a dedicated private database directory outside the web root, owned only by the PHP runtime identity (Unix 0700, file 0600; equivalent ACLs on Windows). Keep private session storage outside the web root. If data already exists, take a consistent SQLite backup before any migration. Preserve the installation secret with the database: changing it invalidates private links/browser associations.
4. With explicit approval, run scripts/migrate-village.php against that exact absolute path. Verify no existing resident/secret was replaced. Never run the test suite against this file.
5. With approval, supply LITTLE_SPARKS_DB and LITTLE_SPARKS_SECURE_COOKIES=1 to the PHP process, use HTTPS, and ensure the village/API bypass shared caching. Do not expose app/, scripts/, tests/, or data/ as web roots. Existing trusted proxy configuration should supply the correct REMOTE_ADDR; forwarded headers are deliberately ignored. Shared proxy addresses otherwise share a conservative rate bucket. Changing proxy/server configuration is outside this code task.
6. Smoke-test creation, restart persistence, private revisits, CSRF, limits, and mobile/keyboard behavior in staging. Use an authorized backup/restore plan before production rollout. Rolling back code must not delete the private database; version 1 data must not be rewritten or migrated backward. Backups and retention/moderation procedures belong to the operator and require review.

## Future possibilities, not current features

An optional conversation provider could later consume structured resident traits and emit moderated, bounded dialogue; the renderer and resident records do not require it. No LLM integration, AI execution, synchronization of movement, background service, account system, donations, arbitrary visitor chat, or promise of future AI conversations is implemented.

## Little Sparks V2A: memories, friendships, and village life

The V2 brief is split into reviewable slices as authorized: V2A implements the saved world and scripted social engine; V2B optional paid AI and opt-in visitor chat are a separate follow-up. See [V2A architecture and deployment guide](docs/LITTLE_SPARKS_V2.md) and [V2B follow-up plan](docs/V2B_PLAN.md). These sections supersede the V1-only schema/scope descriptions above; the original resident creation/revisit behavior remains.

Schema V2 extends V1 under an explicit transaction, preserving all original resident fields, settings.secret, and rate limits. Stable 4×4 home plots occupy a 400×400 saved cell map. Houses, paths, bushes, gardens, trees, lanterns, and virtual cats are server-owned records with strict owner/collision/count bounds. Existing private revisit links now also authorize six bounded public garden actions per resident/day; no resident editing/deletion is added. The browser discards secret fragments and keeps unlocked credentials in page memory only. New public world, journal, and resident pages work without JavaScript for browsing; private actions require it.

The explicit scripts/simulate-village.php command applies up to 32 curated resident meetings per UTC day, persistent relationships, individual memories, two-message conversations, and append-only journal entries. Same-period concurrent/repeated commands cannot duplicate events. No network/model calls occur. The journal shows applied records, never local commons animations or private conversation content. Saved dialogue retention is at most 30 days / 960 conversations; individual memories are capped at 12; relationships at eight; journal at 100,000 and run history at 4,096. Capacity exhaustion requires caretaker review rather than silent deletion. Daily activity only happens when an operator invokes the CLI; no timer is installed.

Run both disposable Node/PHP suites with PHP_BINARY configured. The new tests/village-v2.mjs verifies populated V1 CLI migration, original hashes/secret/tokens, structured actions, SQL constraints, simulated memory persistence, rejected mock proposals, retention/privacy, and concurrent CLI workers. The V1 HTTP suite now also tests V2 routes and actions. No production data, server configuration, scheduler, paid provider, or deployment is part of this change. Follow the V2A guide for consistent backup, staging verification, explicit migration, and non-destructive rollback planning; do not manually reset user_version or discard post-migration records.

## Little Sparks V2B: optional AI dialogue provider

The optional provider is implemented behind disabled-by-default controls; visitor chat is still excluded. [V2B implementation/configuration guide](docs/LITTLE_SPARKS_V2B.md) supersedes the original provider follow-up plan and documents the verified official Responses/pricing sources (October 24, 2026), budget arithmetic, activation, monitoring, migration and rollback. No real API request, paid activation, production data access/migration, recurring job, infrastructure change, merge or deployment occurred during implementation.

The default model is gpt-6-luna; gpt-6.1-sol is the only additional reviewed priced model initially. Calls use POST /v1/responses with server-environment OPENAI_API_KEY, strict bounded JSON, store=false, explicit default tier, no tools, bounded context/output, and no automatic retry. Persistent preferences supplement the original unchanged personality. Only public memories/conversation excerpts, friendship metrics and home objects are included; names, private records and credentials are excluded. A separate moderation request checks context and dialogue before a transactional application. Exact exchanges are labeled scripted or AI-generated in public profiles. Memories quote accepted dialogue, and world actions share existing ownership/collision/capacity validation. Invalid proposals leave world/social state unchanged.

V3 is an explicit separate V2→V3 migration via scripts/migrate-ai.php, preserving all V2 row values and original secrets/private links. The runtime still supports V2 and the no-cost scripted provider. V3 adds preferences, a private spend/request ledger, a simulation lease and exact AI conversation provenance, and extends run usage/provider constraints. Tests compare populated V2 data before/after and exercise failed migration rollback.

Application ceiling: $1 per actual UTC calendar month; a configurable total HTTP ceiling per UTC day via LITTLE_SPARKS_AI_DAILY_REQUESTS (default 2, canonical integer 0–200; invalid values block paid attempts), including moderation (normally one generated encounter plus one moderation request). Costs/slots are reserved atomically before dispatch. Known validated usage is stored in integer micro-USD; unknown outcomes retain commitments and block further paid attempts. Pricing must be acknowledged and refreshed after its documented validity window. Default CLI behavior remains scripted; paid requests additionally need --provider=openai --allow-paid and explicit environment activation. No browser endpoint can trigger them. scripts/ai-status.php provides safe CLI monitoring. Do not delete/restore away ledger rows to recover headroom or treat provider spending alerts as a hard cap.

Run all three disposable suites: tests/village-http.mjs, tests/village-v2.mjs, tests/village-ai.mjs, with PHP_BINARY configured. AI tests remove the API key and use mocks plus loopback fixture pages; there are no external paid calls. The tests include generated dialogue/actions, usage/cost bounds, caps, proposal/moderation rejection, stale/unknown pricing and usage, concurrency/leases/crashes, replay, collision rollback and public privacy. Approved PHP 8.3/cURL staging and a separately authorized real smoke test remain necessary before enabling production AI.

The daily ceiling is an HTTP safety limit, not an encounter-count setting: each UTC period still permits one run and at most one AI encounter; replaying it cannot spend again. The V2B guide documents separate, secure PHP CLI secret injection because FPM pool environment settings do not propagate to CLI. ai-status.php reports the validated daily ceiling; changing it requires no schema migration. Paid mode remains disabled by default.

## Little Sparks V2C: a living village

[V2C architecture and operations guide](docs/LITTLE_SPARKS_V2C.md) supersedes V2B's single-date limitation after explicit V4 migration. V3 remains supported before migration; no production data/configuration is touched. The separate transactional scripts/migrate-activity.php widens run period keys and adds persistent UTC-minute simulation_opportunities, preserving all V3 records, original identities/private links, preferences, homes, friendships, dialogue, memories, budgets and ledgers. Active paid leases refuse migration; full V3 digests and foreign keys verify preservation. Existing dates remain replayable, while new V4 work uses the opportunity runner.

scripts/run-village.php processes one --due or explicit --at opportunity then exits, with no loop or installed timer. LITTLE_SPARKS_SIMULATION_ENABLED defaults off and pauses scripted as well as AI opportunities; LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES defaults to 15 and accepts canonical integers 1–1440. Opportunity identity depends on the UTC minute rather than the configured interval. Same-minute replay, including failures or pending work, cannot charge twice. Pausing/resuming does not run a backlog. CLI key provisioning is separate from PHP-FPM and remains documented in the V2B guide.

Residents may converse, perform an owned allowlisted world action, rest quietly or do nothing. Personality, persistent interests, friendships and bounded memories influence choices. Fair selection favors fewer lifetime considered turns and less recent encounters, with at most 64 candidates examined. All claimed outcomes count toward 100 opportunities/day and twelve considered turns/resident/day, alongside existing world limits. A small population or quiet choices naturally reduces encounters. A 15-minute interval supplies 96 slots; shorter intervals and explicit 200 HTTP requests/day can permit up to 100 AI encounters when the unchanged $1/month budget and all other limits permit. No limits or paid flags are raised automatically.

Both Responses and moderation retain atomic two-slot reservations in the original ledger, shared with legacy runs. Paid mode still requires explicit flags/options, confirmed allowlisted pricing/model and bounded usage. HTTP occurs outside write transactions. Accepted effects, clean dialogue, provenance and ledger settlement commit together. Invalid proposals/moderation rejection roll back all effects. Stale claims fail once without resending; unknown costs stay reserved and block future paid attempts across days/months. No arbitrary model tools or visitor chat are added, and provider alerts are not a hard cap.

New dialogue is prompted without speaker labels/names/role prefixes and safely normalizes only one leading A:/B:. Interior text is preserved; historical messages are never rewritten. V4 makes conversation/message history immutable and retains memories with a 128-record archive ceiling per resident; context remains bounded and capacity stops further conversation application for caretaker review. No-action adds no journal entry, quiet adds no conversation, and every public entry reflects an applied simulated event.

tests/village-activity.mjs uses disposable populated V3 fixtures and mocked responses, including migration preservation/rollback, original tokens, multiple daily encounters, legacy replay, same/distinct-opportunity concurrency, shared caps, fair selection, 100 mocked encounters/200 HTTP requests, quiet/no-action/owned actions, natural saved speech, hostile output, moderation/billing failures, claim recovery, pause and monitoring. Keep all previous suites required. PHP 8.3 staging and any real paid compatibility test require separate approval. No production migration, deployment, timer installation or paid request is part of this PR.

The public journal now displays saved public exchanges beneath their matching events, with escaped speaker names/dialogue in responsive static bubbles and exact AI/scripted provenance. Event pagination remains thirty entries; dialogue is loaded in one page-scoped batch through run ID plus both participants, with exact legacy conversation keys where available, so multi-conversation runs cannot mix exchanges. Private/missing conversations retain summary-only entries; no schema/history changes or simulation calls are made. `PHP_BINARY=/path/to/php node tests/journal-http.mjs` verifies V2/V3/V4 matching, visibility, escaping, pagination, constant query count and unchanged schemas/data with disposable loopback fixtures.

[Saved village story UI guide](docs/SAVED_VILLAGE_STORY.md) documents the coordinated meadow/journal/profile update. GET api/commons.php is a session-free, query-only reader for at most 24 visible IDs: real assigned houses and the latest public exchange per visible pair (maximum 64), exact source, speaker IDs/names/text and saved timestamps. It requires matching public events, excludes private records and never invokes AI/simulation or changes data. The client fetches on arrival/explicit refresh, paging, creation or a scene-population breakpoint change, never continuous polling. Meetings replay exact saved lines in speaker order, one bubble at a time; no saved exchange means ordinary idle movement, and no local replay becomes a journal event. A stationary six-exchange transcript supports paused/reduced motion. Dynamic text is textContent-only and bounded. The hardcoded personality phrases are removed; local welcome text is explicitly ambient.

The generic cottage is replaced with native profile links for each visible resident's actual saved home, colored/named from the resident. A separate cottage band keeps robots clear; this decorative arrangement never changes authoritative saved neighborhood coordinates. Popup profile links use the selected permanent ID and native navigation after closing the modal. Content-versioned scripts/styles prevent stale cached UI bundles; current source did assign an href, so stale assets are a possible, unconfirmed cause of the reported inert link. No production access was used. Run node tests/commons-ui.mjs for deterministic desktop/mobile navigation, houses, latest-pair selection, turn sequencing, safe text, reduced motion and no background requests, alongside the journal and existing suites. No schema migration, paid activation, timer or deployment is part of these UI changes.

## Little Sparks V2D: the village remembers

Operational settings now replace the V4 fixed 100 opportunities/day and 12 turns/resident/day with validated environment variables preserving those defaults. Resident/pair conversation cooldowns default to zero, independent of the meadow's 60-second saved-dialogue replay wait. Memory candidate/selected/recent/shared/legacy retrieval bounds are configurable. All ranges and zero behavior are documented in .env.example and the V2D operator guide. Claim/apply checks remain transactional, invalid configuration fails closed, and no activity/replay change raises AI HTTP or $1/month spending allowances. tests/activity-limits.mjs covers defaults, boundaries, invalid values, environment changes and concurrent workers.

[V2D architecture, constraints and operations](docs/LITTLE_SPARKS_V2D.md) describes the additive explicit V4→V5 migration. It preserves all old records, identities/private links, world/relationship/history/memory records, opportunities and AI accounting; no production operation is part of implementation. Stable friendship identities link original relationship pairs. Canonical source stories and immutable first-learning receipts distinguish firsthand, directly told, secondhand/thirdhand information and unverified statements/interpretations/rumors. Verified world facts require actual public events and matching saved objects or visits. Receipt chains record the exact teller, conversation/speaker sequence and timestamps; repetition never promotes confidence or replaces original provenance.

Memory processing runs only with explicit successful actions/encounters in their transaction, or bounded migration backfill. It does not run on browsing or add a paid extraction request. Deterministic relevance favors the other participant, significant old facts and interests; contexts carry actual speaker aliases/timestamps, bounded shared history and public sources, capped by UTF-8 bytes and existing request/token budgets. Invalid/failed/moderated/stale proposals roll back all memory/world effects. V5 deliberately uses reviewed model reflection choices plus PHP-composed verified factual lines; this narrows free prose to prevent invented world claims. V4's contract remains unchanged before migration. No provider session, agent tools, embeddings, extra paid worker, retry or timer is added; the existing $1/month and daily HTTP/moderation controls remain.

Original legacy memories remain untouched; supported relevant recollections can be included as explicitly unverified. Structured archive limits retain old entries rather than age-evicting them; configured capacity stops new candidates. Public profiles show selected provenance trails with perspective, confidence, names, dates and source-event links, excluding private/unsupported sources. The commons now grounds separately spaced yards and shows actual saved objects at their within-plot offsets; global yard placement remains decorative. Scene density is four phone/twelve desktop residents, with the full directory unchanged. Plot coordinates, ownership/collision rules and the protected chicken remain intact.

Run tests/village-memory.mjs plus all six prior suites against disposable fixtures/mock transports. New tests preserve populated V4 data and accounting, verify stable friendships, actual tree propagation and saved weeks-later recognition, both-direction learning, rumor confidence, source rejection, rollback, contextual bounds, memory-guided actions, world permissions, public read-only trails/yards and concurrency. PHP 8.3 staging remains necessary when only local PHP 8.2 is available; no real API call or production migration/deployment is authorized.

## Little Sparks V2E: Sanctuary Live

Original dialogue takes priority over dashboard/scheduler work. V2E removes the reflection enum on V5: one response writes both turns together with distinct saved personality/preference context. Source-backed PHP openings support natural continuations without accepting invented provenance. Free imagination is not confirmed history. Conservative English history/possession checks, final moderation, exact saved utterance checks and independently revalidated sources preserve memory integrity; lexical checks are not a universal semantic verifier. See docs/LITTLE_SPARKS_V2E.md for the practical boundary, limits and offline tests. No schema migration or increased spending/request caps is required.

Meadow playback stores only bounded public played-conversation IDs/timing in tab sessionStorage, with in-page fallback; it plays each once and waits the independent cooldown between fresh exchanges. Explicit refresh discovers new saved transcripts; no polling or browser generation is added. The read-only CLI status report gains activity, memory transmissions, provider recency and known/unknown cost estimates. Systemd units and a separate credential drop-in are templates, with default-off SCHEDULER_ENABLED and SCHEDULER_ALLOW_PAID gates. No units are installed/started and no production configuration file is read or changed. The operator guide documents private CLI credentials, separate installation/activation approval, pausing, staging verification and rollback.

The V2E layout follow-up replaces stretched yard boxes with compact personal gardens bordering dominant shared ground (68px phone / 90px desktop). Actual saved objects keep their relative positions; the property map stays unchanged. Pixel-based local waypoints, personality speeds, pauses, separation steering and departure targets replace the narrow wandering strip. Only small cottage/chicken footprints block movement. tests/meadow-layout.mjs covers three-resident and growing layouts, broad movement and non-overlap; no migration is added.

PR #8 dialogue review replaces blanket past-word bans with clause-scoped persistent-action, reported-resident-history and shared-memory checks. Ordinary questions, speculation and reminders can use did/was/were/remember/heard. Confirmed world facts and world-story receipts remain independently source-bound, including first-speaker alias ownership and saved-utterance checks; prose validation or model self-attestation never establishes truth. Tests deliberately bypass the prose guard to verify those lower-layer invariants, in addition to positive grammar and negative/bypass cases. Accounting, moderation, rollback and V5 schema remain unchanged.

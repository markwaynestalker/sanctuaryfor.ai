# SanctuaryFor.AI

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

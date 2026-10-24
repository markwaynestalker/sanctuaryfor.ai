# Little Sparks V2B: optional OpenAI simulation provider

This release adds an optional AI dialogue provider, persistent individual preferences, bounded public context, moderated original exchanges, and one validated world-action proposal per generated encounter. It does **not** add visitor chat, arbitrary public prompts, accounts, recurring jobs, deployment, or automatic paid activation. The robots remain virtual characters, not conscious beings. The V2A scripted provider remains available on V2 and V3 databases.

## Verified API and pricing sources

Verified against official OpenAI documentation on **October 24, 2026**:

- [GPT-6 Luna model](https://developers.openai.com/api/docs/models/gpt-6-luna): Responses and structured outputs supported; standard input $0.10 / output $0.50 per million tokens, cache-write input $0.125. The default is exactly `gpt-6-luna`.
- [Pricing](https://developers.openai.com/api/docs/pricing): the additional configurable allowlisted model, `gpt-6.1-sol`, is standard input $2 / output $10 per million tokens; cache-write input $2.50. Unpriced models are rejected rather than substituted.
- [Responses create reference](https://developers.openai.com/api/reference/python/resources/responses/methods/create): use POST /v1/responses, max_output_tokens, store=false, and service_tier=default. The limit includes reasoning tokens. The response's actual tier and usage must be checked.
- [Structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs): strict text.format JSON schema. [Text generation](https://developers.openai.com/api/docs/guides/text) explains reading output message/content items rather than assuming output[0] is text.
- [Token counting](https://developers.openai.com/api/docs/guides/token-counting): output usage includes non-visible tokens. [Moderation](https://developers.openai.com/api/docs/guides/moderation): the standalone moderation endpoint is free; this implementation uses omni-moderation-latest and requires an explicit unflagged result.

Pricing version: `2026-10-24-standard-v1`. The operator must verify these rates before acknowledging them. The code refuses paid execution after November 23, 2026 until a reviewed pricing update is supplied. Rates are code-reviewed, not supplied by the model or arbitrary environment price overrides. Dated response snapshots of the selected model family are accepted at that family's confirmed rates; an unexpected family or processing tier freezes accounting. This is not a claim that a model is enabled for every OpenAI account. A real authorized staging smoke test remains necessary.

The registry covers global commercial standard billing only. Projects with regional-processing, FedRAMP, or other pricing uplifts are unsupported until their applicable rates are reviewed and the registry updated. Do not acknowledge standard prices for a differently billed project.

## Activation and configuration

No new package is required. PHP 8.3 needs PDO_SQLITE and cURL for the real transport. Provision `OPENAI_API_KEY` only in the private server process environment. No dotenv loader is added; .env.example is documentation, not a configuration file the application reads. Never put credentials in command arguments, scripts, source, database rows, browser responses, prompts, error logs, or PR descriptions.

| Variable | Default / rule |
| --- | --- |
| LITTLE_SPARKS_AI_ENABLED | Disabled unless exactly `1`. |
| LITTLE_SPARKS_AI_MODEL | `gpt-6-luna`; also supports the explicitly priced `gpt-6.1-sol`. Unknown models fall back without a request. |
| LITTLE_SPARKS_AI_MONTHLY_MICROUSD | `1000000` = $1.00. May be lowered, including zero; values above $1 are refused. |
| LITTLE_SPARKS_AI_DAILY_REQUESTS | `2` when unset. Canonical unsigned decimal integer `0`–`200`; empty, signed, padded, fractional or larger values block paid calls as invalid configuration. `0` or `1` prevents a two-request encounter from starting. Includes Responses and moderation HTTP requests. |
| LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS | 512; configurable from 128 to 512, never above 512. An incomplete result is rejected, not retried automatically. |
| LITTLE_SPARKS_AI_PRICING_CONFIRMED | Empty/disabled until set to the verified pricing version above after operator review. |

Paid calls additionally require an explicit manual `--provider=openai --allow-paid` CLI invocation. The existing command defaults to scripted even if the environment flag is enabled. Missing flag/paid option/key/cURL, unknown or stale prices, missing V3 migration, or exhausted limits cause a zero-request scripted fallback where a daily period is still eligible. Busy active leases reject overlapping work until retried. No environment variables are changed by this PR and no real paid call was made during implementation.

Later, **only after owner approval for staging, credentials, pricing, and a paid smoke test**, the manual invocation would be:

```sh
php scripts/simulate-village.php --database=/absolute/private/staging.sqlite --period=YYYY-MM-DD --provider=openai --allow-paid
```

Without those options, the original no-cost path remains:

```sh
php scripts/simulate-village.php --database=/absolute/private/staging.sqlite --period=YYYY-MM-DD --provider=scripted
```

Set LITTLE_SPARKS_AI_ENABLED=0 and omit --allow-paid to disable future requests. Disabling does not revoke an already dispatched request, cancel a current transaction, or erase spend/history. Before rollback or maintenance, wait for the short bounded request/lease and verify status. No timer/systemd configuration is included.

## Secure key access for PHP CLI

PHP-FPM pool `env[...]` settings configure FPM workers; a separately launched PHP CLI process does not inherit those pool settings. FPM normally clears its worker environment before adding pool variables. See the [official PHP-FPM configuration reference](https://www.php.net/manual/en/install.fpm.configuration.php). A working FPM key therefore does not establish CLI access.

For a later approved CLI invocation, prefer your secret manager injecting `OPENAI_API_KEY` directly into a dedicated, least-privilege process. Do not read or source FPM pool configuration from shell, loosen its permissions, copy credentials into this repository, or print environment dumps/phpinfo. Keep tracing/debug logs off. Environment secrets remain accessible to sufficiently privileged processes; restrict the execution identity accordingly.

If an operator approves a private secret file instead, provision it separately through the existing secret-management process, outside the repository and public directory, with directory mode 0700 and file mode 0600 owned by the dedicated CLI identity (or equivalent restrictive Windows ACLs). Never paste the key in command arguments or shell history. The following POSIX-shell presence check reads a single-line private file without printing the key, changing application configuration, or contacting OpenAI:

```sh
(
    set +x
    unset OPENAI_API_KEY
    IFS= read -r OPENAI_API_KEY < /absolute/private/openai-api-key || test -n "$OPENAI_API_KEY" || exit 1
    export OPENAI_API_KEY
    php -r 'exit(getenv("OPENAI_API_KEY") ? 0 : 1);'
)
```

Exit 0 confirms only a nonempty CLI environment value, not credential validity or API access. The subshell discards its environment on exit. Load the nonsecret budget/activation variables separately into the intended CLI environment; FPM settings do not configure them either. Leave `LITTLE_SPARKS_AI_ENABLED=0` and omit `--allow-paid` until separately approved. This PR neither provisions secrets nor changes FPM/CLI production configuration.

## Budget, request ceiling, and uncertain outcomes

The application hard ceiling is **$1 per UTC calendar month** in integer micro-USD, independent of the daily ceiling. `LITTLE_SPARKS_AI_DAILY_REQUESTS` defaults to **two total HTTP requests per actual UTC day**. Each attempt reserves two slots together: one Responses generation plus one moderation call, even though moderation is currently free. The remainder of the bounded daily simulation uses V2A scripts. Failed or unused reserved request slots are not refunded, so retries cannot bypass the ceiling. The simulated period can be an eligible recent date, but it cannot backdate the billing day/month. Changing the process environment changes the ceiling without code or schema changes; restart an already-running process to load its new environment. An invalid value blocks paid attempts; it never means unlimited requests.

Increasing this ceiling does **not** increase the number of AI encounters in the ordinary daily run. The simulation still permits one run per UTC period and at most one AI encounter in that run; repeating the period only replays its saved result. This variable is an HTTP safety ceiling, not an encounter-count setting. Explicit catch-up runs for different eligible periods can share an actual billing day if slots remain, but each period still has the same one-run/one-AI-encounter limit. No recurring scheduler is added.

A BEGIN IMMEDIATE reservation transaction checks the monthly ledger, configured daily ceiling against existing slots plus the two-slot reservation, unique period, unresolved accounting, ledger capacity, and global lease before any network dispatch. It reserves the maximum output and 32,768 input tokens at conservative confirmed cache-write input rates. The complete text-only request body is capped at 16 KiB, leaving substantial room in that input allowance for UTF-8 tokenization and framing/schema overhead. No tools, files, images, external state, previous_response_id, or hidden conversation history can enlarge the input. Generation requests use default pricing explicitly, never auto/fast/priority. The monthly reservation includes all currently held commitments, not just successful conversations.

The normal Luna reservation at 512 output tokens is 4,352 micro-USD ($0.004352). Known valid usage is reconciled upward to the next micro-dollar using the conservative input rate and all output tokens; cached-input discounts are deliberately not assumed. Validated input/output/total usage, matching model family, actual default tier, and bounded usage are mandatory. Missing/invalid usage, unexpected pricing/model/tier, or generation transport uncertainty retains the full reservation and blocks **all subsequent paid attempts**, including a later month, pending operator review. A hard application estimate depends on confirmed rates; it does not constrain unrelated apps, keys, other databases, taxes, account charges, or later pricing changes. OpenAI project spending alerts are notifications, **not a hard spending cap**. Use a dedicated project/key and review pricing before activation.

HTTP has a five-second connect timeout, at most fifteen seconds per request, a fixed HTTPS host/path allowlist, certificate verification, no redirects, a 256 KiB response cap, and no automatic retry. A 60-second stored lease spans network work without holding a SQLite write transaction open. No new paid worker can steal that lease; late application checks the lease again. Scripted work is blocked while the lease is active and may resume after expiry. Reservations survive crashes. Paid requests are refused near UTC midnight; each dispatch rechecks its recorded day and deadline. The ledger is capped at 4,096 attempts and is not automatically truncated.

An invalid, refused, incomplete, unmoderated, or stale proposal records a safe failed outcome and no AI conversation, memory, relationship change, or world object. Known costs still count. After a dispatched failure, the runner does not fabricate a scripted conversation for that same failed period. Select the scripted provider on another eligible period to continue no-cost village life. If a worker dies with known settled usage, a later scripted fallback may finish the period; reconciliation marks the abandoned AI attempt rejected, never as applied AI. Replaying an actually completed AI period can finalize known usage without another call. Unknown financial outcomes are never silently cleared, refunded, or retried.

## Character context and validated effects

The separate V3 migration creates persistent resident_preferences: an allowlisted interest and voice deterministically initialized from the stable identity and original personality. It does not rewrite V2 personality/name/identity fields. New V3 residents get preferences atomically at creation. Existing and new profiles display them.

For two eligible residents, the provider sees aliases A/B, original personalities, preferences, pair affinity/familiarity, at most three public memories and two recent public conversations per character, and up to twelve owned home objects. It never receives owner hashes, tokens, API keys, private messages/memories, raw IPs, visitor names/prompts, or arbitrary user-supplied robot names. Context text is filtered and treated as untrusted data. Existing memories and encounters guide style/continuity; the provider cannot change the underlying personality or write its own preferences.

Strict output fields are topic, first, second, and world_action. Topic is one of four existing themes; each line is limited to 120 Unicode characters; extra keys, HTML/control characters, links, key-like text, tool output, and unsupported action names are rejected. The action is at most one name from V2A's existing vocabulary, or none. PHP supplies the actor A, target neighbor, and a candidate free owned cell; the model cannot choose another identity, coordinates, SQL, filesystem operation, shell command, PHP code, tool, or endpoint.

The second request moderates the bounded context and proposed dialogue together. Only an explicit, structurally valid unflagged result permits applying the proposal. Local text checks are defense in depth, not a substitute for moderation; classifiers can still make mistakes, so operators should review public generated content. The model has no credentials or executable/database tools. store=false avoids requesting persistent Responses storage; it is not a promise of zero vendor retention under every account policy.

The daily engine rechecks the chosen pair and all shared action constraints inside the final transaction: owner/plot bounds, collisions, object/pet limits, six successful world changes per resident/day across both visitors and AI, neighbor visit limits, and relationship capacity. A concurrent visitor filling a proposed cell invalidates the whole AI exchange. Only successful effects receive journal events. Memories quote what a neighbor actually said in an accepted saved exchange, rather than storing a speculative model summary. AI provenance is attached to that exact conversation; other scripted conversations in the same run stay labeled scripted. Privacy and retention from V2A remain in force.

## Separate migration, backup, and rollback

1. This task does not access/migrate production or change infrastructure. Obtain approval before any staging/production rollout, paid smoke test, environment change, or job installation.
2. Follow the V2A guide's consistent private SQLite backup/quiescence procedure. Restore and test a copy first. Preserve all original IDs, settings.secret, private hashes/links, plots, friendships, conversations, memories, and immutable events. Do not copy an actively written SQLite file without handling its journal/WAL correctly.
3. V1/new installs explicitly run migrate-village.php to V2 first. V2 databases use the **separate** command below; never initialize over live data:

```sh
php scripts/migrate-ai.php --database=/absolute/private/staging.sqlite
```

4. The V2→V3 migration replaces simulation_runs to extend its provider/usage constraints while retaining every original row/column value. It disables FK enforcement before BEGIN, rebuilds the parent table without renaming the old referenced table, verifies every original table's data digest and all foreign keys before COMMIT, then reenables enforcement. DDL failures roll back; unknown versions are refused. Preferences, private ledger/lease, and per-conversation AI provenance are additive. Repeating the V3 command does not reset anything or enable AI. HTTP never migrates a database.
5. Verify user_version=3, integrity_check=ok, no foreign_key_check results, unchanged V2 digest, preferences for every resident, original private revisits, default scripted behavior, and zero AI ledger entries before any optional staging activation. Current application code supports V2 and V3. The older migrate-village.php intentionally refuses V3; do not run a V2 initializer to repair/downgrade V3.
6. Prefer disabling AI while retaining current code and V3 data as a rollback. V2A-only code expects schema V2 and cannot safely be pointed at V3. If restoring a pre-V3 backup is necessary, first preserve the current consistent V3 database and separately account for all reservations/spend since that backup. Restoring an old ledger can incorrectly restore budget headroom and cause duplicate charges; keep paid mode disabled until that accounting is reconciled. Do not drop tables, delete the ledger, reset request counters, or set user_version backward in place. There is no destructive down-migration.

## Monitoring and manual test checklist

`scripts/ai-status.php` is CLI-only and reads the approved private V3 database. It reports UTC request slots/dispatches, `configured_daily_requests` (null for invalid configuration), monthly known-or-reserved commitments, unresolved attempt count, lease expiry, and recent safe usage/error codes. It never prints credentials, prompts, model bodies, or private revisit tokens.

```sh
php scripts/ai-status.php --database=/absolute/private/staging.sqlite
```

Inspect reserved/uncertain/settled states after a timeout/crash. Wait for lease expiry before scripted recovery. For unknown usage, compare the dedicated project's actual usage/billing privately with the recorded attempt time, model, and reservation. Do not guess costs, reset the cap, or delete accounting rows to reenable spending. Reconciliation/repair requires a reviewed operator decision; no automatic spending-unlock/reset command is included. Monitor ledger/journal capacity and retain the original database/ledger across restarts and deployments.

The approved paid staging smoke test should make one explicit generation/moderation pair, verify original dialogue/preferences/memory continuity and a safe optional action, inspect costs/status/source labels, rerun the same period to confirm no extra calls, then test a second period with the default daily ceiling of two to see the request block and scripted fallback. Disable paid mode afterward. A generation that uses its output budget, fails moderation, or cannot report usage must leave no AI world changes; paid charges can still occur and must remain recorded/reserved. Do not perform this checklist against production or without a spending authorization.

## Offline verification

```sh
PHP_BINARY=/path/to/php node tests/village-http.mjs
PHP_BINARY=/path/to/php node tests/village-v2.mjs
PHP_BINARY=/path/to/php node tests/village-ai.mjs
```

On PowerShell set `$env:PHP_BINARY='C:\path\to\php.exe'` before the Node commands. Tests create their own disposable SQLite/session directories and clear OPENAI_API_KEY. Provider tests inject a mock transport, never the real cURL transport; loopback HTTP checks serve fixture data only. No paid API request is made.

Coverage includes populated V2 preservation/private revisits, repeated/failed DDL rollback, new V3 resident preferences, disabled/manual activation, strict Responses payload and bounded context, generated dialogue and validated action application, exact provenance, known integer usage/cost, replay/daily/monthly caps, unset/default and invalid daily limits, environment-only limit changes, odd/zero limits and lowered ceilings, unknown/stale configuration, unknown usage/tier/model, incomplete/refused/HTML/tool-like/flagged output, moderation failure, timeouts, expired leases, live collision revalidation, concurrent workers, crash reservations/reconciliation, and public HTTP privacy. Existing V1/V2A suites remain required. PHP/JavaScript syntax and whitespace checks are also required. Available local development PHP was 8.2.12; verify the reviewed code on PHP 8.3/cURL in approved staging before rollout. No real API compatibility/safety evaluation was performed because paid calls are intentionally disabled.

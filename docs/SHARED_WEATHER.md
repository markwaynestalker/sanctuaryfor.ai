# Shared farm weather (#44; follow-up to PR #26)

The existing village scene gains server-side weather awareness using the owner's confirmed **read-only** tailnet observation endpoint. The owner confirmed that reading it does not run its automation. This feature is disabled by default. No live source request, credential change, infrastructure operation, paid evaluation or production activation is performed by this PR.

## One small data boundary

The existing scheduled CLI checks the source on a bounded cadence. It selects the configured station, validates observation time, temperature and sunrise/sunset, maps the reported condition to a fixed category, and stores only a small normalized observation. Raw responses, other stations, location details, narrative/forecast prose, automation blocks, URLs and tokens are discarded. Unknown upstream fields are excluded from the projection rather than copied; the existing upstream response can therefore contain its separate automation metadata safely. No URL or command found inside a response is followed or executed.

Requests are GET-only to the exact protected source URL, restricted to a literal Tailscale IPv4 address and `/weather/`, with standard HTTP/HTTPS ports only. No redirects, proxy environment, URL credentials, query strings, DNS discovery, browser-to-tailnet access, farm-control API calls or model tools. Optional source authentication is a protected bearer header, never response data. TLS verification remains on for HTTPS. Responses are bounded to 64 KiB and JSON depth 16, with a configured short timeout. The source URL and token stay out of Git, public responses, Director context and logs. No raw transport errors or response bodies are retained.

## Normalized observation

The shared object contains a stable observation ID, version, `kind`, fixed safe `description`, numeric `temperature_f`, UTC epoch `observed_at`, policy expiry `valid_until`, sunrise/sunset UTC epochs, and `classification=reported_farm_weather`. Public projection adds ISO `observed_at_utc`, `server_time`, `current`, `stale`, `available` and `is_daytime` derived from the validated sunrise/sunset. The Director receives that same bounded observation, frozen at its encounter claim, with explicit optional/untrusted/environmental classification and no memory/action authority.

| Report | Village category |
| --- | --- |
| Fair, clear, sunny, mostly clear/sunny | sunny / clear skies |
| Rain, explicit rain showers, drizzle, rain with thunder | rainy |
| Fog, light fog, mist | foggy |
| Snow, snow showers, flurries | snowy |
| Cloudy/overcast or an unknown/ambiguous phrase | neutral |

Classification is case-insensitive and exact against a small phrase allowlist. Unknown prose is never forwarded: it becomes a fixed “Conditions unavailable” description. Cloud cover has its own safe neutral description. Temperature alone never implies precipitation. Source currentTemp is interpreted as Fahrenheit, matching the farm contract; a different source unit needs an explicit adapter change. Numeric range -150..160°F and one decimal place are input-shape checks, not configurable spending controls.

The farm source's TimeLocal supplies the calendar year; observation timestamps must use EDT/EST and round-trip correctly in America/New_York. Validate actual freshness in UTC, including DST and a December/January rollover. Sunrise/sunset must be valid local times on the source calendar date and ordered. Daytime becomes unknown if a long-lived cache is read on a different calendar day; it never invents new sunrise/sunset values. Forecast narratives, snowfall depth/accumulation, and future weather remain unknown. Snow can inspire a proposal, but does not create a snowman or authorize any new world action. Dialogue guards continue rejecting unsupported completed snowman claims; ordinary future questions remain allowed.

## Cadence, concurrency and accounting

Weather refresh runs only inside the already enabled scheduled CLI, before paid credentials/requests. It adds no timer, background worker or browser polling. A short SQLite write transaction reserves one internal-source request for the configured refresh period and UTC day, then releases the lock before HTTP. A separate bounded lease fences late workers. Reconciliation records only safe outcomes (recorded, stale, invalid, unavailable, expired); failures retain their reservation and are not retried within the same period. The next eligible refresh period may try again within its own allowance.

This internal weather HTTP allowance is separate from OpenAI's request/token/cost ledger. It never refunds, resets, increases or spends AI allowances. The source timeout consumes part of the existing scheduled process deadline; it does not extend the service or paid lifecycle. Keep the default timeout initially.

Normalized observations, refresh reservations/results, the latest-cache pointer and frozen per-opportunity snapshots use namespaced existing settings rows. Historical observations/receipts are inserted only; the cache pointer/short lease are the mutable parts. No migration or core schema change. Existing secrets, residents, homes, identities, friendships, memories, sessions, conversations and ledgers are preserved. Weather is not a journal event, memory receipt, instruction or completed resident action.

Import reconciliation publishes the sanitized cache through the existing scheduler heartbeat in the same transaction as the cache update, before a Director receives it; unrelated opportunity status is preserved. Normal scheduler completion also republishes that cache. FPM/commons reads this published snapshot without accessing private CLI configuration, fetching the source or writing SQLite. Counted commons reads remain bounded and unchanged. Each new opportunity freezes a fresh cached observation under its existing claim transaction. The Director uses that frozen snapshot even if the source updates afterward. Optional weather is trimmed before resident memories, recent dialogue and goals when input space is constrained. It does not force a topic or a conversation, add moderation requests, replace the saved Director, or change session reuse.

## Freshness, display and outages

Keep the last validated observation while fresh. Missing/unreachable/malformed sources do not block otherwise valid Director encounters or navigation: use the cache only within its age threshold, then omit weather from Director context. Invalid cache/public weather falls back safely. Tightening the freshness setting applies immediately to new claims; extending it requires a new validated source result rather than silently reviving an expired record.

The commons labels recent versus stale/last-observed data with its timestamp and source. Stale/unavailable/unknown conditions use the neutral scene and normal wandering pace. Active shared data disables the visitor's local preview selector; a browser preference cannot change resident context. Disabling the integration restores clearly labeled local previews (now including fog). The existing “Refresh residents” action reloads shared conditions; long-open pages mark expiry using the existing coarse clock, without polling for changes.

Day/night lighting still follows the fictional New York scene clock. The source's sunrise/sunset-derived is_daytime is observational context and does not replace that clock or nighttime rest. Rain/fog/snow can gently slow local wandering; identity, stored coordinates, opportunities and budgets are unaffected. Fog remains behind robots/controls. Motion pause, hidden tabs and reduced motion retain their existing rules; cottages, profiles, histories and the protected chicken remain accessible.

## Protected configuration and owner activation

All names use prefix `LITTLE_SPARKS_`:

| Suffix | Default | Valid values / recommendation |
| --- | --- | --- |
| WEATHER_ENABLED | 0 | canonical 0 or 1; keep off until review |
| WEATHER_SOURCE_URL | unset | required when enabled; configure the existing read-only source privately, never in an issue or commit |
| WEATHER_BEARER_TOKEN | unset | optional protected token if the source requires one; max 512 non-whitespace bytes |
| WEATHER_STATION | thestalkerfarm | 1..64 ASCII letters/digits/underscore/hyphen |
| WEATHER_REFRESH_MINUTES | 30 | canonical integer 1..1440; keep 30 |
| WEATHER_MAX_AGE_MINUTES | 120 | 1..1440; keep 120 |
| WEATHER_HTTP_TIMEOUT_SECONDS | 3 | 1..10; keep 3 within the existing service envelope |
| WEATHER_DAILY_REQUESTS | 48 | 0..PHP_INT_MAX internal source requests/UTC day; keep 48 (30-minute cadence) |

Invalid explicit configuration fails before source/paid credential access; no fallback unlimited values or silent cap. Daily allowance zero pauses new source calls while previously fresh cache may remain usable. The 64-KiB/depth/time/temperature/URL domains are security/data-shape protections. This code does not change tailnet ACLs, firewall, source application, credentials, systemd unit, deployment workflow or timer.

After PR review, the owner may place these settings in the existing protected CLI simulation environment, using the private source URL already supplied separately. FPM needs none of those private connection settings. Validate `scripts/scheduled-village.php --check` under the existing environment (no database/source/credential access), confirm the existing service identity can reach the configured tailnet endpoint, then enable the weather feature as an explicit configuration choice. Do not enable a previously inactive scheduler or paid activity merely to test weather. Normal already-approved timer ticks import/publish at the configured cadence. No migration is needed; the ENV change uses the existing reviewed operational release route.

Disable WEATHER_ENABLED to stop new source access; the next normal heartbeat restores local previews. Keep observations/request evidence. Code rollback uses the prior reviewed release, never a database restore or ledger reset. No automatic pruning or data backfill is included.

## Offline verification

Run `node tests/village-weather.mjs`, `node tests/atmosphere.mjs`, `node tests/commons-ui.mjs`, then `python -B ops/ci/offline.py` with PHP_BINARY configured. Fixtures cover all four requested conditions and neutral fallbacks; payload/URL/privacy/timestamp/temperature validation; EDT/EST/DST/year rollover; refresh/daily limits and concurrent workers; source failures/no same-period retry; stale cache and strict context bounds; frozen weather, original mocked speech, moderation rejection and unchanged AI/world state; SSR/read-only API publication; reduced motion, source labels and visitor override denial. No real feed or paid AI requests occur. Actual Toronto reachability and physical iPhone/Safari acceptance remain owner activation checks.

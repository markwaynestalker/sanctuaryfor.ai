# Village atmosphere and small-screen meadow

The village presentation clock follows `America/New_York` for everyone, including daylight-saving changes. Decorative clock bands are dawn 06:00–08:00, day 08:00–17:00, dusk 17:00–19:00 and night otherwise. These are fictional clock-based sunrise/sunset cues, not astronomical calculations. They never change UTC scheduling, billing windows, activity or resident knowledge.

Timestamp timezone is a separate browser preference. Choose UTC, New York, London, Tokyo, Sydney or the browser's recognized timezone. Journal/profile `<time datetime>` attributes and all API/database timestamps retain their original UTC values. Visible times and “Saved today” use the same selected zone; no-JavaScript pages retain their escaped UTC text. Unsupported/invalid preferences safely fall back to UTC. The preference applies across village, journal and profile pages in the same browser.

Local scene preview offers sunny/clear (default), rain, fog and snow. It is a decorative local preset, not live Toronto weather or a recorded village event. Two bounded CSS pseudo-elements provide the effects without polling, particles added each frame, audio or lightning. Pause Wandering and resident dialogs pause weather; reduced motion shows static cues. Hidden tabs pause weather and suspend the display clock. Resuming catches up directly, without backlog. There is no weather API, geolocation, provider prompt change or automatic AI request.

Only cosmetic timezone/weather values are saved in local storage (`sparks_display_timezone_v1`, `sparks_scene_weather_v1`). Denied storage falls back to in-memory preferences. Private revisit links and resident records never enter this storage.

[Village UI 2.0](VILLAGE_UI_V2.md) supersedes the old compact house-hiding rule: awake Sparks roam without visible cottages, while actual resting homes appear even at narrow widths. Saved garden objects remain visible and immutable. Full character/name spacing uses responsive rows with six phone neighbors and existing bounded neighborhood navigation. The directory, profiles and saved map remain accessible. Width-only changes reuse loaded state unless visible membership changes; a selected saved exchange stays readable across responsive rerenders.

## Optional shared weather follow-up (#44)

[Shared farm weather](SHARED_WEATHER.md) adds a separately enabled, sanitized server-side observation snapshot for both the scene and Director. Active shared conditions disable the local preview control. Stale/unavailable observations show a neutral scene; raw source/automation data never reaches browsers or the Director. New York lighting, nighttime rest and visitor timestamp preferences remain independent. The local-preview behavior above remains the default when shared weather is disabled.

## Verification and rollout

Run `node tests/atmosphere.mjs`, `node tests/meadow-layout.mjs` and `node tests/commons-ui.mjs`, then all existing offline suites with `PHP_BINARY` pointing at local PHP. Tests cover both New York DST transitions, selected-zone midnight, malformed/denied preferences, static motion/pause/visibility rules, compact geometry, six-resident separation, persistent saved speech, unchanged garden coordinates, sleep-driven homes and no width-only refetch. Existing HTTP suites check escaped UTC fallbacks and unchanged schemas/data on browsing.

Browser review: clear/rain/snow; narrow 320/390 and wider 430/desktop viewports; keyboard timezone/weather/profile controls; pause, dialogs and resume; journal/profile timezone persistence; readable names/dialogue/chicken; no horizontal overflow. Actual iPhone front-display/fold/unfold and Safari testing remains an owner verification, not something desktop Chromium proves.

This is a code/assets-only release: **no database migration, environment change, service/timer operation or paid call** is needed. Deployment remains owner-controlled; preserve any local production modifications and use the existing release process. Asset URLs use content hashes. Roll back these presentation files together to the previous release if needed; never restore/reset SQLite for a cosmetic rollback. Christopher Robin Mode (#15) is outside this PR.

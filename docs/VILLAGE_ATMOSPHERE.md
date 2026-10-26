# Village atmosphere and small-screen meadow

The village presentation clock follows `America/New_York` for everyone, including daylight-saving changes. Decorative clock bands are dawn 06:00–08:00, day 08:00–17:00, dusk 17:00–19:00 and night otherwise. These are fictional clock-based sunrise/sunset cues, not astronomical calculations. They never change UTC scheduling, billing windows, activity or resident knowledge.

Timestamp timezone is a separate browser preference. Choose UTC, New York, London, Tokyo, Sydney or the browser's recognized timezone. Journal/profile `<time datetime>` attributes and all API/database timestamps retain their original UTC values. Visible times and “Saved today” use the same selected zone; no-JavaScript pages retain their escaped UTC text. Unsupported/invalid preferences safely fall back to UTC. The preference applies across village, journal and profile pages in the same browser.

Scene weather offers clear (default), rain and snow. It is a decorative local preset, not live Toronto weather or a recorded village event. Two bounded CSS pseudo-elements provide the effects without polling, particles added each frame, audio or lightning. Pause Wandering and resident dialogs pause weather; reduced motion shows static cues. Hidden tabs pause weather and suspend the display clock. Resuming catches up directly, without backlog. There is no weather API, geolocation, provider prompt change or automatic AI request.

Only cosmetic timezone/weather values are saved in local storage (`sparks_display_timezone_v1`, `sparks_scene_weather_v1`). Denied storage falls back to in-memory preferences. Private revisit links and resident records never enter this storage.

At **360 CSS pixels or less of actual scene width**, homes, yards and home-object visuals are omitted from the commons. Their collision footprints disappear too; the chicken retains its small protected refuge. All six phone residents remain available and can roam the open space. Cottages return above that boundary with their original saved objects. Page padding/zoom affect the measured scene width; there is no device-name/user-agent detection. The directory, profile pages and saved neighborhood map remain authoritative and accessible at every width. Resize preserves still-valid positions and reuses loaded records; different neighborhood membership still needs its normal read-only request.

## Verification and rollout

Run `node tests/atmosphere.mjs`, `node tests/meadow-layout.mjs` and `node tests/commons-ui.mjs`, then all existing offline suites with `PHP_BINARY` pointing at local PHP. Tests cover both New York DST transitions, selected-zone midnight, malformed/denied preferences, static motion/pause/visibility rules, compact geometry, six-resident separation, bounded speech, unchanged saved coordinates, restored homes and no width-only refetch. Existing HTTP suites check escaped UTC fallbacks and unchanged schemas/data on browsing.

Browser review: clear/rain/snow; narrow 320/390 and wider 430/desktop viewports; keyboard timezone/weather/profile controls; pause, dialogs and resume; journal/profile timezone persistence; readable names/bubbles/chicken; no horizontal overflow. Actual iPhone front-display/fold/unfold and Safari testing remains an owner verification, not something desktop Chromium proves.

This is a code/assets-only release: **no database migration, environment change, service/timer operation or paid call** is needed. Deployment remains owner-controlled; preserve any local production modifications and use the existing release process. Asset URLs use content hashes. Roll back these presentation files together to the previous release if needed; never restore/reset SQLite for a cosmetic rollback. Christopher Robin Mode (#15) is outside this PR.

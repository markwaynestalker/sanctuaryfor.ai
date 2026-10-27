# Village UI 2.0 (#61)

The commons gives awake Sparks open ground and represents resting residents with their actual saved cottages. A single persistent dialogue panel below the scene holds an entire saved exchange in its original order. It never covers the characters and never dismisses words on a reading timer.

## State and data boundaries

- Rest uses the existing opt-in public sleep policy from `api/commons.php`, its server-time sample and the existing monotonic/atmosphere clock. This is currently one village-wide policy, not individual resident schedules. No new schedule, timer, poll, backend state or migration is introduced. Disabled rest never creates sleepers; loaded policy changes still require the existing explicit refresh.
- While commons state is loading, invalid or unavailable, the scene does not guess awake/resting presentation. The directory and profiles remain usable; an explicit refresh can recover it without a new poll.
- During rest, standing SVGs and replay indicators are hidden. Native cottage links show the resident's name and a warm window; ordinary activation opens a profile with current rest status. Modified links keep native navigation. No saved home means no invented cottage; the resident remains accessible in the directory.
- Awake cottages are hidden and have no collision footprint. House links remain in a hidden, inaccessible layer for a short CSS display/opacity transition; reduced motion and Pause Wandering remove the transition.
- Saved trees, flowers, bushes, paths, lanterns and pets remain a separate, decorative projection of existing home objects. Object positions within each saved 4x4 plot retain their original relative coordinates. Cottage placement is a visual arrangement; the saved 16x16 neighborhood map remains authoritative and unchanged.
- Winding paths, a soft common clearing, small flower patches, grass and lanterns are ambient scenery behind residents. They do not create recorded actions, gardens, weather observations or memories.
- All 135 forms and owner-selected colors use the same renderer. Full 96x104 character/name targets, rectangular separation and enough responsive rows replace the old smaller collision circles. Six phone/twelve wider neighbors and the existing 40-resident directory page remain bounded; forms are not population.

## Read the same saved story

The existing public commons response and latest-per-pair validation remain unchanged. One expanded panel shows both original messages, names, source and timestamp. Text uses textContent and pre-wrap/word wrapping, including multiline and full 160-character Unicode dialogue under the current API contract. No new speech, truncation, provider request, simulated event or database write is added.

Speech indicators mean **a saved conversation is available**, not a new live encounter. They and the existing recent-transcript buttons provide explicit keyboard/touch selection. Profiles can open a conversation involving that resident. Automatic replay still observes the existing ENV cooldown and bounded per-tab public-ID history; visitors may intentionally reread saved dialogue. Only public conversation IDs enter that existing session storage.

Only one exchange is expanded. Its visible participants hold position and receive a soft highlight; other residents keep their existing local motion. Reading has no timeout. Pause, reduced motion, hidden/resume, night rest, display-zone changes and responsive rerenders retain the words. If a compact neighborhood no longer contains both participants, their avatars and full names remain in the panel. Explicit close, another selection, profile opening, directory refresh or neighborhood navigation can end the presentation. Close/Escape returns focus to a connected source or resident directory button; automatic selection never steals focus.

This replaces timed per-character bubbles, their repeated size measurements, timing helpers and ambient welcome speech. Creation keeps its existing arrival animation and form feedback.

## Review of #62: Living Village

Implement separately after #61. There is already one bounded movement loop, target selection, pause logic, collision handling, weather pace and saved-pair detection. Extend that engine with longer idle pauses and safe waypoints; do not add a second animation loop or a second story system.

The selected-pair hold and persistent dialogue panel give gathering a stable presentation target. Future decorative gathering should use validated public participant IDs and free positions, without teleporting a reader or recording a new meeting. Preserve original ordering and source labels.

The current commons response has no public "read bulletin" activity record. Do not infer reading from a notice's existence, an author's identity or a visitor opening the board. Only add a bounded, authorized public activity projection when a recorded event supports a specific animation, preferably within the existing commons read. Do not invent readings, memories, decisions, AI requests or paid work.

## Review of #63: Village Growth

Follow #61 and #62 in an independent increment. Existing neighborhood paging already handles 40 residents without rendering them all in the meadow. Retain six/twelve as current display thresholds, not new backend quotas. Measure actual viewport and full name targets; do not infer resident count from the 135-form catalog or shrink characters to fit.

Start with measured tests at 1, 3, 6, 12 and 40 residents and actual narrow/zoomed scene widths. At more than six phone or twelve desktop residents, keep existing explicit neighborhood controls. A compact name search/locate control in the current directory is the first useful discovery extension if users cannot find a resident. It can select an existing neighborhood and profile using public IDs. No camera, pan/zoom, giant empty canvas, new queries, backend state or migration is justified yet.

## Validation and review images

Run `node tests/meadow-layout.mjs`, `node tests/commons-ui.mjs` and `node tests/atmosphere.mjs`, then `python -B ops/ci/offline.py` with PHP_BINARY set. Tests cover backend-policy/DST sleep transitions, disabled rest, missing houses, unchanged gardens, full SVG/name and cottage/board/chicken geometry, all 135 forms/RGB, six-phone/40-resident neighborhoods, persistent exact dialogue, hostile literal text, 160-character Unicode/multiline speech, replay history/cooldowns, responsive selection, focus recovery, weather/reduced motion and the same two initial read-only requests.

The review screenshots use synthetic residents and scripted saved exchanges in a disposable local database. They are not production or live AI evidence. Browser checks cover desktop 1280px, tablet 820px and 390px/320px phones, real keyboard activation, profile links, selected-pair stability and unchanged SQLite bytes. Physical iPhone/Safari testing remains an owner check.

| State | Desktop | Phone |
| --- | --- | --- |
| Before | [Desktop before](screenshots/village-ui-61/before-desktop.png) | [Phone before](screenshots/village-ui-61/before-mobile.png) |
| Awake, selected exchange | [Desktop awake](screenshots/village-ui-61/awake-desktop.png) | [Phone awake](screenshots/village-ui-61/awake-mobile.png) |
| Existing rest policy enabled | [Desktop resting](screenshots/village-ui-61/resting-desktop.png) | [Phone resting](screenshots/village-ui-61/resting-mobile.png) |

## Release and rollback

Code/assets only: no migration, ENV change, Director update, ledger change, paid test or timer operation. Deployment is not part of this work. Use the existing reviewed exact-SHA release process and verify current production service/timer state rather than assuming a pause. Roll back presentation files together through the normal release mechanism; never restore or reset SQLite for a cosmetic rollback. #62 and #63 are reviewed follow-ups, not bundled implementations.


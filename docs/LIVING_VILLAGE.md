# Living village presentation (#62)

The existing commons animation loop now alternates short, slow walks between safe waypoints with longer idle pauses. Decorative pace is independent of owner-written personality or appearance. One extra row of common space gives full-size names room to pass, including on phones. Homes, saved gardens and map coordinates are unchanged.

Automatic saved-dialogue presentation can gently gather its two actual participants into nearby free positions. Targets respect full name/character footprints, other residents, houses, the board and chicken. There is no teleportation. A bounded approach that cannot finish presents the same exchange in place. Explicit reading is immediate. The panel still holds both participants until closed; it never changes message order or wording. Only one presentation/gathering can run. Pause, reduced motion, hidden tabs, profiles, neighborhood/layout changes and rest cancel the approach without consuming a replay. Interrupted movement never creates an event or memory. Closing a finished presentation returns the pair to a pause, then ordinary idle movement.

The existing public commons GET now also returns `bulletin_posts`: at most one latest active public notice per visible resident, bounded to 24 IDs. Fields are only notice ID, resident ID, name and creation timestamp. It reuses the board's completed Director-run provenance and removal rules; absent add-ons return an empty list. No body, session, run, private memory or credential is exposed. Availability/revision checks and one set query are bounded, never per-resident queries. Existing pre-bulletin fixtures take five reads; installed revision 1 adds the revision check and projection (seven total).

A small pin and native board links mean **saved public authorship**, explicitly not live reading. There is no recorded board-reading event today, so no reading animation is invented. Notice contents are still unverified author expression, not confirmed world facts. Withdrawing or expiring a notice removes its indicator on the next existing explicit refresh. No additional browser request, polling loop, model request, timer, ENV control, schema or database write is introduced by viewing these states.

## Checks and review

Run `node tests/meadow-layout.mjs`, `node tests/commons-ui.mjs`, `node tests/village-bulletin.mjs`, `node tests/journal-http.mjs` and all offline suites through `ops/ci/offline.py` (PHP_BINARY required locally). Geometry tests retain broad exploration over a longer observation window, require a majority of idle time, and check bounded speed, no overlap, safe gathering/cancellation and focus holds. DOM/API tests cover original text/source, existing replay controls, rest/weather, all 135 forms/colors, native touch/keyboard links, hostile literal names, optional/removed/expired/unproven notice records and unchanged SQLite/accounting.

Review images use synthetic residents and saved scripted exchanges in a disposable database, with all external browser traffic blocked. No live AI or production evidence is implied. Physical iPhone/Safari remains an owner check.

| Scene | Desktop | Phone |
| --- | --- | --- |
| Before | [Before](screenshots/living-village-62/before-desktop.png) | [Before](screenshots/living-village-62/before-mobile.png) |
| Occasional movement / saved reading | [After](screenshots/living-village-62/after-desktop.png) | [After](screenshots/living-village-62/after-mobile.png) |

Code/assets only; no migration or ENV changes. No production operation is part of development. Use the normal reviewed release process, verifying current timer/service state. Cosmetic rollback restores code/assets together and never restores or resets SQLite. Director generation, moderation, scheduling, quotas, request reservations and cost commitments are unchanged. #63 remains a separate population/discovery follow-up.

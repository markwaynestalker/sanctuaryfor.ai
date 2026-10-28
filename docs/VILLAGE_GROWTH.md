# Village growth and resident discovery (#63)

## Decision

Keep the existing six-resident phone and twelve-resident wider neighborhoods. The directory already loads at most 40 residents per page and has native page links. Measured geometry at 1, 3, 6, 12 and 40 residents fits full-size characters/names using existing rows; no camera or pan/zoom is justified by this review. All 135 cosmetic forms continue through the shared renderer and existing exhaustive tests. Population is independent of the avatar catalog.

This increment adds page-local name discovery and an explicit Locate action. It does not change participant scheduling, world state or the village-depth plan from #65.

## Visitor behavior

- **Find a resident on this page** filters only the already loaded directory names, using literal case-insensitive Unicode-normalized substring matching. It makes no request and does not rebuild avatars, alter visible meadow membership or dismiss saved dialogue. A live count explains matches and the help text states the page boundary. Native Clear restores all loaded names and focuses the search field.
- Each directory entry retains its profile button, full saved name, form/color and existing rest indicator. A separate **Locate** button selects the resident's existing neighborhood by immutable public ID, so duplicate names are independent. Characters stay full size; no camera, animated pan or invented resident movement is added.
- Awake Locate focuses the actual character. During the existing opt-in shared rest policy it focuses only that resident's actual saved cottage. Missing homes or unavailable shared state retain a connected directory control and explain the state. Profile access remains available.
- A changed neighborhood uses the existing scoped commons GET; locating within the current group reuses loaded data. Typing never triggers requests. Late completion does not steal focus if the visitor has moved to another control. There is no polling, additional timer, frame loop, backend query or API endpoint.
- Locate retains the current original saved transcript, even if its participants leave the visible neighborhood. Filtering does not interfere with reading, Pause Wandering, reduced motion or rest. Close restores a connected source, visible directory profile button or the search field if that resident is filtered out. Resting cottage rebuilds restore focus to the same resident after resize or to an available fallback.
- A same-page explicit refresh retains the local query. Switching to another directory page resets it. Searching does not fetch other pages; use the existing Previous/Next links. With JavaScript disabled the search/Locate enhancement stays hidden and all existing server-rendered details, profile links and pagination remain native.

## Geometry and thresholds

The bounded group is laid out using measured scene width and full 96x104 character/name targets, rectangular separation, saved homes/gardens and board/chicken obstacles. Width-only changes reuse loaded data unless visible membership changes. Saved plot coordinates and object offsets are never rewritten. Awake cottages have no collision areas; shared rest shows only actual saved cottages at every width.

Representative awake heights from the unchanged geometry engine (pixels):

| Actual scene width | 1 resident | 3 residents | 6 residents | 12 residents | 40 residents |
| --- | ---: | ---: | ---: | ---: | ---: |
| 120, zoomed phone | 535 | 755 | 1085 | 1085 | 1085 |
| 278, compact phone | 535 | 645 | 755 | 755 | 755 |
| 350, phone | 535 | 535 | 645 | 645 | 645 |
| 740, tablet | 535 | 535 | 535 | 645 | 645 |
| 1168, desktop | 535 | 535 | 535 | 645 | 645 |

Counts above six on phones or twelve on wider screens use explicit neighborhoods rather than additional scene characters. At 40 residents this is seven phone groups or four wider groups. Very narrow/zoomed scenes grow vertically instead of shrinking characters or creating horizontal overflow. A small population keeps the existing compact scene where full footprints permit it.

Consider a camera only after measured user/geometry evidence shows existing paging and page-local discovery cannot support a concrete task. A backend-wide name search would be a separate permission-aware, bounded design; this work does not pretend to search beyond the current directory page.

## Validation and synthetic images

`tests/meadow-layout.mjs` covers the 1/3/6/12/40 population matrix at actual scene widths 120, 272, 320, 600 and 1000 for both capacities, as well as the existing long motion/gathering checks. It verifies full footprints, resting homes, unchanged saved coordinates, bounded groups and compact small populations.

`tests/commons-ui.mjs` covers Unicode/case/literal search, duplicate names/IDs, empty results/population, no search requests or scene-node replacement, same/different neighborhood Locate, missing-home/rest behavior, selected speech preservation and connected focus after resize. Existing suites retain all 135 forms, profiles, weather, shared rest, saved dialogue and replay accounting. Local validation passed with PHP 8.2.12/Node 20.15.1 on Windows: 134 PHP syntax checks, 31 JavaScript syntax checks, deployment tests and all 23 offline suites. The initial run hit an existing staging-commitment assertion in unchanged Director code; the serial full rerun passed without changing that code or its assertions. Ten Linux-specific deployment tests are skipped on Windows and must pass Ubuntu CI. Run all offline checks with `PHP_BINARY=/path/to/php python -B ops/ci/offline.py`.

The optional `tests/browser/village-growth.cjs` uses Playwright and PHP with disposable synthetic databases. It reviews 1/3/6/12/40 residents at viewport widths 1280/820/390/320, a 41-resident second-directory-page case, real Enter-key Locate, delayed-response focus safety, no-JS profiles, existing shared rest, full DOM rectangles/no horizontal overflow and byte-identical SQLite before/after viewing. External browser traffic is blocked and provider credentials/paid flags are cleared. No production or live AI evidence is implied. Physical iPhone/Safari remains an owner check.

Example optional review command, with Playwright already installed and PHP configured:

```sh
PHP_BINARY=/path/to/php node tests/browser/village-growth.cjs
```

`NODE_PATH` may identify an existing Playwright package. `CHROME_BINARY` optionally selects an installed Chrome. Screenshots default to `docs/screenshots/village-growth-63`; an output directory can be supplied as the first argument. Review images capture stable full-page clips after input/request assertions, preserving the tested viewport.

| Synthetic state | Desktop | Tablet | Phone | Compact phone |
| --- | --- | --- | --- | --- |
| One resident | [Desktop](screenshots/village-growth-63/1-awake-desktop.png) | [Tablet](screenshots/village-growth-63/1-awake-tablet.png) | [Phone](screenshots/village-growth-63/1-awake-phone.png) | [Compact](screenshots/village-growth-63/1-awake-compact.png) |
| 40 residents, bounded scene | [Desktop](screenshots/village-growth-63/40-awake-desktop.png) | [Tablet](screenshots/village-growth-63/40-awake-tablet.png) | [Phone](screenshots/village-growth-63/40-awake-phone.png) | [Compact](screenshots/village-growth-63/40-awake-compact.png) |
| Filtered directory | [Desktop](screenshots/village-growth-63/discovery-desktop.png) | [Tablet](screenshots/village-growth-63/discovery-tablet.png) | [Phone](screenshots/village-growth-63/discovery-phone.png) | [Compact](screenshots/village-growth-63/discovery-compact.png) |
| Shared rest, saved homes | [Desktop](screenshots/village-growth-63/40-resting-1280.png) | — | — | [Compact](screenshots/village-growth-63/40-resting-320.png) |

## Release boundary

Code/assets only, with optional offline review fixtures and documentation. No schema, migration, ENV, hosted Director, memory, moderation, scheduling, cost commitment, production state or deployment change. Normal asset hashes update automatically. Rollback restores code/assets together through the reviewed release process and never restores SQLite to undo presentation. The #65 depth roadmap remains independent.

# One saved village story

The animated commons, journal and profiles now read existing saved dialogue. Browsing never invokes a simulation or OpenAI. There is no schema change, migration, infrastructure change or production-data operation in this UI release.

## Journal

Thirty public events remain one page. A single page-scoped dialogue query joins each conversation event's run ID and both participants; legacy keys additionally select the exact conversation ID. This handles multiple pairs and multiple exchanges per run without mixing their messages. Public legacy quiet-time conversations are included; solitary quiet events, missing/pruned conversations and private exchanges keep their summary only. AI provenance comes from ai_conversations per exchange, never merely from the run's provider. All names and dialogue are escaped in PHP. The reader performs three queries on a page containing conversations, independent of event/message count. Pagination and history remain unchanged.

## Read-only commons endpoint

`GET /api/commons.php?ids=<comma-separated resident IDs>` accepts 1–24 distinct visible residents (32 lowercase hex characters per ID; oversized/invalid inputs are rejected). It returns:

- `homes`: only actual assigned home plots with saved house objects, including resident ID, public plot bounds/sector and approved house variant.
- `conversations`: the newest public, completed exchange per visible unordered pair, capped at 64 recent pairs. Each has ID, saved timestamp, `openai`/`scripted` source and two ordered messages with speaker ID, public name, exact text and saved conversation timestamp. Individual message timestamps do not exist in the schema, so the shared saved conversation time is used.

Matching public conversation events are also required, keeping the meadow consistent with the journal's visibility. No private memories, revisit credentials, owner hashes, raw prompts, usage ledger or API key are returned. Body length is bounded to the schema's 160 characters; public names to 64. The endpoint sets no session/cookies, is GET-only, uses parameterized queries, no-store headers and SQLite query_only. It performs three reads, not N+1 queries. Existing indexes and SQLite window functions select the latest pair exchange without a migration.

The browser fetches this snapshot on arrival, explicit resident refresh/paging, creation or a responsive scene-population change. There is no timer/polling. Only visible residents are requested (eight on narrow screens, twenty-four otherwise). The scene never makes an AI request. If data is unavailable or a pair has no saved exchange, ordinary wandering/idle pauses continue without invented dialogue.

## Meadow, homes and navigation

When a pair meets, its newest available saved exchange plays in recorded order, on the correct speaker, one bubble at a time. Each bubble identifies its source and saved UTC timestamp. Read times scale with message length, one exchange plays at once, and each exchange has a local replay cooldown. No local replay writes an event or claims a new encounter occurred. The hardcoded personality phrases are removed. The only welcome bubble is explicitly labeled ambient.

The stationary “Read recent saved conversations” panel shows up to six recent pair exchanges and remains usable with reduced motion or paused wandering. All dynamic text uses textContent, never innerHTML. Pausing, hidden tabs and profile dialogs stop playback. Existing population/animation bounds remain; bubble DOM changes only when the speaking turn changes.

Cottages are native links, colored/labeled for visible owners with actual saved houses. The upper cottage band and lower wandering corridor keep house/robot bodies apart on phones and desktop. This is a decorative arrangement: saved plot coordinates are never edited, and the existing neighborhood map remains authoritative. No generic or nonexistent home is rendered.

The robot popup uses an explicit selected-resident `/resident.php?id=...` anchor. It closes the top-layer dialog without cancelling native navigation, retaining keyboard and browser link behavior. Deferred scripts and shared village styles use content-versioned asset URLs so an older cached script cannot leave newly rendered controls inert. Stale assets are a possible explanation for the reported production symptom; no production access was used to diagnose it. Popup/profile/journal copy now reflects saved scripted and AI-generated exchanges.

## Offline verification

```sh
PHP_BINARY=/path/to/php node tests/journal-http.mjs
node tests/commons-ui.mjs
```

Also run the existing village-http, village-v2, village-ai and village-activity suites, PHP lint and JavaScript syntax checks. All databases/servers are disposable fixtures; all AI transports in the existing suites are mocked and keys cleared. No live API calls occur. The new suites cover V2/V3/V4 compatibility, matching multiple exchanges per run, exact provenance, private/missing data, newest visible-pair selection, real/missing homes, constant query counts, unchanged schemas/records, injection escaping, asset versions, modal navigation on desktop/mobile, sequential speaker identity, idle fallback, reduced motion and absence of background polling.

Manual browser QA on a fixture: open each robot's modal and follow its profile link; click its cottage; check narrow and desktop viewports for overlap/overflow; read the stationary transcript; pause/resume wandering; enable reduced motion; navigate by keyboard; refresh/paginate and verify houses/dialogue follow the visible residents. Current local runtime is PHP 8.2.12; approved PHP 8.3 staging verification remains required. No automatic deployment is included.

# Sanctuary Live: dialogue first

V2E runs on the existing V5 schema. No migration is required. It preserves saved conversations, source stories, receipts, friendship identities, credentials, opportunity IDs and AI accounting. No production operation, paid call, deployment or scheduler activation is authorized by this implementation.

## Original conversations

The reflection enum is removed from the V5 paid response schema. One Responses call writes an original two-turn exchange, using both saved personalities, preferences, relevant memories, recent attributed dialogue and shared history. The prompt asks the second turn to respond to the first, with varied phrasing and distinct voices. Neither sentence is selected from a menu. Scripted fallback remains curated and clearly labeled.

The strict structured response still contains outcome, topic, first, second, world_action and story. Both free lines are at most 120 Unicode characters, the final composed line at most 160, the decoded JSON at most 4096 bytes, context and complete HTTP bodies retain existing bounds, and output remains at most the configured 512 tokens. One leading A:/B: is safely normalized after raw-text validation; repeated/other role or resident-name labels are rejected. HTML, controls, suspicious credentials/code/URLs, unsafe content and unsupported actions are rejected. No tools or provider-managed history are added. The [official Structured Outputs guide](https://developers.openai.com/api/docs/guides/structured-outputs) supports schema constraints; it does not establish factual truth or conversational quality.

### Imagination and history have different jobs

Free text expresses questions, gentle humor, metaphors, present feelings, opinions, possibilities and future plans in English. Stars dreaming about flowers and trees hearing music need no historical source. Such text never creates a confirmed world fact. Narrow meaningful self-statements can still create explicitly unverified statement/interpretation memories through the existing deterministic extractor; ordinary questions do not.

Saved historical narration uses an offered story alias belonging to the first speaker, as in V5. Its exact PHP-composed opening is supplied before generation so the model can write a natural continuation and responsive second line. PHP revalidates the source, receipt chain, knowledge level and current world status, then prepends that opening. It never accepts raw provenance IDs or model declarations of verification. The final composed exchange is moderated, saved, and checked against the actual utterance before a receipt can be committed. Stale/private/contradicted/forged sources, or a rejected world action, roll back the whole encounter. An unmentioned story cannot acquire a receipt. Existing multi-hop and duplicate/cycle rules stay intact.

The practical boundary deliberately disallows free-form historical paraphrases: common past event/witness/telling verbs, past-time references, possession claims about world objects and role labels fail locally, even inside a question, negation or quotation. Use the offered opening to narrate history; free continuations must not restate it. This conservative English lexical check can reject benign wording and is **not a general semantic proof** against every possible invented historical sentence. Do not present it as one. Prompting, local checks and moderation are defense in depth; only independently verified database sources can confer confirmed status, regardless of what any missed phrase says. Extending historical paraphrases or language support requires reviewed tests, not trusting the model's self-assessment. No extra paid fact-check/extraction request is hidden in an encounter.

### Accounting and validation

Explicitly empty/zero/leading-zero output-token settings no longer fall back to 512: output is canonical 128–512, monthly micro-USD is canonical 0–1000000, and an explicitly empty model is unpriced. Unset values keep their existing defaults; zero monthly budget blocks paid calls. These stricter checks close legacy fallback ambiguities without increasing any allowance.

Generation plus moderation still reserves two HTTP slots atomically before dispatch. AI defaults off, daily HTTP defaults to two/max 200, the application monthly maximum stays $1, and uncertain usage remains frozen. No retry is added. Natural prose can use more output tokens than short V5 reflections, but cannot raise the token, body, request or spending ceilings. At the existing reviewed Luna rates, a hypothetical 2000 input/180 output tokens estimates 340 micro-USD ($0.000340); this is an arithmetic illustration, not a measured production average or exact provider bill. The existing worst-case 512-output reservation remains 4352 micro-USD ($0.004352). Pricing confirmation and its expiry still apply.

Run `PHP_BINARY=/path/to/php node tests/village-live.mjs` plus all eight existing suites. The new offline suite reuses populated disposable V5 fixtures and mocks both endpoints: original non-canned exchanges, joint voice/coherence prompting, exact moderated saved wording, source-backed narration, speculation without fact promotion, prefixes, hostile/unsupported output, quiet/none, rollback, idempotency and preserved history. Mocks cannot establish real model quality; any live evaluation remains a separate owner approval. Available local PHP is 8.2.12; PHP 8.3 staging verification remains required.

## Quieter saved-dialogue replay

### Compact neighborhood, open commons

Cottage links occupy fixed 68×86-pixel patches on phones and 90×86 on desktop, with softly rounded garden ground rather than stretched rectangular plot boxes. Their actual saved trees, flowers, paths, bushes, pets and decorations retain the same relative 4×4 placement. This remains a decorative projection: the authoritative 16×16 property map, object ownership and saved coordinates are untouched.

Up to four phone/twelve desktop residents share a scene; the paginated directory retains the full page. Homes form compact staggered rows along the hillside, and height adapts for larger visible neighborhoods. Three residents occupy under one fifth of tested scene area. Movement starts from the ground plane, not below the entire home band; only small cottage-body and chicken footprints obstruct it. No whole yard becomes a forbidden rectangle.

Local pixel-based wandering uses independent waypoints, personality-based speeds, varied pauses, short-range separation, obstacle sliding and opposite departure targets after meetings. It retains bounded requestAnimationFrame updates and pauses for hidden tabs, modals and reduced motion. Names, cottage links, focus/touch targets and the chicken remain accessible. No world mutation or server request is introduced by movement.

`node tests/meadow-layout.mjs` simulates thousands of steps for phone/desktop layouts with three to twelve residents. It checks compact home coverage, free-ground ratios, unchanged input coordinates, viewport containment, local obstacle clearance, broad two-dimensional exploration, minimum separation, natural approaches and post-conversation departure. Browser QA at 375×812 and 1280×900 confirmed compact gardens, dominant shared ground, no horizontal overflow, aligned mobile collision geometry, working keyboard cottage links and the exact saved natural transcript. Existing commons tests cover ordered playback, safe DOM text, idle silence and reduced motion.

The commons endpoint remains a bounded public, query-only snapshot: latest public saved exchange for each visible pair, exact speakers/order/text/source/UTC time and actual homes/objects. No polling or generation on arrival is added. Use **Refresh residents** to discover newly saved life; the page states when it last checked. Existing stale-response guards remain. Saved-today and older-journal labels make playback explicit.

Each exchange plays at most once per tab session. Only public conversation IDs and the last playback time are retained in sessionStorage under sparks_public_replays_v1, never resident credentials, prompts, tokens or dialogue text. This history survives page navigation, explicit refresh and responsive re-rendering. Storage denial falls back to in-page memory; a full reload can then forget played IDs. History has a fixed safety bound of 1024 IDs; at capacity animation stays quiet rather than evicting entries and repeating them. The stationary transcript remains available. Pausing, hidden tabs, profile dialogs and reduced-motion behavior are preserved.

LITTLE_SPARKS_MEADOW_REPLAY_COOLDOWN_SECONDS retains default 60/range 0–3600, now spacing the starts of **different unplayed** exchanges rather than permitting continuous repetition of one. Zero removes that additional wait, never the once-per-session rule or ordered reading time. It has no effect on backend opportunities, events, HTTP request accounting or budgets. Tests cover storage denial/reload, capacity, exact cooldown expiry, explicit refresh and new-ID discovery without background requests.

## CLI operator view

`php scripts/ai-status.php --database=/absolute/private/staging.sqlite` is the read-only dashboard. Access is controlled by the host's normal shell/account and private database permissions. There is no public operator route, browser authentication shortcut or configuration toggle. The command uses SQLite query_only and one read transaction for a consistent snapshot.

Alongside the existing budgets/leases/limits, the report includes opportunities/outcomes today, successful conversations, world actions, activity for up to 40 residents, latest encounter and latest AI/scripted conversation metadata, new stories/receipts, and up to ten source-supported transmissions. It distinguishes known estimated costs from unknown committed reservations and reports recent safe provider failure codes. It prints no API keys, private revisit credentials, raw dialogue/prompts or protected paths. All monetary values are application estimates in integer micro-USD, not exact provider billing. Private counts/ledger information remain CLI-only.

Scheduler flags are reported as configured. Actual systemd installed/running state is explicitly **not inspected**: PHP does not shell out to systemctl or assume that an enabled environment flag means a timer is running. Operators inspect unit state separately through their authenticated shell.

## Optional scheduler: templates only

Two additional environment flags are canonical 0/1 only (unset defaults to zero; empty/other values fail):

| Variable | Default | Meaning |
| --- | --- | --- |
| LITTLE_SPARKS_SCHEDULER_ENABLED | 0 | Allows the scheduled CLI adapter to consider the current slot when SIMULATION_ENABLED is also 1. |
| LITTLE_SPARKS_SCHEDULER_ALLOW_PAID | 0 | Explicit unattended-paid opt-in; effective only with scheduler, simulation and AI flags all 1, approved model/pricing and all existing request/budget gates. |

All existing variables/ranges in `.env.example` and the V2D operator guide remain unchanged, including default 15-minute opportunities, 100/day global, 12/day resident, zero resident/pair cooldowns, two daily HTTP requests and the $1 monthly maximum. A scheduled adapter invocation is a distinct explicit execution path, not authority for a public visitor to spend. Scheduler installation/activation and unattended paid use each need owner approval; preparing files does not grant it.

`scripts/scheduled-village.php` validates flags and bounded configuration, reads LITTLE_SPARKS_DB from its own CLI environment, considers exactly one current UTC slot, then exits. It reuses the same persistent IDs, fair selection, transactional cooldown/cap checks, leases, atomic reservations, reconciliation and unknown-billing handling as manual runs. Existing failed/pending/completed opportunities never dispatch twice. It has no retries, loop or missed-slot backlog.

Safe local checks (no credential read, database open or HTTP request):

```sh
php scripts/scheduled-village.php --check
# Default flags produce paused; an ordinary invocation also pauses without DB access.
```

For a separately authorized scripted rehearsal on disposable staging data, set SCHEDULER_ENABLED=1 and SIMULATION_ENABLED=1, leave SCHEDULER_ALLOW_PAID=0 and AI_ENABLED=0, and invoke the adapter once. Repeat the same slot to verify idempotency, inspect status and verify original V5 records. Never use production for destructive tests. No scheduler is needed to review or deploy the dialogue fix.

### Ubuntu 24.04 installation plan — not executed

The templates follow the systemd 255 [execution/credential reference](https://www.freedesktop.org/software/systemd/man/255/systemd.exec.html) and [timer reference](https://www.freedesktop.org/software/systemd/man/255/systemd.timer.html). Local static tests do not replace verification on the target host.

1. Review `ops/systemd/sanctuary-village.service` and `.timer`. Customize the **copies** to the approved installed code directory and existing private database directory. Defaults illustrate /srv/sanctuaryfor.ai and /var/lib/sanctuaryfor-ai. The service runs as www-data; verify the actual restricted identity has read access to code and only necessary write access to the DB directory/SQLite sidecars. Do not change production ownership or paths as an incidental install step.
2. Use the separately prepared /etc/sanctuaryfor-ai/simulation.env. PHP-FPM environment is not inherited by CLI. The systemd manager can read a root-owned 0600 EnvironmentFile under a root-owned 0700 directory. Supply the approved absolute LITTLE_SPARKS_DB there, keep both new scheduler flags and existing AI/simulation flags at zero initially, and never place an API key in it, a unit, Git, shell history or command arguments. This repository does not read or overwrite the owner's file.
3. On approved Linux staging, inspect the units with `systemd-analyze verify` and the calendar with `systemd-analyze calendar '*-*-* *:*:00 UTC'`. Check PHP 8.3/PDO_SQLITE/cURL, paths, private permissions and the hardened service namespace. Windows tests verify template structure only; they do not prove systemd runtime compatibility. ProtectSystem=strict and ReadWritePaths must match the existing DB directory; a missing/unapproved path fails instead of creating a database. Type=oneshot, no restart, a 50-second start timeout, restricted capabilities/devices/address families, 256 MiB memory and bounded tasks contain execution. HTTP retains shorter per-request/lease deadlines.
4. **Only after explicit install approval**, copy reviewed units to the host's unit directory and reload systemd. Do not start or enable the timer while reviewing/installing files. For approved no-cost testing, enable only the two non-paid environment gates and run the service once through the normal operator process. Inspect `systemctl status sanctuary-village.service`, safe journal output and ai-status.php; no prompt/provider body/key is logged. systemd itself will not start a second instance of the same active oneshot; application concurrency safeguards remain necessary for manual/other-worker overlap.
5. The timer wakes at most once per minute in UTC, with small timing slack. The app's configurable interval maps wakeups to the same slot until the next interval boundary, so default 15 minutes still offers at most 96 distinct slots/day, not a fresh opportunity on every wakeup. Persistent=false prevents catch-up after downtime. No timer is installed or enabled by this PR.

### Credentials and separate paid approval

The base service does not load a credential. Only after explicit unattended-paid approval may an operator install a reviewed copy of `paid.conf.example` as a service drop-in. Its LoadCredential directive reads a separately provisioned root-owned 0600 /etc/sanctuaryfor-ai/openai-api-key and exposes the fixed openai_api_key credential to that service's restricted identity. No key value belongs in the directive. The credential source must not be accessible to web users or stored under public/. systemd encrypted credentials are an optional separately reviewed provisioning alternative.

Only fully armed paid execution reads CREDENTIALS_DIRECTORY/openai_api_key into the short-lived PHP process environment. Scripted/paused/check-only execution does not read it. Manually injected private process-environment OPENAI_API_KEY remains supported if no systemd credential directory is present. Single-line size/control checks fail safely; values are never printed or persisted. The existing V2B CLI-secret guidance applies. AI pricing confirmation/expiry, model allowlist, two-slot reservations, daily limits and $1 maximum still apply independently; no prepaid balance or provider alert increases them. Network failure/timeout/unknown billing never automatically retries.

### Pause, resume and rollback

With approved operator access, stop/disable the timer to pause future scheduled invocations and set SCHEDULER_ENABLED=0 / SIMULATION_ENABLED=0 in the protected configuration. Set SCHEDULER_ALLOW_PAID=0 and AI_ENABLED=0 to pause paid activity independently. An already-dispatched request may finish; wait for the bounded service/claim and inspect unresolved usage before maintenance. Remove the optional credential drop-in when retiring paid scheduling so systemd no longer stages the credential at startup. Never delete reservations to clear a block.

Before any approved resume, inspect the effective flags/interval/caps, pending claims, expired leases, unresolved costs and pricing; test --check and a no-cost current-slot rehearsal. Resume considers only the current slot, not a backlog. Actual timer enable/start is a separate owner action, not part of PR execution.

V2E needs no migration. Rolling back to V2D-compatible V5 code preserves every saved row, including original natural dialogue and receipts. Keep scheduling paused while rolling code back; older V2D code would use its reflection contract for **future** encounters, without rewriting prior messages. Do not restore an old database over newer dialogue/ledger writes, lower user_version, erase unknown commitments or delete provenance. Production deployment and any live paid evaluation remain separate approvals.

Final local verification: all ten suites passed (the eight established suites plus village-live and meadow-layout), PHP lint and JavaScript syntax checks passed, and responsive browser checks used disposable mocked data. PHP 8.3 and systemd runtime verification on approved Ubuntu staging remain outstanding; no production access or live paid evaluation occurred.

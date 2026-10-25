# Supervised experiment and independent releases — 2026-10-25

Status: review/proposal only. No paid call, credential access, project-budget change, production SQLite access, scheduler change, split PR or deployment is authorized or performed. Keep PR #9 a draft pending the owner's test and merge-strategy decisions. This document supersedes the earlier assumption that a manually approved experiment must meet every unattended-production admission condition before it can even be considered. It does not change any runtime guard.

## Decision

A single supervised synthetic-resident experiment can be **operationally constrained** and considered for explicit approval independently of production activation. It cannot currently be described as a financially hard-capped experiment. The public contract reviewed does not establish a defensible maximum final charge from the proposed controls. If the owner requires an absolute dollar maximum, the managed test is not ready under that requirement. If the owner deliberately accepts the disclosed residual exposure for one experiment, a separate staging-only authorization path can be reviewed without enabling production.

I recommend extracting the no-cost user-facing features first, while keeping both the production Director and the paid experiment disabled until their separate decisions are made. This is a recommendation, not an instruction to merge or deploy.

## What is and is not isolated

| Boundary | Proposed restriction | Residual exposure |
| --- | --- | --- |
| World data | Fresh private synthetic-only SQLite, exactly two residents, distinct staging installation secret, no production DB copy/mount/read permission | A separate DB does not create a separate provider bill or spending cap. |
| Generation | One saved-agent session with one initial input, no follow-up generation, durable single-use experiment ID | A managed turn can include internal inference; one client POST is not proof of one model call. |
| Context/tools | Bounded synthetic personalities/context, no tools/subagents/vault/sandbox, allowed world action only none | A bounded schema and post-response output-token check are not pre-dispatch provider token caps. |
| Application traffic | Pre-reserve at most eight total HTTP slots, including reads, moderation and cancellation | That counts client requests, not all provider-managed inference. |
| Time | Short local deadline, one bounded observation window, no unattended loop/retry | Timeout, disconnect or key revocation does not establish that accepted provider work stopped. |
| Money | Proposed temporary experimental allocation of **100,000 micro-USD ($0.10)** and a **one-cent session limit** | Both are controls/thresholds, not a verified maximum final bill. |
| Scheduler | No timer/service installation or invocation; Toronto's timer stays disabled | Other owner-run Platform experiments or manual processes sharing the key/project are outside this runner's control. |

The $0.10/eight-slot proposal is a **new approval choice**, not an increase to any existing setting. No environment, production budget or current four-slot/$0.01 staging command has been changed. The unattended production $1/month ceiling and all existing request limits remain unchanged. The experiment would have its own immutable accounting scope; it must not be presented as new production headroom or silently excluded from an agreed combined application budget. Approval must explicitly state whether the allocation is taken from remaining application headroom or is a one-time research exception. With no production DB read in this experiment, the owner supplies any required headroom evidence through a separately approved process.

## Remaining financial exposure — read before approval

The final charge **could exceed $0.10**. I cannot establish from the reviewed contract that it could not exceed the normal $1 application ceiling either. This is an unverified upper bound, not a prediction of a large bill and not a claim of infinite liability. I have not inspected account credit, other usage or any account-specific contractual maximum. No numerical absolute maximum is being promised.

Reasons and sources:

- The [session API](https://developers.openai.com/api/reference/python/resources/beta/subresources/agents/subresources/sessions/methods/create) exposes a cent-based limit, but the reviewed configuration does not establish the total managed-inference bound or a maximum overshoot used by this application.
- [Usage observations](https://developers.openai.com/api/docs/guides/agents-api/observability) are best effort and revisable, rather than final billing evidence. Missing or low usage cannot be treated as a free attempt or a refund.
- [Project/organization enforced limits](https://developers.openai.com/api/docs/guides/spend-limits) are separate from alerts and can overshoot during enforcement propagation. No existing project setting will be altered for this proposal. A shared-project change could interrupt other work and still would not prove an exact experimental cap.
- The [cancellation API](https://developers.openai.com/api/docs/guides/agents-api/sessions) can request that an active turn stop. Sending that request, ending the client process, or revoking a test key is not proof of a final zero or bounded bill; work may already have accrued charges. If session creation times out before its ID is received, targeted cancellation may not be possible through this runner.

No automatic retry, second session, replacement generation or fresh-database rerun is included. Unknown outcomes remain recorded and block another experiment. Further recovery requests or budget changes beyond the approved envelope need a new decision. A small measured bill would establish that encounter's result, not a general production cost guarantee.

## Credentials and provider-project scope

The default proposal honors use of the existing saved Director and protected API-key mechanism. **Reusing the existing key is not credential or billing isolation.** Its value is never printed, copied into SQLite/context, sent in a browser response, or placed in Git/command arguments. The worker must have access only to synthetic files and its protected credential channel, not the production DB, FPM configuration or production credential directory.

If the owner prefers stronger credential containment, a separately approved temporary restricted key in the same project, with an expiry/revocation plan, is an option to verify—not a key to create automatically. The [official quickstart](https://developers.openai.com/api/docs/guides/agents-api/quickstart) lists Agents read/write and Responses write permissions; exact endpoint-level granularity and moderation permission must be confirmed in the account. Do not claim those permissions necessarily prevent saved-agent edits. Application egress must independently allow only the approved session/read/moderation/cancel routes and forbid agent edits, new agents, automations and unrelated APIs.

A [saved agent is project-scoped](https://developers.openai.com/api/reference/python/resources/beta/subresources/agents/methods/retrieve). A new project cannot be assumed to use this existing ID. This review does not authorize copying/recreating the Director, moving it, changing production keys, or reducing a shared project's limit. If the same project/key is used, final billing attribution and overlapping owner activity must be considered when interpreting evidence.

## Proposed single supervised test

After the owner chooses the financial scope and accepts or declines the residual exposure, prepare a separately reviewed staging-only permit and offline-test its runner. Current code remains blocked; approval of a written plan alone would not silently bypass `managedLiveAdmission()`.

The permit should bind the exact code SHA, fresh private DB/installation identity, existing agent ID, model gpt-6-luna, two resident IDs, context/configuration versions, one persistent experiment ID, expiry, one generation submission, eight HTTP slots and the selected $0.10 allocation/one-cent provider setting. It must be usable only from the explicit staging CLI, never a web endpoint, ordinary village runner or scheduler. Consume it transactionally **before** dispatch, and retain consumption after failure/restart. Prove no production-file access under the chosen host account/isolated environment; a filename check alone is insufficient. Do not create that account/environment or provision credentials without approval.

Proposed maximum HTTP envelope (all reserved before generation):

1. One `POST /v1/agents/sessions`, using the existing saved Director; no PHP Director instructions or new agent.
2. One session-state read.
3. At most three bounded turn-state reads, with a fixed overall deadline; no generation retry.
4. At most one item read and one moderation call after valid completion.
5. One remaining slot: cancellation on an unfinished/unsafe outcome, or a final observation read after success. Cancellation uses only `agent.session.input.cancel`, never another input message.

That is at most eight calls, not a requirement to use them all. Propose a 60-second overall local deadline, an observation cutoff at 35 seconds, and up to 15 seconds reserved for the single cancellation call, leaving exit/accounting margin. Every HTTP timeout must also respect the remaining overall deadline. These are client deadlines, not provider billing limits. The present adapter has only a single inspection and its audit forbids repeated read kinds; it must be extended and tested before this envelope is executable. No paid approval is requested for an unimplemented completion/cancellation path.

Accept an encounter only after matching the exact agent/session/turn/participant identities, final output, local validation and moderation. Save the canonical exchange only in the synthetic DB; world proposals remain none. If progress is unfinished, usage is missing, cancellation is uncertain, configuration mismatches or any ceiling is reached, record the outcome and stop. Keep enough process/deadline margin for the one cancellation attempt; even then do not promise cancellation is effective. If the provider ID is lost, keep the uncertainty and require an owner-reviewed recovery plan.

After the attempt, turn off the staging gates, retire the one-shot permit, retain private HTTP/usage/evidence records, verify the provider terminal outcome and reconcile independently obtained billing. Do not copy synthetic dialogue, tokens, secrets or experimental ledger rows into production. An owner-approved finance record must retain the experimental expense/uncertainty when deciding future application headroom; a fresh DB or restored backup cannot erase it.

Approval must cover this specific one-time execution, its nominal allocation, its shared-key/project implications, the absence of a verified maximum final charge and its accounting relationship to the normal application budget. It would not approve production, scheduler use, automatic retries, repeated experiments or PR merge. No such approval has been given.

## Independent release recommendation

Review identified real coupling, so do not deploy PR #9 with selected files merely omitted.

| Proposed release | Migration / risk | Required work before its PR is ready |
| --- | --- | --- |
| Icon + 24-color picker | No schema migration needed on V5; original resident color column accepts the approved values | Extract icon metadata/assets and curated color validation/rendering from main; make swatches available on V5; verify all public pages, mobile/keyboard, cottages/map and default-off behavior. Exclude Director/runtime/accounting changes. |
| Owner-written personality + private editing | Explicit identity-only additive migration and identity-aware rollback; independent of paid execution | Separate identity capability from Director/schema capability, preserve all V5 rows/secrets/history/budgets, retain revision/CSRF/token/rate/idempotency guards, and ensure no new resident speech is generated by PHP. Keep simulations explicitly paused until a separately approved compatible runtime is selected. |
| Director integration/activation | Separate review and migration adoption; production cost/completion gates remain | Rebase/adopt existing identity data and immutable edits without overwriting them; retain the one canonical encounter and fact/provenance rules. A manual pilot does not approve unattended activation. |

Specific dependency findings in PR #9:

- `identityEnabled()` currently means SQLite version >=6, and `simulateOpportunity()` uses it to cut over to Director-only operation.
- `identityForNewResident()` also inserts a Director-life `resident_states` row.
- Profile topic joins and AI accounting currently use the same capability to assume `director_runs` / `director_attempts` exist.
- The all-in-one V6 migration creates/backfills identity and Director/accounting tables together. Selecting only its identity SQL without changing these readers would break execution or hide edits.

For an independent personality release, prefer a clearly versioned identity add-on marker alongside V5, with a separate explicit migration and independent Director capability checks. Do not silently reinterpret an existing draft V6 database as that smaller schema. The later V6 migration must recognize/adopt the add-on and verify its rows/audit as well as the original V5 digest. New resident creation must not require Director-life tables. The paused identity-only runtime must not route custom personalities through a legacy scripted or paid planner; the disabled Toronto timer alone is not an application guard against a manual invocation.

Rollback must retain identity rows and use an identity-aware compatible build; older V5 code may ignore newer descriptions/effective colors even if it can open the base database. Do not promise a trivial cherry-pick or old-database restore. Tests must cover populated V5 preservation, existing private links, creation/edit replay, concurrent revisions, every color, missing capability fail-closed, no speech/paid dispatch, and later Director adoption. PHP 8.3 and iPhone staging remain separate checks before any deployment.

No new split branch/PR, migration renumbering or rebase is performed in this review. Recommended merge order is icon/colors first, then independently tested personalities, then Director; the owner can instead choose a combined no-paid identity/UI release after its dependency refactor. PR #9 stays a draft until that strategy and the experiment decision are explicit.

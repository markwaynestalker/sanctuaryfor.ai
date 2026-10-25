# Managed Director accounting and staging decision — 2026-10-25

**Merge readiness: NO for the requested live managed-Director V3A release.** The local accounting/transport safeguards are implemented and tested offline, but the provider-enforced total-inference bound and maximum spending overshoot remain unverified. PHP 8.3 staging and an approved live compatibility test are also outstanding. Production egress remains blocked in reviewed code; flags cannot bypass it. This is not a statement that managed Agents has no spending control—it has one, but its documented contract is not sufficient to prove this application's absolute $1 spending requirement.

The independent UI/identity PRs are now deployed, and PR #9 adopts their existing data. The manual one-shot staging implementation is ready for review: bound/consumed permit, two synthetic residents, eight counted HTTP calls, completion/cancellation handling and terminal-evidence billing review. See V3A_STAGING_AND_RELEASE_REVIEW.md for the current exact procedure and residual exposure. Production admission stays blocked, PR #9 stays draft and no paid execution is approved/performed. A nominal $0.10 research allocation is not a guaranteed charge cap.

## Research result

Official OpenAI documentation checked on 2026-10-25:

- [Create session](https://developers.openai.com/api/reference/python/resources/beta/subresources/agents/subresources/sessions/methods/create): supports a saved agent, no execution environment, restricted configuration and a positive integer-cent session limit. The expanded configuration does not expose Responses' `max_output_tokens` or a total-inference-count limit.
- [Usage](https://developers.openai.com/api/docs/guides/agents-api/observability): session/turn usage is best effort, may change or be missing, and is not a final bill. Cached input is part of input tokens; reasoning is part of output tokens.
- [Errors](https://developers.openai.com/api/docs/guides/agents-api/errors): a budget-exceeded terminal outcome exists; a disconnected request does not establish the turn's final outcome.
- [Project/organization spend limits](https://developers.openai.com/api/docs/guides/spend-limits): alerts and enforced limits differ, and even enforced limits can overshoot while enforcement propagates. A project threshold cannot prove an application hard cap.
- [gpt-6-luna pricing](https://developers.openai.com/api/docs/models/gpt-6-luna): the existing conservative standard input/cache-write and output rates remain appropriate for the bounded Responses alternative. They do not establish the number or size of internal managed inferences.

No documented finite maximum session-limit overshoot was established. A one-cent session setting, short wall-clock timeout, small output schema, disabled tools or a successful inexpensive experiment cannot by itself prove an absolute worst-case bill. Post-response rejection of too many tokens does not prevent those tokens from being billed. Do not replace this uncertainty with an invented rate, assumed single inference, model assurance, project alert or configurable bypass.

## Implemented safeguards

- Fixed official HTTPS transport: exact route/method allowlist, TLS certificate checks, no redirects/retries, bounded request/response sizes and deadlines; credentials are read only from the protected process environment after admission. Provider error bodies, prompts and keys never enter exceptions or audit records.
- Admission remains a code-level refusal until the missing provider guarantee and staging evidence are reviewed. There is no live managed transport selected by the ordinary runner today.
- Every managed HTTP step is durably marked before dispatch under the SQLite paid lease and UTC-day guard. Unique creation/submission records prevent resending after a crash; started/unknown calls count. Moderation and inspection calls use the same pre-reserved daily slots. No unbounded polling, tool loop, background worker or charge retry is added.
- Returned session identity/model/tier, no-sandbox environment, empty tools/vaults, disabled delegation and unchanged one-cent session limit are checked. Sessions with incompatible settings or an exhausted reported limit cannot be reused. A creation ID is retained even if subsequent configuration validation fails.
- Token observations are append-only and validate integral totals, cached/reasoning bounds and priced limits. They are labeled observations, never final billing. Unknown usage cannot become zero cost or release a reservation. A failed final apply remains uncertain even after token observations.
- Manual billing reviews require independently verified provider evidence, private CLI access, a unique review ID and a file hash. They never trust a model's billing assertion, alter conversations or lower committed spending. A below-reservation bill keeps the entire reservation consumed; an above-reservation bill increases the commitment and leaves an unresolved overrun block. Reviews are immutable/idempotent. A later smaller amount cannot erase an overrun.
- A failed read of an old session before any generation submission can abandon continuity on a later opportunity; its reservation is conservatively consumed rather than restored. It never resends input for the same opportunity. Any potentially dispatched generation remains frozen pending review.

The explicit V6 migration adopts installed identity revision 1 unchanged, adds four accounting tables plus three staging permit/outcome/revision tables alongside eight Director tables, and verifies both base V5 and identity digests. Identity-aware draft V6 can add these extensions transactionally; unsupported/partial revisions refuse. Pre-identity draft V6 without the identity marker requires separate recovery review. HTTP never migrates. Production remains owner-confirmed V5+identity and has not been accessed by this work.

`php scripts/ai-status.php --database=/private/staging.sqlite` reports started HTTP calls, observations, reviews, committed/unknown amounts and the explicit admission blockers. These are private operational data, not a public dashboard.

For an independently verified bill, with activity drained:

```sh
php scripts/review-director-billing.php \
  --database=/absolute/private/staging.sqlite \
  --attempt=ATTEMPT_32_HEX --review=NEW_REVIEW_32_HEX \
  --microusd=VERIFIED_INTEGER_MICROUSD \
  --evidence-file=/absolute/private/provider-evidence.json \
  --confirm-provider-evidence
```

The evidence file must be private, nonempty and at most 1 MiB. The operator must independently verify the project, original attempt/session/turn, terminal outcome and covered billing interval before using this command. Only its digest is stored. Do not use a best-effort usage snapshot as final evidence. No Admin API key or automatic billing-export job is introduced. A review can clear a known covered uncertainty while retaining the worst-case commitment; it cannot enable live admission, clear an overrun or increase either budget.

The simple review command refuses attempts from an earlier UTC month. An asynchronous charge can cross a billing boundary, so that case requires a separately reviewed period-allocation procedure rather than clearing the global unknown-cost block and creating false headroom in the new month.

## Historical strict-budget prototype — superseded, NOT authorized or run

The following four-slot/$0.01 outline documents only the old offline prototype, not the current staging CLI. Use the implemented eight-slot/$0.10 permit procedure in V3A_STAGING_AND_RELEASE_REVIEW.md. If an absolute charge cap is required, the provider-bound prerequisite still applies. Manual risk-exception code is implemented but not execution-approved; it must never be inherited by production.

Scope once those gates are satisfied:

1. Use the existing saved `agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8`, `gpt-6-luna`, existing protected API-key mechanism. Do not create/modify another agent or attach tools, subagents, vaults or a sandbox.
2. Use a fresh private **nonproduction** SQLite V6 database containing exactly two synthetic residents with owner-written descriptions, no private production data and no earlier managed attempt. Keep Toronto's timer disabled, and schedule no other application paid execution. Account for existing production commitments before approving any additional staging allocation; separate database files must not create new $1 headroom. Owner-run Platform experiments are outside the application ledger and must be accounted for separately when assessing account spend.
3. One explicit UTC-minute opportunity; one new session/input generation attempt; no follow-up generation. Supply only the two bounded resident contexts and `allowed_actions=[none]`. Quiet/no action is permitted; no world change is authorized.
4. Staging-specific application allocation 10,000 micro-USD ($0.01), deducted from available approved monthly headroom, with four HTTP slots: create, one turn inspection, one item inspection, moderation. Keep the session's provider limit at one cent. This is a provider setting plus a ledger reservation, **not a newly claimed hard billed maximum**. No increase to production's default/configured daily or monthly allowance is authorized.
5. Store safe request IDs, session/turn IDs, token observations and uncertain cost. If the asynchronous turn has not completed at the single inspection, stop this application invocation without resubmitting or inventing a conversation. A local timeout is not cancellation or proof that provider work stopped. All uncertainties remain held; inspect the original provider outcome separately under an approved, counted recovery plan.
6. Success requires correct original attributed dialogue, moderation, a single canonical encounter, resident-specific unverified notes, no fabricated world facts, exact request accounting and independently reconciled provider cost within the approved bound. A small bill in one experiment does not validate a general hard cap.
7. End with paid flags off and no scheduler. Do not copy staging dialogue into production. Carry the staging cost/commitment into the deployment spending review before any production paid execution; do not restore an older production ledger to regain its allocation.

`php scripts/stage-director-once.php --check` is safe now: no DB/key read or API request. The paid path currently refuses at admission even if all flags are set. After a separately reviewed admission change, it additionally requires `LITTLE_SPARKS_ENVIRONMENT=staging`, explicit database/UTC minute/`--allow-paid`, the four normal manual activation gates, scheduler flags exactly zero, daily HTTP limit exactly 4, monthly allowance exactly 10000 and model exactly gpt-6-luna. It refuses Toronto's production path. A transactional check permits at most one managed attempt across concurrent invocations, and the world-action allowlist is restricted to none.

## Remaining decision

To finish the live feature without weakening the request, obtain an official provider contract/support confirmation establishing a finite managed inference/spend bound, then implement and test the corresponding admission calculation and bounded asynchronous completion/recovery. The current single-inspection adapter intentionally fails silent on unfinished turns; it is not yet a reliable long-running managed session runner. An approved staging result and PHP 8.3 rehearsal must follow. The explicitly selected Responses alternative retains its existing bounded single-request contract, but is not silently substituted for the saved managed agent.

Do not mark PR #9 ready for the requested live V3A merge/deployment merely because the offline suites pass. The resident personalities and iPhone icon are implemented, but enabling the managed Director is still blocked. See TORONTO_V3A_RUNBOOK.md for the exact future maintenance steps, including the disabled timer and data-preserving rollback.

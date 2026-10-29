# Managed session spending review for #74 / PR #75

Reviewed October 29, 2026. Documentation and repository history only: no provider calls, credentials, production inspection or operational changes. This review supplements the multi-message design; it does not enable `spend_control` or authorize activation.

## Evidence and correction

| Source | Verified finding |
| --- | --- |
| [Python session-create reference](https://developers.openai.com/api/reference/python/resources/beta/subresources/agents/subresources/sessions/methods/create) | Documents a USD-cent `spend_control` input, unlimited provider sessions when omitted/null, and best-effort consumption in the returned object. |
| [Python session-update reference](https://developers.openai.com/api/reference/python/resources/beta/subresources/agents/subresources/sessions/methods/update) | Documents retaining the limit on omission and removing it on null without resetting accumulated spend. |
| [Language-neutral session-create reference](https://developers.openai.com/api/reference/resources/beta/subresources/agents/subresources/sessions/methods/create) | The fetched page omits `spend_control`. The reference variants disagree; absence on this page does not establish lack of API support. |
| Repository commit `c2b4936a5d4e15cefea44ac80048dbbf5b4cb025`, October 25 | Records removing a rejected `spend_control.limit=1` request and its response requirements. It added the local transport prohibition, preserved by PR #75. No original provider rejection receipt was independently inspected in this review. |

The earlier blanket description of `spend_control` as unsupported was too broad. It is documented in SDK references, while acceptance for this application's exact route/project and current behavior remain unverified. We cannot establish whether the mismatch reflects reference generation, API revision or project availability. Do not silently reintroduce a historically rejected field, or claim documentation alone proves enforcement. The current application omits this provider setting; its own ENV admission, atomic reservations, retained holds, request limits and lifecycle checks remain active. Those commitments are not guaranteed provider billing caps.

## Implications for reused pair sessions

The current pair scope includes resident revisions, runtime and Director fingerprint; v2 adds contract and message bounds. Sessions can span multiple opportunities, subject to configured age/turn limits. A session allowance would therefore be cumulative across encounters, not a fresh per-conversation budget. A one-cent setting could be exhausted by earlier turns. The separate moderation call has no demonstrated coverage under a session allowance and remains independently accounted for.

If this capability is adopted in a later reviewed change, bind its policy/version to session scope and validate returned limits before reuse. Do not assume existing unlimited sessions acquire a limit on the next message. A session-update operation would be a new mutation and requires its own authorization, request accounting and effective-setting checks. Do not automatically raise a limit or rotate sessions merely to evade an exhausted allowance.

Unknown or exhausted consumption must not be interpreted as available headroom. If inspection refuses before input submission, retain that attempt's consumed slots/commitment and let a separately eligible future opportunity follow the reviewed replacement policy. If submission may already have occurred, preserve uncertainty and never regenerate or replay that opportunity. Any replacement or update path must still fit the existing eight-slot envelope, exact-once submission and lease fences. Preserve old sessions and resident/history/accounting rows.

## Limits not established

The fetched references do not establish a finite worst-case overshoot, enforcement timing within an active turn, all charge categories covered, or reliable exhaustion/recovery behavior for our reused sessions. Best-effort consumption is not a final invoice. No experiment can prove a universal overrun bound from a few inexpensive results.

Responses' separately documented [max_output_tokens](https://developers.openai.com/api/reference/python/resources/responses/methods/create) bounds generated tokens, including reasoning. This is different from a monetary session allowance. Neither the reviewed managed session envelope nor PR #75 sends that Responses parameter to Agents. The v2 local output allowance remains a reservation/validation input for managed execution; schema, byte and deadline limits do not replace a provider-enforced financial bound.

## Decision before multi-message production activation

Keep PR #75's additive storage, original dialogue, contract and one-encounter accounting architecture. Retain the current transport prohibition and do not add automatic budget updates or session rotation in this PR. Treat managed spending protection as an explicit unresolved activation-review item, alongside the output/request-byte settings and migration. The owner must review the actual residual exposure; CI and the existing six Responses samples do not establish managed financial enforcement.

A proposed follow-up is isolated staging validation, after a separately reviewed explicit request/cost allocation and accepted residual risk. This document grants no live-call approval. Before executing, specify the exact staging project/model/route, synthetic identities, per-session setting, total generation/HTTP count, local reservation, deadlines, UTC budget window and no-retry stop conditions. Do not borrow production sessions, data or the hosted Director definition.

The review should cover:

1. Whether the exact create request accepts the field and returns the intended limit. Stop on rejection; retain sanitized HTTP status/code/parameter and request ID only, without automatically retrying another shape.
2. Whether one explicitly budgeted later turn in the same synthetic session preserves cumulative consumption and the same limit. Reserve both encounters' slots/costs before execution; distinguish observations from final billing.
3. Exhaustion, unknown consumption and pre-submit versus post-submit failures. Cover these offline first. Do not deliberately spend up to an unknown limit merely to force a failure; any live boundary probe needs its own approved exposure and stop plan.
4. Independently verified terminal/billing evidence, including any overrun. Keep all uncertain holds and report every dispatched request. Do not import generated staging dialogue or billing estimates into production.
5. The resulting session-scope/reuse/rotation policy, coverage of separate moderation and whether remaining exposure is acceptable. Provider clarification is needed for guarantees that documentation and experiments cannot establish.

Historical October 25 staging documents describe earlier proposed spend settings and admission rules. They are not evidence that current production has a provider limit. This dated review and current implementation should guide the PR #75 activation decision.

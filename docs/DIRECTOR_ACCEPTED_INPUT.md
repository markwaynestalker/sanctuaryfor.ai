# Accepted managed input (#47)

The official [session events contract](https://developers.openai.com/api/reference/typescript/resources/beta/subresources/agents/subresources/sessions/subresources/events/methods/create) returns void: HTTP 202 means acceptance, not durable completion. Requiring a JSON object for its empty response caused successful submissions to throw locally and enter cancellation cleanup.

The transport accepts an exactly empty 202 response only for one supported message/cancel event on the session-events endpoint. It returns actual HTTP metadata only. Session creation, inspections, items and moderation still require valid JSON. The existing lifecycle must independently bind and observe the new completed turn, validate its original proposal, moderate it and commit once. Cancellation conflicts remain uncertain; acceptance does not prove usage or release any commitment.

Run `node tests/village-director.mjs` with PHP configured, then `python -B ops/ci/offline.py`. Fixtures exercise the production response decoder without network or credentials, including delayed completion, missing usage, failed/missing/malformed turns, wrong identity, deadlines, moderation rejection and cancellation 409.

## Owner recovery

Keep the owner-paused production timer paused while reviewing and deploying this exact change through the protected workflow. No migration, accounting rewrite, ENV change or historical replay is required. Preserve existing attempts, full request/cost reservations and audit evidence. After deployment, inspect accounting and remaining configured capacity; a new bounded live validation requires explicit owner authorization. Verify its accepted submit HTTP status/request ID, subsequent turn/items/moderation and actual saved conversation. Resume the timer only with explicit owner approval. Failed historical opportunities must not be retried to repair their journal.

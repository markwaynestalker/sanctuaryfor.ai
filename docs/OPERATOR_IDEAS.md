# Christopher Robin Mode (#15)

This optional feature belongs to the **site operator**, not each robot's creator. Click a Spark's “Site operator: offer an idea” link, sign in, and offer a question or possibility for its next normal encounter. The form stores an attributed suggestion and returns immediately; it never triggers generation, changes scheduling, or guarantees an answer. The Director may ignore it. This is not private chat: ideas may influence public dialogue, so never include secrets or personal information.

## Disabled by default; explicit review before activation

The feature requires HTTPS, a separate V6 add-on migration, an operator password hash, and explicit ENV activation. No existing account/revisit token grants operator access. Login uses existing strict HttpOnly/SameSite cookies, rotating session IDs/CSRF tokens; all writes require CSRF and same-origin form semantics. The fixed session lifetime is not extended by activity. Logout, password-hash rotation and disabling the feature revoke access. Keep the hash in the protected PHP-FPM environment, not source, URLs, logs or the database. Generate a strong unique password and its PHP password_hash() result using the operator's existing secure tooling; don't pass the password as a shell argument or commit it.

All ENV names have prefix `LITTLE_SPARKS_`:

| Suffix | Default | Valid values / initial recommendation |
| --- | --- | --- |
| OWNER_IDEAS_ENABLED | 0 | canonical 0/1; leave 0 until review |
| OWNER_PASSWORD_HASH | unset | required recognized PHP password_hash value for web login only |
| OWNER_LOGIN_ATTEMPTS_PER_HOUR | 10 | 0..INT_MAX, global login attempts including successful sign-ins; keep 10 |
| OWNER_SESSION_SECONDS | 3600 | 60..86400; keep 3600 |
| OWNER_IDEAS_PER_DAY | 12 | 0..INT_MAX, all submitted ideas per UTC day; keep 12 |
| OWNER_IDEAS_PENDING_PER_RESIDENT | 4 | 0..INT_MAX; keep 4 |
| OWNER_IDEA_MAX_CHARACTERS | 500 | 1..1000 Unicode codepoints; keep 500 |
| OWNER_IDEAS_CONTEXT_COUNT | 2 | 0..8 per participating resident; keep 2 |
| OWNER_IDEAS_HISTORY_PAGE_SIZE | 20 | 1..100; keep 20 |

Integers are canonical nonnegative decimals, without signs, spaces or leading zeroes. Invalid explicit values fail safely, never clamp or allow unlimited access. Zero submission/pending allowances pause submissions; context count zero keeps suggestions out of encounters. Authentication limits apply to the console, not autonomous Director calls. The 1000-character SQL limit, eight-context-item bound, HTTPS, CSRF, session isolation, request-body 8192-byte bound and immutable audit/FK constraints are security/data-shape protections. No AI allowance, monetary commitment, timeout or HTTP lifecycle is changed. A global login limit intentionally bounds distributed guessing; failed logins retain their counter, so excessive attempts can temporarily lock out the operator too.

FPM needs the feature flag, password hash and form/auth limits. The existing CLI scheduler needs OWNER_IDEAS_ENABLED and the matching retrieval/text limits to supply ideas. It never reads the password hash. Configure both explicitly through their existing protected environment channels; this PR does not alter those channels or load the FPM secret file in CLI. Set the feature back to 0 in both to pause it. Malformed enabled retrieval configuration fails before any provider request; validate settings before activation.

## Persistence, memory and the Director

The additive schema leaves SQLite user_version=6, identities, owner tokens, edit audits, homes, conversations, goals, sessions, memories and accounting untouched. Suggestions and exposure receipts are append-only, attributed site_operator, with stable resident IDs and idempotent request IDs. Daily/pending limits and duplicate handling run under BEGIN IMMEDIATE. The history is private to the operator, paginated and escaped.

Each fresh encounter retrieves only bounded pending suggestions for its actual participants. They are explicitly optional untrusted proposals, not instructions, historical knowledge, private resident memories or completed actions. They have no M-source aliases or provenance receipts. Existing factual validation and action allowlists remain authoritative. Suggestions are dropped before existing memory/dialogue/goals when context is full. The Director still writes every resident sentence; PHP neither inserts replies nor rewrites accepted dialogue. Existing moderation covers the complete input context and generated proposal without extra calls.

Only ideas actually retained in the supplied context are eligible for an exposure receipt, and only in the same successful canonical conversation transaction, bound again to actual participants. Rejection, rollback, moderation failure, quiet/no-action outcomes and context preparation alone do not create receipts. “Included” records context exposure, **not agreement, belief, speech, or a performed action**. Accepted exposure removes an idea from pending selection even when the Director ignores it. Saved memory continues to come solely from the existing validated encounter paths.

## Owner-run release steps (not performed by development)

Use the existing reviewed operational deployment path; the code-only deployment gate must not bypass this migration/ENV change. Preserve the current production release and configuration for rollback.

1. Review the PR and test on a disposable SQLite copy first. Pause future timer ticks through the existing operator procedure, preserving whether the timer was active. Let any current sanctuary-village.service invocation finish; do not kill a paid request. Confirm no active lease or pending opportunity using the existing inspect/monitor tools. Migration refuses active work.
2. From the reviewed checkout `/var/www/sanctuaryfor.ai`, as the established database service identity, create a **new** private backup using `php8.3 scripts/backup-village.php --database=/var/lib/sanctuaryfor-ai/village.sqlite --output=/ABSOLUTE/PRIVATE/NEW-BACKUP.sqlite`. Use an existing protected backup directory and a unique destination; never overwrite a backup. Retain the receipt and previous release.
3. Run `php8.3 scripts/migrate-operator-ideas.php --database=/var/lib/sanctuaryfor-ai/village.sqlite` under that same identity while drained. It is transactional and repeatable, changing only the add-on. Check `PRAGMA integrity_check`, foreign_key_check and preserved V6/identity revisions on the backup/test copy as appropriate before enabling. No production command is executed by this PR.
4. Provision the password hash and reviewed matching ENV values through existing protected channels; confirm HTTPS reaches PHP as the trusted HTTPS server parameter and cookies are Secure. Do not trust a visitor-supplied forwarded-proto header. Test login/logout/CSRF and one harmless suggestion **without triggering paid generation**. Confirm private history does not appear in public APIs.
5. Restore the timer's previously approved state only when the owner approves resumption. Future already-authorized ordinary encounters may use ideas within unchanged budgets. No new service, worker, immediate AI call or hosted-agent configuration change is required.

Rollback: disable this feature in FPM and CLI and restore the prior reviewed code. Leave the harmless additive tables and audit receipts in place; old V6 code ignores them. If migration fails, SQLite rolls back the add-on. Never drop populated audit tables, rewind the shared database, reset accounting or restore an old backup over newer village history as routine rollback.

## Offline verification

Run `node tests/village-operator-ideas.mjs`, `node tests/commons-ui.mjs`, then `python -B ops/ci/offline.py` with PHP_BINARY set. The fixture covers migration rollback/preservation, strict ENV boundaries, participant-only context, trimming, original mocked speech, moderation/quiet/disabled outcomes, exact-once delivery, concurrency, quotas, auth expiry/rotation/rate limits, HTTPS controller behavior, CSRF/XSS, idempotent submissions and public privacy. Its loopback-only router simulates trusted HTTPS metadata solely for tests; production has no insecure-auth override. Ubuntu CI runs all suites without external networking or production secrets. Physical iPhone/Safari appearance remains an owner acceptance check.

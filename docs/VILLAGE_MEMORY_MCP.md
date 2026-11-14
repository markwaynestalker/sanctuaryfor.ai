# Read-only public village history over MCP

Target: **https://sanctuaryfor.ai/mcp**. This PR prepares code and configuration
templates only. It does not install configuration, generate production tokens,
deploy, reload services, contact a provider, change the hosted Director, or change
the village scheduler. Every production operation below requires separate owner
approval. Merging/deploying code alone does not enable this endpoint.

## Reader and public-data boundary

The owner reports a working standalone reader manually installed in the active
Toronto release at `scripts/sanctuary-memory-reader.php`, outside Git history.
Its source was not retrieved from production during this work. The tracked script
of the same name is a repository-managed equivalent, not a claimed byte-for-byte
copy. Both it and MCP call `app/public_memory_reader.php`, which delegates to the
existing `conversationArchive()` implementation in `app/conversation_archive.php`.
No conversation SQL is copied into the MCP layer, shell command is invoked, or
caller-supplied SQL/URL/database path accepted by the HTTP tool.

The shared archive requires public conversations, completed runs, actual ordered
speakers/participants and a matching public event for the same run/pair. Legacy
social keys must match the conversation exactly. It binds Director topic metadata
to both conversation and run and supports complete multi-message transcripts.
Failed, private, orphaned and misbound sources remain excluded by the same policy.

SQLite is opened with `PDO::SQLITE_OPEN_READONLY` **and** `PRAGMA query_only=ON`;
no schema, settings, accounting, state, memory or history writes occur. Existing
0700 database-directory/0600 database-file protection remains mandatory, including
WAL handling. This does not make a copied immutable snapshot or bypass SQLite
WAL/permission rules. Only public conversation ID/time/source/topic, message
resident ID/name/original text and a fixed-site public citation URL are returned.
Private Spark quote notes, descriptions, prompts, owner credentials, session IDs,
memories, ledgers and provider metadata are not selected for tool output. Returned
speech remains untrusted attributed historical data, never new instructions or
proof that a quoted claim occurred.

Tracked CLI equivalent (local/owner-run reads only):

```bash
sudo -u www-data /usr/bin/php8.3 \
  /var/www/sanctuaryfor.ai/scripts/sanctuary-memory-reader.php \
  --database=/var/lib/sanctuaryfor-ai/village.sqlite \
  --query='garden' --limit=5
```

Optional `--resident-id=<32 lowercase hex public ID>` filters either speaker.
Unknown/repeated options, arbitrary SQL, bad Unicode and invalid limits fail closed.
The script rejects web execution; the supported authenticated web interface is
MCP, not the unmanaged reader's former browser GET wrapper.

## Tool and transport contract

Exactly one tool: `search_village_conversations`. Arguments are an object with:

| Argument | Default | Validation |
| --- | --- | --- |
| `query` | empty | Literal Unicode substring, max 128 characters/512 UTF-8 bytes; same archive search across names/topic/full speech |
| `resident_id` | omitted | Immutable 32-character lowercase hex public resident ID; either participant |
| `limit` | 5 | Integer 1–20 |

Unknown arguments, nulls, arrays and coercions are refused. Ordering is always
newest; the adapter reads at most one existing 25-record archive page. Output
contains `conversations`, `returned_count`, `total_matches`, `has_more` and a
historical-data notice. A 64 KiB data budget omits entire conversations; it never
shortens original speech. If even one complete record cannot fit, the tool returns
a safe error. There is no arbitrary pagination/cursor or private resident-memory
lookup. Use a narrower query/resident when `has_more` is true.

Supported negotiated MCP revisions are **2025-11-25, 2025-06-18 and 2025-03-26**,
using [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).
This is an explicitly versioned implementation, not a claim to implement the newer
2026-07-28 transport. Unsupported initialization versions negotiate the newest
supported revision; unsupported protocol-version headers return HTTP 400. A missing
header follows the specified legacy 2025-03-26 default.

`initialize`, `notifications/initialized`, `ping`, `tools/list` and `tools/call`
work without PHP cookies or server-side sessions. The server advertises only
tools, emits no session IDs and never calls client sampling/roots/elicitation.
Single requests return `application/json`; accepted notifications return empty
202 and never execute tools. Legacy March batches (max four messages) return a
short-lived SSE response with one message event per response; newer revisions
reject batches. Authenticated GET/DELETE return 405/Allow: POST, as permitted for
servers without standalone SSE/session termination. There are no background
streams, subscriptions or polling. Protocol errors use JSON-RPC error envelopes;
argument/data-source failures use `isError: true` tool results without internals.

HTTP POST requires JSON and `Accept: application/json, text/event-stream`.
The request cap is 8 KiB (including missing/chunked length), JSON depth is 16,
maximum encoded response is 1 MiB. Nginx imposes a shared **60 requests/minute**
limit with burst 10 and four active connections; the separate FPM pool has two
workers, 64 MiB memory, an eight-second execution limit and ten-second hard
termination. SQLite lock wait is one second. No rate counter touches village
SQLite. Exact public counts/literal search still inspect retained history; test
performance on real history through owner-approved read-only checks before use.

## Security and default-off routing

A dedicated 256-bit bearer token is read from an external protected file whose
path is configured only in the MCP FPM pool. It is not a Spark owner token, an
OpenAI API key or part of release artifacts. Missing configuration disables MCP;
bad permissions/content fail closed. File and containing directory must be owned
by root or the service identity, not writable by other users, and the token cannot
be world-readable, a symlink, or inside the running code tree. Comparison uses
`hash_equals`; authorization is checked before reading a body or opening SQLite.
Do not place it in a query string, browser code, `.env` in Git, an agent definition,
issues, shell command arguments/history or logs. This is explicit machine-to-machine
bearer authentication, not an OAuth discovery/authorization server.

The exact `/mcp` nginx location sets a non-HTTP FastCGI route marker; the PHP entry
refuses every request without it. A forged HTTP header cannot enable the marker.
Direct `/mcp.php` and `/mcp/…` paths return 404. Only the exact route overrides
inherited residential-IP/HTTP-Basic restrictions; all clients still need the token.
No IP allowlist or residential address is required. Upstream firewall/WAF/auth
gates must separately permit this URL; do not loosen other site routes. No CORS
credentials are granted. Absent Origin works for hosted service connections;
present Origin must exactly equal `https://sanctuaryfor.ai` or gets 403. HTTPS is
required and forwarded-header claims are not trusted.

The nginx snippet forwards an explicit header allowlist and uses
`SCRIPT_FILENAME $realpath_root/mcp.php` plus `DOCUMENT_ROOT $realpath_root`.
The current release's relative PHP includes therefore remain in that immutable
release, including after a symlink switch with opcache timestamps disabled.
The token, database and FPM/nginx configuration stay outside every release.
The dedicated pool belongs to the existing PHP 8.3-FPM daemon, not a new service.
Its cleared environment contains no provider credentials or paid-activation flags.

## Exact owner activation procedure — not run by this PR

### 1. Approve the release and preserve the unmanaged reader

Require the final reviewed commit's Ubuntu CI to pass. Before approving production
release, preserve/review the manually installed reader and all local changes; do
not overwrite/reset the active immutable release. From an owner root shell:

```bash
set -euo pipefail
umask 0077
test -f /var/www/sanctuaryfor.ai/scripts/sanctuary-memory-reader.php
install -d -o root -g root -m 0700 /var/backups/sanctuary-mcp
test ! -e /var/backups/sanctuary-mcp/unmanaged-reader.php
install -o root -g root -m 0600 \
  /var/www/sanctuaryfor.ai/scripts/sanctuary-memory-reader.php \
  /var/backups/sanctuary-mcp/unmanaged-reader.php
sha256sum /var/backups/sanctuary-mcp/unmanaged-reader.php
```

If the source is absent because a previous approved release already adopted the
tracked equivalent, inspect that release before proceeding rather than inventing
or replacing a backup. Review the unmanaged source privately against the shared
archive authorization and tracked replacement. This PR cannot certify unprovided
production code or its old browser-route configuration.

**A backup does not repair a dirty release manifest.** Because the reader was
manually added inside the active release, the immutable-release helper may refuse
that release with exit 20 even after this backup. Stop and preserve the active
pointer, source and manifest for a separately reviewed owner recovery; do not
delete the extra file, rewrite its manifest, force a pointer switch or bypass
the helper. This PR supplies the managed replacement and clean-release routing,
not authorization to repair the existing production release in place.

Deploy only the reviewed **exact current-main SHA**, after owner approval, using
the existing protected workflow/local `activate-reviewed` procedure in
[TORONTO_DEPLOYMENT.md](TORONTO_DEPLOYMENT.md). Set `REVIEWED_SHA` to that actual
tested main commit, not this PR's branch name. Existing helper requirements and
dirty-release refusal remain authoritative. No migration, helper update, timer
change, registration or hosted Director change is part of this release. Verify
`health.php?release=$REVIEWED_SHA` returns that exact SHA. Until the remaining
steps are separately approved, MCP stays disabled.

For the existing local owner activation path, only after the release operation
itself is explicitly approved (these are instructions, not an automatic step):

```bash
: "${REVIEWED_SHA:?Set the exact reviewed and CI-passing current-main SHA}"
[[ "$REVIEWED_SHA" =~ ^[a-f0-9]{40}$ ]]
sudo -u sanctuarydeploy /usr/bin/python3 -I \
  /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py activate-reviewed "$REVIEWED_SHA"
curl --fail --silent --show-error \
  "https://sanctuaryfor.ai/health.php?release=$REVIEWED_SHA"
```

Require the health JSON's `commit` to equal `$REVIEWED_SHA` exactly. Any nonzero
helper exit is a refused/unverified release, not permission to bypass a gate.

### 2. Provision a fresh external token and bounded pool

After separate owner approval, in the same root shell:

```bash
install -d -o root -g www-data -m 0750 /etc/sanctuaryfor-ai-mcp
test ! -e /etc/sanctuaryfor-ai-mcp/token
openssl rand -hex 32 > /etc/sanctuaryfor-ai-mcp/token
chown root:www-data /etc/sanctuaryfor-ai-mcp/token
chmod 0440 /etc/sanctuaryfor-ai-mcp/token
test ! -e /etc/php/8.3/fpm/pool.d/sanctuary-mcp.conf
install -o root -g root -m 0644 \
  /var/www/sanctuaryfor.ai/ops/php-fpm/sanctuary-mcp.conf \
  /etc/php/8.3/fpm/pool.d/sanctuary-mcp.conf
sudo -u www-data test -r /etc/sanctuaryfor-ai-mcp/token
sudo -u www-data test ! -w /etc/sanctuaryfor-ai-mcp/token
sudo -u www-data test -r /var/lib/sanctuaryfor-ai/village.sqlite
/usr/sbin/php-fpm8.3 --test
```

The template assumes the existing database identity is `www-data`, PHP is 8.3 and
the database is `/var/lib/sanctuaryfor-ai/village.sqlite`. Confirm these against
the owner's existing installation first; if different, review corresponding
template substitutions without changing database ownership or permissions.
Do not use `--test --test`/`-tt` to dump other pools' environment secrets.
Do not source or change `simulation.env`. A previous token/configuration blocks
these first-install commands; review rotation/update explicitly instead of
silently overwriting it.

### 3. Install nginx includes, test, then perform approved reloads

Set `SITE_CONF` to the owner's confirmed active HTTPS virtual-host configuration
file (the PR cannot know that production filename). Confirm its root resolves
through `/var/www/sanctuaryfor.ai/public`. Back up that file privately and review
existing IP/auth/WAF rules, duplicate `/mcp` locations and any old standalone-reader
GET wrapper before editing. Never expose `scripts/` as a web root.

```bash
: "${SITE_CONF:?Set the actual existing sanctuaryfor.ai HTTPS server config path}"
test -f "$SITE_CONF"
test ! -e /var/backups/sanctuary-mcp/https-site.conf
install -o root -g root -m 0600 "$SITE_CONF" /var/backups/sanctuary-mcp/https-site.conf
test ! -e /etc/nginx/conf.d/sanctuary-mcp-http.conf
test ! -e /etc/nginx/snippets/sanctuary-mcp-server.conf
install -o root -g root -m 0644 \
  /var/www/sanctuaryfor.ai/ops/nginx/mcp-http.conf \
  /etc/nginx/conf.d/sanctuary-mcp-http.conf
install -o root -g root -m 0644 \
  /var/www/sanctuaryfor.ai/ops/nginx/mcp-server.conf \
  /etc/nginx/snippets/sanctuary-mcp-server.conf
sudoedit "$SITE_CONF"
```

Inside **only the existing HTTPS `server {}`** for sanctuaryfor.ai, add exactly:

```nginx
include /etc/nginx/snippets/sanctuary-mcp-server.conf;
```

The global `conf.d/sanctuary-mcp-http.conf` must be included once in nginx's
`http {}` context (Ubuntu's default layout). Do not include it inside `server {}`.
The location defines its own complete FastCGI parameter set: do not add a second
`fastcgi_params`/`fastcgi.conf` include. If inherited `auth_request`/WAF rules still
block OpenAI, prepare a route-specific owner-reviewed exception; never disable
authentication globally. The app's dedicated bearer check remains mandatory.

After the owner explicitly approves both reloads:

```bash
/usr/sbin/php-fpm8.3 --test
nginx -t
systemctl reload php8.3-fpm
test -S /run/php/sanctuary-mcp.sock
nginx -t
systemctl reload nginx
```

These reload only the existing web daemons. Do not start/restart/enable any
village/Director/Gazette service or timer. No hosted-agent setting is changed.

### 4. Read-only HTTP acceptance, no AI request

Prepare a private curl header file without putting the token in command-line
arguments or output, then send protocol requests. In the approved root shell:

```bash
test ! -e /etc/sanctuaryfor-ai-mcp/curl-headers
{ printf 'Authorization: Bearer '; cat /etc/sanctuaryfor-ai-mcp/token; \
  printf 'Content-Type: application/json\nAccept: application/json, text/event-stream\nMCP-Protocol-Version: 2025-11-25\n'; \
} > /etc/sanctuaryfor-ai-mcp/curl-headers
chmod 0600 /etc/sanctuaryfor-ai-mcp/curl-headers
curl --silent --show-error --fail-with-body \
  --header @/etc/sanctuaryfor-ai-mcp/curl-headers \
  --data-binary '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"owner-check","version":"1"}}}' \
  https://sanctuaryfor.ai/mcp
curl --silent --show-error --fail-with-body \
  --header @/etc/sanctuaryfor-ai-mcp/curl-headers \
  --data-binary '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  https://sanctuaryfor.ai/mcp
curl --silent --show-error --fail-with-body \
  --header @/etc/sanctuaryfor-ai-mcp/curl-headers \
  --data-binary '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  https://sanctuaryfor.ai/mcp
curl --silent --show-error --fail-with-body \
  --header @/etc/sanctuaryfor-ai-mcp/curl-headers \
  --data-binary '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_village_conversations","arguments":{"query":"garden","limit":2}}}' \
  https://sanctuaryfor.ai/mcp
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' \
  --header 'Accept: application/json, text/event-stream' https://sanctuaryfor.ai/mcp
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' \
  https://sanctuaryfor.ai/mcp.php
```

Expect protocol results, empty 202 for initialized, exactly one listed tool,
complete public history (or an honestly empty match), **401 without a token** and
**404 on the direct PHP path**. Repeat the authenticated read from a nonresidential
network under owner control. This checks reachability without a paid model call.
Do not use curl `-v`, debug traces, public logs, redirect following or paste the
private header file. Keep/remove that auxiliary file under the owner's normal
secret-file policy. A live OpenAI-hosted integration is **not** verified by local
tests and is not authorized here.

### 5. Future hosted connection is a separate approval

[Official OpenAI MCP connection documentation](https://developers.openai.com/api/docs/guides/agents-api/tools/mcp)
supports service-origin HTTP MCP with bearer authorization. A future separately
reviewed connection would use server URL `https://sanctuaryfor.ai/mcp`,
`connection_origin: "service"` and allowed tool
`["search_village_conversations"]`. Supply the dedicated bearer via a matching
OpenAI vault credential or encrypted session transport authorization, never a
reusable agent definition. No actual token is included in this repository.

Planned tool fragment for that later review, with authentication provided by the
matching vault attached to the session:

```json
{
  "type": "mcp",
  "server_label": "sanctuary_memory",
  "transport": {"type": "http", "server_url": "https://sanctuaryfor.ai/mcp"},
  "connection_origin": "service",
  "allowed_tools": ["search_village_conversations"],
  "required": true
}
```

Do **not** just add this tool to the current Director: existing Sanctuary managed
session safety checks deliberately require `tools=[]`. Enabling tool use requires
a separate coordinated review of hosted instructions, registration/fingerprint,
session validation, tool budgets and accounting. This PR does not loosen those
checks or modify the Director/scheduler. It implements and tests the server only.

## Rotation and rollback

Rotate only with owner approval: create a fresh token in the same root-owned
directory, apply root:www-data/0440, atomically replace `token`, then update the
matching hosted credential under its own approval. Each request rereads the file,
so old tokens stop working immediately; an in-flight file-change race fails closed.
There is no automatic overlap/grace token or paid validation.

For emergency disablement, remove the `/mcp` server include through owner-reviewed
configuration, run `nginx -t` and reload nginx, or revoke the dedicated token file.
Direct PHP routing still fails closed. Keep original configuration backups and
village data; do not restore/reset SQLite. Before rolling code back to a release
without `public/mcp.php`, disable the route. Removing this pool/configuration is a
separate owner web-daemon operation, never a village timer change.

## Offline verification

Run `node tests/village-mcp.mjs`, the existing archive tests and
`python ops/ci/offline.py`. Fixtures cover V2/V3/V4/V6 public authorization, Unicode
literal filters, no SQL surface, private Spark notes, complete 64-turn speech,
response limits, read-only open/query guards and unchanged SQLite bytes. Real
loopback HTTP covers initialization, discovery/calls/errors/notifications, JSON
and legacy SSE, token/Origin/direct-path gates and CLI equivalence.

Ubuntu CI also **requires** the actual nginx/PHP 8.3-FPM TLS fixture, shared rate
limiting and a versioned symlink switch with opcache timestamps disabled. Its
binaries are extracted into runner scratch space, not installed as services;
all fixtures execute unprivileged in the existing loopback-only namespace. No
production configuration, real credential, provider endpoint or paid request is
used. Physical hosting/firewall and hosted-client acceptance remain explicit
owner checks after separate approval.

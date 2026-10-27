# Toronto code deployment (#28)

This PR prepares deployment; it does not install or enable anything on Toronto. GitHub CI uses no production credentials and runs all tests inside a Linux network namespace with loopback only. No OpenAI endpoint is reachable. The production workflow is skipped unless the repository variable `TORONTO_DEPLOY_ENABLED` is explicitly `1` and the tested ref is `main`. Initial deployments must additionally pass the owner's GitHub `production` environment approval.

## Tailscale deployment transport

The deployment job now joins the tailnet through the official [tailscale/github-action v4.3.0 commit](https://github.com/tailscale/github-action/tree/99a8333667fff04fdd6cffb46dfd409f8329a7bf), pinned to `99a8333667fff04fdd6cffb46dfd409f8329a7bf`. Review covered that commit's action metadata, OAuth input validation/masking, Linux download verification, ephemeral authentication and post-job logout/daemon cleanup. This is a bounded source review, not a complete audit of every bundled dependency. The Ubuntu x64 client is explicitly `1.104.2`, with SHA-256 `512f613ed7d04fc7282f7dc5bd76aa105aae28168fbb9357eb749ebcbc29f0ae` from the [official Linux archive checksum](https://pkgs.tailscale.com/stable/tailscale_1.104.2_amd64.tgz.sha256). No floating action or client version is used. Review the action, version and checksum together when updating; a different runner architecture needs a different binary checksum.

The Tailscale step exists only in the `production` deployment job, after required CI and GitHub environment approval. It uses environment secrets `TS_OAUTH_CLIENT_ID` and `TS_OAUTH_SECRET` and exactly `tag:sanctuarytorontodeploy`. The action runs on the approved ephemeral GitHub runner, never Toronto; its post-action attempts logout/cleanup even after a later step fails. No tailnet login or deployment is run by PR CI. Tests remain loopback-only with no production secrets.

The SSH destination is fixed to **100.75.63.110:22**. Old `TORONTO_DEPLOY_HOST` / `TORONTO_DEPLOY_PORT` variables are no longer consulted, and there is no public-IP fallback. This is ordinary OpenSSH over the Tailscale network, not `tailscale ssh`: retain the existing restricted `sanctuarydeploy` authorized key, `TORONTO_DEPLOY_SSH_KEY` secret, and trusted `TORONTO_DEPLOY_KNOWN_HOSTS` pin. The unchanged `HostKeyAlias=sanctuary-toronto-deploy` lets the same verified server key authenticate the host despite the new destination address. No SSH key/pin rotation, root SSH, firewall change, service restart or server helper reinstallation is required by this transport-only update. The server's existing HTTPS website health check remains unchanged.

Owner prerequisites before a separately approved deployment:

- Put both OAuth values in the **production environment secrets**, not PR/repository-wide test configuration. The OAuth client needs writable `auth_keys` scope and authority for exactly the requested deployment tag, per the [official action instructions](https://github.com/tailscale/github-action/blob/99a8333667fff04fdd6cffb46dfd409f8329a7bf/README.md). Do not paste secrets into issues or logs.
- Ensure tailnet policy permits that tag to reach Toronto `100.75.63.110` on **TCP 22**, using the existing OpenSSH listener/key restrictions. Do not grant broad tailnet, subnet or administrative access merely to deploy. This PR changes no tailnet ACL, Toronto Tailscale/SSH setting or credential.
- Custom arguments contain only `--accept-dns=false`. The pinned action already supplies `--accept-routes`; do not repeat it in `args`, even with a different value, because duplicate flags make login fail. Route acceptance follows the action's behavior; this workflow does not claim to disable it. Binary caching is disabled. Tailscale login has one 60-second attempt; any failure stops before SSH, with no fallback. Existing workflow/server deployment locks and health/rollback behavior remain unchanged.
- If repository Actions policy uses an allowlist, permit the reviewed `tailscale/github-action` commit. OAuth requires no `id-token: write`; deployment permissions remain empty.
- Keep required production reviewers/main-only restrictions, and keep repository-level `TORONTO_DEPLOY_ENABLED` absent or `0` until the owner explicitly chooses to set it to `1`. Merging this PR does not enable that flag or bypass environment approval. Do not use `workflow_dispatch` as an offline test; it is the real deployment path when enabled.

Offline validation checks action pin/version/checksum, exact secrets/tag/destination, job placement and unchanged SSH protections. A Linux test executes the actual SSH shell step with dummy material and a local fake `ssh` executable, asserting target/port/SHA/pin/0600 temporary files, cleanup and rejection of public-IP/alternate-port/root/ref fallbacks. It never runs the Tailscale action, reads real keys, joins a tailnet or accesses Toronto. Tailnet authorization/reachability remains an owner-approved live deployment check, not something these mocks establish.

## What changes during a deployment

An unprivileged `sanctuarydeploy` account fetches this public repository, verifies the requested SHA is **current main**, validates the current release against its private content/mode manifest, builds an immutable release and switches one symlink atomically. PHP files receive syntax checks only, never execution. HTTPS `/health.php` must report the same commit and working PHP 8.3/PDO SQLite/cURL before success. Health does not open SQLite, start sessions or test the Director. A health failure restores the previous code pointer and verifies it; failures and interrupted transactions retain private audit/recovery evidence.

The workflow never uploads the working tree, runs `git pull/reset/clean`, executes migrations, changes registration/ENV/credentials, reloads PHP-FPM, or starts/stops/restarts the village service/timer. It never copies, restores or opens production SQLite. Old releases are retained so in-flight requests/CLI processes can finish against their original physical code path. There is no automatic pruning.

Paths after **separately approved, owner-run** initial setup:

```text
/var/www/sanctuaryfor.ai -> /var/lib/sanctuaryfor-ai-deploy/current
/var/lib/sanctuaryfor-ai-deploy/current -> .../releases/<40-character SHA>
/var/lib/sanctuaryfor-ai-deploy/releases/<SHA>/{public,app,scripts,...}
/var/lib/sanctuaryfor-ai-deploy/private/{repository.git,<SHA>.json,audit.jsonl,transaction.json}
/usr/local/lib/sanctuaryfor-ai-deploy/toronto.py   # root-owned installed helper
```

SQLite remains `/var/lib/sanctuaryfor-ai/village.sqlite`; keys and `simulation.env` remain under their existing `/etc/sanctuaryfor-ai/` protection. The deploy account must have no access to either. Do not add it to `www-data`, staging or privileged groups; it needs no sudo. The existing scheduler's absolute `/var/www/sanctuaryfor.ai/scripts/...` entry point continues to resolve through the stable link on its next invocation, without a service/timer change.

## 1. Review the one-time prerequisites

Complete these before enabling the workflow. These are owner operations, **not commands Codex has executed**.

* Merge this PR only after the new **Offline checks / PHP 8.3 and JavaScript** check passes. Test names are discovered, so future `.mjs` suites are included. The deployment job reruns that same reusable CI workflow on the exact main SHA; it receives no secrets from the caller.
* Use an existing trusted Toronto session or Linode console to verify host identity. Do not bootstrap trust from an unauthenticated `ssh-keyscan`.
* Confirm Git, Python 3, PHP 8.3 CLI and PHP-FPM PDO SQLite/cURL are installed. The installed helper uses Python stdlib only.
* Verify Nginx's effective PHP configuration uses the **resolved release path**, for example `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;` and `fastcgi_param DOCUMENT_ROOT $realpath_root;`. Replace conflicting existing parameters rather than adding duplicates. This avoids stale symlink paths in PHP's realpath/opcache caches without routine FPM reloads. Check `disable_symlinks`, AppArmor and any path restrictions permit the new releases directory. If changes are needed, handle them as an independently reviewed owner configuration change (`nginx -t` before an owner-approved reload). This PR does not edit Nginx. [Nginx variables](https://nginx.org/en/docs/http/ngx_http_core_module.html#variables), [FastCGI parameters](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_param).
* Initial conversion of the legacy checkout is a small maintenance operation: wait for active encounters/requests to finish and do it between scheduled runs. Preserve the timer's current state. The legacy checkout must be clean, without ignored runtime files relying on its location. Its old directory is retained, not deleted. If a hotfix remains (including the historical `if($paid){...loadScheduledCredential();}` fix), STOP and reconcile it with main first. Never discard it using reset/checkout/clean.

## 2. Create the dedicated key (owner's computer)

Generate **one new Ed25519 key pair**, distinct from personal/root/staging keys:

```powershell
ssh-keygen -t ed25519 -C "sanctuary-github-deploy" -f "$env:USERPROFILE\.ssh\sanctuary_github_deploy"
```

For this unattended, command-restricted key, leave its passphrase empty at the prompt. Do not overwrite an existing key. The `.pub` file goes into the restricted server entry below; the private file goes only into the GitHub production environment secret. Never paste the private key into chat, issues, shell arguments or Git. No Linode API token, new OpenAI key or personal GitHub token is needed: server Git fetches are public, and Actions supplies its read-only repository token. [GitHub token](https://docs.github.com/en/actions/concepts/security/github_token).

## 3. Prepare restricted server ownership (owner root session)

Choose the exact reviewed merged **current main** commit as `REVIEWED_SHA` below (40 lowercase hex characters). Do not type the placeholder literally. All directories must be new; investigate any existing account/path rather than overwriting it.

```bash
set -euo pipefail
REVIEWED_SHA=REPLACE_WITH_REVIEWED_40_CHARACTER_MAIN_SHA
[[ "$REVIEWED_SHA" =~ ^[a-f0-9]{40}$ ]] || exit 1
test ! -e /var/lib/sanctuaryfor-ai-deploy || exit 1
test ! -e /var/empty/sanctuarydeploy || exit 1
getent passwd sanctuarydeploy && exit 1
useradd --system --user-group --home-dir /var/empty/sanctuarydeploy --shell /bin/sh sanctuarydeploy
install -d -o root -g root -m 0755 /var/empty/sanctuarydeploy
install -d -o root -g root -m 0755 /var/empty/sanctuarydeploy/.ssh
install -d -o sanctuarydeploy -g sanctuarydeploy -m 0755 /var/lib/sanctuaryfor-ai-deploy
install -d -o sanctuarydeploy -g sanctuarydeploy -m 0700 /var/lib/sanctuaryfor-ai-deploy/private
install -d -o sanctuarydeploy -g sanctuarydeploy -m 0755 /var/lib/sanctuaryfor-ai-deploy/releases
sudo -u sanctuarydeploy git -c core.hooksPath=/dev/null clone --bare \
  https://github.com/markwaynestalker/sanctuaryfor.ai.git \
  /var/lib/sanctuaryfor-ai-deploy/private/repository.git
test "$(sudo -u sanctuarydeploy git --git-dir=/var/lib/sanctuaryfor-ai-deploy/private/repository.git rev-parse refs/heads/main)" = "$REVIEWED_SHA" || exit 1
install -d -o root -g root -m 0755 /usr/local/lib/sanctuaryfor-ai-deploy
test ! -e /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py || exit 1
sudo -u sanctuarydeploy git --git-dir=/var/lib/sanctuaryfor-ai-deploy/private/repository.git \
  show "$REVIEWED_SHA:ops/deploy/toronto.py" > /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py
chown root:root /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py
chmod 0755 /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py
sha256sum /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py
```

Compare the helper's hash/content against the reviewed commit before running it. The helper is installed outside release code and cannot update itself. Review later helper updates separately.

Create `/var/empty/sanctuarydeploy/.ssh/authorized_keys` with **one line**, replacing only the public key fields:

```text
restrict,command="/usr/bin/python3 -I /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py" ssh-ed25519 PUBLIC_KEY_BASE64 sanctuary-github-deploy
```

Set that file root:root, mode 0644; keep the home and `.ssh` root-owned 0755 so the account cannot replace its own restrictions. `restrict` disables interactive PTY, forwarding and user rc execution; the forced helper accepts only `deploy <SHA>` over SSH. No shell, SCP, arbitrary flags, prepare, reviewed activation or rollback is available to the workflow key. Keep existing SSH configuration unchanged. Check `id sanctuarydeploy` and, without reading secret contents:

```bash
sudo -u sanctuarydeploy test ! -r /var/lib/sanctuaryfor-ai/village.sqlite
sudo -u sanctuarydeploy test ! -x /var/lib/sanctuaryfor-ai
sudo -u sanctuarydeploy test ! -r /etc/sanctuaryfor-ai/simulation.env
```

Also verify denial for the actual existing API credential source path; do not print/read that source. Any failed isolation check blocks activation.

## 4. Preserve the legacy checkout and establish the initial pointer

Read-only checks first (any output from status/ignored-files means STOP and review):

```bash
test -d /var/www/sanctuaryfor.ai/.git && test ! -L /var/www/sanctuaryfor.ai || exit 1
test "$(realpath /var/www/sanctuaryfor.ai)" = /var/www/sanctuaryfor.ai || exit 1
git -c safe.directory=/var/www/sanctuaryfor.ai -C /var/www/sanctuaryfor.ai status --porcelain=v1 --untracked-files=all
git -c safe.directory=/var/www/sanctuaryfor.ai -C /var/www/sanctuaryfor.ai ls-files --others --ignored --exclude-standard
git -c safe.directory=/var/www/sanctuaryfor.ai -C /var/www/sanctuaryfor.ai remote get-url origin
```

Confirm the expected repository and that the staged release is compatible with the existing V6 database/ENV. No migrations run. When separately approved, in the same root shell with `REVIEWED_SHA` still set:

```bash
test -z "$(git -c safe.directory=/var/www/sanctuaryfor.ai -C /var/www/sanctuaryfor.ai status --porcelain=v1 --untracked-files=all)" || exit 1
test -z "$(git -c safe.directory=/var/www/sanctuaryfor.ai -C /var/www/sanctuaryfor.ai ls-files --others --ignored --exclude-standard)" || exit 1
sudo -u sanctuarydeploy /usr/bin/python3 -I /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py prepare "$REVIEWED_SHA"
test ! -e /var/lib/sanctuaryfor-ai-deploy/current || exit 1
sudo -u sanctuarydeploy ln -s "/var/lib/sanctuaryfor-ai-deploy/releases/$REVIEWED_SHA" /var/lib/sanctuaryfor-ai-deploy/current
test ! -e /var/www/sanctuaryfor.ai-before-release-deploy || exit 1
mv -T /var/www/sanctuaryfor.ai /var/www/sanctuaryfor.ai-before-release-deploy
ln -s /var/lib/sanctuaryfor-ai-deploy/current /var/www/sanctuaryfor.ai
curl --fail --silent --show-error "https://sanctuaryfor.ai/health.php?release=$REVIEWED_SHA"
```

Require exactly `{"status":"ok","commit":"<REVIEWED_SHA>"}` (JSON spacing is irrelevant), then check homepage, commons, saved journal and private operator status. These reads must not generate dialogue. Confirm the existing timer remains in its original state; do not restart it. Retain the old checkout and ordinary backups. If the initial link setup fails health, before enabling Actions, restore only the old code directory:

```bash
test -L /var/www/sanctuaryfor.ai || exit 1
test "$(readlink /var/www/sanctuaryfor.ai)" = /var/lib/sanctuaryfor-ai-deploy/current || exit 1
unlink /var/www/sanctuaryfor.ai
mv -T /var/www/sanctuaryfor.ai-before-release-deploy /var/www/sanctuaryfor.ai
```

This is code recovery only. Never copy an old SQLite file over the current village.

## 5. Configure GitHub (owner)

In repository **Settings → Environments**, create `production` **before enabling** the workflow. Restrict deployment branches to **main only**, add yourself/a trusted operator as a required reviewer, and disable administrator bypass if suitable for your team. Do not enable “prevent self-review” if you are the sole operator who must approve a manual dispatch. These protection settings are external GitHub configuration, not activated by YAML. [Environment protection](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments).

Add these to that environment:

| Type | Name | Value |
| --- | --- | --- |
| Secret | `TORONTO_DEPLOY_SSH_KEY` | Complete new private key, including header/footer |
| Secret | `TS_OAUTH_CLIENT_ID` | Tailscale OAuth client authorized for `tag:sanctuarytorontodeploy` |
| Secret | `TS_OAUTH_SECRET` | That client’s protected OAuth secret |
| Variable | `TORONTO_DEPLOY_USER` | `sanctuarydeploy` |
| Variable | `TORONTO_DEPLOY_KNOWN_HOSTS` | Trusted public host key line described below |

From the trusted **server console/session**, obtain the public pin (not the private host key):

```bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
awk '{print "sanctuary-toronto-deploy "$1" "$2}' /etc/ssh/ssh_host_ed25519_key.pub
```

Independently verify the fingerprint using your trusted console. Store the second command's single line in `TORONTO_DEPLOY_KNOWN_HOSTS`. The workflow uses that explicit alias with strict host checking, no keyscan, no fallback/password authentication and no SSH agent forwarding. A changed pin fails closed; review a genuine host-key rotation independently.

In repository **Settings → Actions → General**, keep the workflow token read-only. Only pinned checkout/setup-php/setup-node run in CI; the approved deployment job additionally runs the pinned official Tailscale action, checks out no repository code and uses native SSH. Protect `main`: require pull requests, passing **PHP 8.3 and JavaScript** CI (select its exact observed check name after the first run), code-owner review for `.github`/`ops`/migration/config paths, and prevent force pushes/deletion. Configure reviewer/bypass policy deliberately so the sole owner is not locked out. CODEOWNERS alone does not enforce review.

Finally add a **repository-level variable** `TORONTO_DEPLOY_ENABLED=1`. Omitted, blank or `0` skips deployment. It must be repository-level because the job condition is evaluated before environment variables are available. Do not add it until server setup, isolation, host pin and required environment approval are verified.

## 6. First approved deployment and later automation

From **Actions → Toronto deployment → Run workflow**, choose **main**. No SHA input exists: CI tests and SSH deploy the exact dispatch SHA. Review the successful tests and approve the pending `production` job. An already-current release still receives manifest and HTTPS health verification. Confirm the Actions result/summary and the server's private `audit.jsonl`. CI has no access to that file or SQLite.

If main advances while an older run awaits approval, the server refuses `target_is_not_current_main`. Dispatch/review current main instead; do not weaken SHA checks. Workflow serialization plus the server lock prevent overlap; GitHub may replace a pending run with a newer one, but never cancels an active deployment.

Only after a successful approved rollout and explicit owner decision, remove the required reviewer from `production` to allow unattended deploys after passing main CI. Keep main-only environment restrictions, protected branch reviews, pinned host identity and restricted account. To pause deploys at any point set the repository flag to `0`; this does not stop an already approved/in-progress deployment and does not pause the village timer.

## Recovery and separate operational releases

Local owner commands (the workflow key cannot invoke these), substituting an actual previously installed SHA:

```bash
sudo -u sanctuarydeploy /usr/bin/python3 -I /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py rollback PREVIOUS_40_CHARACTER_SHA
sudo -u sanctuarydeploy /usr/bin/python3 -I /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py recover
```

Use `rollback` for an installed, unmodified, schema-compatible code release. Use `recover` only for a retained interrupted/failed rollback `transaction.json`; it restores the recorded previous release and verifies health before clearing that transaction. Preserve the append-only audit. Automatic rollback reverts code only; accepted village history and ongoing activity remain in current SQLite. Do not assume old code is compatible after an independently performed schema/config change.

Normal SSH deployment refuses changes to schema/migration/registration files, `.env.example`, `ops/systemd/`, or the installed-helper source. Such changes need separate owner review; a template change is intentionally conservative and may require no live config edit. After completing any required compatibility work separately, the owner can explicitly activate reviewed **current main** with:

```bash
sudo -u sanctuarydeploy /usr/bin/python3 -I /usr/local/lib/sanctuaryfor-ai-deploy/toronto.py activate-reviewed REVIEWED_40_CHARACTER_MAIN_SHA
```

This local command only skips the operational-diff gate. It still verifies main, all existing release content, locking, staging and health. It runs no migration/config edit. Never expose it through `authorized_keys`. Update the root-owned installed helper separately if that source changed. Normal code rollback across operational differences is refused pending separate compatibility review.

| Exit / diagnostic | Meaning / smallest safe response |
| --- | --- |
| 10 / setup or restricted command | Check account, root-owned stable link, fixed paths/modes and forced command; no general shell workaround |
| 20 / local/archive/syntax/manifest validation | Keep old code; inspect private staging paths and reviewed source, preserve any unexpected local changes |
| 21 / separate_operational_review_required | Perform the separate owner review above; never run migrations automatically |
| 22 / repository or main mismatch | Verify public origin/current main; dispatch latest main after tests |
| 30 / deployment_busy | Let the existing deployment finish; do not remove its lock file |
| 31 / unfinished transaction | Inspect private journal/audit; use owner `recover`, not journal deletion |
| 40 / health failure, previous restored | Check website/FPM/path prerequisites; deployment is failed, not silently successful |
| 41 / rollback/recovery health failure | Preserve transaction and both releases; owner investigates before another deploy |

If an interrupted staging operation leaves `.stage-*` or a manifest without a completed release, leave the active pointer alone and inspect those exact paths. Preserve/quarantine incomplete artifacts under owner control before preparing that SHA again; no recursive cleanup or retention job is included. Do not edit immutable release files in place. Fix code through a PR; unexpected hotfixes deliberately block deployment.

## Validation evidence and limits

`ops/ci/offline.py` discovers all existing PHP/JS syntax checks and 15 Node suites, plus `tests/deployment_test.py`. Portable tests cover SSH argument/path policy, workflow isolation and a health endpoint that creates no DB. Linux tests additionally use disposable local Git repositories/releases to cover exact SHA, non-main refusal, permissions, no PHP execution, dirty code, symlink/archive rejection, operational gates, locking, idempotency, health rollback and interrupted recovery. They never SSH, access Toronto, read real keys or call OpenAI. Windows skips the Linux permission/lock tests explicitly; the PR's Ubuntu/PHP 8.3 CI is required before merge. Tailnet authorization/reachability, host pin, existing Nginx/FPM settings and the first real deployment remain owner verification steps.

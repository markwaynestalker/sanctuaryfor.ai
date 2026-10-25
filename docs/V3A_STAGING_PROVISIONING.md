# Isolated staging setup and owner-run privileged verification

The owner initially proposed these resources and subsequently reported the account/directory/credential installed. Final credential ownership/mode verification is pending:

| Resource | Proposed value |
| --- | --- |
| Non-login service account/group | `sanctuarystaging` |
| Private staging root | `/var/lib/sanctuaryfor-ai-staging` |
| Dedicated staging-only API-key source | `/etc/sanctuaryfor-ai/staging-openai-key` |
| Credential delivered to PHP | systemd `LoadCredential=openai_api_key:...` |

No resources were created or credentials provisioned by Codex. Do not rerun the historical account/setup commands below against installed resources. Paid execution still needs separate approval against an actual permit. Never send a key value through chat or paste it into commands/history.

## Current no-cost checks and remaining owner action

Read-only SSH through the existing Tailscale alias verified hostname `sanctuary`, login `mark`; the public `canada` route timed out. Verified without privilege: staging UID 999/GID 988 with only its own group; staging directory sanctuarystaging:sanctuarystaging 0700; credential parent root:root 0700; PHP CLI 8.3.6 with PDO SQLite/cURL; production timer inactive and disabled. The key file's metadata was inaccessible to mark. Mark lacks noninteractive sudo. Automatic approval review rejected unapproved root SSH; the owner elected to run privileged checks personally. No key contents, authenticated OpenAI request, SQLite open or service/configuration modification occurred.

Run this metadata-only block in the owner's existing privileged Toronto shell; it does not open SQLite or read the key. Send only its metadata/pass/fail output, never secret contents:

```sh
set -eu
hostname
whoami
id sanctuarystaging
stat -c 'staging-directory owner=%U group=%G mode=%a type=%F' /var/lib/sanctuaryfor-ai-staging
stat -c 'credential owner=%U group=%G mode=%a type=%F' /etc/sanctuaryfor-ai/staging-openai-key
test -f /etc/sanctuaryfor-ai/staging-openai-key
test ! -L /etc/sanctuaryfor-ai/staging-openai-key
test "$(stat -c %U /etc/sanctuaryfor-ai/staging-openai-key)" = root
case "$(stat -c %a /etc/sanctuaryfor-ai/staging-openai-key)" in
  400|600) ;; *) echo 'FAIL: credential source permissions'; exit 1 ;;
esac
test -f /var/lib/sanctuaryfor-ai/village.sqlite
runuser -u sanctuarystaging -- test ! -r /var/lib/sanctuaryfor-ai/village.sqlite
runuser -u sanctuarystaging -- test ! -r /var/lib/sanctuaryfor-ai
runuser -u sanctuarystaging -- test ! -r /etc/sanctuaryfor-ai/staging-openai-key
echo 'PASS: staging cannot read production SQLite/private directory or raw credential source'
```

These checks establish permissions, **not** provider/project authorization. Before any live encounter, independently confirm that the dedicated staging key's project can retrieve the existing saved agent `agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8` with its required restricted permissions. That confirmation has not occurred. A read-only authenticated agent-metadata check may establish access without creating a session/model response, but must use the protected staging credential delivery, retain/count its request evidence and be included in the reviewed staging request envelope; do not improvise a key-bearing curl command or infer authorization from a matching agent ID in configuration. No OpenAI request has been made by this preflight.

## Historical setup plan — do not rerun on installed resources

Check that all chosen resources are genuinely unused, review the existing production database owner/groups (metadata only), and confirm the staging account will receive none of those groups. Abort on a name/path collision; do not modify an unrelated account or overwrite a directory/key. Example for an authenticated operator:

```sh
set -eu
umask 077
if getent passwd sanctuarystaging >/dev/null; then exit 1; fi
if getent group sanctuarystaging >/dev/null; then exit 1; fi
test ! -e /var/lib/sanctuaryfor-ai-staging
test ! -e /etc/sanctuaryfor-ai/staging-openai-key
sudo useradd --system --user-group --no-create-home \
  --home-dir /var/lib/sanctuaryfor-ai-staging --shell /usr/sbin/nologin sanctuarystaging
sudo install -d -m 0700 -o sanctuarystaging -g sanctuarystaging /var/lib/sanctuaryfor-ai-staging
id sanctuarystaging  # require no www-data or production database-access groups
sudo -u sanctuarystaging test ! -r /var/lib/sanctuaryfor-ai/village.sqlite
sudo -u sanctuarystaging test ! -r /var/lib/sanctuaryfor-ai
```

The dedicated API key must be in the existing saved Director's OpenAI project. Owner verifies exact restricted Agents/Responses/moderation permissions in the Platform. The application's transport independently denies agent edits, new agents, automations, arbitrary APIs, tools, vaults and sandboxes. A key's permissions are not a price cap or billing isolation.

Provision the key through the owner's approved secret workflow into the chosen **new** root-owned `0600` source file. Do not change ownership/mode of the existing `/etc/sanctuaryfor-ai` directory, existing production credentials or FPM configuration. Confirm the staging account cannot directly read the key source. systemd reads that source as manager and supplies the named read-only credential only to the approved transient process. Do not include LoadCredential in the no-cost check below; no secret is needed there.

Use a separate root-owned, read-only checkout of the reviewed full SHA at `/opt/sanctuaryfor-ai-staging/REVIEWED_SHA`, not `/var/www/sanctuaryfor.ai`. Provision this checkout under the approved setup; do not grant the staging account code-write access. Verify PHP 8.3.6 PDO SQLite/cURL and systemd support for the selected hardening/credential properties. No package installation is implied.

## No-cost isolated preflight

Set `CODE` to that reviewed checkout and create one fresh experiment directory beneath the private staging root (0700, owned by sanctuarystaging). No timer or persistent service is installed. The transient service below runs only the read-only host check; it neither loads a key nor opens SQLite:

```sh
CODE=/opt/sanctuaryfor-ai-staging/REVIEWED_SHA
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/APPROVED_EXPERIMENT_ID
# Fill/review these exact paths first; refuse existing/unrelated destinations.
test -d "$CODE"
test ! -e "$EXPERIMENT"
sudo install -d -m 0700 -o sanctuarystaging -g sanctuarystaging "$EXPERIMENT"
sudo systemd-run --wait --collect --pipe --service-type=oneshot \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict \
  --property="ReadWritePaths=$EXPERIMENT" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm" \
  /usr/bin/php8.3 "$CODE/scripts/stage-director-once.php" --check-host
```

Require a non-root isolated pass, paid_requests=0, credential_loaded=false and database_opened=false. Then run `--check` with the same isolation to record the runtime fingerprint. Run the thirteen offline suites using disposable fixtures under this environment and API keys cleared. This verifies PHP 8.3 rather than relying only on local Windows PHP 8.2 results. Confirm the production timer is still inactive through the owner's existing operator checks; do not start/enable it or load its credentials.

Initialize the fresh synthetic DB and two owner-reviewed characters with all paid flags off as described in V3A_STAGING_AND_RELEASE_REVIEW.md. Register the existing agent offline, generate an **unapproved** permit, verify source/configuration/resident digests and submit the actual plan for paid approval. This entire preparation stage makes zero provider requests and never copies production data.

## After a later paid-permit approval only

Use the same one-shot isolation, explicit staging-only environment, exact database/permit/opportunity and `RuntimeMaxSec=75s`; the PHP client retains its own 60-second deadline. Add only then:

```text
--property=LoadCredential=openai_api_key:/etc/sanctuaryfor-ai/staging-openai-key
```

The final executable is `php8.3 .../stage-director-once.php --allow-paid --database=... --at=... --permit=...`; its approved limits and gates are listed in the experiment review. Do not put API-key values in systemd-run arguments or environment properties. Never use `systemd-run --on-calendar`, a timer, persistent restart policy or automatic retry. This is not production activation.

After the one attempt, no paid process remains scheduled. Retain the DB/permit/audits and reconcile billing independently; retire/revoke the dedicated key through the owner's secret workflow when appropriate. No automatic deletion of evidence or account/key removal is included. Cancellation, a process kill or revocation does not prove a final bounded bill. A failed plan/preflight must be investigated, not bypassed.

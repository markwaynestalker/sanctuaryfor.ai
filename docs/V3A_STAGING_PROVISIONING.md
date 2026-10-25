# Isolated staging setup and owner-run privileged verification

**Access subsequently confirmed:** the owner-approved metadata GET passed with the existing staging key, original receipt unchanged and zero generation/database opens. Both October 25 UTC access reservations remain consumed. No further metadata request is approved. See [the synthetic encounter preparation and approval plan](STAGING_ENCOUNTER_APPROVAL.md); earlier pending-authorization instructions below are historical and must not be rerun.

**Latest result:** owner-run unit run-u207.service passed repaired credential validation on Toronto with a non-secret stand-in, zero content/network/database reads and unchanged original audit. See [the proposed real-key access review](DIRECTOR_ACCESS_REVIEW.md) for the next exact owner-run command, fixed successor receipt and request accounting. It is prepared for approval only; no retry is authorized by the stand-in pass.

**Current hold:** the original owner-run access attempt returned `not_confirmed` with null HTTP status/request ID. Its receipt is preserved; do not repeat the launch below, choose a fresh receipt or reset it. Subsequent non-secret LoadCredential testing identified the old guard rejecting root:root `0440` delivery. The code fix and next review-gated **non-network, metadata-only stand-in** are documented in [systemd credential validation](SYSTEMD_CREDENTIAL_VALIDATION.md). Agent authorization remains unconfirmed; no paid call is approved. Older access-launch instructions below are historical procedure, not a retry authorization.

The owner reports all privileged security checks passed: hostname sanctuary/operator root; staging UID 999/GID 988 with only its own group; staging directory owned by staging at 0700; credential root:root 0600 regular file; staging cannot read production SQLite/private directory or the raw credential source. No secret content, provider request or database open was involved. These are owner-run results, not a new root login by Codex.

| Resource | Proposed value |
| --- | --- |
| Non-login service account/group | `sanctuarystaging` |
| Private staging root | `/var/lib/sanctuaryfor-ai-staging` |
| Dedicated staging-only API-key source | `/etc/sanctuaryfor-ai/staging-openai-key` |
| Credential delivered to PHP | systemd `LoadCredential=openai_api_key:...` |

No resources were created or credentials provisioned by Codex. Do not rerun the historical account/setup commands below against installed resources. Paid execution still needs separate approval against an actual permit. Never send a key value through chat or paste it into commands/history.

## Current no-cost checks and remaining owner action

Read-only SSH through Tailscale verified hostname `sanctuary`, login `mark`; public `canada` timed out. Nonprivileged checks verified the staging group/directory, root:root 0700 credential parent, PHP CLI 8.3.6 with PDO SQLite/cURL and timer inactive/disabled. The owner has now supplied the privileged passes summarized above. Do not rerun them or ask for key contents. Mark still lacks sudo; privileged launches remain owner-run, not an assumed root SSH grant.

The following metadata-only block is retained as the completed check procedure; it does not open SQLite or read the key. Never send secret contents:

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

## Next: one metadata-only access check through LoadCredential

`scripts/check-director-access.php` implements exactly one fixed `GET https://api.openai.com/v1/agents/agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8`. The [official retrieval contract](https://developers.openai.com/api/reference/resources/beta/subresources/agents/methods/retrieve) returns a reusable agent scoped to the caller's project. HTTP 200 with the exact agent identity proves that key can retrieve it; 401/403/404 or uncertainty does not. This does not prove permission to generate a session or moderate output, and creates no session/model response.

The verifier requires Linux staging identity, denied production access, all paid/activity flags off and CREDENTIALS_DIRECTORY. It reads only the delivered credential, never the root source file directly. It uses fixed HTTPS/TLS, no redirects/retries, a 15-second timeout, bounded body, and prints/stores only status/request ID and structural booleans. Instructions, metadata, vendor error bodies and key values are discarded. It does not open any database.

It exclusively creates a private JSONL audit file, fsyncs one reserved metadata-request intent **before** reading the delivered credential/network, and appends the safe outcome. Existing audit files refuse another request regardless of success/failure. Do not delete/rename the receipt or choose a fresh filename to retry without review. It reports one reserved HTTP request and zero generation requests; do not misreport this as zero total API requests.

Because mark has no sudo and the owner elected privileged operations, run the following only from the owner's existing root shell, using a separate root-owned read-only checkout of the reviewed PR commit. Do not run it from production code, change production settings, or enable any timer. If the checkout does not exist, prepare that isolated checkout first; do not substitute /var/www/sanctuaryfor.ai. Fill CODE with its exact reviewed full SHA path:

```sh
set -eu
umask 077
CODE=/opt/sanctuaryfor-ai-staging/REVIEWED_FULL_SHA
AUDIT=/var/lib/sanctuaryfor-ai-staging/preflight
test -d "$CODE/app"
test -f "$CODE/scripts/check-director-access.php"
# Must report zero HTTP, zero generation, no credential and no database:
/usr/bin/php8.3 "$CODE/scripts/check-director-access.php" --check
# Create only this staging audit directory if absent; never overwrite receipts.
if test ! -e "$AUDIT"; then install -d -m 0700 -o sanctuarystaging -g sanctuarystaging "$AUDIT"; fi
test "$(stat -c %U "$AUDIT")" = sanctuarystaging
test "$(stat -c %a "$AUDIT")" = 700
test ! -e "$AUDIT/agent-access.jsonl"
systemd-run --wait --collect --pipe --service-type=oneshot \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict \
  --property=RuntimeMaxSec=30s \
  --property="ReadWritePaths=$AUDIT" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm" \
  --property=LoadCredential=openai_api_key:/etc/sanctuaryfor-ai/staging-openai-key \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging \
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/php8.3 "$CODE/scripts/check-director-access.php" --audit="$AUDIT/agent-access.jsonl"
```

Send only the safe JSON result, never the delivered/source key. Require authorized=true and configuration_matches=true; service_tier_default is informative because the encounter adapter explicitly requests default tier. A successful read does not activate the Director or authorize a paid test. If it fails, retain the audit and diagnose; no automatic retries or key/permission changes.

The read-only Toronto inspection found `/opt/sanctuaryfor-ai-staging` absent. If still absent, the owner can prepare a **new staging checkout only**, from the existing privileged shell, before the launch above. This does not change the production checkout:

```sh
SHA=REVIEWED_FULL_40_HEX_SHA_FROM_PR9
BASE=/opt/sanctuaryfor-ai-staging
CODE="$BASE/$SHA"
test ! -e "$CODE"
if test ! -e "$BASE"; then install -d -m 0755 -o root -g root "$BASE"; fi
test "$(stat -c %U "$BASE")" = root
(
  umask 022
  git clone --no-checkout https://github.com/markwaynestalker/sanctuaryfor.ai.git "$CODE"
  git -C "$CODE" checkout --detach "$SHA"
)
test "$(git -C "$CODE" rev-parse HEAD)" = "$SHA"
# Return to the metadata-only launch block above, using this CODE value.
```

Local mock tests passed for one-request intent, denied/wrong-agent/redirect/timeout outcomes, safe structural booleans, no secret/instruction/vendor-body disclosure and refusal to repeat an existing receipt. The existing Director suite also passed. Toronto PHP 8.3.6 syntax-checked the three new PHP files via stdin without execution; systemd 255 is present. These results do not establish credential delivery or agent access. The owner-run isolated process is still required; no authenticated OpenAI request has been made by Codex.

**Before any paid permit:** reconcile this separate metadata request/evidence into that UTC day's total application request allowance and the approved experiment envelope. The existing eight-call encounter reservation does not silently include this earlier access check. Do not automatically raise its daily limit, omit the receipt from accounting or run the paid command while this reconciliation remains outstanding. No paid approval is being requested by this metadata check.

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

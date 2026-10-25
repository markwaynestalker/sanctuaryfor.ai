# systemd staging credential validation

The owner-run `run-u206.service` stand-in (invocation `5403278f81b649c8878d6f389a199aad`) delivered a root:root `0440` regular file readable by UID 999/GID 988. PHP read no credential contents; no network/database access occurred; the original audit digest remained unchanged. This diagnoses the old blanket group-bit rejection, not the staging key's content or project authorization.

[systemd v255's credential implementation](https://github.com/systemd/systemd/blob/v255/src/core/exec-credential.c) sets a credential to `0400`, then grants the service UID read access through a POSIX ACL. The ACL mask can appear as group-read mode bits (`0440`). Without ACL support, systemd can instead transfer ownership to the service UID once read-only mounting is safe. The [systemd credential design](https://systemd.io/CREDENTIALS/) provides a private, read-only, per-unit credential directory. Do not chmod the root-owned secret source to accommodate the delivered copy.

## Accepted trust boundary

`app/systemd_credentials.php` is shared by the staging access verifier and the approval-gated one-shot encounter runner. It does not relax the unrelated legacy scheduler/FPM credential path.

- Linux, non-root effective UID, and POSIX UID/GID inspection are required.
- `CREDENTIALS_DIRECTORY` must be the canonical `/run/credentials/<unit>.service`, with the unit name matching `/proc/self/cgroup` (unified cgroup v2) and a well-formed systemd `INVOCATION_ID` present. An environment-selected arbitrary directory is insufficient. Private cgroup namespaces that conceal this unit name fail closed; do not disable validation to accommodate them.
- `/run` and `/run/credentials` must be real root-owned directories without group/world write or special mode bits. The per-unit directory must be real, owned by root or the effective UID, with root or effective GID, owner read/search, no write/special/world permissions, and actual kernel-authorized read/search access.
- Only the fixed `openai_api_key` regular file is accepted: mode exactly `0400` or `0440`, root or effective UID/GID, one hard link, 1–514 bytes, and actually readable by the service. Other owners/groups, executable/writable/world-readable files and symlinks fail closed.
- Bounded kernel metadata inspection must show a read-only mount at the exact credential directory and a read-only covering mount for the file. Missing/ambiguous mounts or a writable child overmount are rejected. Group-read is accepted only inside this root/systemd-controlled boundary; this does not claim to independently enumerate ACL entries. Root and systemd provisioning remain trusted.
- The secret reader validates again, opens the fixed file, matches descriptor metadata/inode to the inspected snapshot before and after the bounded read, and validates key length/whitespace without exposing its contents. Only a real, separately approved access/encounter attempt invokes that reader.

The metadata-only inspector reads `/proc/self/mountinfo` and `/proc/self/cgroup`, never the credential contents. It reports static failure codes and file metadata only. Access audits also record allowlisted failure codes without exception strings or vendor bodies; old receipts remain immutable. Neither a valid delivery report nor an access receipt authorizes paid execution.

## Review first: no-network stand-in verification

After reviewing the fix, the owner can run the following from the existing privileged shell against a new, root-owned, read-only checkout of the reviewed PR #9 SHA. This creates only an ephemeral diagnostic unit. It uses `/etc/hostname` as a **non-secret stand-in**, not the staging API-key source. PHP does not read the stand-in's contents. There are no network requests, database opens or audit writes. Do not substitute the authorization command or real key source.

```sh
set -eu
CODE=/opt/sanctuaryfor-ai-staging/REVIEWED_FULL_SHA
AUDIT=/var/lib/sanctuaryfor-ai-staging/preflight/agent-access.jsonl
test -f "$CODE/scripts/check-director-access.php"
test -f "$AUDIT"
BEFORE=$(sha256sum "$AUDIT" | cut -d ' ' -f 1)
RESULT=0
systemd-run --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=PrivateNetwork=yes --property=ProtectHome=yes \
  --property=ProtectSystem=strict --property=RuntimeMaxSec=15s \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /var/lib/sanctuaryfor-ai-staging/preflight /etc/sanctuaryfor-ai" \
  --property=LoadCredential=openai_api_key:/etc/hostname \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging \
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/php8.3 "$CODE/scripts/check-director-access.php" --check-delivery || RESULT=$?
AFTER=$(sha256sum "$AUDIT" | cut -d ' ' -f 1)
test "$BEFORE" = "$AFTER"
printf 'audit_unchanged=PASS\ndiagnostic_exit=%s\n' "$RESULT"
test "$RESULT" -eq 0
```

Expect `valid=true`, `failure_code=null`, effective UID/GID 999/988, `file_mode="0440"` (or supported `0400`), and `credential_content_reads=0`, `network_requests=0`, `database_opens=0`. A denial means inspect the static failure code; do not relax modes, grant access, retry authorization, reset the audit or change credentials. This probe does not test key formatting, HTTPS or saved-agent permissions.

## Offline regression coverage and rollback

Run `PHP_BINARY=/path/to/php node tests/director-access.mjs` and the existing offline suites with keys cleared. Synthetic stat/mount evidence covers root-owned ACL `0440`, root/service-owned `0400`, service-owned directory fallback, unsafe owners/modes, symlinks, hardlinks, arbitrary/cross-unit paths, missing invocation, writable/ambiguous mounts, safe failure receipts and refusal to change/repeat an existing audit. These tests require no privileged filesystem fixture or API request. Actual systemd ACL/mount behavior still needs the owner-run stand-in above on Toronto.

This is a code-only fix: no schema, migration, source-secret permission change, receipt reset or scheduler activation. Reverting the commit restores the old restrictive guard; retain all receipts and keep execution disabled. Any later authorization retry needs its own reviewed plan, separate evidence and request accounting. The original uncertain request reservation is not erased by this fix.

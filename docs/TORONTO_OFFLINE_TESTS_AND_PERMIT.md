# Existing-stage Linux tests, then an unapproved permit

**Linux gate complete:** owner confirms 13 original passes plus the successful targeted memory recheck (all three groups, exit 0, 1.641 seconds; evidence memory-recheck.6cjuYf). All 14 suites have passing Linux evidence. Do not rerun them or require a fabricated single-run success marker. Use [the final October 26 handoff](TORONTO_ONE_SHOT_HANDOFF.md) for the actual permit, approval-bound paid template and offline transcript/accounting checks. Earlier test commands below remain reproducible history.

Owner reports the isolated six-step staging migration/seed/registration succeeded with zero paid requests. Preserve the existing experiment. Stage Mayor is `1ba5d5eb83a85aaa5d6eba410f353ff6`; Stage Gardener is `231d5dbc30fda0c8b490359b3ad699ed`. Registered configuration is `295ceb3de136d726e8359d16d5a70bb8f887b72077238ada0c02cc67f7b4b245`, and runtime manifest is `b3c6bda3c53f5e638b7a9c4a9278a4c91a95660ecb64f5ff57d839ed40b68f47`. These are owner-reported state, not a new database inspection by Codex.

Non-root read-only SSH verified the installed fa959864b9c7d5015b72adae5e42fa98545623b0 checkout and that runtime manifest, Node 18.19.1 (including Headers.getSetCookie), PHP 8.3.6 and `/etc/php/8.3/cli/conf.d`. No private DB/key was opened. The installed checkout remains the target below; no code reinstall is necessary. Later fcda661 changes only the operator reconciling CLI, its tests and docs; they do not change this Director runtime manifest. Linux results below cover the **installed checkout**, not an assertion that the newer CLI was run there.

The existing SSH login is mark; the owner retained root-only staging launches. This is an executor handoff, **not a request for new approval**. Tests and creation of an unapproved permit are already authorized. Codex has not run these privileged units or created an actual permit on Toronto.

## 1. Existing 14 suites, disposable fixtures only

Run this block in the owner's existing root shell. It uses the existing staging identity and an ephemeral unit, with networking restricted to its private loopback for fixture HTTP servers. No LoadCredential is supplied. Production, the prepared experiment, raw key sources and both audit receipts are inaccessible to the tests. All DB writes are disposable fixtures in a separate private scratch directory. No package/account/global configuration change, scheduler or new test framework is introduced.

The scratch-only ini file enables argv registration for PHP descendants as well as the main test process; normal CLI module configuration is preserved. It never edits php.ini/FPM. Keep the scratch log if a suite fails; the script stops and does not prepare a permit.

```sh
set -eu
umask 077
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
test "$(git -C "$CODE" rev-parse HEAD)" = fa959864b9c7d5015b72adae5e42fa98545623b0
test -d "$EXPERIMENT"
test ! -L "$EXPERIMENT"
RUN=$(mktemp -d /var/lib/sanctuaryfor-ai-staging/linux-offline.XXXXXX)
chown sanctuarystaging:sanctuarystaging "$RUN"
install -d -m 0700 -o sanctuarystaging -g sanctuarystaging "$RUN/tmp" "$RUN/ini" "$RUN/sessions"
printf 'register_argc_argv=1\nsession.save_path="%s"\n' "$RUN/sessions" > "$RUN/ini/99-staging-argv.ini"
chmod 0600 "$RUN/ini/99-staging-argv.ini"
chown sanctuarystaging:sanctuarystaging "$RUN/ini/99-staging-argv.ini"
systemd-run --unit=sanctuary-staging-offline-tests \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=PrivateNetwork=yes --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict --property=RuntimeMaxSec=900s \
  --property="ReadWritePaths=$RUN" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai /var/lib/sanctuaryfor-ai-staging/preflight $EXPERIMENT" \
  --setenv="CODE=$CODE" --setenv="RUN=$RUN" --setenv="TMPDIR=$RUN/tmp" \
  --setenv="PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:$RUN/ini" --setenv=PHP_BINARY=/usr/bin/php8.3 \
  --setenv=OPENAI_API_KEY= --setenv=CREDENTIALS_DIRECTORY= \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging --setenv=LITTLE_SPARKS_AI_ENABLED=0 \
  --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /bin/sh -eu -c '
    umask 077
    cd "$CODE"
    count=0
    for suite in tests/*.mjs; do
      printf "RUN %s\n" "$suite"
      if /usr/bin/node "$suite" >> "$RUN/tests.log" 2>&1; then
        count=$((count+1))
        printf "PASS %s\n" "$suite"
      else
        tail -n 60 "$RUN/tests.log"
        exit 1
      fi
    done
    test "$count" -eq 14
    printf "{\"suites_passed\":14,\"openai_requests\":0,\"prepared_database_access\":false,\"fixture_databases_only\":true}\n" > "$RUN/offline-success.json"
    cat "$RUN/offline-success.json"
  '
test -f "$RUN/offline-success.json"
printf 'Offline test evidence: %s\n' "$RUN"
```

The unit's loopback fixture requests are real local HTTP tests, not OpenAI requests. No actual Linux pass is claimed until the owner supplies the exit/status output. Do not silently repeat a failed suite or erase its log.

Owner's first Linux run passed 13 suites and failed village-memory.mjs on a missing memory_trail. The test server inherited `/var/lib/php/sessions`, which ProtectSystem=strict makes read-only outside ReadWritePaths. Session-backed profile APIs return 503 while the sessionless resident page still returns 200. The test now provisions its own private session directory and checks HTTP status/response shape before reading memory_trail. The scratch-only session setting above also supports the already-installed older test without updating its checkout. See [the single-suite recheck](TORONTO_MEMORY_TEST_RECHECK.md); do not rerun migrations, touch the prepared village or create a permit while this check is unresolved.

## 2. Actual unapproved permit from the same prepared stage

Only after the above unit passes, run this block with CODE, EXPERIMENT and RUN retained from step 1. It opens the **authorized synthetic database** through the existing --plan implementation and reads identity/configuration/history checks; it does not migrate/reseed, reserve API slots, consume a permit, generate a conversation or change world/accounting rows. It writes one new private JSON permit only. Any existing consumed permit/history or identity mismatch will refuse planning. Never overwrite an existing permit file or change approved to true here.

**Existing timing limit:** permits expire within one hour. Both October 25 UTC request slots are already reserved, and paid execution must be on a later UTC day. At preparation time (17:31 UTC October 25), a permit produced now would expire before the first permissible test window. You can create it now to review the real identity/configuration bindings, or run this block close to the later-day window to obtain the final permit. An expired **unconsumed, unapproved** review permit may be regenerated offline in a new file; this does not authorize paid execution, rearm a consumed permit or change the one-generation scope. Do not extend TTL or relax the day guard. No timer or scheduled execution is created.

```sh
set -eu
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
systemd-run --unit=sanctuary-staging-plan-review \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=PrivateNetwork=yes --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict --property=RuntimeMaxSec=30s \
  --property="ReadWritePaths=$EXPERIMENT" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai /var/lib/sanctuaryfor-ai-staging/preflight" \
  --setenv="CODE=$CODE" --setenv="EXPERIMENT=$EXPERIMENT" \
  --setenv=OPENAI_API_KEY= --setenv=CREDENTIALS_DIRECTORY= \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging --setenv=LITTLE_SPARKS_DIRECTOR_RUNTIME=agents \
  --setenv=LITTLE_SPARKS_DIRECTOR_AGENT_ID=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8 \
  --setenv=LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256=295ceb3de136d726e8359d16d5a70bb8f887b72077238ada0c02cc67f7b4b245 \
  --setenv=LITTLE_SPARKS_AI_MODEL=gpt-6-luna \
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /bin/sh -eu -c '
    umask 077
    AT=$(date -u -d "1 minute ago" +%Y-%m-%dT%H:%M:00Z)
    PERMIT="$EXPERIMENT/pending-permit-$(date -u +%Y%m%dT%H%M%SZ).json"
    set -C
    /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/stage-director-once.php" \
      --plan --database="$EXPERIMENT/village.sqlite" --at="$AT" > "$PERMIT"
    set +C
    printf "Unapproved permit: %s\n" "$PERMIT"
    cat "$PERMIT"
  '
```

Expected output: approved=false, production_isolation_attested=false, empty approval_reference, both synthetic IDs above, agent/configuration/manifest matching the owner-reported values, http_slots=8, allocation_microusd=100000, and actual opportunity/expiry/permit hashes. Retain a failed/empty output for diagnosis rather than overwrite it. Send the test summary/exit status and the unapproved permit JSON for review; it contains hashes, not the raw installation secret or private resident tokens.

The final paid command and research-risk approval remain separate, as already agreed. No production configuration, database, Director activation or timer change is included in either block.

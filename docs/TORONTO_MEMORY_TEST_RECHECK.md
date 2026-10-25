# Focused Linux memory-suite correction

The owner reported 13 passing suites, then village-memory.mjs failed at profile.memory_trail.some. Direct fixture memory checks had already passed. The profile API starts a PHP session; resident.php does not. Non-root read-only Toronto inspection confirms CLI session.save_handler=files and session.save_path=/var/lib/php/sessions. That directory is outside the existing test unit's ReadWritePaths and becomes read-only under ProtectSystem=strict.

Offline reproduction with deliberately unavailable ambient session storage confirms HTTP 503 and the safe error object, rather than a changed profile contract. The old test ignored status and crashed when reading the missing array. The correction is confined to tests/village-memory.mjs: create a 0700 fixture session directory, pass it explicitly to the fixture PHP server, and assert HTTP 200 plus an array before reading memory_trail. Bounded fixture-server stderr now explains failures. The corrected test passes under the same bad ambient setting. No application/schema/world/accounting code changes.

## Verify only the failed suite, with the existing installed checkout

Run in the owner's existing root shell. The temporary ini directs the **older installed test** to job-private session storage, so no new checkout, PHP configuration change or production session permission change is required for this recheck. The revised test itself is independent of that ambient setting once installed. This is a focused verification, not a claim that the 13 earlier suites were rerun.

The prepared synthetic database and both receipts are inaccessible to this unit. It supplies no credential and cannot reach external networking; only disposable fixture SQLite and localhost HTTP are used. All scheduled/paid activity flags remain off. Retain the original failed test log; the new scratch directory does not overwrite it.

```sh
set -eu
umask 077
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
test "$(git -C "$CODE" rev-parse HEAD)" = fa959864b9c7d5015b72adae5e42fa98545623b0
CHECK=$(mktemp -d /var/lib/sanctuaryfor-ai-staging/memory-recheck.XXXXXX)
chown sanctuarystaging:sanctuarystaging "$CHECK"
install -d -m 0700 -o sanctuarystaging -g sanctuarystaging "$CHECK/tmp" "$CHECK/ini" "$CHECK/sessions"
printf 'register_argc_argv=1\nsession.save_path="%s"\n' "$CHECK/sessions" > "$CHECK/ini/99-memory-test.ini"
chmod 0600 "$CHECK/ini/99-memory-test.ini"
chown sanctuarystaging:sanctuarystaging "$CHECK/ini/99-memory-test.ini"
systemd-run --unit=sanctuary-staging-memory-recheck \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=PrivateNetwork=yes --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict --property=RuntimeMaxSec=120s \
  --property="ReadWritePaths=$CHECK" \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai /var/lib/sanctuaryfor-ai-staging/preflight /var/lib/sanctuaryfor-ai-staging/director-synthetic-01" \
  --setenv="TMPDIR=$CHECK/tmp" \
  --setenv="PHP_INI_SCAN_DIR=/etc/php/8.3/cli/conf.d:$CHECK/ini" --setenv=PHP_BINARY=/usr/bin/php8.3 \
  --setenv=OPENAI_API_KEY= --setenv=CREDENTIALS_DIRECTORY= \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging --setenv=LITTLE_SPARKS_AI_ENABLED=0 \
  --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/node "$CODE/tests/village-memory.mjs"
printf 'memory_suite_exit=0\nRecheck scratch: %s\n' "$CHECK"
```

Expect the public V5 memory-trail/yard/read-only/concurrency PASS and unit exit 0. If it fails, preserve its safe output and stop; do not rerun the 13 passing suites, change the prepared DB, change credentials or relax the unit's protection. This recheck does not create an overall 14-pass marker or an unapproved/approved permit. After its result is reviewed, retain evidence of the earlier 13 passes plus this focused correction for the existing permit-preparation handoff; never manufacture an all-suite result from an incomplete command.

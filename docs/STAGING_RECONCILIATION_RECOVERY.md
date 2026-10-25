# Focused reconciliation diagnosis and continuation

No new API request, credential access, paid approval or infrastructure is needed. Both private receipts and their October 25 reservations remain unchanged. The existing writer/reconciler round trip passes offline, including the original writer's absence of the later optional failure_code field. That does not establish the exact contents/permissions of the Toronto files. No schema mismatch is confirmed yet; do not weaken hash, identity, UTC or authorization checks on a guess.

The reconciler now reports static failure_code, receipt name, expected field name and zero-based row number. It never prints receipt values, exception messages, raw JSON or credentials. Codes distinguish hash_format_invalid, hash_mismatch, private_path_or_permissions, file_safety, file_read, line_count, json_invalid, row_shape, intent_invalid, shared_field_mismatch, outcome_invalid, authorization_field_invalid, duplicate_checks and chronology_invalid. internal_error is an unexpected failure. A full 64-character digest is still required; the shortened original value repeated in chat is not a replacement for the full owner-confirmed pin below.

## One read-only owner check

Use the existing root shell and an existing root-owned checkout of the updated reviewed PR #9 SHA; fill SHA with that full commit. This command executes only the receipt reconciler. It does not call check-director-access.php, create a request receipt, load LoadCredential, open SQLite or change a service. Output identifies the exact rejected check if it fails. No execution approval for generation is implied.

```sh
SHA=REVIEWED_UPDATED_PR9_FULL_SHA
CODE=/opt/sanctuaryfor-ai-staging/$SHA
test "$(git -C "$CODE" rev-parse HEAD)" = "$SHA"
env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= \
  LITTLE_SPARKS_AI_ENABLED=0 LITTLE_SPARKS_DIRECTOR_ENABLED=0 LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  /usr/bin/php8.3 "$CODE/scripts/reconcile-director-access.php" \
  --directory=/var/lib/sanctuaryfor-ai-staging/preflight \
  --original-sha256=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513 \
  --authorized-sha256=94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0
```

If this fails, send only its safe JSON. Do not recreate receipts, change permissions, change hash pins, bypass the failed field or initialize the database. A code correction can then target the exact mismatch. Hash_format_invalid points to command input, not audit corruption; private_path_or_permissions points to metadata/access, not JSON. Matching hashes alone cannot identify either kind of failure. Successful reconciliation reports total_requests_reserved=2 and reserved_by_utc_day={2026-10-25:2}.

## Continue the same directory only after reconciliation passes

The original preparation creates the directory, copies residents.json and redirects reconciliation output **before** starting the initialization unit. With set -e, a reconciliation failure normally leaves the directory, residents.json and an empty/failed access-reconciliation.json, without starting migrations. Verify that state rather than assume it. Preserve the failed output as evidence.

The following block reads directory/file metadata and compares the already-reviewed seed input; it never opens SQLite. It refuses any existing DB, WAL/journal, registration, permit, symlink or unexpected artifact. If initialization has already progressed, stop and inspect that existing stage; do not migrate/reseed/reset automatically. It does not create a new experiment identity.

```sh
set -eu
umask 077
# CODE must remain the verified updated checkout from the read-only check above.
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
test -d "$EXPERIMENT"
test ! -L "$EXPERIMENT"
test "$(stat -c '%u:%g:%a' "$EXPERIMENT")" = '999:988:700'
for item in "$EXPERIMENT"/* "$EXPERIMENT"/.[!.]* "$EXPERIMENT"/..?*; do
  if test ! -e "$item" && test ! -L "$item"; then continue; fi
  test ! -L "$item"
  case "${item##*/}" in
    residents.json|access-reconciliation.json) test -f "$item" ;;
    *) echo 'Initialization already progressed or an unexpected artifact exists; stop without resetting it.'; exit 1 ;;
  esac
done
test -f "$EXPERIMENT/residents.json"
# The proposed example has not changed; never overwrite an owner-edited input.
cmp -s "$CODE/docs/staging/synthetic-residents.example.json" "$EXPERIMENT/residents.json"
SUMMARY=$(env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= \
  LITTLE_SPARKS_AI_ENABLED=0 LITTLE_SPARKS_DIRECTOR_ENABLED=0 LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  /usr/bin/php8.3 "$CODE/scripts/reconcile-director-access.php" \
  --directory=/var/lib/sanctuaryfor-ai-staging/preflight \
  --original-sha256=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513 \
  --authorized-sha256=94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0)
set -C
printf '%s\n' "$SUMMARY" > "$EXPERIMENT/access-reconciliation-checked.json"
set +C
printf '%s\n' "$SUMMARY"
echo 'Reconciliation passed; existing residents and failed summary preserved. Fresh database initialization may continue.'
```

After this succeeds, run **only the existing systemd-run preparation block** in STAGING_ENCOUNTER_APPROVAL.md with this CODE and EXPERIMENT, starting at systemd-run (not the install/copy commands). It retains PrivateNetwork=yes, no LoadCredential, all paid/scheduler flags off, and writes only the same private synthetic experiment. It creates the fresh DB once, seeds the two existing reviewed descriptions and registers the existing agent offline. Stop on any error and retain the directory. No new paid approval layer is introduced; the previously agreed single-generation approval remains separate and unchanged.

The code fingerprint changes with this diagnostics fix. Generate the actual unapproved permit only from the finally reviewed runtime after no-cost preparation; never edit or rearm an already consumed permit. Production and both request reservations remain untouched.

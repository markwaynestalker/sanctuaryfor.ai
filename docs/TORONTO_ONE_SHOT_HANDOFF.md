# October 26 UTC: one synthetic Director encounter

All 14 suites have passing Linux evidence by owner report: 13 original passes plus all three memory groups passing in the targeted recheck (1.641 seconds, exit 0), evidence `/var/lib/sanctuaryfor-ai-staging/memory-recheck.6cjuYf`. This is combined evidence, not a claim that the original unit passed all 14 in one run. No new test run or old offline-success.json marker is required.

Use the existing stage and installed runtime. No migration, reseeding, installation, production configuration or timer change is included. The owner keeps privileged execution. The owner acknowledges the financial exposure and requests actual-permit review before explicit paid approval. **That execution approval remains pending.** Codex has not opened this prepared DB or made a paid call.

| Binding | Reviewed value |
| --- | --- |
| Code | `/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0` |
| Runtime manifest | `b3c6bda3c53f5e638b7a9c4a9278a4c91a95660ecb64f5ff57d839ed40b68f47` |
| Synthetic DB | `/var/lib/sanctuaryfor-ai-staging/director-synthetic-01/village.sqlite` |
| Stage Mayor | `1ba5d5eb83a85aaa5d6eba410f353ff6` |
| Stage Gardener | `231d5dbc30fda0c8b490359b3ad699ed` |
| Director | `agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8` |
| Configuration | `295ceb3de136d726e8359d16d5a70bb8f887b72077238ada0c02cc67f7b4b245` |

Both October 25 metadata reservations and receipts remain unchanged. Execution is restricted below to **October 26 UTC**, from **00:05 UTC (8:05 p.m. New York, October 25)**. No automatic waiting/job is created. Generate the permit near execution: it expires after one hour. The proposed exception reserves eight HTTP attempts for this staging process, including moderation/recovery, and 100,000 micro-USD ($0.10). The production/default two-request limit and $1 application maximum stay unchanged. Total access-plus-experiment slots across dates are 2+8=10. Confirm no other staging requests/reservations consume October 26's eight-slot envelope when approving execution.

## A. Create and show the actual unapproved permit

Run from the existing owner root shell near that window. This unit has no external network or credential, with all paid/scheduler gates off. It reads the authorized synthetic DB and creates one private unapproved JSON file without overwriting another. It makes no HTTP request, reserves no API slots, consumes no permit and changes no world/accounting rows.

```sh
(
set -eu
umask 077
if test "$(date -u +%F)" != 2026-10-26; then echo 'Run this during October 26 UTC; the agreed day guard is unchanged.'; exit 1; fi
test "$(date -u +%H%M)" -ge 5
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
test "$(git -C "$CODE" rev-parse HEAD)" = fa959864b9c7d5015b72adae5e42fa98545623b0
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
  --setenv=LITTLE_SPARKS_AI_ENABLED=0 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=0 --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=0 \
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
    sha256sum "$PERMIT"
  '
)
```

Send the **actual JSON and SHA-256**. Expected: approved=false, production_isolation_attested=false, empty approval_reference, the bindings above, eight slots, $0.10 allocation and live expiry. These are hashes, not raw installation secrets/private resident tokens. We will compare the actual file, opportunity and expiry before explicit approval. No actual permit is fabricated here.

## B. Bounded paid template — blocked until actual-permit approval

Pending values are the actual file path/hash and owner's explicit approval reference. Approval must cover **one generation submission**, at most eight HTTP attempts, nominal $0.10 allocation/one-cent provider setting, and **no verified numerical maximum final provider charge**. Scope: these two synthetic IDs, existing Director, gpt-6-luna, requested default tier, no sandbox/tools/subagents/vaults or world action. No repeat, production, shared-agent edit or scheduler permission follows.

This block fails until its placeholders and approval gate are filled after that approval. It preserves the pending file, exclusively creates one approved copy, and changes only the existing three approval fields. It never regenerates the opportunity, identities, expiry or hashes. An existing approved copy is a stop condition, not permission to overwrite/rearm. The singleton consumed permit and idempotent ledger prevent duplicate generation.

```sh
(
set -eu
umask 077
OWNER_APPROVAL=NOT_APPROVED
APPROVAL_REFERENCE=NOT_APPROVED
EXPECTED_PENDING_SHA256=PASTE_ACTUAL_REVIEWED_64_HEX_SHA256
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
EXPERIMENT=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01
PENDING="$EXPERIMENT/PASTE_ACTUAL_REVIEWED_PENDING_FILENAME.json"
APPROVED="$EXPERIMENT/approved-director-once.json"
test "$OWNER_APPROVAL" = APPROVED_ONE_SYNTHETIC_GENERATION_RESEARCH_EXCEPTION
test "$APPROVAL_REFERENCE" != NOT_APPROVED
test "${#EXPECTED_PENDING_SHA256}" -eq 64
case "$EXPECTED_PENDING_SHA256" in *[!0-9a-f]*) exit 1 ;; esac
case "$PENDING" in "$EXPERIMENT"/pending-permit-*.json) ;; *) exit 1 ;; esac
test "$(dirname "$PENDING")" = "$EXPERIMENT"
test ! -L "$PENDING"
test "$(date -u +%F)" = 2026-10-26
test "$(date -u +%H%M)" -ge 5
test "$(git -C "$CODE" rev-parse HEAD)" = fa959864b9c7d5015b72adae5e42fa98545623b0
env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= /usr/bin/php8.3 -d register_argc_argv=1 /dev/stdin \
  "$PENDING" "$APPROVED" "$EXPECTED_PENDING_SHA256" "$APPROVAL_REFERENCE" <<'PHP'
<?php
umask(0077);
try {
    $raw=file_get_contents($argv[1],false,null,0,8193);
    if(!is_string($raw)||strlen($raw)>8192||!hash_equals($argv[3],hash('sha256',$raw)))throw new RuntimeException();
    $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$ids=$p['resident_ids']??[];sort($ids);
    if($ids!==['1ba5d5eb83a85aaa5d6eba410f353ff6','231d5dbc30fda0c8b490359b3ad699ed']
        ||($p['approved']??null)!==false||($p['production_isolation_attested']??null)!==false||($p['approval_reference']??null)!==''
        ||($p['manifest_sha256']??null)!=='b3c6bda3c53f5e638b7a9c4a9278a4c91a95660ecb64f5ff57d839ed40b68f47'
        ||($p['configuration_sha256']??null)!=='295ceb3de136d726e8359d16d5a70bb8f887b72077238ada0c02cc67f7b4b245'
        ||($p['http_slots']??null)!==8||($p['allocation_microusd']??null)!==100000
        ||!is_int($p['expires_at']??null)||$p['expires_at']<time()+90
        ||!preg_match('/\A[a-zA-Z0-9 .:_-]{1,160}\z/',$argv[4]))throw new RuntimeException();
    $p['approved']=true;$p['production_isolation_attested']=true;$p['approval_reference']=$argv[4];
    $text=json_encode($p,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";$h=fopen($argv[2],'x');
    if($h===false)throw new RuntimeException();
    if(fwrite($h,$text)!==strlen($text)||!fflush($h)||!fsync($h))throw new RuntimeException();
    fclose($h);
}catch(Throwable){fwrite(STDERR,"Approved-copy creation refused; preserve files and stop.\n");exit(1);}
PHP
chown sanctuarystaging:sanctuarystaging "$APPROVED"
chmod 0600 "$APPROVED"
AT=$(env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= /usr/bin/php8.3 -d register_argc_argv=1 -r \
  '$p=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);echo $p["opportunity"];' "$APPROVED")
sha256sum "$APPROVED"
RESULT=0
systemd-run --unit=sanctuary-staging-director-once \
  --wait --collect --pipe --service-type=oneshot --expand-environment=no \
  --property=User=sanctuarystaging --property=Group=sanctuarystaging \
  --property=NoNewPrivileges=yes --property=PrivateTmp=yes \
  --property=ProtectHome=yes --property=ProtectSystem=strict --property=RuntimeMaxSec=75s \
  --property="ReadWritePaths=$EXPERIMENT" \
  --property=BindReadOnlyPaths=/var/lib/sanctuaryfor-ai-staging/preflight \
  --property="InaccessiblePaths=/var/lib/sanctuaryfor-ai /var/www/sanctuaryfor.ai /etc/php/8.3/fpm /etc/sanctuaryfor-ai" \
  --property=LoadCredential=openai_api_key:/etc/sanctuaryfor-ai/staging-openai-key \
  --setenv=OPENAI_API_KEY= \
  --setenv=LITTLE_SPARKS_ENVIRONMENT=staging --setenv=LITTLE_SPARKS_DIRECTOR_RUNTIME=agents \
  --setenv=LITTLE_SPARKS_DIRECTOR_AGENT_ID=agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8 \
  --setenv=LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256=295ceb3de136d726e8359d16d5a70bb8f887b72077238ada0c02cc67f7b4b245 \
  --setenv=LITTLE_SPARKS_AI_MODEL=gpt-6-luna --setenv=LITTLE_SPARKS_AI_PRICING_CONFIRMED=2026-10-24-standard-v1 \
  --setenv=LITTLE_SPARKS_AI_DAILY_REQUESTS=8 --setenv=LITTLE_SPARKS_AI_MONTHLY_MICROUSD=100000 \
  --setenv=LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=512 \
  --setenv=LITTLE_SPARKS_AI_ENABLED=1 --setenv=LITTLE_SPARKS_DIRECTOR_ENABLED=1 --setenv=LITTLE_SPARKS_SIMULATION_ENABLED=1 \
  --setenv=LITTLE_SPARKS_SCHEDULER_ENABLED=0 --setenv=LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/stage-director-once.php" \
    --allow-paid --database="$EXPERIMENT/village.sqlite" --at="$AT" --permit="$APPROVED" \
    --original-access-sha256=36bc09d1214e9cf683b8fdb8fbb8f540b33d4263891b911d7fcf1cf90264c513 \
    --authorized-access-sha256=94b9fc89751bdcb56a15baf66daeee9864f3e7b11c34db423ff38823532b4af0 || RESULT=$?
printf 'one_shot_unit_exit=%s\n' "$RESULT"
echo 'Stop paid execution here. Inspect saved state with section C even if this failed; never retry generation.'
)
```

HTTPS is enabled only in the approved paid unit: deliberately no PrivateNetwork=yes there. Audit receipts are read-only-bound. LoadCredential supplies the key; it is never in arguments/history, JSON, SQLite or public output. Paid flags apply only to this transient process; both scheduler flags remain zero. The 512-token setting is an acceptance/observation limit, **not a proven total managed inference cap**. Provider charges can exceed the allocation after timeout, wrong-tier failure, moderation rejection or cancellation. A lost session ID can prevent targeted cancellation. Keep all uncertain/failed billing held. No ninth HTTP request or replacement generation is authorized.

## C. Verify saved dialogue and accounting offline

Run after the attempt, regardless of unit success. These commands clear paid flags and have no credential delivery. They read only the synthetic DB; the transcript connection opens SQLite read-only. They make no provider request. Do not browse production to inspect synthetic results.

```sh
(
set -eu
CODE=/opt/sanctuaryfor-ai-staging/fa959864b9c7d5015b72adae5e42fa98545623b0
DB=/var/lib/sanctuaryfor-ai-staging/director-synthetic-01/village.sqlite
env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= LITTLE_SPARKS_AI_ENABLED=0 LITTLE_SPARKS_DIRECTOR_ENABLED=0 LITTLE_SPARKS_SIMULATION_ENABLED=0 \
  LITTLE_SPARKS_SCHEDULER_ENABLED=0 LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=0 \
  LITTLE_SPARKS_AI_DAILY_REQUESTS=8 LITTLE_SPARKS_AI_MONTHLY_MICROUSD=100000 \
  /usr/bin/php8.3 -d register_argc_argv=1 "$CODE/scripts/ai-status.php" --database="$DB"
env OPENAI_API_KEY= CREDENTIALS_DIRECTORY= /usr/bin/php8.3 -d register_argc_argv=1 /dev/stdin "$DB" <<'PHP'
<?php
try {
    if($argv[1]!=='/var/lib/sanctuaryfor-ai-staging/director-synthetic-01/village.sqlite')throw new RuntimeException();
    $db=new PDO('sqlite:'.$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    $conversations=$db->query("SELECT c.id,c.run_id,c.created_at,r.provider,d.topic FROM conversations c JOIN director_runs d ON d.conversation_id=c.id AND d.run_id=c.run_id JOIN simulation_runs r ON r.id=c.run_id JOIN ai_conversations a ON a.conversation_id=c.id AND a.attempt_id=c.run_id WHERE r.status='completed' AND r.provider='openai' AND c.visibility='public' ORDER BY c.created_at,c.id")->fetchAll();
    $saved=count($conversations)===1;$rows=[];
    if($saved){
        $q=$db->prepare('SELECT m.sequence,m.resident_id,r.name,m.body FROM messages m JOIN residents r ON r.id=m.resident_id WHERE m.conversation_id=? ORDER BY m.sequence');$q->execute([$conversations[0]['id']]);$rows=$q->fetchAll();
        $ids=array_column($rows,'resident_id');sort($ids);
        if(count($rows)!==2||$ids!==['1ba5d5eb83a85aaa5d6eba410f353ff6','231d5dbc30fda0c8b490359b3ad699ed'])throw new RuntimeException();
    }
    $db->commit();
    echo json_encode(['conversation_saved'=>$saved,'canonical_conversation_count'=>count($conversations),'conversations'=>$conversations,'messages'=>$rows,'provider_billing_final'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
}catch(Throwable){fwrite(STDERR,"Read-only transcript verification failed; retain evidence and do not retry generation.\n");exit(1);}
PHP
)
```

Success requires one completed OpenAI Director run, one canonical public conversation, exactly two saved messages tied to these IDs, and a consumed single permit. The displayed words are the accepted/moderated saved output; silence/failure gets no template replacement. Check started_requests<=8, reserved request_slots=8, private HTTP audit/latest terminal outcome and the retained 100,000-micro-USD reservation. A completed conversation with uncertain billing is expected until independent final evidence. A run's placeholder zero cost or best-effort usage is **not proof of zero/final cost**.

On no saved conversation, report safe unit/attempt error codes, latest managed outcome and request/usage counts. Preserve every DB/receipt/permit; do not retry/reset or copy synthetic history into production. Any API recovery beyond this envelope needs a new decision. Final billing reconciliation uses independently verified evidence and the existing review CLI, never refunds headroom or treats cancellation as a final bill. PR #9 stays draft; no production migration/activation, deployment, merge or timer follows automatically.

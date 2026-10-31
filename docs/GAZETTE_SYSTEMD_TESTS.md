# Gazette cross-service integration evidence (#83/#84)

This suite closes the gap between the earlier real-UID/fork tests and actual systemd execution. It is restricted to the disposable GitHub Ubuntu CI VM, requires root plus explicit CI guards, and never runs on Toronto. It creates no account, enables no timer, makes no paid request and reads no API credential. The only credential source is a newly created **nonsecret stand-in**. All SQLite/schema setup uses disposable synthetic fixtures.

`tests/gazette_systemd_integration.py` reads the reviewed socket/helper/editor templates, assigns unique CI-only unit names and existing distinct VM identities, and runtime-links temporary copies under `/run/systemd/system`. Nothing is installed under `/etc/systemd/system` or enabled. Finally it stops only its own uniquely named units, verifies/removes only its own runtime links, reloads the CI manager and removes the private fixture tree. Normal offline suites remain unprivileged in a loopback-only namespace.

Each service uses an additional **test-only RootDirectory** containing the actual reviewed code, release marker/current pointer, private synthetic SQLite and state directories. Read-only OS runtime binds supply PHP/Python libraries. A protected shared fixture `/run` supplies the actual pathname socket and permits systemd's real per-unit readonly credential mount. These namespace additions keep production-shaped paths entirely inside a disposable tree; they are not proposed changes to the production units. Source modes, protocol rules, credential validator, network restrictions and socket activation limits are not weakened to make tests pass. The suite respects the real activation-rate window between its rapid independent cases.

The worker receives a real `LoadCredential=openai_api_key:...` mount and calls the unchanged credential metadata validator. The readiness and disabled cases invoke the actual installed-launcher source and brokered CLI. Enabled cases use the actual PHP Unix-socket client and job orchestration with a test-only `EditorialTransport`; the provider returns synthetic proposals/moderation and cannot make HTTP requests. PrivateNetwork is additionally enabled on all CI services. Credential **contents are never read**; the positive checks inspect kernel metadata and readonly mounts only. Failure diagnostics contain static stages, permission metadata and synthetic unit receipts, not source packs or capabilities.

## Required cases

| Case | Evidence required |
| --- | --- |
| Worker disabled | Actual launcher returns disabled; no reservations, dispatches, receipts or provider mock calls |
| Broker disabled | An enabled client cannot reserve or reach the mock provider |
| Readiness | Actual brokered CLI, readonly schema check and real systemd credential metadata pass without paid dispatch |
| Separate UIDs | Actual accepted socket reports the editor UID; editor cannot read/open SQLite, including a real PDO readonly-open attempt |
| Credential isolation | During an active worker invocation, root verifies the stand-in exists in that worker's credential mount; separate helper/other UID services cannot read it via the worker's process-root path or source file |
| Keyless helper | Actual instance's preflight confirms no credential directory/API-key ENV or readable key source; helper has no LoadCredential and passes only its allowlisted policy ENV to PHP |
| Successful draft | Real Accept=yes instances execute all three PHP stages on WAL, preserving one attempt, two dispatches/receipts and the full 30000 micro-USD synthetic reservation; no owner review/publication is fabricated |
| Client/helper release pins | Wrong client SHA or helper release refuses before any attempt or mock request; each assertion uses its own systemd invocation journal receipt |
| SQLite contention | A separate DB-owner service holds BEGIN IMMEDIATE while completion is submitted; bounded failure retains the attempt, one dispatch and full hold; manual start does not regenerate |
| Worker restart | Explicit restart during a dispatched mock call creates a new service invocation but no second generation/moderation or refunded hold |
| Socket restart | Restart between jobs preserves saved state; stopping the required socket during a dispatched worker fences that worker, and subsequent starts cannot replay it |
| Default restart policy | NRestarts remains zero; the suite never changes Restart=no or grants an automatic retry |
| Historical safeguards | All existing offline source/ledger/publication/revision/withdrawal/history tests also pass |

## Operational implication

`Requires=...db.socket` makes a socket stop propagate to the worker. Planned maintenance must disable new admissions and drain the worker **before** stopping/restarting the socket. Otherwise a dispatched call can become uncertain and retains its cost/slots; restarting is not recovery permission. The suite tests that behavior rather than inventing resumable paid jobs.

The tests prove the reviewed code/template boundary on Ubuntu CI with a real service manager. They do not verify Toronto's actual account mappings, deployed manifest, filesystem configuration, project/model availability, billing, real API acceptance or article quality. Those remain separately approved operational/staging gates. Production activation stays blocked and both PRs stay unmerged until final integration review.

References: [systemd socket activation](https://www.freedesktop.org/software/systemd/man/255/systemd.socket.html), [execution/credential namespaces](https://www.freedesktop.org/software/systemd/man/255/systemd.exec.html), [SQLite WAL](https://www.sqlite.org/wal.html). Do not invoke this CI harness on production or substitute a real credential source.

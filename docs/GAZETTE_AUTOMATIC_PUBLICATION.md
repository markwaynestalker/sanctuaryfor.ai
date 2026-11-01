# Automatic daily Gazette publication

The latest owner direction replaces daily human approval with a standing automatic-publication policy. Once installed and enabled, the existing Gazette timer generates yesterday's Eastern-date edition and the keyless helper publishes it immediately after successful validation and moderation. No daily login, review or publication command is needed. Generation remains budgeted; publication makes no API calls.

## One-time installation and activation

After normal review/merge and protected deployment, keep the Gazette timer disabled and editor drained during installation. The explicit transaction adds one immutable `gazette_editor_auto_publications` table and two immutability triggers. It preserves historical reviews, drafts, receipts, reservations, world tables, budgets, credentials and SQLite 0700/0600. Ordinary deployment does not migrate or activate anything.

```bash
sudo /usr/bin/python3 -I /var/lib/sanctuaryfor-ai-deploy/current/ops/gazette/gazette.py install --release "$(basename "$(readlink -f /var/lib/sanctuaryfor-ai-deploy/current)")" --approve-install
sudo gazette configure --approve-enable --approve-auto-publish
sudo gazette check
sudo systemctl enable --now sanctuary-gazette-editor.timer
```

This is one-time standing approval, not a daily permit. The existing timer remains 05:15 America/New_York, with no boot catch-up or automatic paid retry. No new timer, service or credential is installed. Limits stay unchanged; to approve the previously proposed $1/day, $20/month, 20-request profile separately, use `sudo gazette configure --approve-limits`.

To generate and automatically publish the still-unpublished October 31 edition after activation:

```bash
sudo gazette refresh --date 2026-10-31 --approve-paid
```

Earlier rejected attempts and holds stay intact. Today's partial previews stay private. The automatic policy publishes initial completed-date editions only; it preserves finalized publications. Refreshing an already-published date creates a private draft. Explicit reviewed corrections continue to use the manual publication path with `--replace-current`.

## Security and provenance

The fixed root-owned helper configuration holds a versioned policy and activation timestamp. The API worker cannot supply that policy, choose SQL or publication paths, invoke a new publication socket operation, or access SQLite/publication storage. Only the existing keyless helper publishes, after recording completed generation, mandatory moderation and the finished outcome. Its service sandbox now permits the fixed private Gazette publication directory under the database-owned private directory. Worker UID, credential mount, database denial and separate Director remain unchanged. The helper has no API key or network transport.

Publication rechecks exact draft/source hashes, complete public source authorization, known-complete request receipts, passed moderation, finished outcome, completed edition date, non-preview status, activation timestamp and any owner rejection. An immutable audit records the exact automatic policy/release under the SQLite write lock. It never fabricates a `local_owner` review. Existing atomic no-overwrite manifests remain authoritative. Public readers validate policy and source bindings and label these editions as automatically published after source checks and moderation. Historical human-reviewed editions retain their original attribution.

Citation and quote checks establish that the saved sources exist and are public; they do not prove that every generated sentence follows factually from those sources. Moderation is not semantic fact-checking. This workflow removes the human factual-review stage at the owner's request and does not claim that stage occurred.

## Failure, recovery and disabling

Rejected, flagged, incomplete, uncertain and in-flight work never publishes and stays accounted for. A publication lock/filesystem failure after successful generation returns `publication_pending`; its finished generation and holds stay intact. It causes no paid retry. After fixing the operational cause, resume only publication:

```bash
sudo gazette publish-pending --attempt ATTEMPT_ID
```

This uses the same enabled policy/source/moderation guards, makes no API call, is idempotent, cannot overwrite a published edition and cannot retroactively publish a draft created before policy activation. Source withdrawal or later explicit owner rejection withholds an automatic edition on read.

```bash
sudo gazette configure --disable-auto-publish
```

Disabling stops future automatic publication without deleting history, refunding costs, removing already-published editions or changing the key/timer. Future generations return private drafts. No production or paid operation is performed during development.

## Verification

Require all 27 offline suites and final exact-commit Ubuntu CI. Fixtures cover default-off compatibility, broker generation/moderation/publication, immutable automatic authority with zero invented human reviews, truthful public attribution, refresh without overwrite, old/preview/flagged/rejected/uncertain/source-withdrawn guards, retained holds and publication-only recovery. Real Linux systemd tests exercise the distinct DB UID, authenticated socket, protected manifest ownership, unchanged worker credential/database isolation and no duplicate paid dispatch.

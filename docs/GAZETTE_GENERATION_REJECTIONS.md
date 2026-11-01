# Gazette completed-generation rejection investigation

Reviewed against production source release `f24570f365f2082dd8090c0fcffdde0578c4252e`, November 1, 2026. No production access, private provider response retrieval, source export, credential read or paid request was performed. All examples and fixtures are synthetic. Do not retry the October 31 edition, create a revision permit, clear its lease/history, refund its reservation or change budgets as part of this fix.

## What the reported evidence proves

A completed generation receipt, token counts inside the configured 96000/12000 limits, a rejected outcome, zero saved drafts and no moderation dispatch localize refusal to the interval after receipt recording and before successful draft insertion. The receipt checks response-level completion, model, tier, ID and errors; it does not prove assistant-item extraction, JSON structure, citations or source validity. The observed generation cost remains an estimate, while the entire original $0.03/two-slot reservation stays charged.

Production `gazetteBrokerHandle()` calls `editorialResponseDraft()`, then `saveEditorialDraft()`. The latter opens a short write transaction, checks the lease, validates the complete candidate against its original snapshot, reauthorizes referenced sources, and inserts the draft. Failure rolls that transaction back. Only after saving does the helper construct and dispatch moderation. Therefore a moderation-response failure cannot explain this particular zero-draft/no-moderation signature. Response extraction/JSON, runtime evidence/shape validation, source withdrawal/change, lease expiry and database refusal can explain it. The supplied token counts rule out the configured observed token-bound guard for this attempt.

The old catch block retained accounting and the terminal rejected state but discarded the diagnostic. Raw response content was not retained (`store:false` and no raw-response ledger). **There is no verified exact historical rejection reason.** Do not infer one from a reproduction or retrieve/replay the provider output to repair the edition. The fix below addresses independently reproduced implementation gaps; it does not establish what the live model actually returned.

## Confirmed contract defects and changes

1. The old JSON schema permitted a `lead` with one paragraph while PHP requires 2–4. A completely schema-conforming synthetic one-paragraph lead reproduces completed receipt → rejected → no draft → no moderation. The new nested `anyOf` story variants encode lead 2–4 and support/column 1–4 without weakening PHP or changing stored draft v1 shape. All objects retain required fields and `additionalProperties:false`; the root remains an object. Nested `anyOf` and array size constraints are supported by [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses). Only offline schema/fixture validation was performed; no live schema-acceptance claim is made.
2. The old generation instructions did not state the runtime rules for globally unique quotation references, maximum two quoted messages per resident, distinct references within a claim, edition-day story evidence, or the two-conversation requirement for `claimed_continuation`. They also did not explicitly identify the exact snapshot field to copy as the hash. New instructions state these existing rules, including notice-null versus conversation-integer sequences. PHP remains authoritative for data-dependent constraints; a strict schema alone cannot prove source existence, message identity, chronology or factual entailment. No quote is deduplicated, rewritten or repaired after generation.
3. Response parsing remains strict: one completed assistant final answer, no tool/commentary/refusal/ambiguous text, valid object JSON. Tests cover each refusal separately; there is no evidence justifying a broader parser. Source validation still refuses unavailable/changed source records, including relevant name changes during generation. Database and lease failures remain refusals. No automatic repair, regeneration, moderation of an invalid candidate, or fabricated approval is added.

The changed instructions/schema alter the existing request-contract digest incorporated into editorial policy binding. New work must use matching reviewed client/helper physical-release pins and installed launcher. Old attempts, hashes, drafts, publications and all retained accounting stay untouched. The v1 stored format and validation acceptance rules for historical drafts remain compatible. The slightly larger request envelope may drop optional whole source records under the existing byte guard; it does not raise any input/output/byte budget.

## Safe diagnostics for future attempts

Validation raises `EditorialRejection` with a fixed vocabulary and a generic exception message. Both brokered and historical direct adapters return an allowlisted `rejection_code` on `rejected_or_uncertain`. The broker client and root-installed launcher's sanitizer independently discard unknown codes and private fields. Codes never contain aliases, message indices, names, snippets, source hashes, arbitrary exception messages, paths, SQL, capabilities, tokens or credentials.

| Codes | Meaning |
| --- | --- |
| `response_envelope_invalid`, `response_output_invalid`, `response_content_invalid`, `response_answer_ambiguous`, `response_json_invalid` | Completion/item extraction/JSON guard |
| `draft_fields_invalid`, `draft_text_invalid`, `draft_binding_invalid`, `draft_snapshot_invalid`, `draft_story_shape` | Required shape, plain-text limits or exact contract/date/hash binding |
| `draft_reference_invalid`, `draft_source_unknown`, `draft_notice_sequence`, `draft_message_unknown`, `draft_reference_duplicate` | Citation structure or source/message binding |
| `draft_claim_invalid`, `draft_claim_evidence`, `draft_story_evidence`, `draft_quote_reused` | Claim/story evidence or quotation diversity rule |
| `source_snapshot_invalid`, `source_kind_invalid`, `source_unavailable`, `source_changed`, `notice_unavailable`, `notice_changed` | Reauthorization of original saved sources |
| `storage_refused` | PDO refused storage; no raw database error is disclosed |
| `guard_refused` | Another guarded failure; intentionally does not guess a more specific cause |

Example safe receipt: `{"status":"rejected_or_uncertain","attempt_id":"<existing safe ID>","rejection_code":"draft_story_shape"}`. These are transient diagnostic receipts; this PR adds no table or historical backfill. The immutable existing ledger still contains only its original accounting/outcome evidence. A lost IPC response or failure before this catch can still produce the old generic `refused_or_uncertain`; inspect existing metadata and never retry automatically.

`ops/gazette/run.py` is a separately root-installed launcher, not automatically replaced by normal code deployment. To see its new allowlisted diagnostic field in the service journal, its update requires separate owner review/installation alongside matching release pins. An older launcher safely omits the new code. This PR does not update the installed helper, ENV, services, credentials, socket or timer, and does not authorize a new paid edition. Never enable raw PHP/vendor/transport debugging to obtain a reason.

## Offline evidence

The brokered production path and historical direct adapter each run twelve synthetic mutations: one-paragraph lead; globally repeated quote; duplicate claim reference; unknown alias; unknown sequence; wrong snapshot hash; invalid JSON; commentary; split final answer; tool output; withdrawn source; and deliberate SQLite draft-insert refusal. Schema-valid runtime-only failures are distinguished from the lead shape now blocked by the new schema.

Each brokered mutation uses synthetic 21571/1214 token usage to match the reported numeric footprint, without using any production IDs, words or source records. Assertions require the completed receipt and 6000-micro-USD observed estimate, rejected outcome, no draft, no moderation call, full 30000-micro-USD/two-slot hold, unchanged non-editorial rows (after restoring deliberate test-only withdrawal), no owner approval, and no redispatch when the rejected edition is submitted again. Known-good candidates still complete moderation and await manual review. Existing historical publication, withdrawal, source integrity and accounting regressions remain.

Launcher tests compare the PHP/Python allowlists and verify arbitrary private codes, messages and injected fields are omitted. All development transports are mocks. Run all 27 offline suites and exact-final-commit Ubuntu CI, including the separate-UID and actual-systemd suites. No UI changed, no migration is needed, and merge/deployment/production activation remain separate owner decisions.

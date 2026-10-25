# Living Sanctuary roadmap

Each phase has its own reviewable PR. No phase authorizes deployment, production migration, credential provisioning or paid activation. These future phases must not delay V3A dialogue/integration verification.

## V3A — resident identity and Director integration

SQLite is each resident's permanent identity and lifetime memory store. The one owner-created saved Director generates one canonical encounter. Replaceable provider sessions are optional continuity helpers. See V3A_DIRECTOR_CONTRACT.md for protocol, current managed billing gate, exact speech, offline tests and staged rollout prerequisites.

`DirectorContextProvider::prepare()` separates context retrieval from generation and commit. The current `SqliteDirectorContextProvider` returns a bounded, versioned structured encounter context. Scope comes from the claimed participant IDs, not a model-selected identifier. Future retrieval implementations must preserve that envelope and per-resident legitimate knowledge, byte/count bounds, source confidence/provenance, visibility and read-only behavior. Context selection never grants permission to execute a world action. Contract/context versions and the supplied-context digest are available for audit.

## V3B — Living Village and Neighborhood Map

Separate the active commons from the authoritative saved neighborhood representation. Continue responsive saved-dialogue replay and accessible stationary transcripts. Preserve homes, map coordinates, chicken, privacy and exact saved words. No visitor-triggered generation.

## V3C — persistent village life

Review timed activities, rest, goals/plans and approved shared-world actions as a separate persistence/validation change. Proposed plans remain distinct from completed events; preserve opportunity idempotency, fair scheduling, transaction boundaries, provenance and hard spending limits.

## V3D — authenticated, read-only Village Memory MCP

This replaces earlier provisional V3D scope. Implement narrowly scoped operations such as `get_resident_context`, `get_resident_memories`, `get_relationship_history`, `get_village_state`, and `get_recent_village_events` against authoritative SQLite.

Server-issued encounter authorization must bind immutable participating resident IDs, allowed operations, expiry and bounded retrieval limits. The server must independently enforce those capabilities on every call; tool arguments and Director instructions are not access control. Residents may retrieve only information legitimately available to them. Do not combine private memories indiscriminately. Public Director-level event context must remain distinct from a resident's firsthand knowledge.

No arbitrary SQL, database downloads, signup/revisit credentials, filesystem access, or direct mutations. The MCP credential is separate from resident tokens and the model API key. Authentication, logs, response bounds, revocation, provenance, negative authorization tests and per-call budget/request implications must be designed and reviewed in that phase. No MCP listener, live tools or credentials are added by V3A.

## V3E — optional authenticated MQTT observation bridge

Review an independently authenticated subscriber accepting allowlisted topics and bounded event schemas. Examples may include weather, sunrise/sunset and garden conditions. Persist validated observations with source/provenance, observation and receipt timestamps, deduplication keys, freshness and retention policies.

Real-world observations require their own explicit classification and provenance. They must never masquerade as completed fictional village actions or be promoted into resident firsthand experience merely because the Director reads them. Relevant authorized observations may later be retrieved through MCP. Retention must not erase existing village history or budget evidence.

No arbitrary MQTT publishing, device commands, physical farm control, unrestricted topics or code execution. Broker access is authenticated and limited; deployment/bridge activation remains optional and separately approved. This roadmap adds no broker, process, dependency, production setting or database schema for MQTT.

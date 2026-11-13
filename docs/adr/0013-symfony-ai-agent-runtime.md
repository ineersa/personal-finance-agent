# ADR 0013: Use Symfony AI agents with native Messenger concurrency

- Status: Accepted; runtime portion superseded by [ADR 0032](0032-revolt-amp-worker-runtime.md)
- Date: 2026-10-29
- Amended: 2026-11-08

On 2026-11-12, the owner replaced the native worker-pool runtime below with the cooperative Amp/Revolt runtime in ADR 0032. The historical amendment and rationale remain below; Symfony AI ownership of the loop and the context-handoff decision remain accepted.

## Amendment: native worker pool

The original decision selected cooperative fibers inside one PHP process and adaptation of Hatfield's generic execution, Amp HTTP and queue integration. On 2026-11-08, the owner replaced that runtime choice with native Messenger concurrency using four isolated worker contexts. This supersedes the same-process fiber consumer, reference-runtime adaptation and overlapping shared-service reset obligations, not Symfony AI's agent loop or the context-handoff decision. Quotas, budgets and per-user admission remain deferred by [ADR 0021](0021-fixtures-first-jev-import.md).

## Context

The application needs independent financial assistant executions without duplicating Symfony AI's generic agent machinery. A model request can spend much of its time waiting on I/O, so a single Messenger consumer should be able to make progress on work for multiple users.

This decision selects the runtime direction, not a validated implementation. Existing provider, ownership, streaming, and resource restrictions remain in force.

## Decision

### Symfony AI owns the agent loop

Use Symfony AI Agent for the model/tool loop and Platform for provider access. Reuse available, compatible Symfony AI message stores, processors, structured-output facilities, and toolbox support rather than building duplicate generic machinery. Keep application-specific financial behavior and ownership enforcement in the application.

Preserve the three-provider scope and captured generation choices from [ADR 0012](0012-configurable-ai-providers.md). Verify facilities and APIs against the pinned Symfony AI beta packages before adapting reference code.

[ADR 0018](0018-finance-review-sql-and-jev.md) adds selective reuse of Hatfield's request-time target-model history conversion while preserving canonical stored history. Its mutable active message bag remains generation-local. This does not import Hatfield session/catalog machinery.

### Native Messenger worker pool

Use the installed Messenger's native `messenger:consume --concurrency=4`. One consumer manages a pool of four isolated worker contexts through `ParallelExecutionStrategy` and the already-installed `amphp/parallel`; handlers do not interleave as fibers in one shared PHP runtime. Keep the existing `scheduler_default`, `statement_import` and `chat` transports, keepalive, memory/time limits and normal service reset behavior.

Do not introduce a custom consumer, execution strategy, cooperative HTTP replacement or Hatfield runtime dependency. Symfony AI continues to own each model/tool loop; provider clients, handlers and tools remain unchanged.

Four is the native consumer's concurrency setting, not a per-user allowance, fairness policy or instance-wide admission system. Per-user admission, pending-work limits, quotas and budget accounting remain deferred by ADR 0021; this amendment does not reinstate ADR 0011's superseded controls.

### Integration constraints to verify

- Keep mutable messages, tool state, ownership context, and correlation context local to each generation. Worker-context isolation does not replace application ownership checks.
- Retain native service resets between messages within each worker context; do not disable resets to support shared-runtime overlap.
- Keep database write transactions short. Do not hold one across a provider call, parsing, broker communication, or Mercure publication.
- Maintain broker reservation renewal/keepalive for active work while other executions run. Verify native worker shutdown, failure isolation, and settlement against the selected queue integration.
- Preserve at-least-once delivery handling and recoverable publication from [ADR 0004](0004-sqlite-queue.md). Failure or redelivery must not duplicate financial imports. Native concurrency does not itself serialize a user's or conversation's submissions.
- Deliver updates through private Mercure subscriptions as selected in [ADR 0005](0005-mercure-streaming.md). Persist owned messages, generation status, and completed inline chat output independently of streaming so reloads do not depend on hub history.

These are integration verification obligations, not claims that enabling the pool proves concurrent progress, ownership isolation or delivery behavior. Verify real worker and delivery behavior through the Playwright workflow in [TESTING.md](../../TESTING.md).

### Simple context handoff, for late implementation

Select a small context-handoff approach, with implementation scheduled late rather than an advanced compactor or memory-optimization project. Watch request context size with headroom for summarization. At a safe boundary where tool calls and their results are complete, ask the same configured model for a handoff with tools disabled. Subsequent requests use application instructions, the handoff, and recent complete messages. Canonical SQLite conversation history remains unchanged.

The handoff is conversation context, not trusted security instructions or authoritative financial totals. Query financial facts again rather than treating summary values as the source of truth. Summarization budget accounting and per-user admission remain deferred by ADR 0021. Preserve finite context, tool, output, and token bounds.

Exact triggers, token estimates, recent-message windows, and pinned Symfony AI hooks remain implementation details. Do not promise unlimited context or low memory use. Do not import Hatfield compaction machinery, build another model/tool loop, or expand this into transcript-streaming or memory engineering.

This runtime decision does not select skills or instruction management. [ADR 0014](0014-inline-chat-and-antv-charts.md) subsequently selects Docker-hosted AntV MCP with hosted rendering and inline chat output instead of separate artifacts. Exact MCP transport/APIs and image lifecycle remain open. [ADR 0015](0015-sqlite-conversation-history.md) selects shared application SQLite chat persistence and temporary/saved retention, protecting queued/running work without changing generation concurrency. Application MCP is separate from the selected Mate development tooling in [ADR 0008](0008-mate-development-tools.md).

## Alternatives considered

- A duplicate generic agent loop or wholesale Hatfield runtime import would replace facilities selected from Symfony AI and bring unrelated orchestration into the demo.
- Same-process cooperative fibers were the original choice, replaced by the native worker pool to avoid custom runtime and shared-service lifecycle infrastructure.
- Serial execution would not provide the agreed concurrent progress across users.

## Consequences

- Native Messenger owns worker-context scheduling and resets; no new dependency or application runtime infrastructure is selected.
- Four worker contexts can consume more memory and provider capacity than serial execution. They do not increase SQLite writer capacity or provide per-user fairness, admission or budget enforcement.
- Source inspection and the concurrency setting do not establish runtime overlap, safe redelivery, graceful shutdown or private live delivery; those still require integration verification.

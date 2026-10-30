# ADR 0013: Use Symfony AI agents with concurrent fiber execution

- Status: Accepted
- Date: 2026-10-29

## Context

The application needs independent financial assistant executions without duplicating Symfony AI's generic agent machinery. A model request can spend much of its time waiting on I/O, so a single Messenger consumer should be able to make progress on work for multiple users.

This decision selects the runtime direction, not a validated implementation. Existing provider, ownership, streaming, and resource restrictions remain in force.

## Decision

### Symfony AI owns the agent loop

Use Symfony AI Agent for the model/tool loop and Platform for provider access. Reuse available, compatible Symfony AI message stores, processors, structured-output facilities, and toolbox support rather than building duplicate generic machinery. Keep application-specific financial behavior and ownership enforcement in the application.

Preserve the three-provider scope and captured generation choices from [ADR 0012](0012-configurable-ai-providers.md). Verify facilities and APIs against the pinned Symfony AI beta packages before adapting reference code.

[ADR 0018](0018-finance-review-sql-and-jev.md) adds selective reuse of Hatfield's request-time target-model history conversion while preserving canonical stored history. Its mutable active message bag requires generation-local isolation, not shared use across fibers. This does not import Hatfield session/catalog machinery.

### Concurrent execution within one consumer

Run multiple independent Symfony AI agent executions concurrently in one Messenger consumer using a cooperative fiber strategy. Concurrency is across users: preserve [ADR 0011](0011-layered-rate-limiting.md)'s initial limit of one active AI generation per user, shared by chat and AI-powered extraction. Bound pending work and global active execution; this decision does not set the capacity values.

Selectively adapt Hatfield's generic Messenger execution, Amp HTTP, and queue integration from the read-only Agent Core reference checkout. Do not import Agent Core/Hatfield scheduler, catalog, TUI, or session machinery, or its tests. The reference checkout must not become an installation dependency.

Fibers cooperate when I/O yields; they do not make blocking calls or CPU-bound processing non-blocking. The selected HTTP and queue integration must allow other executions and maintenance work to progress while one execution waits.

### Integration constraints to verify

- Keep mutable messages, tool state, ownership context, and correlation context local to each generation. Interleaving executions must not mix users or results.
- Make service lifetimes and reset behavior safe for overlapping executions. Completing one message must not reset shared state still in use by another generation.
- Keep database write transactions short. Do not hold one across a fiber wait, provider call, parsing, broker communication, or Mercure publication.
- Maintain broker reservation renewal/keepalive for active work while other executions run. Verify scheduling, shutdown, failure isolation, and settlement against the selected queue integration.
- Preserve at-least-once delivery handling and recoverable publication from [ADR 0004](0004-sqlite-queue.md). Failure or redelivery must not duplicate financial imports or submission accounting, and actual additional provider usage still counts.
- Apply atomic admission, capacity reservations, and persistent budgets from ADR 0011 before enqueueing and at the worker/model boundary. Concurrent fibers must not spend the same remaining allowance.
- Deliver updates through private Mercure subscriptions as selected in [ADR 0005](0005-mercure-streaming.md). Persist owned messages, generation status, and completed inline chat output independently of streaming so reloads do not depend on hub history.

These are implementation and verification obligations, not claims that the reference integrations already satisfy them for this application. Verify real concurrent worker and delivery behavior through the headed Playwright workflow in [TESTING.md](../../TESTING.md).

### Simple context handoff, for late implementation

Select a small context-handoff approach, with implementation scheduled late rather than an advanced compactor or memory-optimization project. Watch request context size with headroom for summarization. At a safe boundary where tool calls and their results are complete, ask the same configured model for a handoff with tools disabled. Subsequent requests use application instructions, the handoff, and recent complete messages. Canonical SQLite conversation history remains unchanged.

The handoff is conversation context, not trusted security instructions or authoritative financial totals. Query financial facts again rather than treating summary values as the source of truth. Summarization provider usage counts against existing budgets and stays within the one-active-generation-per-user rule. Preserve finite context, tool, output, and token bounds.

Exact triggers, token estimates, recent-message windows, and pinned Symfony AI hooks remain implementation details. Do not promise unlimited context or low memory use. Do not import Hatfield compaction machinery, build another model/tool loop, or expand this into transcript-streaming or memory engineering.

This runtime decision does not select skills or instruction management. [ADR 0014](0014-inline-chat-and-antv-charts.md) subsequently selects Docker-hosted AntV MCP with hosted rendering and inline chat output instead of separate artifacts. Exact MCP transport/APIs and image lifecycle remain open. [ADR 0015](0015-sqlite-conversation-history.md) selects shared application SQLite chat persistence and temporary/saved retention, protecting queued/running work without changing generation concurrency. Application MCP is separate from the selected Mate development tooling in [ADR 0008](0008-mate-development-tools.md).

## Alternatives considered

- A duplicate generic agent loop or wholesale Hatfield runtime import would replace facilities selected from Symfony AI and bring unrelated orchestration into the demo.
- Serial execution would not provide the agreed concurrent progress across users within one consumer.

## Consequences

- Exact worker wiring, packages, capacity, service isolation/reset mechanisms, cooperative I/O behavior, and compatible Symfony AI beta APIs remain bootstrap details requiring verification.
- Blocking operations can stall all executions in the consumer. Fiber concurrency is not parallel CPU execution or an increase in SQLite writer capacity.
- The one-active-generation-per-user rule is unchanged; cross-user concurrency is not permission to bypass shared quotas or budgets.
- No runtime code, dependency, service configuration, or concurrency integration has been implemented or validated by this documentation decision.

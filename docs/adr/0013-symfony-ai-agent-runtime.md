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

#### Request-only checkpoint implementation (2026-11-20)

Use the documented provider `InvocationEvent`, dispatched by installed Platform BETA1 before contract normalization on every invocation, including the initial request and continuations after completed tool batches. ChatGPT's installed factory accepts a dedicated event dispatcher. A generation-local listener receives the already prepared model, `MessageBag` and options after Agent's history compatibility/system processors and toolbox exposure. It only replaces the outgoing event input. Native Agent messages, checkpoints and Doctrine message-store conflict semantics are untouched; this is compatible with append-only steering and does not require the runner's restricted replacement path or replaying input processors.

Trigger from the latest assistant message's per-request `token_usage.input_tokens`, which includes cached tokens, plus conservative byte-based headroom for that assistant output and messages appended since its measurement. Do not sum lifetime, cached or completion counts into the measured prompt count. The byte estimate is not a tokenizer or provider measurement. Before a measurement exists, do not pretend to know the provider's input count; the initial request still has native provider context enforcement. The default `CHAT_HANDOFF_THRESHOLD` is 180000 against the curated 272k models, leaving headroom for the summary. A checkout-local `.env.test.local` may set 23000 for the isolated compaction exercise; never lower the production default merely to force a test.

Partition the effective prepared context into complete units. A tool-calling assistant and all matching tool results stay together; pending, missing or orphan results prevent choosing a new cut. Retain approximately 6000 estimated tokens of newest complete units (at least one complete unit even when larger). Preserve all current system instructions. Summarize only the older prefix, including the previous handoff when advancing a checkpoint. A successful checkpoint stores only `{summary, through}` on the existing owned `chat_metadata` row. The cutoff is the native ID of its last covered message, not an array offset. Later requests retain current prepared system instructions, add the saved handoff as explicitly untrusted user context, and omit native non-system messages through that cutoff. The original messages remain readable, searchable and owned in the native transcript. If a cutoff is absent, leave that request untrimmed instead of guessing coverage.

Generate the handoff using an ordinary Responses request to the same provider/model, with the same prepared system instructions, ordered tool definitions, reasoning configuration and conversation `prompt_cache_key`, plus a handoff request suffix. Set supported `tool_choice: none`; do not run an Agent/toolbox for this request. The installed ChatGPT bridge forwards the cache key both in the request and as session-affinity headers. A generation-local recursion guard prevents this invocation from compacting itself. Its usage never enters the native transcript or trigger history. An in-call attempted-cutoff set suppresses repeated failures; a saved successful cutoff suppresses regeneration of the same prefix across calls. No durable attempt ledger or recovery engine is added.

Accept only nonempty streamed text with no tool calls, at most 12000 UTF-8 bytes, with at most 48000 bytes of consumed text/reasoning. On overflow, cancel the native HTTP response when available and discard the result. The bridge does not forward `max_output_tokens`; these are application acceptance/consumption limits, not a provider-enforced output or billing cap. Empty, oversized, tool-calling or failed summaries preserve the previous successful checkpoint/full request. Existing provider failures may still occur; do not silently truncate tool groups, invent a summary or fail over. All model work precedes the short owner-scoped database update. No transaction is held over provider or tool communication.

Schema change: Doctrine generated `Version20261120032052` against verified fresh task-owned diagnostic storage. Its inspected up adds only nullable `chat_metadata.handoff`; the generated SQLite down rebuild removes that column. The migration was applied successfully only on that diagnostic database; owner/browser storage is for main's lifecycle tasks. This is not a claim of passing application tests.

This runtime decision does not select skills or instruction management. The historical ADR 0014/0015 presentation and retention choices are superseded by ADR 0026/0029. Application MCP remains separate from Mate tooling in ADR 0008.

## Alternatives considered

- A duplicate generic agent loop or wholesale Hatfield runtime import would replace facilities selected from Symfony AI and bring unrelated orchestration into the demo.
- Same-process cooperative fibers were the original choice, replaced by the native worker pool to avoid custom runtime and shared-service lifecycle infrastructure.
- Serial execution would not provide the agreed concurrent progress across users.

## Consequences

- Native Messenger owns worker-context scheduling and resets; no new dependency or application runtime infrastructure is selected.
- Four worker contexts can consume more memory and provider capacity than serial execution. They do not increase SQLite writer capacity or provide per-user fairness, admission or budget enforcement.
- Source inspection and the concurrency setting do not establish runtime overlap, safe redelivery, graceful shutdown or private live delivery; those still require integration verification.

The handoff selector pins application-marked schema messages and all user instructions in the unfinished turn. Older completed tool batches within that same turn remain eligible: a batch is indivisible until all its tool results are present. Restore finds the native cutoff independently of pinned messages, retaining active instructions before the summary when they precede the cutoff. Across turns the saved summary is itself eligible for a later handoff. The recent-window target is at most 6000 estimated tokens (one tenth of the configured threshold); a prefix needs at least 1000 estimated visible-content tokens and the accepted summary must save another 1000. These are heuristics, not a tokenizer or a guarantee that a particular scoped journey reaches the threshold. Native usage drives the trigger; the first request without usage is not preflight-tokenized.

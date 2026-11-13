# ADR 0032: Cooperative Messenger and Symfony AI execution on Amp/Revolt

- Status: Accepted
- Date: 2026-11-12
- Supersedes: the native worker-pool runtime portion of [ADR 0013](0013-symfony-ai-agent-runtime.md), not its native Symfony AI loop or context-handoff decision.
- Amends: the worker runtime references in [ADR 0029](0029-native-chat-store.md) and [ADR 0031](0031-local-demo-packaging.md); their storage, delivery and packaging decisions remain unchanged.

## Context

The owner explicitly selected one PHP process with cooperative execution on Revolt and Amp instead of Messenger's native `--concurrency` pool. Provider waits should let other handlers progress without replacing Symfony AI's agent loop, history, progress events or toolbox semantics.

A disposable experiment with the installed Symfony AI `FiberToolExecutor` showed that its manual raw-fiber resumption bypasses Amp-managed waits. It is not a compatible scheduler for this runtime. Native asynchronous HTTP interfaces alone also do not establish Revolt cooperation: the actual injected transport must yield to Amp.

## Decision

Use `app:messenger:consume` in development, isolated browser and production Compose workers. Keep the existing transport selection (`scheduler_default` where applicable, `statement_import`, `chat`), five-second lease renewal, 256 MiB memory limit, 3600-second intake limit and noninteractive execution. Remove native `--concurrency` from these worker commands. The ordinary framework `messenger:consume` remains available for framework tooling, not as the configured application worker.

Keep Symfony's native `Worker`, injecting a small `MessageExecutionStrategyInterface` implementation with at most four Amp futures in flight. Revolt owns the event loop and Amp owns fiber suspension; no manual `Fiber::resume()`, child worker pool, second event-loop runner or custom agent loop is added. Four is a consumer-local in-flight bound, not a per-user allowance, fairness guarantee, quota or instance-wide admission system.

Each future delegates dispatch to its own native `SyncMessageExecutionStrategy`, retaining Symfony's AckStamp, error and batch dispatch semantics without sharing that strategy's mutable held-ack state across handlers. Its receipt-bound native completion callback is retained and invoked by the worker's draining control flow, not substituted with a generic callback. The acknowledgement bool remains captured by reference. Native acknowledgement queueing is forwarded unchanged; terminal settlement remains with Worker and the transport. No envelope reconstruction or new receipt identity is introduced.

SIGTERM, SIGINT and SIGQUIT stop intake and cancel idle notification waiting; existing in-flight handlers and their native completions drain before exit. Keepalive uses a Revolt timer so lease renewal continues during Amp I/O suspension, including drain. It reuses the native queue transport's busy-operation deferral. Shared services are reset only after all in-flight handlers and their completion callbacks drain, and at final shutdown, never on each interleaved WorkerRunning event. Memory/time stopping does not promise interruption of a synchronous blocking operation or a fixed shutdown deadline.

Use a command-scoped idle hook and the installed sqlite-queue `Transport::waitAny()` on its separate notification connection. Poll scheduler readiness within a bounded one-second idle budget; use a 100 ms budget while handlers are active. At the in-flight limit, await handler completion with bounded scheduler ticks. Configure Worker sleep to zero, avoiding its blocking Clock sleep. Do not copy the reference runtime's wait/session orchestration, sender serialization or recovery machinery.

Inject explicit, untraced Symfony `AmpHttpClient` services for ChatGPT provider/authentication, chart MCP, Jev, Mercure publication and scheduled ECB requests. Preserve existing request/default options; do not globally replace framework HTTP/profiler infrastructure or log private HTTP arguments/bodies. Native test cassette transport decorations remain unchanged. Use the normal Composer-locked dependencies without an upstream fork or vendor overlay.

### Amendment: temporary application-owned tool executor

Initially, this ADR selected the upstream optional `Symfony\AI\Agent\Toolbox\AmpToolExecutor`, prohibited an application copy and required experimental source linking because the locked release did not supply it. The owner subsequently explicitly authorized a temporary application-owned copy so a clean Composer installation works without a fork or vendor overlay. This supersedes only that no-duplication/source-linking choice, not the other runtime decisions.

Construct `App\Chat\AmpToolExecutor` with the same owner-bound native decorated toolbox supplied to Agent. Copy the upstream algorithm from published commit [`57961ae9`](https://github.com/ineersa/ai/blob/57961ae9/src/agent/src/Toolbox/AmpToolExecutor.php), retaining MIT attribution and source provenance. Yield progress in input order before starting Amp futures, settle every started sibling with `awaitAll()` before propagating the first observed error, and return results in input order. Exclude this manually constructed executor from automatic service discovery; do not introduce a globally bound toolbox. Amp is already a direct production dependency, so no optional-dependency checks or fallback paths are needed.

Once an appropriate Symfony AI release contains the executor, update the locked dependency, replace the factory's application executor with the released class and remove the temporary copy and its DI exclusion. The upstream contribution is [Symfony AI PR #2938](https://github.com/symfony/ai/pull/2938) and proceeds independently. Do not rewrite `FiberToolExecutor` or create a custom agent loop: Symfony AI continues to own the model/tool loop, native tool semantics, progress and message store.

## Alternatives considered

- Keep native Messenger `--concurrency=4`: explicitly replaced by the owner for this cooperative experiment.
- Use the installed `FiberToolExecutor` unchanged: rejected by the measured Amp suspension incompatibility.
- Broadly reimplement AckStamp/batch dispatch, tool semantics or the agent loop in the application: unnecessary when native extension points suffice. The explicitly authorized temporary executor copy only supplies the unpublished Amp scheduling algorithm.
- Import the reference runtime's complete consumer/wait/session machinery: unnecessary for the selected fixed transports and native queue wait support.

## Consequences and remaining verification

The runtime shares services in one PHP process. Current handlers bind trusted owners explicitly: tenant SQL uses an owner-bound tool clone, imports open/release a handler-local tenant manager, and native chat history/metadata use short per-operation DBAL work. The central ORM and its identity map remain shared. Resetting only at drained boundaries avoids clearing another suspended handler's state, but source inspection is not proof of every shared-service interaction.

Synchronous SQL, file/CPU work and callbacks can still stall Revolt. The browser stack excludes Scheduler/ECB, so ordinary replay journeys do not establish live scheduled-rate HTTP cooperation. The existing ChatGPT per-identity OAuth file lock blocks while held and refresh can perform HTTP under it; same-identity refresh overlap is an unproven hazard, not authorization for new locking infrastructure. The queue client allows one operation per connection; preserve its native keepalive deferral and separate notification connection rather than add speculative serialization.

No quotas, per-user locks, retries, recovery engine, generation fencing, transaction held over model I/O, alternate transcript or data migration is added. Native at-least-once delivery and optimistic history conflicts keep their existing limitations. This ADR records an approved runtime replacement, not proof of live streaming overlap, lease safety, graceful shutdown or cross-user isolation. Main must run the existing browser happy paths and approved bounded real-stack overlap verification after dependency preparation; no new browser scenarios are introduced by this slice.

# ADR 0034: Native chat cancellation through a shared cache mailbox

- Status: Accepted
- Date: 2026-11-14
- Amends: [ADR 0029](0029-native-chat-store.md) and [ADR 0033](0033-chat-feedback-and-chart-panel.md), only for Stop and bounded reload reconciliation.

## Context

The owner observed an optimistic conversation conflict after submitting overlapping follow-ups. They requested replacing Send with Stop while a response is pending/running, real cancellation, and restoration after reload without permanently stuck controls. The owner approved the narrow exception to the no-application-run-control rule and selected a shared Doctrine-backed Symfony cache mailbox rather than routing cancellation messages to an arbitrary consumer.

Symfony AI exposes native `ExecutionInterface::cancel()`, `getStatus()` and execution/message identifiers. Cancellation aborts the active model HTTP response and marks the execution Cancelled, but does not return a partial final result. The existing `getMessages()` accessor throws on cancellation. A running tool may need to finish before its handler unwinds. Native execution objects are local to the consumer, not available to an HTTP request or another consumer.

## Decision

Use the installed Symfony `DoctrineDbalAdapter` in a dedicated `cache.chat_control` pool on the central DBAL connection. Its native Doctrine schema listener supplies the cache table to generated migrations. No custom cache table SQL, transcript, execution entity, queue transport or retry scheduler is introduced.

The Stop endpoint rechecks Finance ownership, validates the native submitted message UUID and CSRF, then writes an expiring cancellation key for that exact turn. Each consumer holds only references to its own live native executions. A 250ms Revolt timer batch-reads only those cancellation keys, calls native `cancel()` and deletes consumed keys. It does not scan other consumers' requests, require a free generation slot or perform per-token database queries. Stop delivery and termination are distinct: key deletion does not mean the handler has finished.

A small cache presence record per conversation holds the native submitted message ID, status and cancellation-in-progress presentation. Submission records Pending for at most 60 seconds. A worker checks that it is still the current submission before starting, so an expired/superseded queued message cannot start later. While the handler is live, its event-loop timer refreshes presence every two seconds with a 30-second lifetime. It remains busy while cancellation unwinds, even though native `getStatus()` already reports Cancelled. After exit, terminal status is retained for at most five minutes for reload presentation. This is expiring control metadata, not durable conversation history or a resumable execution engine.

The UI swaps Send for Stop immediately, permits drafting but blocks Enter/follow-up submission, and shows Stopping until termination. Selected-chat reload renders cache presence; a lightweight owner-scoped status read every two seconds while busy reconciles missed terminal delivery. Timers/fetches are cleaned up on navigation. Expired presence is not proof the provider stopped: stop stale progress presentation and explain expiration rather than label it a successful cancellation. A resumed old worker requests native cancellation if its presence was retired. Normal native optimistic storage conflicts remain the final history safeguard.

Submission rejects a currently reported pending/running response before changing saved model/effort. This is best-effort UI/admission protection, not an atomic cross-process claim or an exactly-once guarantee. Simultaneous submissions and an event loop stalled beyond the presence lifetime remain outside that guarantee; no locks, fencing counter, strict active-deletion protection or distributed lease protocol is added.

Handle intentional native Cancelled separately from real failures, publish Stopped, clean references/mailbox keys in `finally` and return normally to Messenger. Native failures retain the existing failure/retry policy. Final reconciliation must also replace a transient reply when canonical history has no matching exchange; otherwise an error can leave a spinner visible indefinitely. Keep native saved input/tool checkpoints and retained charts; do not manufacture or persist an unfinished assistant answer. Cancelling before the input checkpoint may leave no saved question.

## Validation

Use the existing native-network browser harness, real cooperative worker/broker/Mercure and genuine provider cassettes. Verify the Send/Stop/Stopped journey, reload reconciliation and continued usability through the UI, plus the existing chat/chart happy paths and static gate. No fabricated streams/provider responses or service-only tests. Additional fault injection, worker crashes, saturation/concurrency matrices and expiry-boundary tests require separate owner permission. Owner design acceptance still precedes independent review.

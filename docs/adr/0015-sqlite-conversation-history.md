# ADR 0015: Store chat history in SQLite with five temporary chats per user

- Status: Accepted
- Date: 2026-10-29

## Context

Chat needs reloadable, owned history accessible to the web application and background workers. The project owner selected a minimal retention policy: keep five temporary chats per user, plus saved chats. Temporary means eligible for this retention cleanup, not browser-only or unstored.

## Decision

### Use the existing application database

Store conversations and messages in the existing shared application SQLite database from [ADR 0003](0003-shared-sqlite-database.md), not a separate database or files. Keep trusted generation ownership, state, selected provider/model/reasoning choices, and accounting accessible to both web and workers through this database. Queue-broker storage remains separate under [ADR 0004](0004-sqlite-queue.md).

Reuse compatible Symfony AI message, storage, and serialization facilities with owner enforcement. Choose exact adapters, tables, and APIs during bootstrap; compatibility has not been validated. Do not infer authorization from serialized message content or model output.

### Temporary by default, with Save chat

- Create conversations as temporary by default. Provide a **Save chat** button to exclude a conversation from automatic temporary cleanup.
- Keep at most five temporary conversations per user. Saved conversations are outside that five-conversation count.
- When creating a sixth temporary conversation, remove that user's oldest inactive temporary conversation. Never delete a conversation with queued or running work, and never select a saved conversation for this cleanup.
- If no eligible inactive temporary conversation exists, do not delete protected work or silently exceed five. Define safe admission/cleanup behavior during bootstrap without assuming a new queue or concurrency policy.
- All stored conversations remain reloadable by their owner while retained. Temporary conversations may be removed by the rule above; saved conversations are excluded from that rule.

Saved means protected from five-temporary-chat pruning. It is not a promise of unlimited storage or immunity to separately authorized demo-reset or account-lifecycle operations.

### Atomic retention and work protection

Coordinate retention checks and pruning atomically with Save chat, new-conversation creation, and work admission. Concurrent tabs must not prune a newly saved conversation, delete queued/running work, or exceed the per-user temporary count. Check ownership throughout these operations and in workers.

Keep write transactions short. Do not hold one open during provider calls, MCP rendering, broker communication, or other long I/O. Exact admission, locking, and recoverable cleanup mechanisms remain implementation details.

Conversation cleanup must not remove source financial data or usage accounting/idempotency information needed for admitted work. Preserve the existing persistent-budget and redelivery rules rather than allowing chat pruning to erase them. Do not log raw chat or financial data to implement retention.

### Unchanged runtime and display boundaries

- Five temporary conversations is a stored-history count, not an execution allowance. Preserve the one-active-AI-generation-per-user limit shared by chat and extraction, with bounded global concurrency, from [ADR 0011](0011-layered-rate-limiting.md) and [ADR 0013](0013-symfony-ai-agent-runtime.md).
- Keep inline text, tables, and chart images without an artifact subsystem, as selected in [ADR 0014](0014-inline-chat-and-antv-charts.md). Exact image/reference storage remains open, and retaining a chat does not guarantee availability of remote chart images.
- [ADR 0013](0013-symfony-ai-agent-runtime.md) selects a simple context handoff for late implementation without rewriting canonical stored history. Stored or saved history does not imply unlimited model context, low memory use, or automatic compatibility across providers. Preserve existing context/resource bounds and the validated generation choices in [ADR 0012](0012-configurable-ai-providers.md).

## Alternatives considered

- A separate chat database or file store would introduce another persistence boundary instead of using the agreed shared application database.
- Discarding history at browser-session end would not provide the selected reloadable temporary/saved chat policy.
- Pruning saved or active conversations to make room would violate the owner's explicit retention safeguards.

## Consequences

- This resolves the earlier blanket history/session-retention deferral without selecting broader session-management features.
- No TTL, saved-chat cap, folders, search, archive, rename, export, unsave or deletion controls, scheduled-cleanup mechanism, public settings, routes, or schema fields are selected by this decision.
- Verify owner isolation, save/prune races, queued/running-work protection, reload, and accounting preservation through headed Playwright scenarios in [TESTING.md](../../TESTING.md).
- No database schema, adapter, retention job, UI, or runtime integration has been implemented or validated by this documentation decision.

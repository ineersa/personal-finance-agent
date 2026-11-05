# ADR 0027: Owned chat history, retention and short generation writes

- Status: Accepted
- Date: 2026-11-05
- Amends: ADRs 0015 and 0024 for the combined history/controls slice.

## Context

The owner approved combined durable history and New/Save/history controls, five temporary chats **plus** saved chats, refusing New with clear busy feedback when there is no inactive temporary victim. The owner also approved disabling/refusing Send while that same conversation is queued/responding; other conversations remain usable. There is one connected ChatGPT account per Finance user, not a separate account-switching/history partition product.

Existing conversations already contain native MessageBagNormalizer JSON and a readable projection, including private chart references. The installed Symfony AI BETA1 Agent accepts MessageStoreInterface and saves input, completed tool rounds and final native messages. The reference demo's v0.5 session/cache history is not the installed API and loses native information when flattening replies.

## Decision

Keep conversations and native messages in the central SQLite database. Preserve existing rows, native JSON, initial reference context, signatures, tool relationships and readable projections. Implement the small generation-bound MessageStoreInterface adapter over the existing history column, using native MessageBagNormalizer and ConflictDetector. A fresh owner/conversation/generation-scoped database read and continuation check occur inside the existing BEGIN IMMEDIATE transaction before updating only canonical history. No new serializer, generic transcript engine, AiBundle, message-store table, data converter or provider loop is added. The native Doctrine bridge's configurable table name does not solve its different required row shape (`id`, `version`, `messages`, `updated_at`); adopting that shape would require additional schema/data movement without adding ownership or retention policy.

New creates a retained temporary conversation without deleting the currently selected chat. Save is one-way exclusion from temporary pruning. Retention counts only unsaved conversations; admitting a sixth prunes the owner's oldest-created inactive temporary chat. Existing UUIDv7 IDs provide stable creation ordering without a second timestamp column. All create/save/work-admission/pruning decisions and writes use short immediate SQLite transactions and refreshed entities. Private chart cleanup happens after commit and never touches financial data, credential files or uploads. There is no scheduled cleanup subsystem.

Persist the latest generation ID, native ExecutionStatus, captured connection epoch and error, plus per-turn readable status/error. Admission stores the question and its native message before dispatch; broker communication happens after commit. Atomically transition only the matching Pending generation to Running. Duplicate, obsolete and terminal queue messages do not regenerate a reply or write into a later turn. Compare the queued epoch with the freshly read current owner before resolving credentials; cancel stale queued work. Native Agent owns inference, tool execution and message save points. Readable output/chart authorization is committed before publishing private Turbo snapshots; terminal status/error is durable independently of Mercure.

Disable Send and reject submission only while that conversation has active work. This is not a per-user AI quota or a cross-conversation scheduler. No database write transaction spans provider, MCP, Mercure, broker or initial schema/template I/O.

Selection is explicit in the URL, forms and snapshot requests; it is not mutable global/session per-user selection. Owner-scope every lookup, including Save, snapshot and charts. Use conversation-specific Turbo transcript and Send-button targets on the existing private account topic. Updates for other selected conversations have no matching DOM target. Terminal snapshots re-enable Send without replacing a typed draft or CSRF token. Successful submission alone clears its own composer fields.

Retained history belongs to the Finance user and remains readable while disconnected and continuable after reconnect with current credentials. Connection epochs protect queued work; they do not partition retained history or permanently make older chats read-only. New conversations capture current model/reasoning defaults; retained conversations keep their captured model/reasoning. ChatGPT remains the sole provider in this slice.

## Alternatives considered

- The demo's session cache or flattened assistant text loses durable ownership/native replay information.
- Moving native JSON to the Doctrine bridge's separate row shape requires a converter or extra table and still needs application metadata, atomic retention and owner enforcement.
- A global mutable selected conversation or fixed transcript target mixes tab selections and asynchronous replies.
- Locks, leases, outbox/recovery, per-user quotas or a scheduler exceed this slice; native short writes and metadata cover the approved admission/retention boundary.

## Consequences and limits

The first-slice no-status/no-claim limitation in ADR 0024 is amended only by these short durable state transitions. No exactly-once inference, abandoned-worker recovery, lease expiry or broker outbox is promised. Process death after admission/claim can leave Pending/Running work protected and that chat busy; other chats remain available, and New may be refused if all five temporary chats are protected. A dispatch exception marks a still-Pending matching generation failed; no automatic replay is introduced. Legacy broker payloads lack stable generation IDs and are ignored rather than risk repeated inference; deploy with old work drained. No migration rewrites existing message payloads or invents execution history for old turns.

No unsave/delete/rename/search/archive/provider selector or context compactor is added. Generated migrations preserve prior applied history. Browser coverage remains one canonical-fixture happy controls journey plus the existing worker chat journey; the existing replay cassette is stale for current chart tools, so worker/provider proof requires a separately authorized isolated live run. Static/container checks do not establish that proof.

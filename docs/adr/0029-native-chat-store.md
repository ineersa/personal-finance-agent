# ADR 0029: Native Symfony AI conversation storage

- Status: Accepted
- Date: 2026-11-06
- Supersedes: [ADR 0027](0027-owned-chat-history-controls.md).
- Amends: [ADR 0015](0015-sqlite-conversation-history.md), [ADR 0024](0024-basic-chatgpt-worker-chat.md), [ADR 0026](0026-local-unovis-charts.md) and [ADR 0028](0028-native-chat-search.md).

## Context

The owner rejected the PR's custom store, durable readable transcript, claims/attempt fencing and recovery projection engine. The goal is a finance demo using native Symfony facilities, not an agent-execution platform. The owner explicitly authorized discarding/recreating chat-only storage without copying old data and relying only on native behaviour, including removal of the strict queued/running Send/Delete guarantee.

## Decision

Inject the installed `Symfony\AI\Agent\Bridge\Doctrine\DoctrineDbalMessageStore` directly as `MessageStoreInterface`. Its `id`, `version`, `messages` and `updated_at` row is the sole history. Agent owns native input continuation/message IDs, checkpoint saves at input/tool-round/final boundaries and optimistic conflicts. The package serializes the complete MessageBag on save; no incremental persistence adapter, decorator, converter or second serializer is added.

Application `chat_metadata` contains only native conversation ID, Finance owner, scalar label, favourite and private-chart references associated with the submitted native user message ID. Metadata queries belong to `ChatConversationRepository`; runtime history access never uses ORM. A read-only `ChatMessages` ORM mapping exists solely for native Doctrine schema generation and Alice fixture insertion. Its columns were compared with the installed bridge's actual `setup()` schema on disposable diagnostic storage. It is not a message-store implementation, ORM cache or eager entity association.

Delete `ConversationMessageStore`, `NativeAssistantOutput`, the persisted `history`/`turns`, generation/attempt identifiers, durable execution/error state, and recovery restoration methods. Do not replace them with busy flags, locks, lease timers, claims, schedulers, custom retries or complete-answer detection. Messenger/transport retain their existing delivery/keepalive/redelivery behaviour. The queue carries native input with native message IDs and trusted owner/conversation identifiers; the worker rechecks their ownership before binding tools. Current configured model/effort and current owner credentials are used at execution. Old-format queued chat jobs must be discarded by the operator when applying the chat-only recreation; imports and unrelated work must not be purged.

`ChatView` formats canonical native user/assistant/tool-call content for existing Twig templates without modifying or persisting messages. Hide the first native user message, which is the initial finance reference context. Display only readable Text, Commentary, Thinking summaries and tool calls/results; never signatures or encrypted reasoning. Private charts remain application-authorized metadata and private Flysystem bytes. The transient `ChatStream` uses Symfony's supplied `AssistantMessageStreamListener` to render live native deltas; it does not replace the Agent's result. At model boundaries, refresh the display base from the native store and discard transient deltas; no per-token database work occurs.

Keep the agreed UI: unstored New draft, automatic retention after submission, reversible favourites, bounded independent five-row sidebar pages with selected entry pinned, modal FTS search, owner-scoped CSRF-protected native confirmed Delete and the existing reset restrictions without its removed opt-in flag. Native Forms/Validator handle composer/search input; native argument mapping validates other chat action/query DTOs. Successful selected-chat submission remains acknowledgement-only 204, and validation errors return a focused 422 stream. Do not overwrite the document or a later typed draft. Pagination uses the framework route generator and maps a submission-error page to canonical GET `admin_chat`, preserving selection and both cursors.

Bind the supplied search expression directly to SQLite `MATCH`. SQLite owns FTS grammar; SQL bindings already protect SQL structure. Expected native expression errors produce user feedback, not a regex rewrite/parser. FTS triggers derive only questions and assistant Text from the canonical native serialized messages, excluding initial context, tool content, reasoning and signatures. Maintain the indexed UUID-to-integer document mapping and direct virtual-table rowid targeting. This derived search index is not another conversation history.

## Migration and fixtures

Generate a new Doctrine migration; preserve prior applied migrations. The generated ordinary schema creates `chat_messages` and `chat_metadata`, then drops only obsolete `chat_conversation`. The existing explicit exception covers FTS-specific schema/triggers needed for the new source. Do not copy or backfill old history, provide legacy fallback, delete finance rows, credentials or uploads. The migration is irreversible because discarded chats cannot be restored. It does not delete old private chart bytes; those become inaccessible without retained associations. Main must verify exact storage/stack ownership before applying this destructive chat-only change outside the fresh diagnostic database.

Keep the same five approved English real-model recordings as canonical Alice definitions: four favourites and one ordinary chat. Native JSON payloads/signatures remain byte-for-byte identical; remove duplicate readable turn arrays. Native table versions were verified with the installed `ConflictDetector::version` after loading through the supplied store. No supplementary dataset, new recordings or loader is added. Remove the obsolete one-off exporter tied to the discarded entity shape.

## Alternatives considered

- Keep or rename the custom adapter/projection/claims: explicitly rejected by the owner.
- Copy/convert old application history to the native table: explicitly rejected; chat-only recreation is authorized instead.
- Reintroduce a minimal busy flag or dispatch lock: explicitly rejected in favour of native behaviour.
- Use AiBundle: not selected; direct native components suffice and preserve the existing trusted owner/tool construction boundary.

## Consequences and native gaps

No strict queued/running Send/Delete protection, exact-once inference, automatic checkpoint restart, completed-job suppression or execution fencing is promised. Native optimistic history conflicts do not prevent duplicated external work. A failed/interrupted dispatch can leave metadata without history. Deleting during execution may race native saves and leave inaccessible orphan history/chart bytes; there is no repair subsystem. Terminal error/status and incomplete display deltas are not durable. Reload displays what the native store retained, not an inferred busy state.

The installed ChatGPT bridge emits its own signed `MessageComplete`, while the installed generic `AssistantMessageStreamListener` handles standard Text/Thinking/Commentary/tool deltas but does not consume that bridge-specific completion. Removing the old result-rewriting processor may therefore lose signed Text replay metadata on new streamed replies. Existing fixture signatures are unchanged. Native reasoning-signature handling exists, but live signed continuation across the exact bridge/Agent combination remains to be verified. This is an upstream integration candidate, not authorization to recreate the custom output processor.

The native store's `drop()` deletes rows rather than removing its table; ordinary generated migrations own schema lifecycle here. Native package limits and unsupported behaviours should be reported or proposed upstream, not filled with an unapproved replacement subsystem.

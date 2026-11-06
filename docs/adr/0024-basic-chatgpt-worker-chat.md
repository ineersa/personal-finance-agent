# ADR 0024: Start chat with ChatGPT, Messenger and private Turbo streams

- Status: Accepted
- Date: 2026-11-03
- Amends: ADRs 0012, 0013 and 0015 for the first chat slice only.
- Amended by: [ADR 0029](0029-native-chat-store.md), replacing application-owned history/projections and signed-output rewriting with the supplied native store and collector. Current owner approval accepts native limitations; earlier implementation detail below is historical.
- Amended by: [ADR 0027](0027-owned-chat-history-controls.md) for retained owner history, explicit selection, same-chat busy refusal and short durable generation transitions. The original first-slice limitations below remain historical context.

## Context

The owner requested a simple multi-turn chat using the existing local ChatGPT connection, `gpt-6-luna` and low reasoning. Provider selection, financial tools, charts, full history management and concurrent execution are separate tasks. The current worker and private Mercure hub already serve statement imports.

## Decision

Run Symfony AI Agent in the existing Messenger worker through a dedicated chat transport. The ChatGPT package owns provider requests and account refresh; Agent owns execution. Register the requested model with message input, text output and thinking capabilities. Registration does not prove model entitlement. `CHAT_MODEL` and `CHAT_REASONING_EFFORT` configure new conversations; their values are captured with the conversation rather than retargeting queued work. Do not substitute another model automatically.

Keep one current conversation per connection epoch in the application SQLite database. Store native history using Symfony AI's `MessageBagNormalizer`, plus a separate readable transcript. The queue carries only the conversation identifier; derive its owner from the entity relationship and compare its saved connection epoch with the owner's current epoch before resolving credentials (ADR 0022). Controllers retain the existing finance-access voter and owner-scoped queries. Do not duplicate role checks in workspace or worker code.

Symfony AI Agent owns generation, tool calls and completion. Drive its execution through `getMessages()` and observe native progress callbacks for Mercure updates. Do not add persisted execution states, application locks, generation claims or a replacement agent loop. Native execution status is transient display feedback, not a stored admission policy. This simple flow does not serialize overlapping HTTP requests or provide exactly-once inference or abandoned-worker recovery.

The owner approved a bounded per-generation output processor because the installed Agent stream collector drops ChatGPT native message boundaries and signatures. Retain signed completed text and complete native reasoning items in output order, replace the typed final result, then let Agent construct its MessageBag. Do not add a second agent loop or serializer. [Issue #13](https://github.com/ineersa/personal-finance-agent/issues/13) tracks potential upstream replacement. Tools are excluded from this adaptation.

Publish escaped, server-rendered Turbo stream snapshots through private account-scoped chat topics. Add the topic to the existing import subscription in one call, avoiding duplicate Mercure cookies. Refresh subscription elements on navigation/account changes. Reuse the existing catch-up controller with a configurable target selector and an owner-scoped snapshot endpoint. Mercure streams replace only the transcript/progress, never the composer or CSRF token. The SQL integration’s successful form-submission response also clears composer fields through a native stream, preserving the outer controller and CSRF token. There is no server-side busy rejection. Actual submission errors return the submitted text with an error response instead of redirecting to an empty draft.

Render only provider-emitted readable summaries, commentary and answer text. Summaries are collapsible and may be absent at low effort; never fabricate them or expose encrypted reasoning/signatures. The native-output adapter classifies completed text once, using the bridge's native message metadata when its typed phase is missing. Render Markdown server-side through Twig's CommonMark extension, then Symfony HtmlSanitizer. Strip raw HTML, reject unsafe link protocols and drop images/media before browser delivery. Disable the sanitizer's input-length truncation for this local demo so long replies remain complete; HTML/link filtering still applies, but the sanitizer no longer provides an input-size denial-of-service bound. Support ordinary formatting, lists, tables and code; syntax highlighting is not included. Native history stays unchanged. Edit `prompts/chat.txt` for the system instructions, loaded through the native file-based prompt processor. The tenant SQL integration in ADR 0025 adds finance tools; charts remain unavailable.

Use a conversation-first layout: an empty-only welcome, a readable transcript scroll region and a separate reachable composer. A small Stimulus controller sizes the textarea, sends on Enter (Shift+Enter adds a line, IME composition is preserved), remembers expanded summaries across transcript replacement and follows updates only while near the bottom. A Jump to latest action resumes following without moving readers who scroll back. Keep the existing Turbo/Mercure transport and snapshot catch-up unchanged.

## Alternatives considered

- Direct browser-request inference follows the demo's SSE example, but bypasses the selected worker boundary.
- Flattened assistant text loses native replay information. A custom history engine duplicates installed facilities.
- A JSON event protocol and custom browser stream renderer are unnecessary with existing private Turbo streams.

## Consequences

The current conversation survives reloads and worker handoffs. Saved-chat controls, retention, tools, quota accounting, provider selection and cooperative scheduling remain deferred. Disconnect creates a new connection epoch; previous records remain stored but are not reattached. Process death may leave a submitted message without a saved reply; no recovery subsystem or durable progress state is added here. A reload shows saved messages, not an inferred execution state. New chat removes the current conversation without revoking the ChatGPT connection.

Live verification requires fresh owner login into the task-owned isolated stack before provider calls. Web and worker share the same package-managed private auth files and database. Static checks do not prove model entitlement, native replay or Mercure delivery.

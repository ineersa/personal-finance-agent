# ADR 0023: Start chat with ChatGPT, Messenger and private Turbo streams

- Status: Accepted
- Date: 2026-11-03
- Amends: ADRs 0012, 0013 and 0015 for the first chat slice only.

## Context

The owner requested a simple multi-turn chat using the existing local ChatGPT connection, `gpt-6-luna` and low reasoning. Provider selection, financial tools, charts, full history management and concurrent execution are separate tasks. The current worker and private Mercure hub already serve statement imports.

## Decision

Run Symfony AI Agent in the existing Messenger worker through a dedicated chat transport. The ChatGPT package owns provider requests and account refresh; Agent owns execution. Register the requested model with message input, text output and thinking capabilities. Registration does not prove model entitlement. `CHAT_MODEL` and `CHAT_REASONING_EFFORT` configure new conversations; their values are captured with the conversation rather than retargeting queued work. Do not substitute another model automatically.

Keep one current conversation per connection epoch in the application SQLite database. Store native history using Symfony AI's `MessageBagNormalizer`, plus a separate readable transcript and generation status. The queue carries only conversation, generation, owner and epoch identifiers. Check ownership, administrator exclusion and the current epoch before resolving the user's durable registration store for inference (ADR 0022). Disconnect invalidates queued work without discarding that registration. Claim each queued generation once, without holding a database transaction across inference or Mercure. This slice does not implement abandoned-worker recovery or automatic inference retries.

The owner approved a bounded per-generation output processor because the installed Agent stream collector drops ChatGPT native message boundaries and signatures. Retain signed completed text and complete native reasoning items in output order, replace the typed final result, then let Agent construct its MessageBag. Do not add a second agent loop or serializer. [Issue #13](https://github.com/ineersa/personal-finance-agent/issues/13) tracks potential upstream replacement. Tools are excluded from this adaptation.

Publish escaped, server-rendered Turbo stream snapshots through private account-scoped chat topics. Add the topic to the existing import subscription in one call, avoiding duplicate Mercure cookies. Refresh subscription elements on navigation/account changes. Reuse the existing catch-up controller with a configurable target selector and an owner-scoped snapshot endpoint. Streams replace only the transcript/status, never the composer or CSRF token. A user may draft a follow-up during generation, but a second submission is refused until the current turn finishes.

Render only provider-emitted readable summaries, commentary and answer text. Summaries are collapsible and may be absent at low effort; never fabricate them or expose encrypted reasoning/signatures. Render Markdown server-side through Twig's CommonMark extension, then Symfony HtmlSanitizer. Strip raw HTML, reject unsafe link protocols and drop images/media before browser delivery. Support ordinary formatting, lists, tables and code; syntax highlighting is not included. Native history stays unchanged. Edit `templates/chat/system_prompt.txt.twig` for the system instructions. The initial prompt states that finance tools and charts are unavailable.

Use a conversation-first layout: an empty-only welcome, a readable transcript scroll region and a separate reachable composer. A small Stimulus controller sizes the textarea, sends on Enter (Shift+Enter adds a line, IME composition is preserved), remembers expanded summaries across transcript replacement and follows updates only while near the bottom. A Jump to latest action resumes following without moving readers who scroll back. Keep the existing Turbo/Mercure transport and snapshot catch-up unchanged.

## Alternatives considered

- Direct browser-request inference follows the demo's SSE example, but bypasses the selected worker boundary.
- Flattened assistant text loses native replay information. A custom history engine duplicates installed facilities.
- A JSON event protocol and custom browser stream renderer are unnecessary with existing private Turbo streams.

## Consequences

The current conversation survives reloads and worker handoffs. Saved-chat controls, retention, tools, quota accounting, provider selection and cooperative scheduling remain deferred. Account switching creates a new current namespace; previous records remain stored but are not reattached. Process death during generation may leave a running conversation requiring later recovery work; no recovery subsystem is added here.

Live verification requires fresh owner login into the task-owned isolated stack before provider calls. Web and worker share the same package-managed private auth files and database. Static checks do not prove model entitlement, native replay or Mercure delivery.

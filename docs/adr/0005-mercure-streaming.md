# ADR 0005: Deliver streamed responses through Mercure

- Status: Accepted
- Date: 2026-10-29

## Context

The chat interface needs to show assistant output while a response is being generated. Model calls can be long-running and may involve financial tools. The application already uses Symfony Messenger with sqlite-queue for background processing, as recorded in [ADR 0004](0004-sqlite-queue.md).

The re-search prototype demonstrates worker-driven Symfony AI streaming through Mercure. The Symfony AI demo also demonstrates streamed chat and server-rendered updates, although its inspected chat example uses native SSE rather than Mercure.

## Decision

- Use Symfony AI for model streaming and Mercure for delivering live updates to the browser.
- Run long-running chat generation in Messenger handlers rather than tying generation to the browser's streaming HTTP request.
- Use authenticated, private Mercure subscriptions. Check the current user's ownership of the conversation or generation before granting access to its topic.
- Persist conversation messages, generation status, and completed inline chat output independently of live delivery. Reconstruct the interface from authorized persisted state after a reload or reconnect; hub history is not the source of truth.
- Use Symfony UX and browser-side behavior to display streamed text, status, and completed results. Keep model-stream handling, event delivery, and rendering separate.
- Keep database transactions short. Do not hold a write transaction while waiting for model output or publishing to the hub.

Mercure supplies live delivery, not task execution, financial-data authorization, or durable conversation storage. Exact event payloads, rendering mechanics, chunk batching, and cancellation behavior remain implementation details.

## Reference implementations

Use these local sources as read-only references, not installation dependencies:

- `/home/ineersa/projects/re-search/src/Research/Event/MercureEventPublisher.php`: publishing application events through Mercure.
- `/home/ineersa/projects/re-search/assets/controllers/research_ui_controller.js`: browser subscriptions and streamed UI updates.
- `/home/ineersa/projects/re-search/src/Research/Message/Llm/ExecuteLlmOperationHandler.php`: background model streaming.
- `/home/ineersa/projects/ai/demo/src/Stream/TwigComponent.php`: native `EventStreamResponse` with streamed model output.
- `/home/ineersa/projects/ai/demo/templates/components/stream.html.twig` and `templates/_stream.html.twig`: Turbo stream subscription and partial rendering.

Adapt the useful patterns rather than importing re-search's full orchestrator, provider-specific converters, or anonymous-run authorization. Verify Symfony AI APIs against the pinned 1.0 beta packages before copying examples.

## Alternatives considered

- Native `EventStreamResponse` can serve a direct model stream with fewer delivery components. For our worker-driven flow it would still need a bridge from background processing to browser connections.
- The Symfony AI demo's direct SSE/Turbo approach is useful reference material, but does not replace our selected background-processing and Mercure boundaries.

## Consequences

- The local demo needs a configured Mercure hub in addition to the broker and Messenger consumer. The web template's FrankenPHP/Mercure setup is a reference; runtime packaging remains to be finalized.
- Topic authorization is part of financial-data isolation. An existing generation ID is not sufficient proof of permission.
- Browser disconnection does not itself establish that a background generation has stopped. Cancellation and duplicate-delivery handling need explicit application behavior.
- No streaming code, hub configuration, or application dependency has been installed at the time of this decision.

## Subsequent decision

[ADR 0014](0014-inline-chat-and-antv-charts.md) replaces separate artifacts with inline chat text, tables, and chart images. Private Mercure delivery and durable message/generation requirements remain. [ADR 0015](0015-sqlite-conversation-history.md) selects shared application SQLite persistence and five temporary chats per user plus saved chats, with queued/running work protected. Exact image persistence remains open.

# ADR 0014: Display AntV charts inline in chat using hosted rendering

- Status: Superseded by [ADR 0026](0026-local-unovis-charts.md) on 2026-11-05
- Date: 2026-10-29

## Context

The project owner selected a simpler visualization path: an AntV MCP server in Docker calls its documented free hosted renderer, and the resulting chart image appears in chat. This replaces the earlier two-panel workspace and separate artifact-management proposal.

## Decision

### Chat output, not an artifact subsystem

Use one chat containing text, tables, and inline chart images. Do not build a separate artifact workspace, gallery, artifact entities, or editor. Do not introduce a custom JavaScript chart renderer, MCP Apps host, or model-generated executable JavaScript.

Keep existing ownership checks, safe text/table rendering, and the prohibition on sensitive logging. Treat model/tool output and remote image references as untrusted; validate references before fetching or displaying them. Application access to saved chat output remains ownership-scoped; external image URLs are not guaranteed private.

Retain the existing durable conversation-message and generation-state requirements independently of Mercure delivery. Represent chart output as part of chat, not separate managed artifacts. The task 13 choices below select local image copies. [ADR 0015](0015-sqlite-conversation-history.md) subsequently selects shared application SQLite persistence with five temporary chats per user plus saved chats, protecting queued/running work. It does not guarantee remote chart-image availability.

### Docker MCP server and remote renderer

Use AntV `mcp-server-chart` as a separate Docker service. Reuse Symfony AI MCP facilities and the official PHP MCP SDK where compatible. The task 13 choices below specify packages and transport; no per-chat stdio lifecycle is selected.

The MCP server calls AntV's documented default hosted renderer at `https://antv-studio.alipay.com/api/gpt-vis`. The upstream README describes this rendering service as free and documents `VIS_REQUEST_SERVER` as an override. Initially use that hosted path; a private-renderer container is not required.

The Docker MCP server and hosted renderer are different components. No completely hosted MCP endpoint has been selected or verified. Node in the separate MCP service does not embed Node in PHP or introduce a Node/npm browser asset build. [ADR 0006](0006-assetmapper-and-symfony-ux.md) remains Accepted.

### Accepted data-transfer trade-off

Aggregated chart labels and values leave the installation for rendering. Make that clear to users: choosing a local chat provider does not make chart rendering local. The owner accepts this trade-off. Do not send credentials or log raw financial data for convenience.

The upstream description of a free service is not a guarantee of permanent pricing, unlimited use, availability, retention, or private external image URLs. Hosted-renderer and image failures must be handled visibly in chat without discarding existing saved conversation state, as specified below.

### Task 13 implementation choices (2026-11-04)

The owner selected **local image copies**, not remote-reference-only retention. Store supported chart images in private Flysystem storage and reference them directly from existing conversation-turn JSON. Serve them through an authenticated, owner-and-retained-conversation-scoped image route. Reload uses the same local copy without another remote request. New chat removes its conversation's copies; the explicitly enabled demo reset also clears private chart storage. This does not extend conversation retention or introduce an artifact entity, gallery, image editor or cache platform. Missing local files show “Chart unavailable”; generation/download failures remain visible in chat without automatic retries. Filesystem/database failures may leave inaccessible orphaned bytes; no recovery subsystem is selected.

Use published `@antv/mcp-server-chart` **0.9.10**, running streamable HTTP at internal `http://charts:1122/mcp`. GitHub main advertises 0.9.11, but that version was not published to npm when probed. The container has no host ports, host Docker socket, database or private-file mount. Its log driver is disabled because upstream errors can contain inputs. Use Symfony AI `McpToolbox`, `ClientToolset`, `ChainToolbox` and the official PHP `mcp/sdk` client, with explicit untraced PSR-18 HTTP and native null loggers. The MCP bundle's automatically wired logger records raw RPC arguments; a bundle plus custom logger/container overrides is unnecessary here. Discover the server's complete catalog rather than selecting a restricted catalog or visualization skill.

Validate arguments against the discovered JSON Schema (Opis, already used by the SDK), rejecting undeclared top-level options. Bound each generation to four chart calls, 16 KiB arguments per call, 100 items per collection, depth eight, 200 characters per string, and requested sides of 100–1600 pixels. Accept only plain labels without links, markup or control characters. These are chart-resource bounds, not the deferred account/AI quota subsystem. Keep the newer ADR 0025 approximate SQLite calculation semantics; this task does not introduce exact SQL arithmetic.

Accept only HTTPS image references matching the observed `mdn.alipayobjects.com/one_clip/afts/img/…/original` destination, with no credentials, queries or fragments. Download with Symfony's `NoPrivateNetworkHttpClient`, no redirects, bounded time and streaming byte count (2 MiB). Verify PNG/JPEG magic and dimensions before storing; reject other content, including SVG. The hosted renderer produced 1800×1200 pixels for a requested 600×400 image, so bound the returned sides separately at 4800 pixels. Only locally generated opaque filenames enter the image route/template; model Markdown images remain forbidden by the existing sanitizer. The third-party service may still retain publicly reachable copies; a local private copy does not retract external disclosure.

Verification evidence is task-specific, not an architecture guarantee: a synthetic 40/60 pie was generated through the actual container/SDK/toolbox and copied/read/deleted privately (27 discovered tools; 66,456-byte PNG, 1800×1200). Browser, ownership, unsafe-input and failure verification must still be reported separately; this probe does not prove those paths.

### Unchanged boundaries and open details

- Preserve the Symfony AI-first cooperative worker architecture in [ADR 0013](0013-symfony-ai-agent-runtime.md) and private Mercure delivery in [ADR 0005](0005-mercure-streaming.md).
- Preserve the one-active-generation-per-user limit shared by chat and extraction, bounded global concurrency, and budgets from [ADR 0011](0011-layered-rate-limiting.md).
- Keep provider scope and the absence of selected automatic cross-provider failover from [ADR 0012](0012-configurable-ai-providers.md). Follow ADR 0013's simple context handoff, selected for late implementation without an advanced compactor.
- No restricted model-visible tool catalog, runtime skill, arbitrary SQL MCP access, websearch MCP, or new backend API/settings contract is selected. Those are not implied by choosing the chart server.
- Application visualization MCP is separate from the selected Mate development tooling.

## Alternatives considered

- A two-panel workspace and separate artifact-management subsystem add scope that the owner has explicitly removed.
- A mandatory private renderer would add setup work contrary to the accepted simpler hosted-rendering path.

## Consequences

- This decision replaces earlier layout and separate-artifact requirements, not the otherwise-valid stack, streaming, asset-pipeline, or agent-runtime decisions.
- Verify actual MCP/worker/Mercure chart delivery, safe inline rendering, owned chat-output access, reload behavior, and hosted failures through the headed browser workflow in [TESTING.md](../../TESTING.md). External-service tests must use synthetic data only.
- The original documentation decision installed or validated no runtime integration. The task 13 implementation probe is distinguished above from outstanding browser verification.

## References

- [AntV mcp-server-chart: private deployment](https://github.com/antvis/mcp-server-chart#-private-deployment), documenting its free default hosted renderer and `VIS_REQUEST_SERVER` override.

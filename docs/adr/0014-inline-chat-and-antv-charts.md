# ADR 0014: Display AntV charts inline in chat using hosted rendering

- Status: Accepted
- Date: 2026-10-29

## Context

The project owner selected a simpler visualization path: an AntV MCP server in Docker calls its documented free hosted renderer, and the resulting chart image appears in chat. This replaces the earlier two-panel workspace and separate artifact-management proposal.

## Decision

### Chat output, not an artifact subsystem

Use one chat containing text, tables, and inline chart images. Do not build a separate artifact workspace, gallery, artifact entities, or editor. Do not introduce a custom JavaScript chart renderer, MCP Apps host, or model-generated executable JavaScript.

Keep existing ownership checks, safe text/table rendering, and the prohibition on sensitive logging. Treat model/tool output and remote image references as untrusted; validate references before fetching or displaying them. Application access to saved chat output remains ownership-scoped; external image URLs are not guaranteed private.

Retain the existing durable conversation-message and generation-state requirements independently of Mercure delivery. Represent chart output as part of chat, not separate managed artifacts. Exact image/reference persistence and display mechanics remain open. [ADR 0015](0015-sqlite-conversation-history.md) subsequently selects shared application SQLite persistence with five temporary chats per user plus saved chats, protecting queued/running work. It does not select a cache, image storage schema, or route, or guarantee remote chart-image availability.

### Docker MCP server and remote renderer

Use AntV `mcp-server-chart` as a separate Docker service. Reuse Symfony AI MCP facilities and the official PHP MCP SDK where compatible. Exact packages, APIs, connection transport, container configuration, and versions remain bootstrap choices; no per-chat stdio lifecycle is selected.

The MCP server calls AntV's documented default hosted renderer at `https://antv-studio.alipay.com/api/gpt-vis`. The upstream README describes this rendering service as free and documents `VIS_REQUEST_SERVER` as an override. Initially use that hosted path; a private-renderer container is not required.

The Docker MCP server and hosted renderer are different components. No completely hosted MCP endpoint has been selected or verified. Node in the separate MCP service does not embed Node in PHP or introduce a Node/npm browser asset build. [ADR 0006](0006-assetmapper-and-symfony-ux.md) remains Accepted.

### Accepted data-transfer trade-off

Aggregated chart labels and values leave the installation for rendering. Make that clear to users: choosing a local chat provider does not make chart rendering local. The owner accepts this trade-off. Do not send credentials or log raw financial data for convenience.

The upstream description of a free service is not a guarantee of permanent pricing, unlimited use, availability, retention, or private external image URLs. Hosted-renderer and image failures must be handled visibly in chat without discarding existing saved conversation state. Exact failure/retry and image lifecycle behavior remains to be defined.

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
- No application code, developer or runtime skill, dependency, container, provider call, or chart rendering has been installed, run, or validated by this documentation decision.

## References

- [AntV mcp-server-chart: private deployment](https://github.com/antvis/mcp-server-chart#-private-deployment), documenting its free default hosted renderer and `VIS_REQUEST_SERVER` override.

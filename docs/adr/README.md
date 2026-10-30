# Architecture decision records

ADRs record significant technical decisions, their context, and their consequences. An accepted decision describes the intended direction; it does not mean the decision has been implemented or validated.

## Decisions

| ADR | Status | Decision |
|---|---|---|
| [0001](0001-application-stack.md) | Accepted | Use Symfony 8.2, Symfony AI 1.0 beta, and EasyAdmin. |
| [0002](0002-project-foundation.md) | Accepted | Adapt selected parts of the existing projects without importing their full setup. |
| [0003](0003-shared-sqlite-database.md) | Accepted | Use a shared SQLite application database with centralized ownership enforcement. |
| [0004](0004-sqlite-queue.md) | Accepted | Use sqlite-queue through Symfony Messenger with separate broker storage. |
| [0005](0005-mercure-streaming.md) | Accepted | Deliver worker-generated streamed responses through private Mercure subscriptions. |
| [0006](0006-assetmapper-and-symfony-ux.md) | Accepted | Use AssetMapper and Symfony UX without Encore or a mandatory Node.js toolchain. |
| [0007](0007-structured-monolog-logging.md) | Accepted | Use structured, correlated Monolog logs without raw financial or model payloads. |
| [0008](0008-mate-development-tools.md) | Accepted | Use Mate with the official Symfony and Monolog extensions for development diagnostics. |
| [0009](0009-castor-and-static-checks.md) | Accepted | Use Castor with concise reports, PHPStan strict rules, and mandatory Symfony LSP checks. |
| [0010](0010-playwright-only-testing.md) | Accepted | Test application behavior only through Playwright, using visible browsers and agent-operated verification. |
| [0011](0011-layered-rate-limiting.md) | Accepted | Apply per-user rate limits, coarse IP protection, upload quotas, and persistent shared AI budgets. |
| [0012](0012-configurable-ai-providers.md) | Accepted | Configure local llama.cpp, z.ai, and DeepSeek integrations with chat provider, model, and reasoning selection. |
| [0013](0013-symfony-ai-agent-runtime.md) | Accepted | Use Symfony AI's agent loop with bounded cross-user fiber concurrency in one Messenger consumer and a simple context handoff for late implementation. |
| [0014](0014-inline-chat-and-antv-charts.md) | Accepted | Display chart images inline in chat using a Docker-hosted AntV MCP server and its documented free hosted renderer; remove the separate artifact workspace. |
| [0015](0015-sqlite-conversation-history.md) | Accepted | Store conversations in the shared application SQLite database, with five temporary chats per user plus saved chats, protecting queued/running work. |
| [0016](0016-local-markitdown-conversion.md) | Accepted | Convert PDF/XLSX locally with official MarkItDown MCP in Docker; parse CSV in PHP, leaving OCR backend selection unresolved. |
| [0017](0017-exact-money-and-ecb-reporting.md) | Accepted | Preserve exact decimal money and present one configurable reporting currency using ECB reference rates. |
| [0018](0018-finance-review-sql-and-jev.md) | Accepted | Approve editable drafts before analysis; use bounded owner-scoped SQL, Jev decision tasks, and isolated target-model history conversion. |
| [0019](0019-worktree-development-routing.md) | Accepted | Route checkout-scoped development and live-browser stacks through a shared loopback Caddy proxy. |

## Recording decisions

Create an ADR when a choice affects architecture, data integrity, security, deployment, or the development workflow enough that future contributors need its rationale.

Use the next sequential number and a descriptive filename. Include these sections:

- Status and decision date.
- Context: the requirement or constraint behind the choice.
- Decision: what was agreed and its boundaries.
- Alternatives considered: options actually discussed.
- Consequences: benefits, costs, risks, and unresolved details.

Use `Proposed` for choices awaiting agreement and `Accepted` for agreed choices. When replacing an accepted decision, add a new ADR, mark the old one `Superseded`, and link the records in both directions. Preserve the old rationale.

Keep product requirements in [SPEC.md](../../SPEC.md). Record concrete dependency resolutions in Composer configuration and the lockfile when the application is bootstrapped; do not maintain every package update as an ADR.

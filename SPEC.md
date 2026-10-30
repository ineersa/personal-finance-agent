# Personal finance assistant

## Project goal

Build a personal finance demo for the Symfony AI hackathon. Users upload bank statements, review the extracted financial data, and ask questions about their finances through a chat interface.

Judges must be able to install and run the demo locally. Prioritize a small, working MVP and fast development while keeping code quality high.

This document defines the product requirements. Technical decisions and their rationale are recorded in the [architecture decision records](docs/adr/README.md).

## Core workflow

1. The user logs in.
2. The user uploads bank statements.
3. The application prepares an editable draft of extracted transactions and associated information, including categories and sources.
4. The user edits/approves the draft and manages sources; only approved financial records feed analysis.
5. The user asks financial questions in the main chat interface.
6. The assistant presents text, tables, and chart images inline in chat.

## Statement inputs and extraction

Accept PDF, XLSX, and CSV statement inputs. Convert digital PDF/XLSX locally in Docker using Microsoft's official MarkItDown MCP server, then prepare editable drafts for validation and user approval before financial analysis. Parse CSV in PHP. Conversion does not itself validate financial records or guarantee complex-table fidelity.

Extract transaction rows from MarkItDown Markdown/text using a configured llama.cpp, z.ai, or DeepSeek LLM through Symfony AI structured output. Validate rows into editable drafts, using Jev separately for categorization/decision tasks during preparation/review. Jev is not the arbitrary-field extractor. Only user-approved valid records feed analysis; CSV parsing does not require an LLM.

Configure extraction provider/model independently of chat through settings/environment, using only enabled/configured allowed integrations and server-side secrets. Validate and capture the selected choices for queued work. Provider schema/JSON-mode support varies, and exact pinned bridge compatibility remains unverified; validate output and preserve decimal-string money, not JSON floats. Existing data-transfer disclosures, shared quotas, one-active-generation-per-user protection, and no automatic cross-provider failover remain. Advanced reconciliation, overlapping-statement matching, and automatic deduplication are not MVP requirements; message-redelivery idempotency remains required. [ADR 0018](docs/adr/0018-finance-review-sql-and-jev.md) records these boundaries.

Scanned/image-only PDF support and an existing OCR backend remain unresolved; ordinary conversion is not proof of automatic/free OCR. Do not build a custom OCR engine/pipeline. Subsequent AI extraction follows the configured provider's data-transfer policy. [ADR 0016](docs/adr/0016-local-markitdown-conversion.md) records the local conversion boundary.

## Finance semantics and reporting

A source is a bank account, card, or other financial account instrument, not a merchant. Keep one transaction date exactly from the statement, original amount/currency, credit/debit direction, and category. Direction alone does not distinguish income, refunds, or own-account transfers; no complex accounting or inferred tax policy is selected.

Store editable drafts and approved records in one transaction table with an `isDraft` flag. Approval must validate records before clearing that flag; only valid non-draft records feed analysis. Categories are shared reference data with names, descriptions and icons, not per-user category CRUD. Sources have names and optional descriptions, without AI instructions at this stage.

Show finances in one user-configurable reporting currency stored on User, USD by default, limited to ECB feed currencies with EUR as the implicit base. Use transaction-date reference rates where available, initially aiming for roughly three years of history without a fixed retention threshold. Preserve original values; reporting conversion is not the bank's actual FX/fees. Use decimal strings and BCMath, with SQLite-safe storage/aggregation and rounding/missing-rate behavior still to verify. See [ADR 0017](docs/adr/0017-exact-money-and-ecb-reporting.md).

Provide a model-written read-only SQL analysis tool over limited, owner-scoped finance tables with externally enforced access restrictions and bounded execution/output. Supply schema/field semantics in prompts, but never rely on prompts or ORM filters to secure arbitrary SQL. Money calculations must preserve precision, not rely on model arithmetic. SQL MCP and unrestricted database access are not selected.

## Main interface

Use a single chat containing text, tables, and inline chart images. There is no separate artifact workspace, gallery, editor, or generic artifact-management subsystem.

Use a Docker-hosted AntV `mcp-server-chart` service with its documented free default hosted renderer. Aggregated chart labels and values leave the installation even when the chat model runs locally. [ADR 0014](docs/adr/0014-inline-chat-and-antv-charts.md) records this accepted trade-off and its boundaries.

The chat is the primary entry point for financial questions. The review workflow lets users correct the data on which those answers depend.

Chat must offer provider, model, and reasoning selection. Initially support local llama.cpp, z.ai, and DeepSeek integrations. Show only providers enabled and configured for the installation, allowed models, and reasoning options supported by each model. Keep provider credentials server-side. [ADR 0012](docs/adr/0012-configurable-ai-providers.md) records configuration and queued-generation boundaries.

ChatGPT sign-in is optional exploratory work, not an MVP goal or prerequisite. It must not block the three selected provider integrations.

### Conversation history

Store conversations and messages in the existing shared application SQLite database, available to the web application and workers. Conversations are temporary by default, with a **Save chat** button. Keep five temporary chats per user, plus saved chats outside that five-chat count.

When creating a sixth temporary chat, remove the owner's oldest inactive temporary chat. Never prune saved chats or chats with queued/running work. If no eligible victim exists, do not delete protected work or silently exceed five; safe admission details remain open. Coordinate save, creation, work admission, and pruning atomically. All retained conversations are reloadable by their owner.

Saved protects against temporary-chat pruning, not all storage limits or separately authorized demo/account lifecycle operations. The five-chat count does not change the shared one-active-generation-per-user limit or promise unlimited model context or remote chart-image availability. [ADR 0015](docs/adr/0015-sqlite-conversation-history.md) records the persistence and retention boundaries.

For late implementation, use the simple context handoff in [ADR 0013](docs/adr/0013-symfony-ai-agent-runtime.md): watch context size with summary headroom, summarize with the same configured model and tools disabled at a complete tool-call/result boundary, then use application instructions, the handoff, and recent complete messages. Preserve canonical history. The summary is not security instructions or authoritative financial data; query financial facts again. Existing generation limits and budgets include summarization. This is not an advanced compactor, low-memory guarantee, or unlimited-context promise.

## Example questions

- "Show me how much I spent on subscriptions last month."
- "Show me my income growth over the past year."
- "Show me my internet bills over the past year."

## MVP scope

- User login.
- Bank statement upload and transaction extraction.
- Transaction categorization and source identification.
- Review and editing of parsed transactions.
- Creation and editing of sources.
- Financial questions through chat.
- Chat provider, model, and supported reasoning selection.
- Reloadable temporary conversations and a Save chat button, with five temporary chats per user plus saved chats.
- Streamed assistant responses while generation is in progress.
- Answers presented as text, tables, and inline chart images in chat.
- A locally installable demo for judges.

## User roles

- Users manage their own financial data, statement uploads, reviews, and conversations. They must not access another user's personal data.
- Administrators can create, view, edit, and delete user accounts and perform authorized demo-reset operations. Reset scope and safeguards remain to be defined.

## Demo proposal

Provide synthetic financial fixtures and a demo user so judges can explore the application without uploading personal bank statements.

The fixtures should cover enough history and transaction types to demonstrate the example questions, including subscriptions, income, and internet bills.

Support restoring the demo to a known state. A manual reset and a scheduled reset were proposed; the mechanism and schedule remain undecided. Any reset must be limited to demo data and clearly communicate that demo edits will be lost.

## Development priorities

- Deliver a working demonstration before expanding the scope.
- Keep financial calculations and data handling testable.
- Make local setup and the demo walkthrough reproducible.
- Use synthetic data in fixtures and automated tests.
- Keep planned behavior distinct from implemented behavior.

Leave recovery hardening, final verification, and judge-installation polish until near the end. Do not build a broad reliability framework now; basic failure states, idempotency, short transactions, security, and resource bounds still apply. Final verification remains headed Playwright. Cassettes/replay or documented inference setup may be fallback options, but no specific tool or provider is selected and replay must not be claimed as live integration. Persisted history reconstructs context for a new attempt, not an exact in-flight provider execution.

## Resource restrictions

Limit AI work and statement uploads primarily per authenticated user, with coarse IP-based abuse protection and login throttling. Chat and AI-powered statement extraction share the user's AI allowance and an instance-wide daily AI budget. Bound active and pending work, file sizes, daily uploaded bytes, and retained storage.

Concurrent agent execution is across users. The initial one-active-generation-per-user limit remains shared by chat and extraction, with global concurrency bounded.

Explain refused actions and when retrying is possible. Rejected requests must not enqueue work; users must still be able to read existing financial data and conversations. Normal cache clearing and financial demo resets must preserve quota and AI-budget accounting. Configurable initial limits and implementation boundaries are recorded in [ADR 0011](docs/adr/0011-layered-rate-limiting.md).

## Technical decisions

- [Application stack](docs/adr/0001-application-stack.md).
- [Selective reuse of the existing projects](docs/adr/0002-project-foundation.md).
- [Shared SQLite application database](docs/adr/0003-shared-sqlite-database.md).
- [sqlite-queue with Symfony Messenger](docs/adr/0004-sqlite-queue.md).
- [Mercure streaming](docs/adr/0005-mercure-streaming.md).
- [AssetMapper and Symfony UX without Encore](docs/adr/0006-assetmapper-and-symfony-ux.md).
- [Structured Monolog logging](docs/adr/0007-structured-monolog-logging.md).
- [Mate development diagnostics](docs/adr/0008-mate-development-tools.md).
- [Castor and final static checks](docs/adr/0009-castor-and-static-checks.md).
- [Playwright-only browser testing](docs/adr/0010-playwright-only-testing.md), with the verification workflow in [TESTING.md](TESTING.md).
- [Layered rate limits and persistent AI budgets](docs/adr/0011-layered-rate-limiting.md).
- [Configurable AI providers and chat selection](docs/adr/0012-configurable-ai-providers.md).
- [Symfony AI-first agent runtime with concurrent execution](docs/adr/0013-symfony-ai-agent-runtime.md).
- [Inline chat and AntV charts with hosted rendering](docs/adr/0014-inline-chat-and-antv-charts.md).
- [SQLite conversation history and temporary/saved retention](docs/adr/0015-sqlite-conversation-history.md).
- [Local MarkItDown MCP document conversion](docs/adr/0016-local-markitdown-conversion.md).
- [Exact money and ECB reporting](docs/adr/0017-exact-money-and-ecb-reporting.md).
- [Draft review, bounded SQL, Jev decisions, and history conversion](docs/adr/0018-finance-review-sql-and-jev.md).

## Open decisions

- Supporting technologies, exact dependency releases within the accepted stack, and deployment setup.
- Private storage for uploaded statements and file-lifecycle handling.
- Safe inline rendering and chart data contracts, image/reference persistence, reload behavior, and hosted-renderer failure handling.
- Supported banks/layouts and extraction mappings within PDF/XLSX/CSV, including complex tables, scanned/image-only PDF handling, and explicit OCR backend selection/configuration/verification.
- Exact MarkItDown transport, versions, parser dependencies/extras, staging/cleanup integration, and compatible MCP APIs.
- Category vocabulary/Jev decision mappings and exact LLM extraction schema, provider/model configuration, and pinned structured-output bridge compatibility.
- SQL owner scoping, read-only/table enforcement, bounds, and precision-preserving aggregation; exact target-model history conversion compatibility and generation-local integration.
- Exact model IDs, supported reasoning options, enabled/default providers and models per installation, credentials, and network requirements for the selected integrations.
- Provider configuration schema, exact integration packages, and compatible APIs.
- Simple context-handoff trigger, token estimates, recent complete-message window, and pinned Symfony AI hooks. The approach is selected for late implementation; advanced compaction and memory engineering are not selected.
- Exact AntV MCP packages, APIs, connection transport, and container configuration. No restricted tool catalog, runtime skill, SQL MCP access, or websearch MCP has been selected. Application chart MCP remains separate from Mate development tooling.
- Exact conversation-storage adapters/APIs and atomic retention/admission mechanics, including safe behavior when no inactive temporary conversation can be pruned. Broader session-management features are not selected.
- SQLite-safe decimal-string mapping and precision-preserving SQL strategy, rounding/scale, missing ECB dates/weekends, and exact history window/cache/import/backfill mechanics.
- How answers reference the transactions used to produce them.
- Whether the assistant can propose or apply edits, or only analyze reviewed data.
- Manual versus scheduled demo reset and its safeguards.
- Resource-policy thresholds not fixed in ADR 0011, including daily byte/storage quotas, shared AI-budget amounts, global capacity, coarse IP limits, and the daily reset boundary.

## Planned repository documentation

Development uses the external task board and the project task workflow described in [AGENTS.md](AGENTS.md#task-workflow). Keep task status and execution tracking there. Reserve `docs/plans/` for descriptions of specific functionality, not a separate backlog or project-wide development plan.

The agreed documentation layout is:

```text
AGENTS.md
README.md
SPEC.md
ARCHITECTURE.md
TESTING.md
docs/
  adr/
    README.md
    0001-application-stack.md
    0002-project-foundation.md
    0003-shared-sqlite-database.md
    0004-sqlite-queue.md
    0005-mercure-streaming.md
    0006-assetmapper-and-symfony-ux.md
    0007-structured-monolog-logging.md
    0008-mate-development-tools.md
    0009-castor-and-static-checks.md
    0010-playwright-only-testing.md
    0011-layered-rate-limiting.md
    0012-configurable-ai-providers.md
    0013-symfony-ai-agent-runtime.md
    0014-inline-chat-and-antv-charts.md
    0015-sqlite-conversation-history.md
    0016-local-markitdown-conversion.md
    0017-exact-money-and-ecb-reporting.md
    0018-finance-review-sql-and-jev.md
  plans/
.agents/
  skills/
```

This specification, `README.md`, `AGENTS.md`, `TESTING.md`, the ADRs listed above, and the [project-local UX skills](.agents/skills/README.md) exist. The other planned documents and application setup remain future work. Add ADRs for financial-data history when those decisions are made.

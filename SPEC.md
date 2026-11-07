# Personal finance assistant

## Project goal

Build a personal finance demo for the Symfony AI hackathon. Users upload bank statements, review the extracted financial data, and ask questions about their finances through a chat interface.

Judges must be able to install and run the demo locally. Prioritize a small, working MVP and fast development while keeping code quality high.

This document defines the product requirements. Technical decisions and their rationale are recorded in the [architecture decision records](docs/adr/README.md).

## Core workflow

1. The user logs in.
2. The user uploads bank statements.
3. The application prepares an editable draft of extracted transactions and associated information, including categories and sources.
4. The user edits/approves the draft and manages sources and categories; ordinary analysis uses approved records unless the user asks about drafts.
5. The user asks financial questions in the main chat interface.
6. The assistant presents text, tables, and interactive charts inline in chat.

## Statement inputs and extraction

Accept PDF, XLSX, and CSV statement inputs. Convert digital PDF/XLSX locally in Docker using Microsoft's official MarkItDown MCP server, then prepare editable drafts for validation and user approval before financial analysis. Parse CSV in PHP. Conversion does not itself validate financial records or guarantee complex-table fidelity.

Preserve an explicit statement date. If it is missing, use the earliest valid imported transaction date, regardless of row order; leave it unknown when no valid transaction dates are available. Do not infer it from the filename or relax parser validation.

Extract transaction rows from MarkItDown Markdown/text using a configured llama.cpp, z.ai, or DeepSeek LLM through Symfony AI structured output. Validate rows into editable drafts, using Jev separately for categorization/decision tasks during preparation/review. Jev is not the arbitrary-field extractor. Ordinary analysis uses user-approved valid records, with draft analysis available on explicit request; CSV parsing does not require a generative LLM. Jev may interpret column roles from representative complete rows with explicit role descriptions and presence/support questions; PHP parses and validates exact values. Jev separately judges transaction meaning, recurrence and category, retaining unresolved outcomes when evidence is insufficient.

Configure extraction provider/model independently of chat through settings/environment, using only enabled/configured allowed integrations and server-side secrets. Validate and capture the selected choices for queued work. Provider schema/JSON-mode support varies, and exact pinned bridge compatibility remains unverified; validate output and preserve decimal-string money, not JSON floats. Existing data-transfer disclosures and no automatic cross-provider failover remain. Upload/AI quotas beyond the 10 MiB file limit are deferred by [ADR 0021](docs/adr/0021-fixtures-first-jev-import.md). Advanced reconciliation, overlapping-statement matching, and automatic deduplication are not MVP requirements; message-redelivery idempotency remains required. [ADR 0018](docs/adr/0018-finance-review-sql-and-jev.md) records these boundaries.

Scanned/image-only PDF support and an existing OCR backend remain unresolved; ordinary conversion is not proof of automatic/free OCR. Do not build a custom OCR engine/pipeline. Subsequent AI extraction follows the configured provider's data-transfer policy. [ADR 0016](docs/adr/0016-local-markitdown-conversion.md) records the local conversion boundary.

While an ordinary user's workspace is open, update the owned processing view through private Mercure and native Symfony UX Turbo. Do not show global import completion/failure toasts, including after navigation elsewhere. Persisted statement status remains authoritative after closing or reopening the browser. Ordinary action/error flash feedback remains available; no notification inbox or operating-system notifications are required.

For repeated local demo imports, Settings exposes a database-wide reset without an environment opt-in flag. It is available only in dev/test to the canonical demo operator, requires confirmation and CSRF validation, restores the canonical fixture accounts/history/categories/sources, removes uploaded statement files, and signs the operator out. Environment files and API keys remain untouched. Refuse reset when any user's import is observed queued/running; concurrent reset/import coordination is not guaranteed. Do not run reset concurrently with uploads or processing. Production must hide and deny this action. Normal development fixture loading remains append-only.

Owners may delete a statement through EasyAdmin's native confirmed delete action. Commit deletion of its record and linked approved/draft transactions before removing the private CSV; refuse observed queued/running imports. Enforce ownership server-side, including direct requests. Report storage failures honestly; orphaned private bytes are an accepted limitation, without a cleanup ledger or recovery mechanism.

## Finance semantics and reporting

A source is a bank account, card, or other financial account instrument, not a merchant. Keep one transaction date exactly from the statement, original amount/currency, credit/debit direction, and category. Direction alone does not distinguish income, refunds, or own-account transfers; no complex accounting or inferred tax policy is selected.

Confirmed own-account-transfer and credit-card-payment meanings exclude a row from both spending and income, independently of category or a matched counterpart. This must work for one-sided uploads, cross-currency transfers and different posting months. Unknown meaning remains unresolved, not automatically excluded. Do not achieve the exclusion merely by netting all debits and credits.

Store editable drafts and approved records in one transaction table with an `isDraft` flag. Approval must validate records before clearing that flag. Instruct the model to exclude drafts from ordinary analysis unless the user explicitly asks about them; drafts remain readable within the owner's database. This is prompt guidance, not a row-level security boundary. Categories have names, descriptions and icons and belong to the user's finance database, with ordinary-user CRUD. Seed the canonical 21-category catalog from `fixtures/category.yaml` independently for each user, without later catalog synchronization. Sources have names, optional descriptions and optional currency/instrument context, without AI instructions at this stage. Transaction meaning (expense, income, refund, own-account transfer, card payment or unknown) and recurrence (bill, subscription/membership, other recurring, non-recurring or unknown) are independent of category and direction. Unknown is the migration default; do not guess existing values. Refunds retain the underlying purchase category when identifiable, not income. No automatic transfer matching or subscription-management subsystem is selected.

Each draft transaction row has Edit and Delete actions using native EasyAdmin editing and confirmed deletion. One statement-level Approve draft button approves all remaining draft transactions from that statement, not individual rows. Validate every remaining row before promoting any; report field errors with links to the affected rows. Approval changes the existing rows atomically and retains their statement relationship. Saving edits does not approve a row. Repeated approval and import-message redelivery must not create duplicate transactions.

Show finances in one user-configurable reporting currency stored on User, USD by default, limited to ECB feed currencies with EUR as the implicit base. Use transaction-date reference rates, filling missing calendar dates with the previous available rate, initially aiming for roughly three years of history without a fixed retention threshold. Dates before a currency's first available rate remain unavailable. Preserve original values; reporting conversion is not the bank's actual FX/fees. Use decimal strings and BCMath and reuse existing money formatting for display. SQLite-safe aggregation and runtime conversion verification remain outstanding. See [ADR 0017](docs/adr/0017-exact-money-and-ecb-reporting.md).

Include public historical ECB rates in canonical demo fixtures. Refresh hourly with native Symfony Scheduler, normally requesting only the daily feed. Request recent 90-day history only to fill or correct missing/provisional recent dates before the latest publication. Carry-forward values after that publication stay provisional until reconciled; confirmed weekends and holidays must not cause repeated historical downloads. Do not download or rewrite the full historical dataset on each refresh.

Currency rates belong to each user's finance database and are maintained by canonical historical fixtures and the ECB refresh job, not manual CRUD. Fetch refresh data once and update all tenant databases. Preserve exact rate strings and uniqueness of each date/currency pair; EUR remains implicit.

Store a separate money locale on User, defaulting to `en_US`, selectable in Settings, for money display formatting. Changing it does not rewrite stored amounts or change the interface language. Detect CSV number formatting independently: bundle a format choice from labelled examples and an evidence question with the existing Jev column/header mapping request. Reject unresolved formats rather than guessing from the user's locale or currency. Validate the original amounts with native ICU and preserve exact decimal strings for storage. Parse original dates and datetimes with PHP's native parser, not a fixed list of formats or a Jev date-format question.

Keep identity, authentication and user settings in the central application database. Store authoritative sources, statements, transactions, categories and currency rates in a private SQLite database per ordinary user, without `user_id` or ORM associations to central users. Select it from trusted request/job identity and isolate connection/entity-manager lifetimes across users. Provision and migrate tenant databases, seed canonical reference data for new users, and handle database deletion with account deletion. Use a clean fixture-backed development cutover on verified disposable project dev/test databases, not a legacy converter or shared-finance fallback. Do not introduce reporting replicas, revisions, triggers or synchronization. [ADR 0023](docs/adr/0023-per-user-finance-databases.md) records the storage decision.

Provide a model-written SQL analysis tool through a separate read-only connection to the selected tenant database, including its drafts. Enforce cross-user isolation, external database/file-access restrictions and input/result bounds outside prompts; `PRAGMA query_only` alone is not a sandbox. Supply schema/field semantics and draft-exclusion guidance in prompts, but never rely on prompts or ORM filters for cross-user isolation. Execute queries directly in-process. There is no hard query deadline or per-query native-memory bound; result limits do not bound work or prevent a slow query from occupying the chat worker. For this slice use SQLite builtins only: monetary arithmetic, aggregation and FX may use floating point and are approximate, without an exact-total guarantee. Preserve canonical decimal-string storage and existing application BCMath conversion; model mental arithmetic is not verified calculation. SQL MCP and unrestricted process/host access are not selected. [ADR 0025](docs/adr/0025-in-process-tenant-sql.md) records this scoped precision trade-off.

## Main interface

Use the dark theme throughout the application and management screens. Do not offer a light or system-theme selector; saved appearance preferences and operating-system settings must not change the theme.

Use a single chat containing text, tables, and inline interactive charts. There is no separate artifact workspace, gallery, editor, or generic artifact-management subsystem.

Use a Docker-hosted Unovis MCP service to generate interactive HTML locally. Retain private documents with their conversation, served only to its owner inside script-enabled sandboxed iframes without same-origin privileges or external network access. Reload uses the saved document; deleting a conversation removes its copies. Existing saved PNG/JPG charts remain readable. Return tool errors to the model for correction and retry through native Symfony AI fault tolerance. Chart schemas, labels, titles, values and dimensions are validated by the tool, not application argument rules. The application controls only private output destinations/presentation and authenticated sandboxed delivery. [ADR 0026](docs/adr/0026-local-unovis-charts.md) supersedes the hosted AntV choice in ADR 0014.

The chat is the primary entry point for financial questions. The review workflow lets users correct the data on which those answers depend.

Financial answers establish their period, account scope, currency and metric, query current records for new analysis, and explain material coverage gaps. Use transaction magnitudes plus stored meaning: gross spending is expense magnitudes, refunds are a separate positive total, and net spending is gross spending minus refunds. For an unqualified spending question, headline gross purchases and distinguish refunds/net rather than silently calling net spending gross. Transfers/card settlements are neither spending nor income; unknown meanings remain unresolved. Use recurrence for subscription reports, not a category name, and do not infer contracts or renewals from observed charges.

Use the last complete calendar month for an unspecified spending period and name it; explicit periods take precedence. Value transactions on their own dates with the accepted ECB cross-rate direction, preserve missing/provisional coverage, and identify approximate SQL valuation. No recorded data is not proof of zero real-world spending. Compare equivalent periods and explain gross, refund and net movements separately. Income less net spending is recorded surplus/deficit, not an account balance or proven savings. Budgets and reductions may be user-supplied or explicitly proposed scenarios, not invented stored records.

Give a self-contained Markdown answer with the main finding, supporting figures and material limitations; a short factual answer need not become a report. Add a useful chart for composition, ranking, trends or comparisons, not mechanically for every answer. Keep chart metric/scope/currency consistent with its SQL evidence and labelled table. Charts attach after the full answer; no arbitrary embedded placement or live drilldown is promised. Behavioral rules are refreshed through the existing system prompt; schema and clearly labelled examples are initial reference context, not live evidence or new message roles. Do not rewrite saved conversations when installing examples. See the [synthetic reporting references](docs/examples/finance-reporting/README.md).

Chat uses **ChatGPT only**. Offer model and supported reasoning-level selectors in the composer using only GPT-6 and higher choices curated from Hatfield, without a provider selector or registry. Validate each combination server-side and capture it immutably in each queued generation; later UI or default-configuration changes must not retarget accepted work. Keep credentials server-side and extraction configuration independent. `CHAT_MODEL` and `CHAT_REASONING_EFFORT` supply the initial composer defaults (`gpt-6-luna` / `low`); navigation initializes a fresh composer from those defaults. [ADR 0030](docs/adr/0030-chatgpt-model-selection.md) narrows ADR 0012 for chat only and records request-time history compatibility.

Provide a local **Continue with ChatGPT** connection in Chat. This connection belongs to the signed-in Finance user; it does not replace application authentication. Gate chat until the package verifies a usable grant with ChatGPT plan-usage permission. Show connection or reconnect status truthfully and provide disconnect. No provider switching or automatic failover is offered.

Use the connected ChatGPT account and each turn's selected model/reasoning level, with private Mercure delivery from the Messenger worker. Symfony AI Agent owns generation, tool execution and completion; the application forwards its progress and persists its returned native messages without a second execution state machine, application locks or generation claims. Preserve native signed history server-side through Symfony AI serialization facilities. Display the final answer separately from a chronological agent work log containing native tool calls, provider-emitted commentary and readable reasoning summaries in their observed order. Tool entries start collapsed and expand to escaped arguments and bounded results/errors; keep expanded entries usable during live updates and retain the log on reload. Show only provider-supplied readable summaries, never hidden reasoning, signatures or raw provider payloads; summaries may be absent. Do not invent activity for older turns. Keep the system instructions editable at `prompts/chat.txt`. Bind the read-only finance tools to the trusted conversation owner. Supply concise system instructions with tool signatures/gotchas and one initial native user-role context message containing schema and financial semantics before the first actual question; retain that context in native history without displaying it as a user turn. The application runs locally, but relevant messages are sent to ChatGPT. [ADR 0024](docs/adr/0024-basic-chatgpt-worker-chat.md) records the original generation slice; ADRs 0026, 0029 and 0030 extend its charts, history and model selection.

Keep credentials in the package-managed server-side file store and short-lived OAuth attempts in private server-side files. These files contain plaintext secrets protected by OS permissions, not application-level encryption. Use the documented HTTP `127.0.0.1` callback on a checkout-managed high port, retain the Finance hostname, and bind the one-use callback attempt to the initiating application user. The completion page links back to the configured Finance Settings connection section without credentials, codes, identity details, remote assets, or an arbitrary return destination. Settings shows the connected account's verified display identity, usage management and disconnect; connected Chat keeps only a compact status and Manage link near its composer. Support one active account per Finance user: disconnect retains the current account registration for reconnecting to the same account. Use one committed public OAuth client ID and fixed installation UUID across all worktrees, users and accounts, without per-user overrides. Initialize fresh private stores with registration only, while retaining isolated per-user grants and per-attempt state, nonce and PKCE. Preserve existing matching grants unchanged; reject mismatched client registrations rather than rewriting them. This does not guarantee provider-side deduplication or remove existing provider entries. No account-switch action or multi-account manager is provided. Never record callback queries or provider token exchanges in diagnostics. [ADR 0022](docs/adr/0022-local-chatgpt-connection.md) records this local integration and its boundaries.

### Conversation history

Store all submitted conversations automatically in the shared application SQLite database through the supplied Symfony AI Doctrine message store. Native messages are the sole conversation history; link application ownership, label, favourite and private-chart metadata by native conversation ID. Do not persist a parallel readable transcript. No temporary status, retention count or automatic pruning remains. New chat opens an unstored draft; only its first submitted message creates owned metadata and queues native input. Favourite/Remove favourite replaces Save. For the native-store rewrite, the owner authorized discarding and recreating old chat-only storage, not copying or converting it. Financial records, credentials and uploads must remain untouched.

Show compact borderless compose-icon New chat navigation and a search icon beside the sidebar heading throughout the ordinary-user workspace, never for administrators. Show Favourites followed by Chats excluding favourites, five entries per independently paged group with native full-page Show more/Show latest links; keep an out-of-page selected entry visible and highlighted. Use direct title links and an accessible ellipsis menu offering Favourite/Remove favourite and Delete. No folders, projects, rename or archive.

Delete only an explicitly identified owned conversation, with native confirmation and a CSRF-protected POST. Remove its native history, application metadata and private charts; never delete financial records, credentials or uploads. Preserve selected conversation when deleting another; deleting the selected chat opens an unstored draft. There is no durable queued/running deletion safeguard or application busy flag; native concurrent-execution limitations are accepted.

Search opens a modal with autofocus input, owner-scoped native FTS results and bounded readable snippets. Escape closes it; selecting a result opens that chat and closes the modal through native navigation. Search only titles, questions and answers using native SQLite FTS syntax, never native/tool/reasoning/signature payloads. The canonical corpus contains five English real-model synthetic conversations, four favourites and one ordinary chat, without credentials or connection identity.

Rely on native Symfony AI message IDs, continuation, checkpoint persistence and conflict handling, and Messenger/transport delivery, keepalive and redelivery. Do not add claims, attempt fencing, durable execution state, a busy flag, leases, retry scheduling or a custom recovery engine. Send remains available; transient native progress is feedback, not admission control. No exactly-once execution, automatic checkpoint restart, already-completed-job suppression or active-deletion guarantee is promised. An interrupted operation may repeat under native delivery. Reload renders the canonical native messages, not an inferred execution state; incomplete streaming fragments and terminal errors are transient. Presentation uses native messages/content objects, not a parallel flattened transcript. Live updates affect the current response, tool card, chart or status rather than rebuilding the conversation on every delta; page/reconnect snapshots may render full native history. Keep database writes short and outside model calls. There is one ChatGPT account per Finance user: retained chats stay owned/readable while disconnected and continuation uses the current owner's credentials. See [ADR 0029](docs/adr/0029-native-chat-store.md), which supersedes the custom runtime in ADR 0027.

Automatic retention does not promise unlimited model context or external chart availability. AI admission quotas remain deferred by ADR 0021. ADR 0029 supersedes the earlier temporary retention and custom recovery policies. Missing native capabilities are upstream candidates, not permission to build a replacement subsystem.

For late implementation, use the simple context handoff in [ADR 0013](docs/adr/0013-symfony-ai-agent-runtime.md): watch context size with summary headroom, summarize with the same configured model and tools disabled at a complete tool-call/result boundary, then use application instructions, the handoff, and recent complete messages. Preserve canonical history. The summary is not security instructions or authoritative financial data; query financial facts again. Summarization is subject to provider context limits; application AI budgets are deferred by ADR 0021. This is not an advanced compactor, low-memory guarantee, or unlimited-context promise.

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
- ChatGPT model and supported reasoning-level selection.
- Automatically retained conversations, unstored New chat drafts, favourites and modal full-text search.
- Streamed assistant responses while generation is in progress.
- Answers presented as text, tables, and inline interactive charts in chat.
- A locally installable demo for judges.

## User roles

- Ordinary users manage their own financial data, categories, statement uploads, reviews, conversations, and reporting preferences. They must not access another user's personal data or user administration.
- Administrators manage user accounts, not personal finances, categories, rates, chat, or reporting preferences. They must not enter those user workflows even for administrator-owned records or when an account also carries `ROLE_USER`. Separately authorized demo-reset scope and safeguards are defined above.
- Anonymous `/` is a public landing with a Log in call to action. In dev/test it displays only the ordinary demo credentials, not administrative credentials. Login always enters the appropriate role workspace instead of restoring a previously saved target. Protected pages use native EasyAdmin routes and layout under `/home`: ordinary users enter Chat at `/home/chat` and manage reporting preferences at `/home/settings`, while administrators enter `/home/user`. Public landing and login keep the public shell.

## Demo proposal

Provide synthetic financial fixtures and a demo user so judges can explore the application without uploading personal bank statements.

The canonical demo user's reporting preference is CAD; the application-wide default for new users remains USD.

Prepare 24 fixed completed months (November 2024–October 2026) for CAD chequing, CAD credit and a USD account, using wholly invented identities, merchants, amounts and sequences. Reserve the latest month as one unprocessed upload file per source; process and review preceding history through the actual pipeline before exporting canonical Alice fixtures. Generated expected labels are evaluation targets, not Jev results. Historical exports replace the small owner-demo placeholder examples while preserving separate-owner security records and unresolved model judgments. Include subscriptions, recurring income, variable bills, refunds, transfers and ambiguous references.

Support restoring the demo to a known state. A manual reset and a scheduled reset were proposed; the mechanism and schedule remain undecided. Any reset must be limited to demo data and clearly communicate that demo edits will be lost.

## Development priorities

- Deliver a working demonstration before expanding the scope.
- Keep financial calculations and data handling testable.
- Make local setup and the demo walkthrough reproducible.
- Use synthetic data in fixtures and automated tests.
- Keep planned behavior distinct from implemented behavior.

Leave recovery hardening, final verification, and judge-installation polish until near the end. Do not build a broad reliability framework now; basic failure states, idempotency, short transactions, security, and resource bounds still apply. Final verification remains headed Playwright. Cassettes/replay or documented inference setup may be fallback options, but no specific tool or provider is selected and replay must not be claimed as live integration. Persisted history reconstructs context for a new attempt, not an exact in-flight provider execution.

## Resource restrictions

Limit uploaded files to 10 MiB each. Upload frequency, daily bytes, retained-storage quotas, shared AI submission limits, one-active-generation-per-user admission, pending/global capacity limits and instance AI-budget accounting are deferred to later work. Preserve existing login throttling, owner isolation, short writes, failure states and basic redelivery idempotency. Operators must supervise resource use until deferred controls exist. [ADR 0021](docs/adr/0021-fixtures-first-jev-import.md) supersedes ADR 0011 and earlier quota references.

## Technical decisions

- [Application stack](docs/adr/0001-application-stack.md).
- [Selective reuse of the existing projects](docs/adr/0002-project-foundation.md).
- [Per-user authoritative finance databases](docs/adr/0023-per-user-finance-databases.md), superseding [shared SQLite application storage](docs/adr/0003-shared-sqlite-database.md).
- [sqlite-queue with Symfony Messenger](docs/adr/0004-sqlite-queue.md).
- [Mercure streaming](docs/adr/0005-mercure-streaming.md).
- [AssetMapper and Symfony UX without Encore](docs/adr/0006-assetmapper-and-symfony-ux.md).
- [Structured Monolog logging](docs/adr/0007-structured-monolog-logging.md).
- [Mate development diagnostics](docs/adr/0008-mate-development-tools.md).
- [Castor and final static checks](docs/adr/0009-castor-and-static-checks.md).
- [Playwright-only browser testing](docs/adr/0010-playwright-only-testing.md), with the verification workflow in [TESTING.md](TESTING.md).
- [Fixtures-first Jev import and deferred quotas](docs/adr/0021-fixtures-first-jev-import.md), superseding [ADR 0011](docs/adr/0011-layered-rate-limiting.md).
- [Configurable AI providers and chat selection](docs/adr/0012-configurable-ai-providers.md).
- [Symfony AI-first agent runtime with concurrent execution](docs/adr/0013-symfony-ai-agent-runtime.md).
- [Local interactive Unovis charts](docs/adr/0026-local-unovis-charts.md), superseding the historical AntV decision.
- [SQLite conversation history and temporary/saved retention](docs/adr/0015-sqlite-conversation-history.md).
- [Local MarkItDown MCP document conversion](docs/adr/0016-local-markitdown-conversion.md).
- [Exact money and ECB reporting](docs/adr/0017-exact-money-and-ecb-reporting.md).
- [Draft review, bounded SQL, Jev decisions, and history conversion](docs/adr/0018-finance-review-sql-and-jev.md).

## Open decisions

- Supporting technologies, exact dependency releases within the accepted stack, and deployment setup.
- Private storage for uploaded statements and file-lifecycle handling.
- Supported banks/layouts and extraction mappings within PDF/XLSX/CSV, including complex tables, scanned/image-only PDF handling, and explicit OCR backend selection/configuration/verification.
- Exact MarkItDown transport, versions, parser dependencies/extras, staging/cleanup integration, and compatible MCP APIs.
- Jev decision wording/evaluation and exact LLM extraction schema, provider/model configuration, and pinned structured-output bridge compatibility.
- Tenant provisioning and migration fan-out, read-only/external-access enforcement, SQL bounds, and precision-preserving aggregation; exact target-model history conversion compatibility and generation-local integration.
- Exact model IDs, supported reasoning options, enabled/default providers and models per installation, credentials, and network requirements for the selected integrations.
- Provider configuration schema, exact integration packages, and compatible APIs.
- Simple context-handoff trigger, token estimates, recent complete-message window, and pinned Symfony AI hooks. The approach is selected for late implementation; advanced compaction and memory engineering are not selected.
- Native message persistence and Messenger delivery are selected in ADR 0029, without application checkpoint-restart or busy guarantees. Discard obsolete chat jobs when applying the chat-only recreation; never purge imports or unrelated queue work.
- SQLite-safe decimal-string mapping and precision-preserving SQL strategy, rounding/scale, missing ECB dates/weekends, and exact history window/cache/import/backfill mechanics.
- How answers reference the transactions used to produce them.
- Whether the assistant can propose or apply edits, or only analyze reviewed data.
- Manual versus scheduled demo reset and its safeguards.
- Deferred resource-policy design and thresholds beyond the 10 MiB upload limit.

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

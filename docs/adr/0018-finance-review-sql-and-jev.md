# ADR 0018: Review draft imports and bound SQL analysis and Jev decisions

- Status: Accepted
- Date: 2026-10-29

## Context

The MVP needs a small finance model, reviewable imports, flexible analysis, and AI-assisted categorization without inventing accounting rules or overstating Jev's extraction capabilities.

## Decision

### Minimal finance model and import workflow

Use one transaction date exactly as supplied by the statement, not separate booking/value dates. A source is a bank account, card, or other financial account instrument, not a merchant. Retain amount strings, currency, credit/debit direction, and category. The legacy Credit/Debit enum is not proof that a credit is income rather than a refund or own-account transfer. Do not introduce complex accounting or inferred tax policy.

Use upload → parsed editable draft → user approval/editing → approved financial records for analysis. Validation and ownership enforcement remain mandatory; confirmation cannot authorize invalid money or bypass security. No advanced reconciliation, overlapping-statement matching, or automatic deduplication subsystem is required for the MVP. Required message-redelivery idempotency remains unchanged.

### Model-written, bounded read-only SQL

Provide a model-written read-only SQL tool against a limited set of finance tables, with schema and field semantics supplied in prompts. A direct PHP tool fits this choice; SQL MCP is not selected. This is not restricted to specialized fixed repository queries.

Treat generated SQL as untrusted. Enforce owner-scoped rows, read-only execution, and table restrictions outside the prompt. Never expose other users' data, user/auth/token data, or system tables. ORM ownership filters do not protect arbitrary DBAL SQL, and a prompted `WHERE` clause is not an access boundary.

Bound rows, execution time, and output. Do not allow write SQL, unrestricted shared-database access, or generic file/URL/attach/host-access tools. Preserve the monetary precision contract in [ADR 0017](0017-exact-money-and-ecb-reporting.md); ordinary SQLite aggregation is not assumed exact. Actual scoping, SQL validation, read-connection enforcement, and precision-preserving query strategy remain implementation details, not a selected custom SQL parser or public tool/API contract.

### LLM row extraction and separate Jev decisions

Use a configured llama.cpp, z.ai, or DeepSeek LLM through Symfony AI structured-output facilities to extract transaction rows from MarkItDown Markdown/text. Validate the output into an editable draft. Use Jev for categorization and decision tasks within draft preparation/review, not arbitrary transaction-field extraction. User review/approval remains required before analysis; this does not prescribe a rigid ordering of editing versus categorization. CSV remains PHP-parsed, without a mandatory LLM extraction step. Local conversion and unresolved OCR remain governed by [ADR 0016](0016-local-markitdown-conversion.md).

Configure the extraction provider/model through settings or environment independently of chat selection. Use only enabled, configured, allowed llama.cpp/z.ai/DeepSeek integrations, validate the combination server-side, and capture those choices for queued extraction work. Keep credentials server-side and preserve data-transfer disclosures, shared AI quotas/accounting, and the one-active-generation-per-user rule. No automatic cross-provider failover, fourth extraction provider, default model, public settings UI, or environment-variable names are selected.

Structured output is untrusted: validate complete JSON, row fields, dates, currencies, and monetary decimal strings, not JSON floats, under [ADR 0017](0017-exact-money-and-ecb-reporting.md). Invalid or truncated output must not become approved financial records. Exact DTO/schema, pinned bridge APIs, and runtime compatibility remain implementation details to verify.

Use Symfony Serializer to decode the JSON and denormalize it into extraction DTOs, then validate those DTOs with Symfony Validator. On decoding, denormalization, or validation failure, return concise errors with field paths where available to the same configured model and request a corrected complete response. Keep the original statement in context and instruct the model not to invent missing information. Bound correction attempts; the exact retry count remains an implementation detail. If attempts are exhausted, report extraction failure rather than accept invalid data. Successful validation produces an editable draft, not automatic approval.

Provider support differs: llama.cpp documents JSON/schema-constrained `response_format`, subject to build/template/model compatibility. z.ai documents `json_object` with schema instructions and client validation, not universal strict native JSON Schema. DeepSeek documents `json_object` JSON mode with prompt/token caveats and possible empty output, and separately `text.format`/`json_schema` on its Responses API. Use native schema constraints or JSON mode plus application validation as appropriate; endpoint/model/bridge compatibility is not established by this decision.

Jev's Choice/Score/Noul primitives remain for categorization/decision tasks, separate from LLM row extraction, and are not binary PDF OCR. Keep Jev credentials server-side, enforce existing shared AI limits/accounting, and disclose applicable personal-description data transfers. An implementation skill may be added later; no Jev skill installation or mandatory runtime plugin is selected here.

### Target-model history conversion

Selectively adapt the existing read-only Hatfield history conversion from:

- `/home/ineersa/projects/agent-core/src/AgentCore/Infrastructure/SymfonyAi/ConversationHistoryConversion.php`.
- `/home/ineersa/projects/agent-core/src/AgentCore/Infrastructure/SymfonyAi/AgentMessageConverter.php`.

Convert at request time for the target model while preserving canonical stored history. Remap tool IDs and handle native reasoning signatures appropriately. Adapt to pinned Symfony AI APIs and generation-local isolation: the reference converter's mutable active message bag must not be copied as shared state across concurrent fibers.

Do not import Hatfield runtime/session/catalog machinery or tests. The simple context handoff selected for late implementation in [ADR 0013](0013-symfony-ai-agent-runtime.md) does not import Hatfield compaction machinery or alter canonical history. Persisted history can reconstruct context for a new attempt; it cannot resume an exact in-flight provider execution.

## Alternatives considered

- Treating debit/credit as a complete accounting classification would infer semantics absent from the statement.
- Prompt-only SQL restrictions would not enforce ownership or read-only access.
- Using Jev decision primitives as arbitrary statement-field extraction would conflate categorization with the separately selected LLM row-extraction step.

## Consequences

- Exact extraction schema/bridge compatibility, SQL safeguards/precision strategy, and target-model conversion compatibility require implementation verification. No tool names, views, routes, public settings UI, or environment-variable names are selected.
- Existing basic failure states, idempotency, short transactions, security/resource bounds, chat retention, and inline charts remain required. Do not create a broader reliability framework as part of this decision.
- No application code, provider integration, SQL tool, runtime skill, or history converter is implemented or validated by this documentation decision.

## References

- [TypeSafe primitives](https://docs.typesafe.ai/primitives).
- [TypeSafe models](https://docs.typesafe.ai/models).
- [llama.cpp server response formats](https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md).
- [z.ai structured output](https://docs.z.ai/guides/capabilities/struct-output).
- [DeepSeek JSON mode](https://api-docs.deepseek.com/guides/json_mode).
- [DeepSeek Responses API](https://api-docs.deepseek.com/api/create-response).
- Inspected Symfony AI reference: `/home/ineersa/projects/ai/src/platform/src/Bridge/TypeSafe/Evaluation.php` and adjacent `Question` classes.
- Legacy read-only reference: `/var/www/finance/src/Enum/TransactionTypeEnum.php` and its Source/Transaction entities.

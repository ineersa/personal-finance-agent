# ADR 0025: Execute read-only tenant SQL in-process

- Status: Accepted
- Date: 2026-11-04
- Amends: [ADR 0017](0017-exact-money-and-ecb-reporting.md) and [ADR 0023](0023-per-user-finance-databases.md)

## Context

The owner selected flexible SQL with SQLite builtins only, rather than custom BCMath SQL functions or record selection for application-side aggregation. Monetary SQL results are approximate; canonical storage and the application's CurrencyConverter remain unchanged.

The original implementation supervised a short-lived PHP subprocess to enforce a query deadline. During PR review the owner explicitly replaced that design with direct in-process execution to reduce complexity. PHP 8.5's SQLite3 extension exposes authorizers but not SQLite's progress handler or interrupt API. A lock-wait timeout or result limit cannot cancel expensive query execution.

## Decision

Register `TenantSqlTool` as an ordinary autowired service with one native `#[AsTool]` definition. Symfony AI derives its JSON argument schema from `__invoke(string $sql)` and its parameter documentation. The tool description, prompt line and guidelines live beside that definition. `AgentFactory` substitutes the prompt line and guidelines into `prompts/chat.txt`.

Before each generation, `AgentFactory` binds a copy of the tool to the persisted conversation owner and passes it to a native Toolbox. The shared service remains unbound. Model arguments contain SQL only, never an owner or path. There is no separate toolbox factory, schema tool, custom execution loop or hidden model argument. Keep the Toolbox's default NullLogger: debug logging would record SQL arguments. Scoped native toolbox events still project private activity results.

`ReadOnlyQuery` resolves the tenant path through the existing TenantManagers service and opens a separate SQLite3 connection with `SQLITE3_OPEN_READONLY`. It enables `query_only` and memory-backed temporary storage before preparing SQL. A small default-deny native authorizer permits SELECT, recursive queries, reads of the main database and native functions other than extension/file-access functions. It denies ATTACH, model-issued pragmas, writes and other capabilities. No broad builtin function catalog, SQL keyword parser, custom functions or extensions are installed. `query_only` alone does not prevent attaching another readable database. The owner's MCP reference also combines its read-only pragma with an executor; its SQL keyword filter is not copied here.

Only the first statement is prepared; trailing statements are ignored. SQL is limited to 16 KiB; results to 200 rows, 50 columns and 256 KiB of encoded column/row data. Truncation is explicit and query failures return a generic error without paths or financial payloads. These are input/result limits, not execution or allocation limits. There is no subprocess, hard wall/CPU deadline or per-query address-space limit. A slow or allocation-heavy query can occupy or exhaust the chat worker. This is an accepted limitation of the simplified in-process design, not a protection supplied by prompts.

InitialContext reads table definitions directly and includes them with reporting currency in the first user-role context message. Financial semantics remain in `prompts/finance-context.txt`. Workspace saves this initial context in native history without displaying it as a user question or appending it on every turn. Draft exclusion remains prompt guidance, not a security boundary. Record text and tool output remain untrusted data.

At generation invocation, clone the saved MessageBag through native `withoutSystemMessage()`. The native SystemPromptInputProcessor can then inject the current application instructions instead of skipping them because of a saved system message. User, assistant and tool history and the initial user-role context remain intact. No history migration or chat reset is required; normal result persistence records the new application instructions.

SQLite monetary aggregates, arithmetic and casts may use binary floating point. SQL totals and FX calculations must be described as approximate. Preserve decimal-string storage, application BCMath conversion and transaction-date ECB rate semantics. This amends the exact-SQL-calculation obligation in ADRs 0017/0023 for this slice.

## Alternatives considered

- Bounded subprocess: initially implemented, then explicitly rejected by the owner in favor of the smaller direct query design.
- Pragma and prompt alone: insufficient to prevent external database access; retain the native capability restriction.
- BCMath SQL helpers or application-authoritative aggregation: not selected; retain flexible SQL with native builtins.
- A new engine, queue, persistent execution service, SQL parser or sandbox framework: not selected.

## Consequences

The native Agent owns model/tool execution and saved history. Existing provider-specific OAuth setup, private progress events and payload-free logging remain unchanged. Tool calls render as collapsed escaped details in the chronological private activity log; saved results survive reload. Provider-readable summaries and commentary retain their observed positions, separately from the final answer. The composer enables Turbo so follow-up submissions preserve the surrounding controller and expanded entries. Old turns retain their original display without invented activity.

The existing live chat journey asks for a canonical transaction description through SQL, expands its real arguments/result, checks follow-up memory and open-state retention, then verifies persisted call ID/result after reload. It appends to current history without clearing the owner conversation. A previously recorded no-tools cassette does not prove this integration; a new recording requires actual inspected provider traffic.

Capability restrictions and the simplified in-process integration require runtime verification. Source inspection is not security certification, and result limits must not be presented as a query deadline.

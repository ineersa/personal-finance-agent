# ADR 0025: Execute tenant SQL in a bounded subprocess

- Status: Accepted
- Date: 2026-11-04
- Amends: [ADR 0017](0017-exact-money-and-ecb-reporting.md) and [ADR 0023](0023-per-user-finance-databases.md)

## Context

PHP 8.5's SQLite3 extension exposes authorizers but not SQLite's progress handler or interrupt API. A lock-wait timeout or result limit cannot cancel expensive query execution. The owner approved trying a short-lived subprocess rather than introducing another database engine or a persistent execution service.

The owner also selected SQLite builtins only for this slice, instead of custom BCMath SQL functions or restricting SQL to record selection for application-side aggregation. This changes the earlier exact-money requirement for SQL results, not canonical storage or the application's CurrencyConverter.

## Decision

Use native Symfony AI tools, bound once to a trusted persisted generation owner through `TenantSqlToolFactory`. Model arguments contain SQL only, never an owner, database path or command. Use a dedicated native Toolbox with its default NullLogger; the native debug logger otherwise records tool arguments. Do not register the bound tool as a global shared service.

Supervise one short-lived PHP process per query with installed Symfony Process. Pass input as bounded JSON on stdin, using an argument array without a shell. The child loads Composer's autoloader, not the application kernel. It opens only the application-selected tenant file with `SQLITE3_OPEN_READONLY`, enables query_only and memory-backed temporary storage, and installs a default-deny native authorizer. Reads of tenant tables and selected SQLite builtin functions are permitted; writes, ATTACH, model-issued pragmas, extension loading and external-file functions are denied. No table allowlist is needed for owner isolation. No custom SQL functions or extensions are installed.

The parent enforces a five-second wall timeout and a 256 KiB combined pipe-output limit, stopping a still-running child on exit. The child requires POSIX limits: five CPU seconds and 512 MiB address space, alongside PHP's 64 MiB managed-memory limit. It refuses execution if OS limits cannot be set. Input SQL is limited to 16 KiB; results to 200 rows and 50 columns. Row truncation is explicit; oversized or failed results return a fixed error, not partial financial totals. These limits bound individual calls, not aggregate concurrent generations. A subprocess is not an OS security sandbox: native SQLite authorization still supplies capability restrictions.

Expose a schema tool and query tool. Use SQLite's native first-statement behavior; any trailing statements are ignored, never executed. The schema result supplies current table definitions and reporting currency. Financial guidance lives in the initial user-role context. Draft exclusion is prompt guidance. Nullable statement dates remain unknown unless populated by the application. SQL and returned data remain untrusted content.

SQLite monetary aggregates, arithmetic and casts may use binary floating point. SQL totals and FX calculations are approximate and must not be described as exact. Preserve decimal-string storage, existing application BCMath conversion and transaction-date ECB rate semantics; do not substitute current rates or mix currencies without explicit valuation. This supersedes the exact-SQL-calculation obligation in ADRs 0017/0023 for this slice.

## Alternatives considered

- In-process SQLite authorizer and result limits: insufficient to cancel expensive execution through PHP's current native API.
- BCMath-backed SQL helpers: deferred by explicit owner choice; they would not make arbitrary SQL arithmetic or casts exact.
- SQL returning record IDs for application-side totals: not selected; retain flexible model-written SQL.
- New engine, queue, persistent worker or general sandbox framework: not selected.

## Consequences

Queued chat loads the conversation owner and binds a fresh toolbox for each generation. The native Agent owns tool execution and saved history, with the model registered for tool calling. No alternative loop or endpoint is added. The toolbox retains its default NullLogger to avoid SQL-argument logging.\n\n`prompts/chat.txt` contains the short role, tool signatures, tool-grouped gotchas and context-channel references. `prompts/finance-context.txt` contains financial semantics; `InitialContext` adds current table definitions and reporting currency. Workspace inserts this as one native user-role context message before the first question only, saves it in native history and never adds it to the visible transcript. Later turns reuse history. Record text and tool output remain untrusted data, not instructions.\n\nThe visible turn projection stores a chronological activity list inside the existing JSON turns: streamed tool announcements/inputs establish call positions, native execution progress marks calls running, and scoped native toolbox success events attach bounded results by call ID. Provider-readable summary/commentary deltas occupy their observed positions; completion replaces accumulated text rather than appending duplicates. The final answer remains separate. Private Mercure carries the same escaped projection; no payload logging or new subscription channel is added. Native details start collapsed, with the existing Stimulus controller preserving open entries during replacements. The composer explicitly enables Turbo over EasyAdmin’s disabled default; successful submissions return workspace/composer streams rather than replacing the page and losing that controller state. Ordinary non-Turbo submissions retain their redirect. Old turns retain their original rendering without invented activity.

The existing live chat journey now asks for a canonical transaction description through SQL, expands its real arguments/result, checks follow-up memory and open-state retention during updates, then verifies the saved call ID/result after reload. It appends to current history without clearing the owner conversation. Provider replay needs a newly inspected recording of that journey; the earlier no-tools cassette does not prove SQL integration.

Linux/POSIX resource-limit support and an executable PHP CLI are required. Wall cancellation is enforced by the supervising process while it runs; this is not a real-time scheduling guarantee. SQLite native allocations are bounded by address space rather than PHP's memory_limit alone. Capability enforcement and failure/concurrency behavior remain subject to separately authorized verification; source inspection is not security certification.

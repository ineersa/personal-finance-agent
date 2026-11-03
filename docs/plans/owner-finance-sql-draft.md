# Owner-bound SQL draft

`OwnerFinanceQuery` is excluded from service discovery and is not connected to an agent or route. It is an inspection draft, not a production sandbox or completed task 12.

Trusted application code supplies a persisted ordinary User and the application database path. Each instance opens its own read-only main connection and creates one TEMP view over live approved transactions. The view checks transaction, source, statement and statement-source ownership. No rows are copied and no transaction stays open between calls. Destroy the instance after its generation; never share it between owners.

The authorizer denies operations by default. It permits reporting-view reads and selected base-column reads attributed by SQLite to the trusted view. Only count, substr and decimal_sum functions are allowed. This policy needs browser-backed adversarial verification before exposure. In particular, the origin parameter is a name, not an object identity: query-defined CTEs sharing the view name must be investigated before trusting provenance as an authorization boundary. Do not enable the tool merely because direct base-table reads are denied.

Upstream SQLite `src/select.c` assigns `pParse->zAuthContext = pItem->zName` while generating subquery code, and `src/auth.c` passes the context to authorization callbacks. That source inspection supports investigating name collisions; it does not establish a successful bypass or compatibility with the container's SQLite version. No adversarial query was executed.

## Money contract and gaps

Amounts remain original decimal strings. `decimal_sum(amount)` uses BCMath and retains fractional precision. Group by currency; the draft does not prevent mixing currencies. Native SUM/AVG are denied, but SQL arithmetic, casts, comparisons and ordering can still coerce monetary text. This draft therefore does not enforce an exact arbitrary-expression contract. It has no reporting-currency conversion, category labels, agent prompts or answer integration. Credit/debit is not income/expense; meaning and recurrence are independent dimensions. Own-account transfers and card payments are neither spending nor income, and unknown classifications remain unresolved.

## Bounds and verification gaps

Input is limited to 8,000 bytes, output to 200 rows and approximately 64,000 JSON bytes. A limit failure discards the result. These are not memory or execution-time bounds: a single value or aggregate can allocate heavily before fetching. A one-second busy timeout bounds lock waiting only. PHP SQLite3's documented method list and the inspected host runtime expose no progress handler, interrupt or SQLite limit setter. No query deadline is implemented. Keep this tool unwired until execution bounds have a verified implementation; do not build a subprocess framework as an implicit follow-up.

SQLite prepare executes the first statement only; trailing SQL is ignored, not explicitly rejected. The draft does not promise multi-statement input rejection. Database errors are replaced with a generic tool error, without logging SQL or financial rows.

No special-case tests were added or run. Main should run `vendor/bin/castor cs:fix`, relevant saved browser scenarios, then `vendor/bin/castor qa:static`. Security/query-bound scenarios need explicit owner permission under the repository testing policy. Real queued-agent verification also depends on task 11. The unavailable unslop and technical-writing skills were not restored from deleted copies.

References: [SQLite authorizer](https://www.sqlite.org/c3ref/set_authorizer.html), [PHP authorizer](https://www.php.net/manual/en/sqlite3.setauthorizer.php), [PHP aggregates](https://www.php.net/manual/en/sqlite3.createaggregate.php), [Symfony AI tools](https://symfony.com/doc/current/ai/components/agent.html#tools). The installed AsTool constructor was checked against vendor source; no additional agent runtime was introduced.

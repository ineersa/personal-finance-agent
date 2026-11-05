# Finance reporting reference examples

These are synthetic teaching references, **not runtime fixtures, a test suite, provider recordings or current user evidence**. Only `prompts/chat.txt` and `prompts/finance-context.txt` are loaded by the existing application. This directory is not automatically injected into model context.

## Contents

- `worked-examples.txt`: complete query → chart → Markdown journeys and interpretation notes.
- `sql/`: 12 independent SELECT/CTE arguments for `finance_query(sql: string)`. Each repeats its needed CTEs; connections do not persist temporary state between calls.
- `charts/`: six schema-reviewed call examples. Each JSON document contains `tool` and `arguments`; pass only the inner `arguments` to the named tool. These files are reference data, not executable JavaScript.
- `verified-results.json`: **supplied** scratch-SQL results and their original provenance. It is not a report from this task's application validation.

The examples use explicit 2026 dates, CAD and canonical demo records. Adapt dates/currency/scope and query current records; do not answer a live question from these numbers. Original per-transaction amounts are decimal text; SQL arithmetic is approximate. Examples use magnitude plus stored meaning, not amount sign or cash direction. Expense magnitudes are **gross purchases**, refund magnitudes are separate, and **net spending = gross purchases − refunds**. Transfers/card settlements are neither spending nor income. A net category/budget comparison must be labelled net; it must not silently replace a gross spending headline.

The primary September report uses both summary and category SQL. September's gross and net happen to coincide because the supplied results contain no refunds. The August/September example is important: gross rises by CAD 28.43, while net rises by CAD 173.36, primarily because August had CAD 144.93 in refunds. Do not call the whole net change increased purchasing. These values are illustrative evidence supplied with the pack, not a new live pass.

Budget examples are user-supplied assumptions in read-only VALUES CTEs. They create no budgets. Subscription reports describe observed classified charges, not contracts or guaranteed renewals. Availability and missing-valuation examples explain reporting semantics; they do not authorize a negative-case browser/test matrix. No recorded transactions does not establish zero real-world spending.

## Chart contract and presentation

Reviewed against Unovis fork `e57001218dd00995aa13331bf731bd0bb4644fb9`, the pin in `docker/charts/Dockerfile`:

- Bar/line records are flat; `x`/`y` name fields. Multiple series use wide rows and an actual array of field names in `y`, not Chart.js datasets or JavaScript accessors.
- Horizontal bars still use category `x` and numeric `y`; the numeric axis label is `xAxisLabel`. Ascending categorical input places the largest bar at the top in this pinned renderer.
- `theme: dark`, axis labels, titles and the selected line/donut options are supported by the pinned recipes. There is no currency/decimal/tooltip formatter argument; formatted Markdown accompanies the chart.
- Financial lines use chronological ISO dates and explicit NULL rows for unavailable periods, with `curve: linear` and `interpolateMissing: false`. An omitted row cannot create a missing-data gap. The optional example's zero lower bound suits only its nonnegative supplied values, not all net-spending series.
- Only necessary aggregate fields enter the chart. Unplotted columns are embedded in the retained HTML too.
- HTML generation and private destinations are application-owned. Do not supply paths or embed chart markup in final Markdown. Charts attach after the complete answer; there is no arbitrary interleaving, live refresh, drilldown or report composer.
- `get_unovis_info` is a separate text-only tool with an empty input schema. The adapter passes it directly to native MCP handling without output arguments or file retention; chart generators still use private HTML retention.

## Provenance and limits

The owner supplied this pack for task 26. It reviewed application `f40562d8bba0eeadf1109437fbbbbe33f88dafce` and rechecked `52427e3a1d8aa4d676002e6df7657ada9d411166`. Those changes were subsequently merged as `af5313ac851b55eab054195f04128c81923d5536`, the task baseline.

The supplied research reports execution of all 12 SQL files using Python SQLite 3.53.1 against a scratch reconstruction of canonical fixtures/calendar-day ECB data, and source/schema review of six chart calls. It did **not** execute the PHP tool, renderer or live model. SQL and result JSON are retained unchanged; narrative/chart labels clarify gross versus net, and the runtime initial-context query additionally returns gross/refund totals. That adapted runtime query is not covered by the supplied execution claim.

This task's source review checked entity/import amount conventions, tenant schema fields, native template placeholders, tool result handling and the pinned chart recipes. Source inspection is not a runtime pass. Main records actual saved-browser/QA/live checks separately. Do not fabricate provider frames or treat this directory as a replacement replay dataset.

Existing conversations receive the refreshed system policy each generation, but keep their initial context snapshot. Use a new isolated conversation to evaluate the changed initial examples; never rewrite or delete owner history to install prompt changes. Each new worktree needs its own owner-authorized OAuth login; never copy credentials from another checkout.

### Sources

- [Reporting semantics and SQL](../../adr/0025-in-process-tenant-sql.md)
- [Private Unovis documents](../../adr/0026-local-unovis-charts.md)
- [Pinned tool registration](https://github.com/ineersa/unovis/blob/e57001218dd00995aa13331bf731bd0bb4644fb9/packages/mcp/src/tools/register.ts)
- [Pinned shared schemas](https://github.com/ineersa/unovis/blob/e57001218dd00995aa13331bf731bd0bb4644fb9/packages/mcp/src/recipes/shared.ts)
- [Bar](https://github.com/ineersa/unovis/blob/e57001218dd00995aa13331bf731bd0bb4644fb9/packages/mcp/src/recipes/bar.ts), [line](https://github.com/ineersa/unovis/blob/e57001218dd00995aa13331bf731bd0bb4644fb9/packages/mcp/src/recipes/line.ts), [donut](https://github.com/ineersa/unovis/blob/e57001218dd00995aa13331bf731bd0bb4644fb9/packages/mcp/src/recipes/donut.ts)
- [ECB reference rates](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html)
- [Symfony AI Agent](https://symfony.com/doc/current/ai/components/agent.html)

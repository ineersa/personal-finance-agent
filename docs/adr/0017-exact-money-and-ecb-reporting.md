# ADR 0017: Preserve exact money and use ECB rates for reporting

- Status: Accepted
- Date: 2026-10-29

## Context

Users need one reporting currency without losing original statement amounts. SQLite numeric affinity and floating-point aggregation do not provide the exact decimal arithmetic required for financial values.

## Decision

### Exact monetary values

Use decimal strings with Doctrine and BCMath operations. Preserve each original amount and currency as canonical financial data; changing the reporting currency changes presentation, not those originals.

Do not assume a Doctrine DECIMAL declaration alone guarantees exact SQLite storage. A prior in-memory SQLite probe stored `DECIMAL(24,8)` input `123456789012.12345678` as `real`, reading `123456789012.12346`, while TEXT preserved the string. SQLite `SUM` of `0.10` and `0.20` yielded `0.30000000000000004`.

Transaction amounts use Doctrine text/CLOB storage so incomplete drafts can retain unvalidated original text. Approval must validate plain decimal strings before analysis. `CurrencyRate` uses the custom `exact_decimal` type, also declared as CLOB. Both have SQLite TEXT affinity and preserve signs, zeros and precision. Migration diagnostics preserve long fractional amounts and signed, zero-padded rate strings with storage class `text`. Application round trips remain part of the later CRUD browser scenarios. Reporting currency is a User column, defaulting to USD. See [domain persistence](../domain-persistence.md).

Raw SQL `SUM` or `CAST` can still coerce textual values; SQL-based money aggregates and FX calculations must preserve precision. The SQL strategy remains unresolved, not delegated to model arithmetic.

### Reporting currency and ECB valuation

- Show finances in one user-configurable reporting currency, defaulting to USD.
- Support only currencies in the ECB reference-rate feed, with EUR as its implicit base and rate `1`.
- Use ECB rates for statement transaction dates where available. For a source amount, cross-convert using `amount × rate(target) / rate(source)`, with exact decimal arithmetic.
- Use public ECB HTTPS feeds; no paid FX provider or API key is required. Store rates in the existing application SQLite database.
- Seed roughly three years of historical coverage through canonical Alice fixtures. This is sufficient initial coverage, not a retention/deletion threshold.

ECB reference valuation is not the bank's actual execution rate or fees. Fill missing calendar dates with each currency's previous available rate, including weekends and holidays. Never fill dates before that currency's first available reference rate. Unstored dates fail conversion explicitly.

`resources/ecb/reference-rates.json` contains public ECB publication dates and decimal-string rates from `eurofxref-hist.xml`, retrieved on 2026-11-02, covering 2023-11-02 through 2026-11-02. Native Alice PHP fixtures expand this compact snapshot into calendar-day rows without network access. Development setup, browser fixtures and the existing demo reset share these definitions. Normal fixture loading stays append-only; no new purge action is introduced. EUR stays implicit.

Symfony Scheduler runs `app:currency:refresh` hourly through the existing worker's `scheduler_default` transport. The periodic trigger has a fixed UTC anchor, so hourly worker restarts do not postpone it indefinitely. Native stateful scheduling processes only the last missed occurrence after downtime. The browser-stack worker inherits the same transport configuration. Manual refresh uses `vendor/bin/castor app:console -- app:currency:refresh`.

Each refresh requests only `eurofxref-daily.xml` unless a recent date before its latest publication is missing or provisional. That condition requests `eurofxref-hist-90d.xml` once to reconcile recent dates. Valid historical coverage confirms intervening nonpublication days; those dates do not trigger another historical request. Dates after the latest publication remain provisional, including today before publication, and do not themselves trigger history. A later daily publication replaces its provisional row and exposes any intervening dates needing reconciliation. Incomplete, malformed or lagging history fails before writes rather than confirming absent rates. Gaps beyond the recent feed's coverage require historical fixture restoration, not repeated full-history downloads. Only changed rows are written; older history is preserved. HTTP/parsing finishes before short per-day transactions. Native worker errors leave stored data intact and the next hourly occurrence can try again.

Conversion keeps multiplication exact and divides to at least 20 fractional decimal places (or the input amount's scale if greater). This is finite calculation precision, not a promise of exact repeating quotients. Reuse existing `format_currency` for display; do not add a separate display-rounding layer.

## Alternatives considered

- Binary floating-point storage/arithmetic or unverified SQLite DECIMAL mapping can lose precision.
- Rewriting original amounts into a reporting currency would destroy the statement's canonical values.
- A paid FX service is unnecessary for the selected ECB currency scope.

## Consequences

- Verify database round trips, aggregation, conversion, and presentation with known exact values before claiming monetary correctness.
- The refresher and converter have no browser consumer yet; their runtime integration and exact-value browser verification remain outstanding.
- No financial-query integration or precision-preserving SQL aggregate is implemented.

## References

- [ECB euro foreign exchange reference rates](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html).
- [Latest reference day XML](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml).
- [Historical 90-day XML](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml).
- [Full historical XML archive](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml).

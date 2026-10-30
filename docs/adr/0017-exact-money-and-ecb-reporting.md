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
- Use public ECB HTTPS feeds; no paid FX provider or API key is required. Local rate caching/import in the existing application SQLite database is appropriate, without selecting a scheduler or new service.
- Aim for roughly the last three years of historical coverage initially. This is sufficient initial coverage, not an exact retention/deletion threshold. Exact history windows and backfill mechanics remain open.

ECB reference valuation is not the bank's actual execution rate or fees. Missing dates/weekends, rounding, scale, and handling unavailable rates remain implementation choices. Do not silently substitute today's rate, zero, or dropped records as an approved fallback.

## Alternatives considered

- Binary floating-point storage/arithmetic or unverified SQLite DECIMAL mapping can lose precision.
- Rewriting original amounts into a reporting currency would destroy the statement's canonical values.
- A paid FX service is unnecessary for the selected ECB currency scope.

## Consequences

- Verify database round trips, aggregation, conversion, and presentation with known exact values before claiming monetary correctness.
- Feed parsing, rounding/scale, missing-day behavior, cache/import, and backfill integration remain implementation details.
- The schema provides textual amount/rate storage only. No FX importer, scheduler, conversion or precision-preserving SQL aggregate is implemented.

## References

- [ECB euro foreign exchange reference rates](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html).
- [Latest reference day XML](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml).
- [Historical 90-day XML](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml).
- [Full historical XML archive](https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml).

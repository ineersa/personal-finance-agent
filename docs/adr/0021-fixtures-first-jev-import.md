# ADR 0021: Build the fixtures-first CSV import with Jev decisions

- Status: Accepted
- Date: 2026-11-02
- Supersedes: [ADR 0011](0011-layered-rate-limiting.md); modifies the CSV, finance semantics and resource-policy portions of [ADR 0018](0018-finance-review-sql-and-jev.md).

## Context

The owner selected a functionality-first import demo, realistic synthetic account history, and Jev decisions for CSV interpretation. The previous quota subsystem would delay this slice. Small synthetic API probes showed that row-shaped state preserves the relationships needed for column interpretation; independent column arrays confused debit and credit in a reordered example.

## Decision

### Scope and resource policy

Retain the 10 MiB limit per uploaded file. Defer upload frequency, daily bytes, retained-storage quotas, shared AI submission limits, one-active-generation-per-user admission, pending/global capacity limits and instance AI-budget accounting to later work. This supersedes those requirements wherever earlier accepted ADRs refer to them, including chat and summarization. Existing login throttling remains unchanged. Do not remove implemented authentication protections.

Keep authorization, validation, short database writes, basic processing failure states and message-redelivery idempotency. Parse, call providers and communicate with the broker outside database write transactions. Do not add overlapping-upload deduplication, automatic transfer reconciliation, or a broad broker/recovery framework.

### PHP facts and Jev judgments

PHP reads CSV records and preserves exact original decimal amounts, dates, currency and direction. Jev selects column roles from a bounded sample of complete rows, with explicit descriptions of each candidate role and separate presence/support questions. Missing fields remain missing; trusted source context can supply currency and instrument kind. A model-selected account identifier never authorizes access to a source.

Use Jev separately for transaction meaning, category and recurrence. Choice always selects an option; pair it with evidence-sufficiency questions and retain unknown/unresolved outcomes. Do not treat probability thresholds tuned on a few examples as established accuracy. Use compatible Symfony AI facilities, keep credentials server-side, and disclose the actual external destination. The initial hosted experiment sent only invented data. Do not log personal financial payloads, despite the upstream development skill's payload-logging suggestion.

PDF/XLSX conversion and configured LLM row extraction remain governed by ADRs 0016 and 0018. Jev is not an arbitrary-field generator or OCR engine.

### Independent finance dimensions

Transaction meaning is expense, income, refund, own-account transfer, credit-card payment or unknown. Recurrence is recurring bill, subscription/membership, other recurring activity, non-recurring or unknown. Both default to unknown for existing records; migrations must not infer old values. Source currency is optional and instrument kind defaults to other, preserving existing sources.

Use the 21 shared categories defined in `fixtures/category.yaml`. Refunds retain the underlying purchase category where known, rather than becoming income. Recurrence does not imply fixed amounts or monthly frequency, and repeated shopping alone does not prove a recurring bill. These labels are not an accounting or automatic reconciliation system.

Confirmed own-account transfers and credit-card balance payments contribute to neither spending nor income, regardless of category, debit/credit direction, whether a counterpart exists, or whether the two sides cross currencies/months. Do not infer this exclusion merely by netting credits and debits. Unknown meaning remains unresolved and must not be automatically treated as a transfer. Preserve original source, amount and currency; this decision does not match pairs or invent FX rates. Synthetic expected totals report spending debits, refund credits and income credits separately per source/currency, with unresolved counts.

Set the canonical demo user's reporting preference to CAD. Keep the application-wide new-user default USD and the administrator fixture unchanged.

### Canonical synthetic inputs

Prepare 24 fixed completed months, November 2024 through October 2026, for CAD chequing, CAD credit and USD bank accounts. Reserve October 2026 (one file per source) for upload scenarios. The preceding 69 files are historical candidates. Use wholly invented merchants, amounts, identifiers and sequences, preserving bank-export patterns without reversible pseudonymization or personal source rows.

The Castor generator writes CSV inputs and known expected outcomes, not model results. Historical records become canonical Alice fixtures only after processing through the real pipeline and reviewing the outcomes. Until that exists, keep historical inputs explicitly pending. Existing small hand-authored Alice examples remain compatibility scaffolding with matching statement bytes, not a claim of Jev processing. Development and browser scenarios continue to use the same `fixtures/*.yaml`; do not introduce a second test dataset or purging development loader.

## Alternatives considered

- Building quota accounting now was explicitly deferred by the owner.
- General-purpose LLM CSV extraction is unnecessary when PHP can retain facts and Jev can select roles.
- Folding recurrence into meaning would conflate expenses, income and transfer patterns.
- Presenting generated expected labels as processed historical transactions would conceal an unverified integration.

## Consequences

Provider charges, storage growth and concurrent work are not application-budgeted in this MVP slice. Operators must supervise use until deferred controls are implemented. This trade-off is explicit, not an assurance of bounded resource use.

The prepared corpus enables evaluation of refunds, transfers, recurring bills, annual subscriptions, variable utilities and ambiguous references. It does not establish general CSV accuracy. Actual upload, worker, Jev integration and historical fixture finalization still require runtime verification.

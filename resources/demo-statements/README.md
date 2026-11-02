# Synthetic statement inputs

These files contain invented financial activity, not anonymized copies of private rows and not Jev output. Merchants, account/card identifiers, amounts and sequences were authored independently. The headerless layout follows the observed bank-export pattern: date, description, debit amount, credit amount; credit-card exports add an instrument identifier. Empty amount cells remain empty. Currency comes from the selected source (CAD chequing, CAD credit, USD bank account).

Regenerate the fixed corpus with:

```console
vendor/bin/castor demo:statements:generate
```

The generator accesses neither private statements, providers nor a database. It writes 24 months per source (November 2024–October 2026). This fixed window makes regeneration reproducible; it does not roll with the system clock.

- `chequing/`, `credit/`, `usd/`: 72 monthly inputs. October 2026 is reserved for upload scenarios, one file per source. The other 69 files were processed through the real parser/Jev handler and reviewed before export to canonical Alice fixtures: 733 approved transactions and 25 unresolved drafts.
- `expected.json`: known synthetic outcomes for comparison, including exact string amounts, category fixture references, meaning and recurrence. `history_pending_pipeline` is not processed or approved status. These expectations must never be presented as model predictions. The opaque USD reference deliberately expects unknown meaning/recurrence and Uncategorized.
- `expected_totals` within each statement separates spending debits, refund credits, income credits and unresolved-row counts. Confirmed own-account transfers and card payments are excluded independently of category or a matched counterpart. Totals are per currency, not converted reporting totals. CAD card settlements use one shared exact amount per month on both source exports; cross-currency transfer amounts are not assumed equal.
- `examples/admin_statement.csv`: the separate synthetic administrator-owned fixture used for access-denial checks. Ordinary-owner fixture files come from the historical monthly inputs through `StatementFileProcessor`; September card downloads use `history-credit-2026-09.csv`.

Only `fixtures/*.yaml` defines the canonical development/browser database dataset. Do not load `expected.json` into approved records as a shortcut. `history-review.json` records the historical review, including model uncertainty and differences retained rather than forced to match expectations. Keep the three October files as upload holdouts. Application processing creates drafts and never auto-approves them.

Transfers and card payments describe transaction meaning, not proven reconciliation pairs. The corpus intentionally does not encode authoritative cross-source matching or actual bank FX rates. Recurrence describes bills/subscriptions and other periodic activity independently of category, direction and meaning.

Use the saved isolated upload scenarios in [TESTING.md](../../TESTING.md#isolated-live-stack) to verify the actual web/broker/worker/Jev path. The earlier standalone inspection command has been removed. Unknown recurrence is legitimate when a generated schedule is not observable from the supplied rows.

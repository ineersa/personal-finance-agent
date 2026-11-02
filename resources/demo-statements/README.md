# Synthetic statement inputs

These files contain invented financial activity, not anonymized copies of private rows and not Jev output. Merchants, account/card identifiers, amounts and sequences were authored independently. The headerless layout follows the observed bank-export pattern: date, description, debit amount, credit amount; credit-card exports add an instrument identifier. Empty amount cells remain empty. Currency comes from the selected source (CAD chequing, CAD credit, USD bank account).

Regenerate the fixed corpus with:

```console
vendor/bin/castor demo:statements:generate
```

The generator accesses neither private statements, providers nor a database. It writes 24 months per source (November 2024–October 2026). This fixed window makes regeneration reproducible; it does not roll with the system clock.

- `chequing/`, `credit/`, `usd/`: 72 monthly inputs. October 2026 is reserved for upload scenarios, one file per source. The other 69 files await the actual pipeline and reviewed historical export.
- `expected.json`: known synthetic outcomes for comparison, including exact string amounts, category fixture references, meaning and recurrence. `history_pending_pipeline` is not processed or approved status. These expectations must never be presented as model predictions. The opaque USD reference deliberately expects unknown meaning/recurrence and Uncategorized.
- `examples/`: small hand-authored compatibility examples corresponding to existing `fixtures/transaction.yaml` rows. `StatementFileProcessor` copies these actual bytes into private fixture storage and computes their metadata. These are not historical pipeline output. The retained `demo-card-2026.csv` download name is a compatibility name for September's seed example.

Only `fixtures/*.yaml` defines the canonical development/browser database dataset. Do not load `expected.json` into approved records as a shortcut. Once the pipeline exists, process the 69 historical files into drafts, inspect results against expectations, review synthetic outcomes and export the finalized records into the canonical Alice fixtures. Keep the three October files unprocessed. Production processing still creates drafts and never auto-approves them.

Transfers and card payments describe transaction meaning, not proven reconciliation pairs. The corpus intentionally does not encode authoritative cross-source matching or actual bank FX rates. Recurrence describes bills/subscriptions and other periodic activity independently of category, direction and meaning.

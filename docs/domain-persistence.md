# Finance persistence foundations

## Current schema

- `User` stores identity, password hash, roles and reporting currency, defaulting to USD. Login and settings forms remain future work. EasyAdmin manages application entities; it does not supply the application's user entity.
- `Source` represents an account, card or financial instrument, with name, optional description and statements. It has no merchant classification, speculative kind or AI instructions.
- `Statement` links its source, transactions, upload/processing dates, optional statement date and private-storage metadata. Storage keys are opaque identifiers, not public URLs. Upload validation, path resolution and file handling remain future work.
- `Transaction` maps to `financial_transaction`. `isDraft` defaults to true. Drafts may be incomplete and retain original textual amounts, including invalid input awaiting correction. The `approved` Symfony Validator group requires date, plain decimal-string amount, three-letter uppercase currency and Credit/Debit direction. Approval must validate this group and the selected associations before clearing the draft flag. Only validated non-draft rows may feed later analysis. The flag alone is not validation. No approval or analysis workflow exists yet.
- `Category` is shared reference data with name, description and icon, not personal category CRUD. `CurrencyRate` is also global and stores dated currency units per EUR. No FX importer, rounding or missing-date behavior is implemented.

Source and category timestamps use native immutable dates and a Doctrine update callback, not the legacy timestamp library. Existing rows with unknown creation/update dates retain null rather than invented history. Conversation, message, generation, usage, quota and budget schemas are deferred to their actual feature tasks. Their security, retention, accounting and atomic-admission requirements remain in the specification and accepted ADRs.

## Ownership and authorization

`UserRecord` is a mapped superclass containing only the immutable `user` relationship and `getUser()`. Source, Statement and Transaction each declare their own ID. Personal foreign keys are named `user_id`. Categories and rates have no user relationship.

Inject the personal repositories for collection queries, direct lookups and association choices. `queryForUser()` requires a persisted trusted user; `findForUser()` adds the record ID to the same scoped query rather than using the identity map. Queries also constrain associated personal records to that user. Obtain the user from authentication or trusted admitted work, never request fields or model output. These repositories deliberately do not inherit unscoped `find()`/`findAll()` methods.

`FinanceVoter` handles `FINANCE_VIEW`, `FINANCE_EDIT` and `FINANCE_DELETE`. It checks the record's user and each personal association. There is no role-based administrative bypass. Categories are global choices; this voter does not authorize category maintenance.

Voters authorize individual objects; they do not filter lists. Future HTTP/EasyAdmin handlers must use scoped repository queries and invoke the voter for record actions, including after binding association changes and before persistence. An association must be selected from a user-scoped query, not an unrestricted entity choice. Inverse ORM collections describe relationships, not independent authorization queries. Do not use the entity manager's default repository, direct ORM/DBAL queries or bulk DQL to bypass the scoped repositories and voter. There is no automatic flush listener, owner filter, mutable owner scope or entity-manager clearing.

No financial endpoints exist yet. Actual cross-user browser proof belongs with CRUD in task 05. Workers, event subscriptions and the model-written SQL tool need their own enforced authorization boundaries in their feature tasks. In particular, task 12 must independently secure raw SQL; a repository or voter cannot secure arbitrary SQL text.

## SQLite and exact values

The connection-scoped driver/middleware/connection integration configures WAL, foreign keys, a 5000 ms busy timeout and `synchronous=NORMAL` on each connection. Explicit outer transactions use `BEGIN IMMEDIATE`; ordinary reads remain unwrapped. Keep write transactions short and outside parsing, file operations, broker calls and model calls. Broker storage remains separate.

Middleware priority 1000 places native connection initialization inside DAMA's connection-caching wrapper. It avoids reapplying connection settings inside an existing test transaction or bypassing its savepoints.

Transaction amounts use Doctrine text/CLOB storage, preserving draft input without numeric affinity or normalization. Approval validates decimal syntax. Currency rates use `exact_decimal`, also declared as CLOB, which validates plain decimal strings at the mapping boundary. Neither changes signs, zeros or precision. Use BCMath for calculations; SQLite `SUM` and numeric casts remain unsafe for exact money. No calculation service or SQL aggregate is implemented.

## Migration behavior

The published initial migration remains unchanged. Two ordinary follow-ups reduce the schema and rename personal foreign keys/indexes. They preserve approved transaction IDs and exact values, append draft rows with new IDs to avoid collisions, retain category IDs and references, copy reporting preferences onto User, and rename the rate table without changing rate strings. Draft sources come from their statement. Existing source kind text becomes its description. Existing category rows become shared reference data without deduplication.

The reduction drops unused future-feature scaffolding and is irreversible because former category ownership and removed tables cannot be reconstructed. The naming migration is reversible. Foreign keys remain enabled; temporary constraint deferral permits the category-table rebuild inside the short migration transaction.

`vendor/bin/castor test:database:prepare` upgrades the fixed checkout-owned browser database. `vendor/bin/castor test:database:fresh` migrates and validates new diagnostic storage without resetting any existing database. It prints report paths containing the generated storage path. No HTTP probe or non-browser application test suite is introduced.

# Finance persistence foundations

## Current schema

- `User` stores identity, password hash, roles and reporting currency, defaulting to USD. Form login, administrator user CRUD, and owner-only reporting settings use this entity. New passwords are transient form input, hashed before persistence and excluded from session serialization.
- `Source` represents an account, card or financial instrument, with name, optional description and statements. It has no merchant classification, speculative kind or AI instructions.
- `Statement` links its source, transactions, upload/processing dates, optional statement date and private-storage metadata. Storage keys are opaque identifiers, not public URLs. Upload validation, path resolution and file handling remain future work.
- `Transaction` maps to `financial_transaction`. `isDraft` defaults to true. Drafts may be incomplete and retain original textual amounts, including invalid input awaiting correction. The `approved` Symfony Validator group requires date, plain decimal-string amount, three-letter uppercase currency and Credit/Debit direction. Approval must validate this group and the selected associations before clearing the draft flag. Only validated non-draft rows may feed later analysis. The flag alone is not validation. No approval or analysis workflow exists yet.
- `Category` is shared reference data with name, description and icon, not personal category CRUD. `CurrencyRate` is also global and stores dated currency units per EUR. No FX importer, rounding or missing-date behavior is implemented.

Source and category timestamps use native immutable dates and a Doctrine update callback, not the legacy timestamp library. Existing rows with unknown creation/update dates retain null rather than invented history. Conversation, message, generation, usage, quota and budget schemas are deferred to their actual feature tasks. Their security, retention, accounting and atomic-admission requirements remain in the specification and accepted ADRs.

## Ownership and authorization

`UserRecord` is a mapped superclass containing only the immutable `user` relationship and `getUser()`. Source, Statement and Transaction each declare their own ID. Personal foreign keys are named `user_id`. Categories and rates have no user relationship.

Inject the personal repositories for collection queries, direct lookups and association choices. `queryForUser()` requires a persisted trusted user; `findForUser()` adds the record ID to the same scoped query rather than using the identity map. Queries also constrain associated personal records to that user. Obtain the user from authentication or trusted admitted work, never request fields or model output. These repositories deliberately do not inherit unscoped `find()`/`findAll()` methods.

Native Symfony controller authorization excludes administrators from every financial CRUD action and reporting settings, including accounts that carry both roles. EasyAdmin's entity permissions delegate individual financial-record access to `FinanceRecordVoter`, which also rejects administrators and checks ordinary-user ownership and all personal associations. Internal ORM hydration is not an authorization boundary or a user-visible exposure. Category and user controllers require `ROLE_ADMIN`; their entity permissions also suppress unauthorized association links. Categories remain shared transaction choices for ordinary users. Administrator-owned fixture records remain stored for denial regressions, not as an administrator financial workspace.

Voters authorize individual objects; they do not filter lists. `OwnedCrudController` constrains EasyAdmin index queries with IDs from the scoped repository query and rechecks the bound record before persistence. The approved-transaction source field uses the owner-scoped source query for both displayed and submitted choices. Statement provenance and the draft flag are not editable. Inverse ORM collections describe relationships, not independent authorization queries. Business queries must not bypass scoped repositories with unscoped ORM/DBAL queries or bulk DQL; EasyAdmin's internal entity loading is protected by its native permissions. There is no custom request guard, flush listener, owner filter, mutable owner scope or entity-manager clearing.

Sources support CRUD, statements are read-only, and approved transactions support corrections without creation, deletion or draft approval. Upload metadata has no creation form. Feature-named browser regressions are in `tests/E2E/`; execution results belong in the task's validation evidence. Workers, event subscriptions and the model-written SQL tool still need their own enforced authorization boundaries. In particular, task 12 must independently secure raw SQL; a repository or voter cannot secure arbitrary SQL text.

## SQLite and exact values

The connection-scoped driver/middleware/connection integration configures WAL, foreign keys, a 5000 ms busy timeout and `synchronous=NORMAL` on each connection. Explicit outer transactions use `BEGIN IMMEDIATE`; ordinary reads remain unwrapped. Keep write transactions short and outside parsing, file operations, broker calls and model calls. Broker storage remains separate.

Middleware priority 1000 places native connection initialization inside DAMA's connection-caching wrapper. It avoids reapplying connection settings inside an existing test transaction or bypassing its savepoints.

Transaction amounts use Doctrine text/CLOB storage, preserving draft input without numeric affinity or normalization. Approval validates decimal syntax. Currency rates use `exact_decimal`, also declared as CLOB, which validates plain decimal strings at the mapping boundary. Neither changes signs, zeros or precision. Use BCMath for calculations; SQLite `SUM` and numeric casts remain unsafe for exact money. No calculation service or SQL aggregate is implemented.

## Migration behavior

One regenerated initial migration creates the six finance tables directly. Monetary columns use CLOB/TEXT affinity, personal relationships use `user_id` foreign keys, and rollback drops dependent tables before their referenced tables.

The initial version identifier is retained. An already-final task database therefore does not schedule another create-table migration. This regenerated initial migration is not an upgrade path for earlier intermediate schemas. No compatibility branches or migration-history repair are provided, and existing databases are not reset.

`vendor/bin/castor test:database:prepare` applies available migrations to the fixed checkout-owned browser database. `vendor/bin/castor test:database:fresh` migrates and validates new diagnostic storage without resetting any existing database. It prints report paths containing the generated storage path. No HTTP probe or non-browser application test suite is introduced.

# Domain persistence foundations

## Schema boundaries

`src/Entity` and the initial migration define storage, not completed workflows:

- `User` stores authentication identity, password hash and roles. `UserSettings` has one row per owner and defaults reporting currency to USD. Login and settings forms are not implemented.
- `Source` names an account, card or financial instrument, never a merchant. `Statement` links that source to private-storage metadata. Its storage key is an opaque identifier, not a public URL. File validation, path resolution and storage operations belong to the upload workflow.
- `DraftTransaction` holds editable, possibly incomplete rows. Its amount is unvalidated text. `Transaction` maps to `approved_transaction`, with required statement date, source, original decimal amount, currency and Credit/Debit direction. Categories are separate, owner-specific records. Approval, currency validation and source consistency checks belong to review, not automatic persistence callbacks.
- `ExchangeRate` stores reference-date currency units per EUR. No importer, conversion, rounding or missing-date policy exists yet.
- `Conversation` defaults to temporary. Messages store role and text. This is not a selected Symfony AI history adapter or a complete tool/reasoning serialization contract.
- `Generation` captures an operation key, owner, purpose, state and selected provider/model/reasoning. Optional statement/conversation references support the two later AI workflows. No admission, worker or provider execution exists yet.
- `DailyQuota`, `DailyAiBudget` and `UsageAccounting` are durable accounting foundations. Submission operation keys and provider-attempt keys are distinct. They do not implement atomic admission, reservations, settlement or pricing policy. Costs have no selected pricing currency yet.

There are no cascading deletes. Deletion of referenced records fails rather than silently removing accounting or admitted work. Later conversation pruning must detach retained generation references and remove messages explicitly. Financial demo reset must preserve quota, budget and usage records. No reset implementation exists.

## Ownership contract

`OwnedRecord` gives each personal row an immutable, non-null owner. The always-enabled `OwnerFilter` returns no personal rows without an owner scope. It scopes ORM repository queries, direct database lookups, joins and lazy association loads. Global exchange rates and instance budget rows are not personal records. User account maintenance is separate from financial access.

`OwnerScope::run()` accepts a persisted, trusted user. HTTP callers must obtain that user from authentication, not request data. Workers must resolve trusted ownership from admitted work, not model output. No role, including `ROLE_ADMIN`, bypasses the filter.

Run each bounded operation in one scope. The callback receives a managed owner reference for new records. Scope entry and exit clear the entity manager so its identity map cannot reuse another owner's records. Flush changes inside the callback; do not carry managed entities or pending changes across scopes. Nested scopes are rejected. Return scalar values or presentation data when crossing this boundary.

The scope belongs to its entity manager, not to a fiber. Future concurrent in-process generations must use isolated entity managers/scopes rather than share this mutable service.

`OwnershipListener` checks every personal insertion, update and deletion at flush, plus each associated personal record. Cross-owner association writes and writes without a matching scope fail. Current associations are all to-one; adding to-many write associations requires extending this enforcement.

Use ORM entity writes and flush for personal mutations. Bulk DQL, native SQL and direct DBAL bypass lifecycle listeners and are not authorized mutation paths. Do not disable the owner filter or expose the entity manager/connection to untrusted callers. This is an application boundary, not database row-level security. The model-written SQL tool needs its own enforcement in task 12; ORM filters do not secure it.

## SQLite and exact values

The default connection uses the complete driver/middleware/connection integration from ADR 0003. Each connection configures WAL, foreign keys, a 5000 ms busy timeout and `synchronous=NORMAL`. Explicit outer transactions, including ORM flush transactions, use `BEGIN IMMEDIATE`; ordinary queries remain unwrapped. Keep explicit write transactions short and outside parsing, filesystem work, broker calls and model calls. Broker storage is not mapped here.

The middleware has native-driver priority 1000. Installed DBAL wraps middleware in configured order, and DAMA registers its caching wrapper at priority 100. SQLite initialization and transaction overrides must sit inside that wrapper so cached test connections do not reapply connection settings inside a transaction or bypass DAMA's savepoints. This ordering is adapted to the installed packages, not copied from the reference's test configuration. Browser-suite verification remains required.

`exact_decimal` declares CLOB, which has SQLite TEXT affinity. It accepts plain decimal strings, including an optional sign, and preserves leading/trailing zeros and precision. It rejects floats and exponent notation without normalizing the value. Amount signs do not infer business categories or replace Credit/Debit direction. Calculations must use BCMath; no calculation service or SQL aggregate is implemented. SQLite `SUM` and numeric casts still lose precision.

The initial schema was generated with Doctrine migration diff after MakerBundle scaffolding and inspected for SQLite affinity, foreign keys, indexes and dependency-safe rollback order. `vendor/bin/castor test:database:prepare` applies migrations only to the fixed, checkout-owned browser database. Financial persistence and ownership browser assertions belong beside the actual CRUD in task 05. No diagnostic HTTP route or service-only test suite is introduced here.

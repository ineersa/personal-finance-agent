# ADR 0003: Use a shared SQLite application database

- Status: Accepted
- Date: 2026-10-29

## Context

The demo needs local installation, user login, personal financial workflows, and administrative user management. A shared SQLite database keeps Doctrine configuration and migrations simpler than a database per user.

The alternative of per-user databases would reduce cross-user query mistakes and write contention, but would require database provisioning, tenant-aware connection lifecycles, and migrations across multiple files.

## Decision

Use one shared SQLite application database, managed through Doctrine. Keep queue-broker storage separate as described in [ADR 0004](0004-sqlite-queue.md).

### Access boundaries

- Support `ROLE_ADMIN` for user CRUD and authorized reset operations.
- Support `ROLE_USER` for personal financial workflows.
- Scope personal-data access to its owner through centralized query and authorization mechanisms. Role checks alone do not scope records.
- Enforce ownership for direct record access, collection queries, aggregates, association selection, chat tools, and background processing.
- Treat administrative maintenance permissions separately from access to personal financial records. Do not introduce an implicit ownership bypass.
- Define reset scope and safeguards before implementation. Administrative permission is not permission to reset unrelated installations or reference projects.

Source, Statement and Transaction inherit only an immutable user relationship/accessor from a mapped superclass; each declares its own ID. Personal foreign keys use `user_id`. Scoped repository queries cover lists, direct lookups and association choices. Symfony voters authorize individual records and their associations, without an administrative bypass. Voters do not scope lists, and there is no flush listener, owner filter or mutable scope. Shared categories and currency rates have no user relationship. [Domain persistence](../domain-persistence.md) defines the calling contract and remaining HTTP/worker/raw-SQL enforcement obligations.

### Connection and transaction policy

Adapt the SQLite transaction middleware and connection settings from:

- `/home/ineersa/projects/agent-core/src/CodingAgent/Infrastructure/Doctrine/SqliteImmediateTransactionConnection.php`.
- The adjacent `SqliteImmediateTransactionDriver.php` and `SqliteImmediateTransactionMiddleware.php`.
- `/home/ineersa/projects/agent-core/config/packages/doctrine.yaml` and the middleware registration in `config/services.yaml`.

Use WAL mode, enable foreign-key enforcement on each connection, and start with the reference's five-second busy timeout. Use `BEGIN IMMEDIATE` for outer transactions that can write, reserving the writer slot before taking a read snapshot. Do not wrap ordinary read-only requests in immediate transactions.

Keep write transactions short. Do not hold a transaction while uploading, parsing a statement, calling an AI provider, or waiting on the queue broker. A timeout does not eliminate all lock failures.

Adapt the middleware as a complete, connection-scoped integration rather than copying the connection class alone. Do not import application-specific entity mappings, paths, profiling settings, or test-bundle priorities from Agent Core.

Use `synchronous = NORMAL` for the application database, configured on every connection. The project owner accepts this WAL durability trade-off for the demo. Use the same durability mode for the separate broker database, as recorded in [ADR 0004](0004-sqlite-queue.md).

## Alternatives considered

- A SQLite database per user would isolate ordinary financial queries, but add provisioning, migration, connection-lifecycle, and reset complexity.
- A shared database with only ad hoc role checks and manually added owner predicates would be easy to implement inconsistently. Centralized ownership enforcement is required instead.

## Consequences

- The application has one schema and migration history.
- Cross-user access remains an application security concern and must be verified when the testing requirements are defined.
- WAL permits concurrent readers and a writer, but SQLite still has one writer per database file.
- `BEGIN IMMEDIATE` moves writer contention to transaction start; it does not increase writer concurrency and can unnecessarily block writers if used for read-only work.
- WAL with `synchronous = NORMAL` can lose recently committed transactions after an OS crash or power loss. It does not provide the stronger commit durability of `FULL`.
- The domain scaffolding includes the connection-scoped middleware, mappings and initial migration. Browser persistence/ownership proof belongs with the real CRUD implementation; this schema does not implement financial workflows.

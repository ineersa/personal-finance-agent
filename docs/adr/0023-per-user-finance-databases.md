# ADR 0023: Store authoritative finance data in per-user SQLite databases

- Status: Accepted
- Date: 2026-11-04
- Supersedes: [ADR 0003](0003-shared-sqlite-database.md)
- Amends: [ADR 0017](0017-exact-money-and-ecb-reporting.md) and [ADR 0018](0018-finance-review-sql-and-jev.md)

## Context

Model-written SQL is a core analysis requirement. The shared database selected in ADR 0003 simplified provisioning and migrations, but made arbitrary SQL ownership enforcement harder than ordinary application queries. Owner predicates supplied by the model are not an authorization boundary. The unexposed protected-view draft also depended on an unverified view-provenance assumption in SQLite's authorizer.

The owner accepts database provisioning and migration costs in exchange for an authoritative finance database per user. This is a storage redesign, not a reporting cache.

## Decision

Keep users, authentication and user settings in the central application database. Store Source, Statement, Transaction, Category and CurrencyRate in each ordinary user's private SQLite database. Finance rows have no `user_id` or ORM association to the central User. Finance associations stay within one database; Doctrine does not support associations across entity managers. Queue-broker storage remains separate under ADR 0004. Conversation persistence remains central under ADR 0015; it is not part of this finance split.

Resolve the database from trusted authenticated or queued-work identity, never a model-supplied filename or request-selected owner. Central users must always use the central entity manager. Scope tenant entity managers, connections and identity maps to their owner; do not switch a shared entity manager between concurrent jobs. Release owner-bound resources when work ends. Carry the trusted owner separately for private statement storage, Mercure topics and role authorization.

Administrators retain user management and cannot enter tenant finance workflows, including when they also have ROLE_USER. Ordinary users manage their own categories through the existing CRUD interface. Seed each database with the canonical category catalog; categories are independent thereafter, without central catalog synchronization. Remove manual currency-rate CRUD. ECB refresh fetches reference data once and maintains rates in all tenant databases. Preserve the existing exact-decimal and historical-date semantics.

Provision finance storage for new ordinary users, apply one generated finance migration history across tenant databases, and handle storage removal when an account is deleted. Canonical fixtures must load into the corresponding central and tenant stores, with the same definitions used by development and browser tests. Generate schema migrations through Doctrine and Castor against isolated task-owned storage; do not hand-write migration SQL or rewrite committed migration history.

The owner has authorized a clean development cutover: recreate verified project-owned disposable dev/test databases and reload canonical fixtures. No legacy conversion, migration bridge, shared-finance runtime fallback or data-copy compatibility mechanism is required. Generated central migrations may remove the old finance tables. Verify exact storage paths and active stack ownership before any destructive operation; authorization does not extend to unrelated installations, broad volume deletion, uploaded statement files or authentication credential stores.

Give analysis a separate read-only connection to the selected tenant file, not the application's writable connection. The file itself contains only that owner's finance data and permitted reference data. Block access to other databases and external files; `PRAGMA query_only` alone is not a sandbox. No protected views, financial replicas, refresh revisions, triggers, change logs or reporting synchronization are selected.

Drafts are visible within the owner's database. Instruct the model to use `is_draft = 0` for ordinary financial analysis unless the user explicitly asks about drafts. Draft exclusion is a model-behavior rule, not a security boundary. Approval still validates and atomically promotes records. This replaces earlier requirements for technically enforced approved-only SQL visibility.

The tenant split does not solve exact SQL money calculations, mixed currencies, unsafe external capabilities or execution/output bounds. Those remain requirements of the SQL tool, and must be verified before exposing it. Do not describe whole-tenant read access as unrestricted process access.

## Alternatives considered

- Shared SQLite with owner-filtered temporary views and an authorizer: rejected because the isolation policy was harder to establish and inspect than a separate owner-only file. The draft was never connected to the application runtime.
- Disposable owner-only exports per analysis: avoids synchronization but copies the owner's history for every analysis.
- Persistent reporting copies with scheduled rebuilding, revisions or triggers: retains the shared source of truth but adds freshness and publication machinery.
- Updating analytical copies on every write: introduces a dual-write problem, especially for shared rates.
- Structured aggregation instead of SQL: reduces the exposed query language but does not meet the selected flexible-SQL direction.
- DuckDB: changing engines does not itself isolate untrusted SQL or remove copying/synchronization costs.

## Consequences

Tenant selection replaces per-row user ownership predicates for finance queries. Local foreign keys and normal Doctrine associations remain available. Per-user write contention is separated, and model queries do not share a finance file with other users.

Provisioning, deletion, fixture loading, test isolation and migration fan-out become application responsibilities. A private path does not replace correct connection lifetime or external-access restrictions. The clean fixture-backed cutover deliberately avoids compatibility infrastructure; destructive verification still requires verified task-owned storage.

Serialize provisioning per tenant with Symfony Lock's `FlockStore` in the tenant database directory. Acquire before checking whether the database exists, and hold through migration, initial seeding and failed-initialization cleanup. Use the owner ID in the lock key, not the absolute database path, so host and container paths address the same lock file. Leave lock files to the native store lifecycle. HTTP and CLI processes must share that directory on a local filesystem supporting `flock`; this does not provide multi-host coordination. The default temporary-directory `LOCK_DSN=flock` is not used for provisioning.

This ADR records the accepted target. It does not establish that tenant lifecycle, fixture setup, SQL isolation, precision or runtime bounds have been implemented or tested.

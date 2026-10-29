# ADR 0004: Use sqlite-queue with Symfony Messenger

- Status: Accepted
- Date: 2026-10-29

## Context

Statement processing needs background work without introducing an external queue server for the local demo. The project owner selected the existing `ineersa/sqlite-queue` broker.

The reference checkout is `/home/ineersa/projects/sqlite-queue`. It provides a SQLite-backed broker, socket client, Symfony bundle, and Messenger transport. The local checkout is reference material, not an installation prerequisite for judges.

## Decision

- Use `ineersa/sqlite-queue` through its Symfony Messenger transport.
- Run the broker as a separate process and use standard Messenger consumers for application handlers.
- Keep the broker's SQLite file separate from the shared application database. Queue writes must not share the application's writer lock.
- Use WAL with `synchronous = NORMAL`, accepting the demo's durability trade-off.
- Use the package's broker-owned storage and supported APIs. Do not access or migrate its tables through the application entity manager.
- Carry owner and record identifiers in processing messages. Resolve records within the appropriate owner context.
- Make handlers safe under duplicate delivery. A retried extraction must not duplicate imported transactions.

The exact package revisions, queue configuration, supervision, storage paths, and retry policy remain to be selected during bootstrap. Long-running handlers need the package's supported reservation-renewal mechanism.

## Integration constraints

The inspected package requires PHP `^8.5`, `ext-sqlite3`, and Symfony `^8.0`. The broker also needs Unix sockets, `ext-posix`, and `ext-pcntl`. The application will separately need PDO SQLite for Doctrine. These requirements must be included in the eventual local runtime.

At inspection time, the package is not published on Packagist and depends on `fabpot/amphp-sqlite3` from the `ineersa/amp-sqlite3` development branch. Application-level Composer configuration must declare both repositories because dependency repository declarations are not inherited. Pin resolved revisions in the lockfile and verify compatibility with the selected stack.

Local installation must not depend on absolute paths to the author's checkouts. The broker, application, and consumers need access to their configured private socket and persistent storage locations.

## Alternatives considered

The project owner selected this broker. No comparative performance claim or broker benchmark is part of this decision.

## Consequences

- The demo needs a broker and consumer lifecycle in addition to its web process.
- Delivery is at least once, not exactly once. Handlers must account for redelivery and expired reservations.
- Queue publication and an application database commit are not one atomic transaction. The processing workflow needs a recoverable handoff; its mechanism remains undecided.
- Demo reset must coordinate queued work and application data so stale messages cannot repopulate reset records.
- WAL with `synchronous = NORMAL` can lose recent commits after an OS crash or power loss, including recently accepted queue messages or settlements.
- No package has been installed and no broker has been started at the time of this decision.

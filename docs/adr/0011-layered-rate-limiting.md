# ADR 0011: Apply layered rate limits and persistent AI budgets

- Status: Accepted
- Date: 2026-10-29

## Context

Chat and statement extraction can incur provider charges and occupy workers. Uploads also consume disk space and processing capacity. Authentication alone does not bound these costs.

The project owner accepted per-user controls backed by coarse IP protection, using Symfony facilities and the existing SQLite database. IP-only limits would penalize users sharing an address and remain bypassable by changing addresses.

## Decision

### Admission limits

Use Symfony RateLimiter for burst protection. Make the following initial defaults configurable:

| Resource | Initial per-user limit |
|---|---|
| AI submissions | 5 per minute and 100 per day, shared by chat and AI-powered statement extraction. |
| Active AI generation | 1 at a time, shared by chat and extraction. |
| Statement uploads | 5 files per minute and 20 files per day. |
| File size | 10 MiB per file. |

Also enforce daily uploaded-byte and retained-storage quotas. Bound pending jobs per user, total pending work, and instance-wide AI concurrency. Select those thresholds during bootstrap rather than leaving the queues or storage unbounded.

Use authenticated user identity as the primary quota key. Add coarser IP-based burst limits as an abuse backstop, not as a replacement for user limits or as the daily allowance for users sharing an address. Enable Symfony's built-in login throttling, initially using its default of 5 failed attempts per minute per IP and username, with its broader IP protection.

### AI consumption and persistent accounting

- Enforce an instance-wide daily token/cost budget across users and both AI workflows. Choose budget amounts after selecting the provider and model.
- Bound input sizes, output tokens, tool iterations, and execution time. A submission allowance alone does not bound the number or price of provider calls.
- Account for actual provider usage, including retries. Do not treat one accepted submission as exactly one model call.
- Store authoritative daily quota and AI-budget accounting in the shared application SQLite database from [ADR 0003](0003-shared-sqlite-database.md), not the broker database. Normal cache clearing and financial demo resets must not erase these counters.
- Do not rely on Symfony's default `cache.rate_limiter` pool for durable daily allowances or spending ceilings: clearing that cache resets limiter state. Use Symfony's locking facilities where needed for burst-limit concurrency.
- No Redis service is required for this policy.

### Concurrent admission and background work

Atomically check and reserve the applicable quota and capacity before accepting work for enqueueing. A rejected request must not enqueue a job. Concurrent requests must not each spend the same remaining allowance.

Enforce available budget again at the worker/model-call boundary before incurring provider usage. Account for outstanding reservations so concurrent provider calls cannot each consume the full remaining budget. Distinguish repeated delivery of the same operation from additional provider attempts: retries must not duplicate submission accounting, but any actual provider usage still counts.

Keep write transactions short. Never hold one open during uploading, parsing, broker communication, or provider calls. As [ADR 0004](0004-sqlite-queue.md) records, the application commit and broker publication are not one atomic transaction. Define recoverable publication, reservation settlement, and failure cleanup during bootstrap; this decision does not prescribe an outbox or another handoff mechanism.

### Responses and infrastructure boundaries

- Return `429 Too Many Requests` with `Retry-After` for request throttling and exhausted daily request allowances. Show the reason and next eligible time where applicable.
- Explain storage-capacity failures and background budget exhaustion clearly. A failure after enqueueing needs an observable job/UI state, not an HTTP response that no longer reaches the initiating request.
- Keep existing transactions, conversations, and artifacts readable when costly actions are restricted.
- Enforce body-size limits in PHP and the web server as well as application file validation. A file-count allowance does not cap transferred bytes.
- Configure trusted proxies explicitly before relying on client IP addresses. Do not trust arbitrary forwarded headers.
- Do not present application rate limiters as denial-of-service protection: they execute only after PHP and Symfony start. Network or web-server protection is a separate deployment concern.

## Alternatives considered

- IP-only limits do not provide fair per-user allowances and can be evaded by changing addresses.
- Request counts alone do not bound provider cost, concurrency, or retained storage.
- Default cache-backed daily accounting can be erased by a normal cache clear and is insufficient for a spending ceiling.

## Consequences

- Admission, background processing, provider calls, and reset operations must preserve one consistent accounting policy.
- Precise daily reset boundary and timezone, coarse IP thresholds, storage thresholds, pending-work limits, global concurrency, budget amounts, and reservation expiry/settlement rules remain bootstrap details to resolve.
- Verify compatible Symfony package APIs and shared-process storage/locking behavior against pinned releases during bootstrap. Do not infer runtime integration from the documentation examples.
- Verify the policy through headed Playwright scenarios in [TESTING.md](../../TESTING.md), including shared-IP users, concurrent admission, refused work, and persistent accounting.
- SQLite accounting retains the WAL `synchronous = NORMAL` durability trade-off in ADR 0003. Persistence across normal cache clears and demo resets is not a guarantee against every OS crash or power loss.
- No limiter, budget store, runtime configuration, or dependency has been installed at the time of this decision.

## References

- [Symfony Rate Limiter](https://symfony.com/doc/current/rate_limiter.html).
- [Symfony login throttling](https://symfony.com/doc/current/security.html#limiting-login-attempts).
- [Symfony trusted proxies](https://symfony.com/doc/current/deployment/proxies.html).

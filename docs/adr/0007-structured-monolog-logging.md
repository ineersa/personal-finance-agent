# ADR 0007: Use structured Monolog logs for observability

- Status: Accepted
- Date: 2026-10-29

## Context

Developers and coding agents need to diagnose statement processing, background jobs, model calls, and streamed responses across multiple processes. Financial data and model payloads are sensitive, so more logging must not mean recording their contents.

## Decision

- Use Symfony MonologBundle and PSR-3 loggers with Monolog's JSON formatter. Prefer framework facilities over a custom logging subsystem.
- Record structured lifecycle events with stable names, severity, channel, timestamp, and contextual fields. Cover uploads and extraction, review operations, chat generation, model/tool failures, queue retries, and administrative maintenance.
- Carry a correlation identifier from the initiating request into background work. Include relevant opaque record identifiers, such as statement, conversation, generation, and message IDs, where available.
- Record useful operational metadata such as durations, attempt counts, provider/model names, and token counts when supplied by the provider. Do not log every streamed text delta.
- Keep application lifecycle events available at informational level. Do not retain them only when an error triggers a buffered handler.
- Keep framework noise and deprecations separable from application events through channels and handler filtering.
- Keep readable JSON-lines files under the configured log directory for local development and Mate inspection. Bound retention and configure container output separately when packaging the runtime.
- Reset request and job context between executions in long-lived workers. Never attach one user's identifiers to the next operation.

## Data protection

Do not log bank-statement contents, transaction descriptions or amounts, raw chat prompts or responses, tool arguments/results, model request/response bodies, credentials, authorization cookies, or SQL parameter values by default.

Use an allowlist of diagnostic context and redact sensitive exception details before output. Treat log files and profiler data as private even after redaction. Do not expose logs through user-facing chat tools or public web routes.

Use synthetic fixtures when investigating payload-level problems. Any temporary increase in diagnostic detail must remain explicit, local, and bounded. Logging is not a substitute for financial correction history or a business audit trail.

## Alternatives considered

- Plain-text logs and ad hoc debug dumps are harder for agents to query and more likely to expose raw payloads.
- Logging complete AI exchanges would help some debugging but would unnecessarily duplicate sensitive financial and conversational data.

## Consequences

- Monolog context searches can connect an HTTP action to its background job and failure without reading full payloads.
- Logging processors, channel names, rotation limits, and exact handler configuration still need implementation and verification.
- Existing framework integrations and exception logging need review for unintended payload disclosure.
- No logging configuration or dependencies have been installed at the time of this decision.

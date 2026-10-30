# ADR 0007: Use structured Monolog logs for observability

- Status: Accepted
- Date: 2026-10-29

## Context

Developers and coding agents need to diagnose statement processing, background jobs, model calls, and streamed responses across multiple processes. Financial data and model payloads are sensitive, so more logging must not mean recording their contents.

## Decision

Amendment, 2026-10-30: the owner selected the web template's Monolog configuration without adaptations. This supersedes the earlier unbuffered lifecycle logging, custom correlation, sanitizing processor, and rotating-file design.

- Use Symfony MonologBundle and PSR-3 loggers. Copy `config/packages/monolog.yaml` byte-for-byte from `/home/ineersa/projects/symfony-web-template/config/packages/monolog.yaml`; treat that reference checkout as read-only.
- Development writes debug-level logs to `%kernel.logs_dir%/%kernel.environment%.log`, excluding event and deprecation channels from the main handler, and includes the template's console handler.
- Tests use an error-triggered `fingers_crossed` handler with a nested file handler, excluding HTTP 404 and 405 triggers.
- Production uses an error-triggered `fingers_crossed` handler with a buffer limit of 50 and a nested JSON stream on stderr. The console and dedicated JSON deprecation handlers follow the template.
- Do not add a custom HTTP lifecycle subscriber, correlation generator, sanitizing processor, or replacement logging framework. The configuration does not rotate local files or automatically add application correlation identifiers.

## Data protection

Do not log bank-statement contents, transaction descriptions or amounts, raw chat prompts or responses, tool arguments/results, model request/response bodies, credentials, authorization cookies, or SQL parameter values by default.

The standard configuration does not sanitize messages or context. Callers must avoid sensitive diagnostic context and redact sensitive exception details before logging them. Treat log files and profiler data as private even after redaction. Do not expose logs through user-facing chat tools or public web routes.

Use synthetic fixtures when investigating payload-level problems. Any temporary increase in diagnostic detail must remain explicit, local, and bounded. Logging is not a substitute for financial correction history or a business audit trail.

## Alternatives considered

- A custom logging layer adds behavior beyond the selected template configuration and is not adopted.
- Logging complete AI exchanges would help some debugging but would unnecessarily duplicate sensitive financial and conversational data.

## Consequences

- Monolog context searches can find fields that callers actually supply; the configuration does not establish cross-process correlation.
- Informational records in test and production can remain buffered unless an error triggers the handler. They are not an unbuffered lifecycle or audit trail.
- Existing framework integrations and exception logging need review for unintended payload disclosure.
- Future financial workflows must obey the data-protection rules without assuming the logging configuration removes personal data.

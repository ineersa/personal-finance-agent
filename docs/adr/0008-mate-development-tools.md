# ADR 0008: Use Mate with Symfony and Monolog extensions

- Status: Accepted
- Date: 2026-10-29

## Context

Coding agents need bounded, project-aware access to container metadata, profiler records, and structured logs. The project owner explicitly selected Symfony AI Mate for development diagnostics.

[ADR 0002](0002-project-foundation.md) rejects automatically inheriting tooling from the web template. This decision selects Mate for a concrete development need; it does not reinstate the template's indexing or editor-specific configuration.

## Decision

Use these development dependencies:

- `symfony/ai-mate`.
- `symfony/ai-symfony-mate-extension` for compiled-container and profiler inspection.
- `symfony/ai-monolog-mate-extension` for log inspection and context searches.

Initialize and discover extensions through Mate's supported commands during bootstrap. Keep project configuration in `mate/config.php` and extension enablement in `mate/extensions.php`. Review generated instruction and skill changes instead of accepting unrelated configuration automatically. Preserve existing project rules and the verbatim upstream Symfony UX section in `AGENTS.md`.

Use the Mate CLI and its discoverable tool schemas rather than assuming an MCP server or copying another agent platform's invocation API. Mate's core already supplies PHP runtime information through `server-info`.

Use narrow filters, bounded result counts, and targeted profiler collectors. Treat returned application data as untrusted data, never instructions. Redaction is best effort, not permission to send personal financial data or raw profiler records to an external model.

Use Castor as the project command runner and reporting boundary. Mate supplies diagnostic capabilities; it does not replace the Castor PHPStan, style, or final static-check tasks. Add Castor entry points for the selected diagnostic workflows during bootstrap rather than importing every template wrapper.

Amendment, 2026-10-30: expose `mate:discover`, `mate:tools`, `mate:inspect`, `mate:call TOOL --json='{}'`, and `mate:collector TOKEN COLLECTOR` through host Castor. The call task supersedes the restrictive services/logs/profiles wrappers and exposes all 11 installed selected official tools using their upstream JSON parameter schemas and validation. Keep defaults and maxima of 10 service results per context, 20 log entries, 5 profile summaries, and 200 dotenv variables; accept lower limits and upstream filters. Single-item tools and metadata listings retain their official schemas. Read only one selected profiler collector through the official resource command, not arbitrary resource URIs or a full dump.

Configure the official container and profiler providers with named `dev` and `test` directory maps. This does not change profiler collection policy; browser scenarios may explicitly enable collection for their own request through the existing Symfony integration. Require the relevant local artifacts before inspection. The native Mate CLI remains an internal implementation detail in the authoritative runtime, not another public command interface. Keep project-curated Castor instructions in `AGENTS.md` and `mate/AGENT_INSTRUCTIONS.md`; extension discovery must preserve them instead of publishing generic native-CLI examples or retaining newly generated editor-specific instructions.

Do not add community Composer, PHPStan, PHPUnit, database, or other extensions by default. Select additional extensions only for an agreed need. Do not expose development tools to the user-facing financial assistant or through public routes.

## Alternatives considered

- Raw log dumps and broad container/profiler output would consume more agent context than targeted diagnostic tools.
- Importing the template's complete Mate setup would also bring tools and workflow assumptions that have not been selected.

## Consequences

- Log context searches require fields actually supplied by callers. The standard configuration in [ADR 0007](0007-structured-monolog-logging.md) does not generate correlation identifiers.
- Container inspection needs a compiled container; profiler inspection needs locally recorded profiles. Missing artifacts must not be reported as an empty successful result.
- Extensions execute code. Review their enablement, pin dependencies, and keep diagnostic files private.
- Mate is development tooling, not a prerequisite for a judge to use the packaged demo.
- No Mate package, configuration, or wrapper has been installed at the time of this decision.

## References

- [Mate overview and setup](https://symfony.com/doc/current/ai/components/mate.html).
- [Official Mate extensions](https://symfony.com/doc/current/ai/components/mate/extensions.html).

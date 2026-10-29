# ADR 0009: Use Castor with concise reports and mandatory static checks

- Status: Accepted
- Date: 2026-10-29

## Context

Developers and coding agents need reproducible commands without filling their context with routine tool output. The sqlite-queue project already demonstrates Castor tasks that write diagnostics to files and print short result records.

The project owner selected Castor, PHPStan rules based on sqlite-queue, and `symfony lsp:check` as part of the final static check. Testing requirements remain a separate decision.

## Decision

### Commands and reporting

Use a project-local, Composer-locked Castor dependency as the command runner. Keep tasks in `.castor/`, imported by `castor.php`. Do not add parallel Composer QA scripts.

Adapt these read-only references rather than copying their package-specific tasks:

- `/home/ineersa/projects/sqlite-queue/.castor/tasks.php`.
- `/home/ineersa/projects/sqlite-queue/.castor/reports.php`.
- `/home/ineersa/projects/sqlite-queue/phpstan.neon.dist`.

Expose the following tasks when the application is bootstrapped:

| Task | Purpose |
|---|---|
| `composer:validate` | Validate Composer metadata and lock consistency. |
| `cs:fix` | Apply PHP CS Fixer changes, separately from validation. |
| `cs:check` | Check formatting without editing, with diffs in the report. |
| `phpstan` | Run the configured static analysis. |
| `lsp:check` | Run `symfony lsp:check` with JSON diagnostics. |
| `qa:static` | Run Composer validation, style checking, PHPStan, and LSP checking. |

Each task prints one JSON summary with `task`, `status`, `exit_code`, and a relative result path. Write detailed diagnostics to `var/qa/<task>/`, replacing colons in directory names with hyphens. Keep native JSON output where supported, otherwise capture stdout and stderr as logs.

Record command, timestamps, duration, exit code, and output paths in `result.json`. Mark an invocation `running` before execution so interruption cannot leave a stale pass. Preserve tool failure codes; missing tools, exceptions, and incomplete analysis must fail rather than be skipped.

The static aggregate runs all its checks, reports every result, and returns the first nonzero exit code. It does not modify source. Keep `cs:fix` separate. Do not run the same task concurrently against the same report directory.

### PHPStan

Start from sqlite-queue's level 6, `phpstan/phpstan-strict-rules`, `treatPhpDocTypesAsCertain: false`, and Castor extension. Adapt analysis paths to this application's source, task definitions, and PHP entry points. Do not import benchmark paths, a legacy baseline, or test tooling automatically.

Do not add baselines, error ignores, or disable strict rules merely to pass checks. Adapt any test-related analysis configuration to the browser-only harness selected in [ADR 0010](0010-playwright-only-testing.md), without importing the reference projects' testing setup.

### Symfony-aware diagnostics

Run `symfony lsp:check --format=json --environment=dev` from the project workspace through the Castor task. Use the application's compatible PHP runtime, installed dependencies, and a safe local environment. This check complements PHPStan by inspecting Symfony-specific wiring and references.

Require complete runtime-aware analysis. Do not silently substitute `--source-only`, ignore an incomplete result, or treat a missing checker as a pass. Runtime boot must not trigger model calls, consume queues, or perform business operations.

Include Symfony CLI and Symfony Language Tools in development prerequisites. The documented CLI integration requires Symfony CLI 5.20.0 or newer and can download the checker automatically. Record and verify the actual tool versions during bootstrap; do not assume a Composer dependency installs this command.

## Alternatives considered

- Raw tool output is useful for diagnosis but needlessly expensive for every successful agent command.
- PHPStan alone does not cover all Symfony-specific diagnostics supplied by Language Tools.
- Reusing sqlite-queue's complete QA task would import its test suite and benchmark assumptions before this project's testing design is agreed.

## Consequences

- Agents inspect a short summary first, then read only the reports needed to diagnose failures.
- QA reports are private, generated files and must not be committed.
- The final static gate must include LSP checking; a PHPStan-only pass is insufficient.
- Tests are not replaced by this static gate. [ADR 0010](0010-playwright-only-testing.md) selects the Playwright browser suite and a combined gate that runs both static checks and browser tests.
- No task runner, checker, PHPStan configuration, or application dependency has been installed at the time of this decision.

## References

- [Symfony lsp:check overview](https://symfony.com/blog/introducing-symfony-lsp-check-symfony-aware-diagnostics-in-your-ci).
- [Symfony Language Tools check reference](https://github.com/symfony/language-tools/blob/main/docs/check.rst).

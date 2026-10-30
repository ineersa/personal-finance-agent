# ADR 0009: Use Castor with concise reports and mandatory static checks

- Status: Accepted
- Date: 2026-10-29

## Context

Developers and coding agents need reproducible commands without filling their context with routine tool output. The sqlite-queue project already demonstrates Castor tasks that write diagnostics to files and print short result records.

The project owner selected Castor, PHPStan rules based on sqlite-queue, and `symfony lsp:check` as part of the final static check. Testing requirements remain a separate decision.

## Decision

### Commands and reporting

Use a project-local, Composer-locked Castor dependency as the command runner. Keep tasks in `.castor/`, imported by `castor.php`. Do not add parallel Composer QA scripts.

Amendment, 2026-10-30: Castor is the single public tooling interface. Invoke `vendor/bin/castor TASK` on the host; tasks own Docker, application PHP, display forwarding, reporting, and cleanup. Keep task-specific implementation in `.castor/`, not executable runners under `bin/`. Only the initial installation of locked dependencies may use Docker directly when the Composer-installed Castor executable does not yet exist. Routine preparation uses `tooling:prepare`; agents must not choose or prefix a second runtime interface.

The external task workflow executes literal `castor check` during its CODE-REVIEW transition. Expose `check` as the sole combined static/browser gate, with the same reporting, cleanup, and failure conventions as individual tasks. Keep `qa:static` for static-only validation. Do not add another combined command or alias, or execute the workflow gate prematurely during task-start.

Adapt these read-only references rather than copying their package-specific tasks:

- `/home/ineersa/projects/sqlite-queue/.castor/tasks.php`.
- `/home/ineersa/projects/sqlite-queue/.castor/reports.php`.
- `/home/ineersa/projects/sqlite-queue/phpstan.neon.dist`.
- `/home/ineersa/projects/sqlite-queue/.php-cs-fixer.dist.php`.

The selected static rule files are authoritative, with one owner-approved exception on 2026-10-30: set `php_unit_test_case_static_method_calls` to `call_type: self` instead of the reference's `this`. Call declared-static PHPUnit and Symfony assertions with `self::`; keep actual instance methods such as Playwright's `visit` and `assertPageContains` on `$this`. The existing fixer handles PHPUnit assertions; no custom fixer is added for Symfony helpers. Preserve all other rules and adapt project paths/entry points only. CS Fixer uses `@Symfony`, `@Symfony:risky`, binary fopen flags, strict types, the explicit class-element order, risky-rule enablement, and detected parallel configuration. Include application, task, and browser-test PHP in its Finder. Do not replace these rules with the bare Symfony recipe defaults.

Expose the following tasks when the application is bootstrapped:

| Task | Purpose |
|---|---|
| `composer:validate` | Validate Composer metadata and lock consistency. |
| `cs:fix` | Apply PHP CS Fixer changes, separately from validation. |
| `cs:check` | Check formatting without editing, with diffs in the report. |
| `phpstan` | Run the configured static analysis. |
| `lsp:check` | Run `symfony lsp:check` with JSON diagnostics. |
| `qa:static` | Run Composer validation, style checking, PHPStan, and LSP checking. |
| `check` | Run the static gate and headed browser suite during the workflow review transition. |

Each task prints one JSON summary with `task`, `status`, `exit_code`, and a relative result path. Write detailed diagnostics to `var/qa/<task>/`, replacing colons in directory names with hyphens. Keep native JSON output where supported, otherwise capture stdout and stderr as logs.

Record command, timestamps, duration, exit code, and output paths in `result.json`. Mark an invocation `running` before execution so interruption cannot leave a stale pass. Preserve tool failure codes; missing tools, exceptions, and incomplete analysis must fail rather than be skipped.

The static aggregate runs all its checks, reports every result, and returns the first nonzero exit code. It does not modify source. Keep `cs:fix` separate. Do not run the same task concurrently against the same report directory.

### PHPStan

Use sqlite-queue's level 6, `phpstan/phpstan-strict-rules`, `phpstan/phpstan-phpunit` extension, `treatPhpDocTypesAsCertain: false`, and Castor extension. Adapt analysis paths to this application's source, task definitions, configuration, and PHP entry points. Match the reference's production/tooling scope rather than adding browser-test paths. Do not import benchmark paths or a legacy baseline.

The reference does not analyze its test files. Keep that production/tooling scope. Browser tests receive style checking and real-browser execution; do not report them as PHPStan-covered.

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

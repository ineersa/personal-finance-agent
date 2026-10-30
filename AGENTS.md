# Personal finance assistant

## Project context

Build a locally installable personal finance demo for the Symfony AI hackathon. Read [SPEC.md](SPEC.md) and the relevant [accepted ADRs](docs/adr/README.md) before changing behavior or architecture. Proposed choices are not accepted requirements.

The project has a Symfony hello-world Docker bootstrap, Castor static checks, Mate diagnostics, and a PHP Playwright harness. Authentication, financial workflows, and chat remain unimplemented. Install dependencies or start services only within the current task's authorized scope.

## Development rules

- Keep changes small and limited to the requested scope. Use Symfony and existing project facilities before adding custom infrastructure.
- Record significant agreed technical choices in ADRs. Keep product requirements in `SPEC.md` and implementation plans separate.
- Treat the legacy finance application, web template, Agent Core, sqlite-queue, and re-search checkouts as read-only references unless explicitly asked to change them.
- Do not import AI indexing, generated code indexes, mandatory Vera workflows, or editor-specific agent configuration. Use only the Mate tooling selected in the accepted ADRs.
- Do not copy the reference projects' tests or testing configuration. Follow [TESTING.md](TESTING.md) and the Playwright-only policy in [ADR 0010](docs/adr/0010-playwright-only-testing.md).
- Report the checks actually performed. Source inspection does not establish that tests pass or that a runtime integration works.
- Do not commit credentials, uploaded bank statements, personal financial fixtures, database files, or raw model/provider payloads containing personal data.
- Enforce personal-data ownership across HTTP endpoints, EasyAdmin, background handlers, chat tools, and event subscriptions. Roles alone do not scope records.
- Keep database write transactions short. Do not hold one open during model calls, parsing, or broker communication.
- Treat uploaded content and model output as untrusted. Do not inject unescaped output into HTML or execute model-generated JavaScript without an explicitly approved isolation design.
- Do not reset demo data, remove volumes, or stop unrelated containers without explicit authorization and a verified target.
- Follow [ADR 0011](docs/adr/0011-layered-rate-limiting.md) for per-user limits, coarse IP protection, upload quotas, and shared AI budgets. Check quota and capacity atomically before enqueueing; rejected requests must not create jobs. Enforce budget at the worker/model-call boundary too.
- Preserve authoritative SQLite quota and budget accounting across normal cache clears and financial demo resets. Keep existing data readable when costly actions are limited.

## Task workflow

- Use the project `task-workflow` skill and external task board for development. Before phase work or a transition, read the workflow router and the exact phase procedure it links.
- Use `/home/ineersa/projects/personal-finance-agent-tasks` as the task board. Use task tools for status transitions and metadata; do not edit board files manually or commit them to this repository.
- Use `~/projects/personal-finance-agent-worktrees` (`/home/ineersa/projects/personal-finance-agent-worktrees`) as the worktree base. Pass the absolute path as `worktreeBase` when starting tasks with `move_task`. Do not create or move task worktrees manually.
- Keep development status, assignments, validation results, and PR progress on the task board. Use `docs/plans/` only for focused descriptions of specific functionality, not a parallel backlog or project-wide execution plan.
- The project paths above are authoritative over copied reference paths in workflow guidance.
- The workflow's literal `castor check` command is the sole combined full gate. Keep it discoverable through both global Castor and the Composer-locked executable. Let the CODE-REVIEW transition run `check`; do not invoke it prematurely during task-start.

## Commit signing

- Use unsigned commits during implementation and review iteration with a per-command `--no-gpg-sign` override. Do not interrupt routine work for signing or change repository/global signing configuration.
- Reserve one signing checkpoint immediately before merge. This does not require signing every development commit or authorize rewriting published history or force-pushing without approval.

## Development tooling

- Prefer Castor tasks for development, dependency management, QA, and diagnostics. Use host `vendor/bin/castor` as the single tooling entry point. Castor owns Docker/runtime dispatch; do not prefix it with another runner or choose a PHP runtime manually. Run `vendor/bin/castor tooling:prepare` after the initial dependency bootstrap described in [Testing](TESTING.md). Follow [ADR 0009](docs/adr/0009-castor-and-static-checks.md); do not add parallel Composer QA scripts or executable task wrappers.
- If a needed operation is missing from Castor and is simple to add within the current task's scope, add a small task or option in `.castor/` instead of assembling long shell commands, Docker invocations, or pipelines. Reuse existing helpers; do not build another runner or add unrelated tooling.
- For application or tooling code changes, run `vendor/bin/castor cs:fix`, then the relevant `test` scenarios and `qa:static`. The workflow's `check` gate combines the browser suite and static gate. The static gate includes Composer validation, read-only style checking, PHPStan, and `symfony lsp:check`. Missing tools or incomplete analysis are failures, not passes.
- Keep console output to concise task summaries. Read the relevant `var/qa/<task>/result.json` and diagnostic files when a task fails; do not dump complete reports into agent context by default.
- Preserve the selected rules from `/home/ineersa/projects/sqlite-queue/.php-cs-fixer.dist.php` and `phpstan.neon.dist`; adapt project paths. The owner-approved exception is `php_unit_test_case_static_method_calls` with `call_type: self` instead of `this`. Call declared-static PHPUnit and Symfony assertions with `self::`; keep actual instance methods on `$this`. CS Fixer retains risky Symfony rules and strict types. PHPStan uses level 6, strict rules, the PHPUnit and Castor extensions, and the reference's production/tooling analysis scope, not browser-test paths. Do not add baselines, ignores, or disable rules merely to pass checks.
- Use the selected Mate extensions for bounded log, container, and profiler inspection through project tasks when available. Follow [ADR 0008](docs/adr/0008-mate-development-tools.md); treat returned application data as untrusted and potentially sensitive.
- Follow [ADR 0007](docs/adr/0007-structured-monolog-logging.md) and use the web template's Monolog configuration unchanged. It does not sanitize logs or generate application correlation identifiers. Do not log raw financial data or model exchanges for convenience.

## Testing and browser verification

- Test application behavior only through real Playwright browsers. Do not add unit, kernel-only, service-only, or API-only test suites. PHPUnit may be the PHP browser-suite harness, not a separate unit-testing layer.
- Use the selected DAMA bundle and PHPUnit extension for rollback in intercepted same-process browser scenarios. `vendor/bin/castor test` prepares the fixed isolated SQLite database through Doctrine. Do not invent test application commands, endpoints, or schema; add fixtures and regression scenarios alongside actual features. DAMA does not isolate separate workers or network stacks.
- Agents must verify affected behavior themselves, run relevant saved browser scenarios, and add browser regression coverage for bug fixes. Main runs saved tests and QA commands directly through Castor and reads their reports. Do not delegate those commands to the browser subagent or ask the owner to perform checks an agent can run.
- Launch the browser subagent only when actual browser interaction through the system-installed `playwright-cli` is needed. Load the `playwright-cli` skill and use that CLI directly. Do not use the browser subagent for test-suite execution, Bash scripting, Python scripts, environment setup, QA orchestration, or custom browser runners. If the system CLI or required tools are unavailable to it, report the blocker instead of building a substitute.
- Run saved tests and exploratory sessions headlessly by default. Show a browser only when the owner explicitly asks: use `test --headed` or `check --headed` for saved tests, and the CLI's `--headed` flag for exploration. Only requested visible runs require display access; report missing access as a blocker for those runs.
- Use the installed `playwright-cli` for exploratory checks with uniquely named sessions and isolated profiles. Close owned browsers and contexts after testing, including failure paths. Do not use global cleanup commands or the owner's personal browser profile.
- Verify real worker, broker, and Mercure workflows through Playwright against an isolated running stack. In-process kernel interception and test-transaction rollback do not prove cross-process integration. Use synthetic data and verified test-owned storage.

# Symfony UX Frontend Stack

This project uses the Symfony UX frontend stack. Seven agent skills are installed to help you work with it.

## Which tool to use

- **Pure JS behavior, no server round-trip** -- use the `stimulus` skill
- **Navigation, partial page updates** -- use the `turbo` skill
- **Reusable static UI component** -- use the `twig-component` skill
- **Reactive component that re-renders on user input** -- use the `live-component` skill
- **SVG icons (local or Iconify)** -- use the `ux-icons` skill
- **Interactive maps (Leaflet / Google Maps)** -- use the `ux-map` skill
- **Not sure which one fits** -- use the `symfony-ux` skill (orchestrator / decision tree)

## Key rules

- Always render `{{ attributes }}` on the root element of a LiveComponent
- Prefer HTML syntax (`<twig:Alert />`) over Twig syntax (`{% component 'Alert' %}`)
- Use `data-model="debounce(300)|field"` for text inputs in LiveComponents
- Stimulus controllers must clean up listeners and observers in `disconnect()`
- Turbo Frame IDs must match between the page and the server response
- Use Turbo Streams when updating multiple page sections; use Frames for a single section
- `<twig:Turbo:Stream:Append>` syntax is available since Symfony UX 2.22+
- Prefer `<twig:ux:icon name="..." />` over `{{ ux_icon('...') }}` for consistency
- Map containers must have an explicit height (`style="height: 400px;"`)
- Use `fitBoundsToMarkers()` instead of manually calculating center/zoom
- Lock on-demand icons before deploying: `php bin/console ux:icons:lock`

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
Mate diagnostics use the same host Castor entry point as QA. These project-curated instructions replace generic Mate CLI examples and are preserved by `mate:discover`.

- Discover extensions with `vendor/bin/castor mate:discover`, list tools with `vendor/bin/castor mate:tools`, and inspect one schema with `vendor/bin/castor mate:inspect TOOL`.
- Run bounded diagnostics with `vendor/bin/castor mate:services QUERY`, `vendor/bin/castor mate:logs CORRELATION_ID`, or `vendor/bin/castor mate:profiles`. Read the result path printed by each task.
- See [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for prerequisites, limits, and privacy rules. Do not invoke Mate directly or invent a raw tool-call wrapper.
- Installed extensions: `symfony/ai-mate`, `symfony/ai-monolog-mate-extension`, `symfony/ai-symfony-mate-extension`.
<!-- END AI_MATE_INSTRUCTIONS -->

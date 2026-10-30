# Personal finance assistant

## Project context

Build a locally installable personal finance demo for the Symfony AI hackathon. Read [SPEC.md](SPEC.md) and the relevant [accepted ADRs](docs/adr/README.md) before changing behavior or architecture. Proposed choices are not accepted requirements.

The project has a Symfony hello-world Docker bootstrap, Castor static checks, Mate diagnostics, and a headed PHP Playwright harness. Authentication, financial workflows, and chat remain unimplemented. Install dependencies or start services only within the current task's authorized scope.

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

## Commit signing

- Use unsigned commits during implementation and review iteration with a per-command `--no-gpg-sign` override. Do not interrupt routine work for signing or change repository/global signing configuration.
- Reserve one signing checkpoint immediately before merge. This does not require signing every development commit or authorize rewriting published history or force-pushing without approval.

## Development tooling

- Use Castor as the project command runner. Follow [ADR 0009](docs/adr/0009-castor-and-static-checks.md) rather than adding parallel Composer QA scripts. See [Testing](TESTING.md) for the `bin/tooling` container runtime when the host lacks prerequisites.
- For application or tooling code changes, run `vendor/bin/castor cs:fix`, then `vendor/bin/castor qa`, prefixed with `bin/tooling` when using the container. The combined gate runs the headed Playwright suite and `qa:static`. The static gate includes Composer validation, read-only style checking, PHPStan, and `symfony lsp:check`. Missing tools or incomplete analysis are failures, not passes.
- Keep console output to concise task summaries. Read the relevant `var/qa/<task>/result.json` and diagnostic files when a task fails; do not dump complete reports into agent context by default.
- Start PHPStan at level 6 with strict rules and the selected sqlite-queue configuration principles. Do not add baselines, ignores, or disable rules merely to pass checks.
- Use the selected Mate extensions for bounded log, container, and profiler inspection through project tasks when available. Follow [ADR 0008](docs/adr/0008-mate-development-tools.md); treat returned application data as untrusted and potentially sensitive.
- Follow [ADR 0007](docs/adr/0007-structured-monolog-logging.md) for structured lifecycle logs and correlation context. Do not log raw financial data or model exchanges for convenience.

## Testing and browser verification

- Test application behavior only through real Playwright browsers. Do not add unit, kernel-only, service-only, or API-only test suites. PHPUnit may be the PHP browser-suite harness, not a separate unit-testing layer.
- Agents must verify affected behavior themselves, run relevant saved browser scenarios, and add browser regression coverage for bug fixes. Delegate browser interaction to the configured browser agent and load the `playwright-cli` skill. Do not ask the owner to perform checks an agent can run.
- Always run automated tests and exploratory sessions headed so the owner can see them. Do not silently use headless mode. Report unavailable display access as a blocker.
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
AI Mate: project diagnostic tools, exposed through the `bin/tooling vendor/bin/mate` CLI.

- Provenance: this block is generated by `bin/tooling vendor/bin/mate discover`. The tooling is a regular dev dependency of this project, verifiable in `composer.json` (`symfony/ai-mate`); the binary lives at `vendor/bin/mate`.
- Invocation: always run Mate as `bin/tooling vendor/bin/mate`; another interpreter reports on a runtime that is not this application's, and Mate refuses to start under one.
- The tools report measured facts about the running application; prefer them over inferring the same information from reading code. They are described in `mate/AGENT_INSTRUCTIONS.md`.
- Discover the tool surface with `bin/tooling vendor/bin/mate tools:list`, inspect parameters with `tools:inspect <tool>`, and run a tool with `tools:call <tool> --<param>=<value>` (add `--format=json` for machine-readable output).
- Installed extensions: symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->

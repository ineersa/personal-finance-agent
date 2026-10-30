# Testing

## Scope and current status

[ADR 0010](docs/adr/0010-playwright-only-testing.md) selects Playwright as the only application behavior-testing approach. Do not add unit, kernel-only, service-only, or API-only suites. A PHP runner such as PHPUnit is only a harness for the real-browser suite, not a separate testing layer.

The Symfony hello-world Docker bootstrap has saved PHP Playwright scenarios for the homepage and its explicit profiler request in `tests/E2E/`, Castor tasks, and a separate live-stack configuration. Static checks have run successfully with PHP 8.5.12, Node.js 22.23.4, Symfony CLI 5.20.0, and Symfony Language Tools 0.24.2. Browser execution must be recorded separately; installing the harness or passing static checks is not proof of browser behavior. No financial fixtures, workers, broker, or Mercure workflows exist yet.

## Prepare the tooling runtime

Use Linux amd64 with Docker, Docker Compose 2.24.4 or newer, Git, and host PHP 8.5 CLI with PCNTL/POSIX for Castor orchestration. Only explicitly requested headed runs need a visible X11 display and host `xauth`. Application commands always run in the authoritative tooling container, including when the host lacks BCMath. The tooling image includes PHP 8.5, the application's extensions, Node.js, Chromium system libraries, and Symfony CLI. Language Tools downloads on its first invocation and needs network access.

On a fresh checkout without `vendor/bin/castor`, install the locked dependencies once using the existing development Docker target. This bootstrap is necessary before the Composer-installed command exists:

```sh
	docker build --target frankenphp_dev -t personal-finance-agent-bootstrap .
	docker run --rm --user "$(id -u):$(id -g)" --volume "$PWD:/app" --entrypoint composer personal-finance-agent-bootstrap install --no-interaction
```

After that bootstrap, use only host Castor for routine preparation and tooling:

```sh
	vendor/bin/castor tooling:prepare
	vendor/bin/castor cs:fix
	vendor/bin/castor qa:static
	vendor/bin/castor test
```

`tooling:prepare` builds the development-only image, installs locked dependencies, and installs Chromium. Its constituent tasks are `tooling:build`, `composer:install`, and `browser:install`. Castor runs application tools as the host UID/GID in disposable containers and mounts this checkout. Its private home, browser cache, Symfony CLI cache, and per-invocation display cookie live under ignored `var/tooling/`. Only for explicitly requested `--headed` runs does it pass `DISPLAY`, a read-only X11 socket mount, and the current display's Xauth entry. Default runs require no display or Xauth access. It does not use `xhost` or the owner's browser profile. An unavailable display fails a requested headed run; a failed browser launch fails either mode.

Castor sets the literal `PLAYWRIGHT_HEADLESS=true` for default tests and `false` for `--headed`; PHPUnit XML and `.env.test` do not override the choice. Saved scenarios extend the integration's `PlaywrightTestCase` directly. Use `vendor/bin/castor test --headed` or `vendor/bin/castor check --headed` only when the owner explicitly requests visibility. The `check` task forwards this option to `test`. Each test uses fresh browser contexts. The bridge closes contexts after tests and stops its shared browser after the class. Its pinned Node server closes owned browsers when its PHP parent's stdin closes, including termination paths. The Castor report/runtime helpers forward normal interruption signals, bound browser execution to five minutes, and clean up only the invocation's named container after checking its ownership label. They also remove its private display cookie. Docker's init process and container removal terminate owned remaining processes. Abrupt host failure still requires inspecting task-owned leftovers, not global browser cleanup.

The default suite uses intercepted Symfony requests, not a network application server. Test SQLite storage is `var/browser/application.db`, separate from development `var/app.db`; shared test files use `var/browser/share`. The test Doctrine configuration fixes the SQLite URL explicitly rather than resolving ambient `DATABASE_URL` overrides. SQLite does not support Doctrine's database-name suffix isolation. `test` invokes `test:database:prepare`, which creates the parent directory, rejects symlinked storage, and runs `doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration` in the application runtime. SQLite creates a missing database file when Doctrine connects; pending application migrations establish or update its schema. The pinned DBAL 4 SQLite platform does not support `doctrine:database:create`. Preparation does not drop databases or reset data.

DAMA is a development dependency, registered only in the test environment with its PHPUnit extension. The extension begins and rolls back a transaction for each same-process browser scenario. DBAL 4 requires no additional savepoint setting. The [domain schema](docs/domain-persistence.md) exists, but financial browser fixtures and CRUD scenarios do not yet. The homepage scenarios verify browser/runtime setup, not entity rollback, financial persistence or owner isolation. Add synthetic fixtures and observable persistence, ownership and rollback regression scenarios alongside the real CRUD workflows. Do not add a test application command, endpoint, or invented schema merely to test the test bundle. Do not use development data as fixtures.

### Isolated live stack

Use this path for exploratory checks and future direct-network PHP browser scenarios. It does not demonstrate worker, broker, or Mercure integration by itself.

```sh
	vendor/bin/castor browser:stack:up
	vendor/bin/castor browser:stack:status
	# Browser agent: open the printed URL in a unique session; add --headed only on an owner request.
	vendor/bin/castor browser:stack:stop
```

Castor layers `compose.browser.yaml` over the base and development files. The resulting stack uses its own checkout-scoped Compose project and `http://<checkout-id>.browser.finance.localhost` route through the shared loopback-only proxy. It publishes no host ports. Source is read-only; `var/browser-live` is mounted as the entire writable `/app/var`. Its SQLite URL points to `/app/var/browser/application.db`. Development keeps separate storage and a different hostname. Never reuse development worker, queue, or upload storage when adding background services. Stop only this stack; do not remove volumes or reset data as part of normal validation.

For a saved direct-network regression, start two owned stacks, then pass their printed URLs as one positional comma-separated argument:

```sh
	vendor/bin/castor dev:stack:up
	vendor/bin/castor browser:stack:up
	vendor/bin/castor browser:routes:test 'http://<checkout-id>.finance.localhost,http://<checkout-id>.browser.finance.localhost'
```

`tests/Live/RoutingTest.php` launches real headless Chromium without Symfony request interception. It checks HTTP success, the heading, loaded CSS, disabled controls, and independent browser storage across origins. The task attaches only its disposable tooling container to the shared proxy network and passes the inspected proxy IPv4 address to Chromium's native `--host-resolver-rules`. It does not change host DNS or add a DNS server. This separate command requires live stacks; the default `test` and `check` suites remain self-contained intercepted-browser scenarios. Routing coverage does not prove worker, broker, or Mercure integration.

For worktree-isolation verification, start stacks from two task-managed worktrees, run the routing scenario against both URLs, stop one checkout, and confirm the other URL still renders through Playwright. Inspect the stopped checkout's route failure too. Preserve evidence before rerunning a task because its report directory is reused. Stop each owned stack before task-worktree removal; the shared proxy stays running until `dev:proxy:stop` succeeds with no registered routes.

Use `browser:routes:test "$SURVIVING_URL" --absent="$STOPPED_URL"` to save the survivor and exact unregistered HTTP 404 assertions together. Omit the positional argument when all supplied routes are expected absent; pass at least two distinct URLs across both lists. Follow the [routing lifecycle regression procedures](docs/development-routing-regressions.md) to verify cleanup while the proxy is stopped, persisted configuration after restart, and SIGTERM cancellation during a controlled subprocess. Process and registry probes corroborate browser assertions; they do not replace them.

## Agent verification workflow

Use this procedure once the application and relevant project tasks exist. Delegate browser interaction to the configured browser agent and load the `playwright-cli` skill. The agent must perform the checks, not ask the owner to operate the browser.

1. Select the affected workflows and observable assertions. Include a failure or ownership-denial path when relevant.
2. Use project Castor tasks to prepare isolated synthetic fixtures and start only the services needed for the scenario. Verify the target URL and test storage before any reset or cleanup.
3. Run the relevant saved browser scenarios. Use headless mode unless the owner explicitly requests visibility.
4. Open a uniquely named CLI session when further investigation is needed. Read the page snapshot before interacting and use current element references.
5. Assert the expected result. For background operations, wait on observable UI state with a bounded timeout. Do not replace assertions with sleeps or treat a click, screenshot, or successful page load as proof of the workflow.
6. Inspect bounded browser diagnostics, Mate logs, or profiler records when a check fails. Fix the cause and rerun the affected scenario. Add a repeatable browser regression case for a bug fix.
7. Save useful failure evidence, then close all browser resources owned by this run. Clean up only its test processes and data. Ensure teardown also runs on errors and normal interruption.
8. Report the command or scenario, assertions checked, result, and evidence paths. State any blocked, untested, or mocked integration explicitly.

Show a browser only on an explicit owner request. If display access is unavailable for that requested visible run, report the blocker rather than silently changing mode. Do not use `close-all`, `kill-all`, or the owner's personal profile to recover a session. Verify ownership before cleaning up a leftover browser.

### CLI session commands

Prefer project Castor browser tasks when available. The underlying commands for the installed CLI are shown below. Set `APP_URL` to the verified test application URL and `BROWSER_SESSION` to a unique name for this run.

```sh
	playwright-cli -s="$BROWSER_SESSION" open "$APP_URL"
	playwright-cli -s="$BROWSER_SESSION" snapshot
	playwright-cli -s="$BROWSER_SESSION" close
```

Arrange cleanup before opening the browser. The final command is required even when assertions fail. An unexpected process termination may prevent teardown; report that condition rather than claiming the browser was closed.

## Automated browser suite

Use the PHP Playwright/Symfony approach from the [SymfonyCasts article](https://symfonycasts.com/blog/playwright-php-symfony). Keep browser launch configuration headless by default. Use the explicit `--headed` Castor option only when the owner requests visibility.

Use fresh browser contexts and synthetic users. Cover the real login form even if other scenarios use the integration's authentication helpers. Use stable accessible locators and Playwright waiting/assertion facilities rather than timing guesses.

Kernel interception is suitable for browser workflows handled inside the test process. For queue, worker, and Mercure checks, visit a real isolated running application with Playwright and observe browser results. Verify that this mode uses real network delivery rather than intercepted in-process responses.

Commit fixtures that workers must read. Do not wrap a cross-process run in one long test transaction: independent workers do not share that connection, and SQLite writer locks can block them. Keep the test application database, broker database, uploads, and worker processes separate from development/demo state. Reset only verified test-owned resources.

Do not assume the article's transaction rollback technique cleans up the whole live stack. Do not introduce a non-browser integration suite to work around these constraints.

### Command contract

Run these tasks from the host checkout. Castor owns their runtime selection:

| Command | Scope |
|---|---|
| `vendor/bin/castor test` | Run the headless Playwright browser suite with cleanup and saved diagnostics; `--headed` requires an owner request. |
| `vendor/bin/castor qa:static` | Run the static gate defined in ADR 0009, including `symfony lsp:check`. |
| `vendor/bin/castor check` | Sole combined static/browser gate, executed by the workflow's literal `castor check` at the CODE-REVIEW transition. |

Pass native PHPUnit arguments after Castor's `--` separator. Castor owns visibility, the JUnit path, and failure on an empty test selection; PHPUnit owns file selection, filters, groups, suites, and listing options. Listing commands do not launch a browser. `check` accepts only its visibility option and always invokes the full suite, never a filtered selection.

```sh
	vendor/bin/castor test -- tests/E2E/HomepageTest.php
	vendor/bin/castor test -- tests/E2E/HomepageTest.php tests/E2E/HomepageProfilerTest.php
	vendor/bin/castor test -- --filter='testHomepage$|testHomepageProfile$'
	vendor/bin/castor test -- --group=profiler
	vendor/bin/castor test -- --testsuite='Browser suite'
	vendor/bin/castor test -- --list-tests
	vendor/bin/castor test -- --list-groups
```

The profiler scenario exercises the real homepage with the integration's `enableProfiler()` and `getProfile()` methods. It records a test-context artifact without changing the default `collect: false` policy. Main runs these saved tests directly; the browser subagent remains limited to actual system `playwright-cli` interaction.

Use `app:composer` for application Composer, not Castor's built-in `composer` command, which manages Castor's own dependencies. Use `app:console` for Symfony application console commands. Both forward native argv after `--` through the existing runtime/report boundary, without a shell parser. Keep quotes around a single argument containing spaces or regular-expression metacharacters. `composer:install` also accepts native install options after `--`.

```sh
	vendor/bin/castor app:composer -- --version
	vendor/bin/castor app:composer -- check-platform-reqs
	vendor/bin/castor composer:install -- --dry-run --no-scripts
	vendor/bin/castor app:console -- about --env=test
	vendor/bin/castor app:console -- cache:warmup --env=dev
```

Read each task's result before another invocation overwrites the same report directory. Tool failures retain their exit codes. For official Mate filters, context maps, bounds, and targeted collector access, see [Mate instructions](mate/AGENT_INSTRUCTIONS.md).

The individual static tasks are `composer:validate`, `cs:check`, `phpstan`, and `lsp:check`. `cs:fix` modifies PHP formatting separately. The selected sqlite-queue CS Fixer rules apply to application/task/test PHP and selected entry points, with one owner-approved exception: `php_unit_test_case_static_method_calls` uses `call_type: self`. Call declared-static PHPUnit and Symfony assertions with `self::`; keep actual instance methods such as Playwright's `visit` and `assertPageContains` on `$this`. PHPStan preserves its strict level 6 rules, PHPUnit/Castor extensions, and production/tooling scope; it does not analyze browser-test files. Each task writes `var/qa/<task>/result.json`, with colons replaced by hyphens, and prints a short JSON summary. Reports include command, timestamps, duration, status, exit code, and stdout/stderr paths. Native JSON is preserved for style, PHPStan, LSP, and Mate tasks. The `test` report contains database/browser child summaries; PHPUnit diagnostics are in `var/qa/test-browser/`, with JUnit at `var/qa/test/junit.xml`. Do not run the same task concurrently: its report directory is locked. Normal interruption records a failure after cleanup; abrupt interruption can leave `running`, never a stale pass. QA aggregates execute all children and preserve the first failure; interruption stops further work. Their stdout files contain each child's summary.

The LSP task requires both global and project completeness, runtime mode, and ready runtime state. It never uses source-only analysis. Read only the failing task's diagnostic files before widening inspection. Reports, logs, profiles, cookies, screenshots, and traces remain private generated data.

Run `vendor/bin/castor cs:fix` before validation when application or tooling code changes. During task-start, run affected browser scenarios and `qa:static`; let the CODE-REVIEW transition execute `check`. Keep task output concise, preserve failures, and save reports under `var/qa/`. A missing browser, unavailable display for a requested headed run, startup failure, or interrupted suite must not produce a passing result.

## Initial coverage requirements

Implement browser scenarios alongside each supported feature:

- Authentication: login, rejected credentials, logout, and protected pages.
- Ownership: at least two users with distinct data. Check lists, direct record URLs, association selectors, downloads, aggregates, conversations, saved inline chat output, and private event access. An administrator role does not grant access to everyone's finances.
- Statement processing: valid uploads, rejected uploads, processing progress, failures, extracted results, and persistence after reload.
- Review: transaction corrections and source creation/editing, including invalid submissions.
- Financial questions: synthetic history with known expected totals for subscriptions, yearly income, and internet bills. Assert results, not just the presence of answer text.
- Chat and inline output: input, safe text/table rendering, streamed updates, completed chart images, failure states, and reload/reconnect recovery. Include real worker/broker/Mercure delivery checks.
- Untrusted content: uploaded and model-supplied text must not execute scripts or render unsafe HTML.
- Administration: account management and authorized reset behavior once reset scope and safeguards are defined.

### Statement conversion

Cover [ADR 0016](docs/adr/0016-local-markitdown-conversion.md) through browser uploads and the actual worker/local MCP converter. Use synthetic digital PDF/XLSX and PHP-parsed CSV fixtures with known dates, amounts, and expected transactions; assert validation/review and stored results, not just generated Markdown.

Exercise unsupported, malformed, no-text/image-only, conversion-error, and bounded-output cases without fabricating financial records or claiming OCR succeeded. Verify owner isolation, authorized staging access, cleanup, and hostile document text treated as data rather than instructions. Ordinary digital conversion must not require an LLM/API key; subsequent AI extraction remains a separate configured-provider step. Do not claim scanned-PDF support until an existing OCR backend is explicitly selected and verified.

### Finance precision, review, and analysis

Cover [ADR 0017](docs/adr/0017-exact-money-and-ecb-reporting.md) and [ADR 0018](docs/adr/0018-finance-review-sql-and-jev.md) through browser scenarios with synthetic fixtures:

- Verify exact high-precision amount round trips, sums, and EUR-based cross-conversion with known decimal results. Changing reporting currency must preserve originals. Exercise missing-date/weekend handling once chosen; do not assume a fallback or exact SQLite `SUM`.
- Verify editable drafts require valid user approval before analysis, preserve the statement date and account-source meaning, and do not treat every credit as income. Redelivery must not duplicate imports.
- Exercise model-written SQL with attempted owner/table/write-access violations and bounded results. Confirm external enforcement rather than a prompted owner clause, and precision-preserving money results.
- Verify LLM row extraction from converted Markdown/text separately from Jev categorization/decision checks. Exercise malformed/truncated/empty JSON and invalid dates, currencies, fields, or money values. LLM output must not automatically approve/commit financial records, and no invalid output or unapproved draft may feed analysis. Preserve monetary strings rather than JSON floats, and keep CSV PHP parsing independent of a forced LLM step.
- Exercise JSON decoding, DTO denormalization, and Symfony Validator failures. Verify bounded correction requests include specific errors and retain the original statement context. Cover a successful correction that produces only an editable draft and exhausted attempts that report failure without accepting invalid records.
- Verify extraction provider/model configuration is independent of chat, uses only enabled/configured allowed integrations, and captures validated choices for queued work. Do not infer uniform native JSON Schema support, automatic failover, or runtime bridge compatibility from provider documentation. Jev Choice/Score/Noul checks do not establish arbitrary-field extraction or OCR.
- Verify target-model history conversion leaves canonical stored history unchanged and isolates tool IDs/reasoning state between concurrent users. Reconstructing context is not resuming an exact provider execution.

Any cassettes/replay used for final verification must be identified as such, not reported as live provider integration.

### Inline charts

Cover [ADR 0014](docs/adr/0014-inline-chat-and-antv-charts.md) through browser scenarios. Verify actual Docker MCP chart generation through the worker and private Mercure delivery, not just a substituted image. Use synthetic aggregates only for external-renderer tests.

Check unsafe text/table content and untrusted image references, owned saved chat-output access, and reload/reconnect behavior. Exercise hosted-renderer failures and unavailable images with clear chat feedback and retained conversation state still readable. Do not claim that external image URLs are private or permanently available. Exact image lifecycle remains open; chat retention follows ADR 0015 below.

### Conversation persistence and retention

Cover [ADR 0015](docs/adr/0015-sqlite-conversation-history.md) through Playwright scenarios against the isolated application/worker stack:

- Verify temporary-by-default creation, Save chat, and reload of retained messages and generation state from the shared application database.
- Give two users distinct histories. Verify five temporary chats per user, saved chats outside that count, owner-only access, and pruning of only the owner's oldest inactive temporary chat when a sixth is created.
- Keep queued/running conversations protected. Exercise creation when no eligible victim exists; verify no protected deletion or silent count increase without assuming a new admission policy.
- Race Save chat, new-chat creation, and work admission in separate browser contexts. Verify atomic counts and protection of newly saved or queued/running conversations.
- Verify pruning leaves source financial data and accounting/idempotency information needed for admitted work intact. Use bounded test-owned diagnostics when browser output alone cannot establish this.
- Distinguish the five stored temporary chats from the unchanged shared one-active-generation-per-user limit. Do not infer unlimited context, cross-provider compatibility, a TTL, or permanent remote image availability from saved history.

### Rate limits and budgets

Cover [ADR 0011](docs/adr/0011-layered-rate-limiting.md) through Playwright scenarios with isolated synthetic users and explicitly configured test thresholds:

- Exercise login throttling, AI burst/daily allowances, upload count/size/byte limits, and retained-storage quotas. Assert clear refusal messages, `429` and `Retry-After` where applicable, and continued read access to existing records.
- Use two users sharing an IP to verify separate user allowances below the coarse IP threshold. Verify that chat and AI extraction share one user's AI allowance.
- Submit competing actions from separate browser contexts to verify active/pending/global limits and atomic admission. Establish that rejected actions create no jobs or provider work, not merely that the UI displays an error.
- Exercise actual worker delivery, provider retries, and duplicate messages through browser-triggered workflows. Verify that additional provider usage counts without counting a redelivered submission twice, and that exhausted shared budgets prevent further provider calls.
- Verify that normal cache clearing and an authorized financial demo reset preserve quota/budget accounting. Test the configured daily reset boundary without arbitrary long sleeps.
- Exercise web-server/PHP upload rejection and trusted-proxy behavior against the isolated running stack, not only intercepted-kernel browser requests.

Use bounded test-owned diagnostics to corroborate job and usage counts when the browser result alone cannot establish them. Do not add a non-browser test suite. Provider fixtures can verify accounting logic but do not prove real provider billing accuracy.

Document how a scenario controls provider responses. Do not claim that a mocked response proves a real model/provider integration. Select the exact provider testing arrangement during bootstrap.

## Evidence and privacy

Keep test output, snapshots, screenshots, traces, authentication state, and profile data private and out of Git. Use synthetic financial data only. Read short summaries first; inspect detailed artifacts only when needed.

Passing static checks does not establish browser behavior. Passing an intercepted-kernel browser scenario does not establish real background-process integration. Report only the checks actually performed.

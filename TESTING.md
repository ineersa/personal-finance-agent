# Testing

## Scope and current status

[ADR 0010](docs/adr/0010-playwright-only-testing.md) selects Playwright as the only application behavior-testing approach. Do not add unit, kernel-only, service-only, or API-only suites. A PHP runner such as PHPUnit is only a harness for the real-browser suite, not a separate testing layer.

The Symfony hello-world Docker bootstrap has a saved PHP Playwright scenario in `tests/E2E/HomepageTest.php`, Castor tasks, and a separate live-stack configuration. Static checks have run successfully with PHP 8.5.12, Node.js 22.23.4, Symfony CLI 5.20.0, and Symfony Language Tools 0.24.2. Browser execution must be recorded separately; installing the harness or passing static checks is not proof of browser behavior. No financial fixtures, workers, broker, or Mercure workflows exist yet.

## Prepare the tooling runtime

Use Linux amd64 with Docker, a visible X11 display, and host `xauth`. The host PHP must not substitute for the application runtime when required extensions are missing. The tooling image includes PHP 8.5, the application's extensions, Node.js, Chromium system libraries, and Symfony CLI. Language Tools downloads on its first invocation and needs network access.

```sh
	docker build --target frankenphp_tooling -t personal-finance-agent-task02-tooling .
	bin/tooling composer install --no-interaction
	bin/tooling vendor/bin/playwright-install chromium
	mkdir -p var/browser
	bin/tooling vendor/bin/castor cs:fix
	bin/tooling vendor/bin/castor qa
```

`bin/tooling` runs as the host UID/GID and mounts this checkout. Its private home, browser cache, Symfony CLI cache, and display cookie live under ignored `var/tooling/`. It passes `DISPLAY`, a read-only X11 socket mount, and a copy of the current display's Xauth entry. It does not use `xhost` or the owner's browser profile. An unavailable display or failed browser launch must fail the suite, never select headless mode. The display-cookie copy can warn that the original authority file is not writable; it only reads that file.

The Symfony bridge reads the literal string `PLAYWRIGHT_HEADLESS=false`. PHPUnit XML sets `verbatim="true"` so it does not convert that value to boolean false. `HeadedBrowserTestCase` rejects a headless registry before browser creation, and the homepage scenario asserts that configuration. Extend that base for saved Symfony browser scenarios. Each test uses fresh browser contexts. The bridge closes contexts after tests and stops its shared browser after the class. Its pinned Node server closes owned browsers when its PHP parent's stdin closes, including termination paths. `bin/browser-suite` forwards normal interruption signals to PHPUnit, stops its child on shutdown, and bounds execution to five minutes. The disposable tooling container uses Docker's init process and removes its own remaining processes when it exits. Abrupt host failure still requires inspecting task-owned leftovers, not global browser cleanup.

The default suite uses intercepted Symfony requests, not a network application server. Test SQLite storage is `var/browser/application.db`, separate from development `var/app.db`; shared test files use `var/browser/share`. Do not use development database files or uploads as fixtures. Keep future worker-accessible synthetic data within verified test-owned storage.

### Isolated live stack

Use this path for exploratory checks and future direct-network PHP browser scenarios. It does not demonstrate worker, broker, or Mercure integration by itself.

```sh
	mkdir -p var/browser-live/browser
	docker compose -f compose.browser.yaml up -d --build
	docker compose -f compose.browser.yaml ps
	# Browser agent: open http://127.0.0.1:18081/ in a unique headed session.
	docker compose -f compose.browser.yaml stop
```

This configuration does not load the development override. It uses Compose project `personal-finance-agent-browser`, loopback port 18081, read-only source, and `var/browser-live` mounted as the stack's entire writable `/app/var`. Its SQLite URL points to `/app/var/browser/application.db`. Never reuse development worker/queue/upload storage when adding background services. Future direct-network PHP scenarios must create a browser context without the Symfony interception client and use this verified URL. The current saved scenario is explicitly not cross-process coverage. Stop only this stack; do not remove volumes or reset data as part of normal validation.

## Agent verification workflow

Use this procedure once the application and relevant project tasks exist. Delegate browser interaction to the configured browser agent and load the `playwright-cli` skill. The agent must perform the checks, not ask the owner to operate the browser.

1. Select the affected workflows and observable assertions. Include a failure or ownership-denial path when relevant.
2. Use project Castor tasks to prepare isolated synthetic fixtures and start only the services needed for the scenario. Verify the target URL and test storage before any reset or cleanup.
3. Run the relevant saved browser scenarios. Use headed mode for the automated suite as well as exploratory checks.
4. Open a uniquely named, headed CLI session when further investigation is needed. Read the page snapshot before interacting and use current element references.
5. Assert the expected result. For background operations, wait on observable UI state with a bounded timeout. Do not replace assertions with sleeps or treat a click, screenshot, or successful page load as proof of the workflow.
6. Inspect bounded browser diagnostics, Mate logs, or profiler records when a check fails. Fix the cause and rerun the affected scenario. Add a repeatable browser regression case for a bug fix.
7. Save useful failure evidence, then close all browser resources owned by this run. Clean up only its test processes and data. Ensure teardown also runs on errors and normal interruption.
8. Report the command or scenario, assertions checked, result, and evidence paths. State any blocked, untested, or mocked integration explicitly.

Do not silently switch to headless mode. If the owner cannot see the browser because display access is unavailable, report that blocker. Do not use `close-all`, `kill-all`, or the owner's personal profile to recover a session. Verify ownership before cleaning up a leftover browser.

### CLI session commands

Prefer project Castor browser tasks when available. The underlying commands for the installed CLI are shown below. Set `APP_URL` to the verified test application URL and `BROWSER_SESSION` to a unique name for this run.

```sh
	playwright-cli -s="$BROWSER_SESSION" open "$APP_URL" --headed
	playwright-cli -s="$BROWSER_SESSION" snapshot
	playwright-cli -s="$BROWSER_SESSION" close
```

Arrange cleanup before opening the browser. The final command is required even when assertions fail. An unexpected process termination may prevent teardown; report that condition rather than claiming the browser was closed.

## Automated browser suite

Use the PHP Playwright/Symfony approach from the [SymfonyCasts article](https://symfonycasts.com/blog/playwright-php-symfony). Keep browser launch configuration headed. The integration documents `PLAYWRIGHT_HEADLESS=false`; verify it against the pinned release during bootstrap.

Use fresh browser contexts and synthetic users. Cover the real login form even if other scenarios use the integration's authentication helpers. Use stable accessible locators and Playwright waiting/assertion facilities rather than timing guesses.

Kernel interception is suitable for browser workflows handled inside the test process. For queue, worker, and Mercure checks, visit a real isolated running application with Playwright and observe browser results. Verify that this mode uses real network delivery rather than intercepted in-process responses.

Commit fixtures that workers must read. Do not wrap a cross-process run in one long test transaction: independent workers do not share that connection, and SQLite writer locks can block them. Keep the test application database, broker database, uploads, and worker processes separate from development/demo state. Reset only verified test-owned resources.

Do not assume the article's transaction rollback technique cleans up the whole live stack. Do not introduce a non-browser integration suite to work around these constraints.

### Command contract

Run these tasks directly in a fully provisioned application runtime, or prefix them with `bin/tooling`:

| Command | Scope |
|---|---|
| `vendor/bin/castor test` | Run the headed Playwright browser suite with cleanup and saved diagnostics. |
| `vendor/bin/castor qa:static` | Run the static gate defined in ADR 0009, including `symfony lsp:check`. |
| `vendor/bin/castor qa` | Run the static gate and the browser suite, reporting both results. |

The individual static tasks are `composer:validate`, `cs:check`, `phpstan`, and `lsp:check`. `cs:fix` modifies PHP formatting separately. Each task writes `var/qa/<task>/result.json`, with colons replaced by hyphens, and prints a short JSON summary. Reports include command, timestamps, duration, status, exit code, and stdout/stderr paths. Native JSON is preserved for style, PHPStan, LSP, and Mate tasks. PHPUnit writes `var/qa/test/junit.xml`. Do not run the same task concurrently: its report directory is locked. An interrupted invocation leaves `running`, never a stale pass. Aggregates execute all children and preserve the first failure. Their stdout files contain each child's summary.

The LSP task requires both global and project completeness, runtime mode, and ready runtime state. It never uses source-only analysis. Read only the failing task's diagnostic files before widening inspection. Reports, logs, profiles, cookies, screenshots, and traces remain private generated data.

Run `vendor/bin/castor cs:fix` before final validation when application or tooling code changes. Keep task output concise, preserve failures, and save reports under `var/qa/`. A missing browser, unavailable display, startup failure, or interrupted suite must not produce a passing result.

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

Cover [ADR 0016](docs/adr/0016-local-markitdown-conversion.md) through headed browser uploads and the actual worker/local MCP converter. Use synthetic digital PDF/XLSX and PHP-parsed CSV fixtures with known dates, amounts, and expected transactions; assert validation/review and stored results, not just generated Markdown.

Exercise unsupported, malformed, no-text/image-only, conversion-error, and bounded-output cases without fabricating financial records or claiming OCR succeeded. Verify owner isolation, authorized staging access, cleanup, and hostile document text treated as data rather than instructions. Ordinary digital conversion must not require an LLM/API key; subsequent AI extraction remains a separate configured-provider step. Do not claim scanned-PDF support until an existing OCR backend is explicitly selected and verified.

### Finance precision, review, and analysis

Cover [ADR 0017](docs/adr/0017-exact-money-and-ecb-reporting.md) and [ADR 0018](docs/adr/0018-finance-review-sql-and-jev.md) through headed browser scenarios with synthetic fixtures:

- Verify exact high-precision amount round trips, sums, and EUR-based cross-conversion with known decimal results. Changing reporting currency must preserve originals. Exercise missing-date/weekend handling once chosen; do not assume a fallback or exact SQLite `SUM`.
- Verify editable drafts require valid user approval before analysis, preserve the statement date and account-source meaning, and do not treat every credit as income. Redelivery must not duplicate imports.
- Exercise model-written SQL with attempted owner/table/write-access violations and bounded results. Confirm external enforcement rather than a prompted owner clause, and precision-preserving money results.
- Verify LLM row extraction from converted Markdown/text separately from Jev categorization/decision checks. Exercise malformed/truncated/empty JSON and invalid dates, currencies, fields, or money values. LLM output must not automatically approve/commit financial records, and no invalid output or unapproved draft may feed analysis. Preserve monetary strings rather than JSON floats, and keep CSV PHP parsing independent of a forced LLM step.
- Exercise JSON decoding, DTO denormalization, and Symfony Validator failures. Verify bounded correction requests include specific errors and retain the original statement context. Cover a successful correction that produces only an editable draft and exhausted attempts that report failure without accepting invalid records.
- Verify extraction provider/model configuration is independent of chat, uses only enabled/configured allowed integrations, and captures validated choices for queued work. Do not infer uniform native JSON Schema support, automatic failover, or runtime bridge compatibility from provider documentation. Jev Choice/Score/Noul checks do not establish arbitrary-field extraction or OCR.
- Verify target-model history conversion leaves canonical stored history unchanged and isolates tool IDs/reasoning state between concurrent users. Reconstructing context is not resuming an exact provider execution.

Any cassettes/replay used for final verification must be identified as such, not reported as live provider integration.

### Inline charts

Cover [ADR 0014](docs/adr/0014-inline-chat-and-antv-charts.md) through headed browser scenarios. Verify actual Docker MCP chart generation through the worker and private Mercure delivery, not just a substituted image. Use synthetic aggregates only for external-renderer tests.

Check unsafe text/table content and untrusted image references, owned saved chat-output access, and reload/reconnect behavior. Exercise hosted-renderer failures and unavailable images with clear chat feedback and retained conversation state still readable. Do not claim that external image URLs are private or permanently available. Exact image lifecycle remains open; chat retention follows ADR 0015 below.

### Conversation persistence and retention

Cover [ADR 0015](docs/adr/0015-sqlite-conversation-history.md) through headed Playwright scenarios against the isolated application/worker stack:

- Verify temporary-by-default creation, Save chat, and reload of retained messages and generation state from the shared application database.
- Give two users distinct histories. Verify five temporary chats per user, saved chats outside that count, owner-only access, and pruning of only the owner's oldest inactive temporary chat when a sixth is created.
- Keep queued/running conversations protected. Exercise creation when no eligible victim exists; verify no protected deletion or silent count increase without assuming a new admission policy.
- Race Save chat, new-chat creation, and work admission in separate browser contexts. Verify atomic counts and protection of newly saved or queued/running conversations.
- Verify pruning leaves source financial data and accounting/idempotency information needed for admitted work intact. Use bounded test-owned diagnostics when browser output alone cannot establish this.
- Distinguish the five stored temporary chats from the unchanged shared one-active-generation-per-user limit. Do not infer unlimited context, cross-provider compatibility, a TTL, or permanent remote image availability from saved history.

### Rate limits and budgets

Cover [ADR 0011](docs/adr/0011-layered-rate-limiting.md) through headed Playwright scenarios with isolated synthetic users and explicitly configured test thresholds:

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

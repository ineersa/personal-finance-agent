# Testing

## Scope and current status

[ADR 0010](docs/adr/0010-playwright-only-testing.md) selects Playwright as the only application behavior-testing approach. Do not add unit, kernel-only, service-only, or API-only suites. A PHP runner such as PHPUnit is only a harness for the real-browser suite, not a separate testing layer.

The application is not bootstrapped. The system `playwright-cli` command is installed and its headed/session options have been inspected. The project browser suite, Castor tasks, fixtures, and live test stack do not exist yet. No application test has run.

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

### Planned command contract

These commands are requirements for bootstrap, not currently runnable tasks:

| Command | Scope |
|---|---|
| `vendor/bin/castor test` | Run the headed Playwright browser suite with cleanup and saved diagnostics. |
| `vendor/bin/castor qa:static` | Run the static gate defined in ADR 0009, including `symfony lsp:check`. |
| `vendor/bin/castor qa` | Run the static gate and the browser suite, reporting both results. |

Run `vendor/bin/castor cs:fix` before final validation when application or tooling code changes. Keep task output concise, preserve failures, and save reports under `var/qa/`. A missing browser, unavailable display, startup failure, or interrupted suite must not produce a passing result.

## Initial coverage requirements

Implement browser scenarios alongside each supported feature:

- Authentication: login, rejected credentials, logout, and protected pages.
- Ownership: at least two users with distinct data. Check lists, direct record URLs, association selectors, downloads, aggregates, conversations, artifacts, and private event access. An administrator role does not grant access to everyone's finances.
- Statement processing: valid uploads, rejected uploads, processing progress, failures, extracted results, and persistence after reload.
- Review: transaction corrections and source creation/editing, including invalid submissions.
- Financial questions: synthetic history with known expected totals for subscriptions, yearly income, and internet bills. Assert results, not just the presence of answer text.
- Chat and artifacts: input, streamed updates, completed artifacts, failure states, and reload/reconnect recovery. Include real worker/broker/Mercure delivery checks.
- Untrusted content: uploaded and model-supplied text must not execute scripts or render unsafe HTML.
- Administration: account management and authorized reset behavior once reset scope and safeguards are defined.

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

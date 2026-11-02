# Testing

## Scope and current status

[ADR 0010](docs/adr/0010-playwright-only-testing.md) selects Playwright as the only application behavior-testing approach. Do not add unit, kernel-only, service-only, or API-only suites. A PHP runner such as PHPUnit is only a harness for the real-browser suite, not a separate testing layer.

Saved PHP Playwright scenarios in `tests/E2E/` are organized by feature: authentication, ownership, source CRUD, transaction corrections, catalog/user CRUD, password forms, settings, and the homepage/profiler. Fixture-backed scenarios use `tests/Support/FinanceBrowserTest.php` only for Alice's native fixture lifecycle. Development and tests load the same canonical definitions, one entity per file in `fixtures/*.yaml`, into separate databases. There is no supplementary test dataset. Arrange necessary boundary and hostile inputs through the existing browser forms, not another fixture loader or manual ORM seeding. Browser execution must be recorded separately; installing the harness or passing static checks is not proof of browser behavior. CSV imports have a separate real-network scenario in `tests/Live/StatementImportTest.php`; Mercure workflows are not implemented.

The anonymous homepage scenarios cover the public landing, ordinary demo credentials, and absence of workspace controls. Signed-in scenarios compare the shared native EasyAdmin chrome, current navigation, sidebar width, header spacing, footer, and narrow-screen layout across Chat, Settings, and CRUD pages. Mobile sidebar width must fill the body's available layout box, which excludes EasyAdmin's stable scrollbar gutter. Role regressions cover deterministic login entry, removal of `/admin` and standalone `/settings` URLs, and administrator denial of finance, Chat, and Settings requests through direct URLs, legacy route queries, and browser POSTs, even for owned records and dual-role accounts. Protected pages use `/home` paths; administrators enter Users, while ordinary users enter `/home/chat` and use `/home/settings` for preferences. Dark-only regressions seed saved light/auto preferences and emulate a light operating system at desktop and mobile widths, while checking that selectors are absent and native controls remain dark.

## Test quality

Quality over quantity. Never write tests merely to increase their count. Each test must have a specific purpose and cover distinct behavior, a failure mode, or a regression not already covered.

Name the purpose in the test method. Consolidate duplicate coverage and keep unrelated concerns separate. Repeated setup and control assertions are valid when the test still adds distinct coverage. Assert meaningful outcomes: for example, prove that owned records are accessible as well as foreign records being denied, and reload after a rejected write to confirm that persisted data is unchanged. Do not treat test or assertion counts as evidence of quality.

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

Castor sets the literal `PLAYWRIGHT_HEADLESS=true` for default tests and `false` for `--headed`; PHPUnit XML and `.env.test` do not override the choice. Saved scenarios extend the integration's `PlaywrightTestCase`, through `FinanceBrowserTest` when they need fixtures. Use `vendor/bin/castor test --headed` or `vendor/bin/castor check --headed` only when the owner explicitly requests visibility. The `check` task forwards this option to `test`. Each test uses fresh browser contexts. The bridge closes contexts after tests and stops its shared browser after the class. Its pinned Node server closes owned browsers when its PHP parent's stdin closes, including termination paths. The Castor report/runtime helpers forward normal interruption signals, bound browser execution to five minutes, and clean up only the invocation's named container after checking its ownership label. They also remove its private display cookie. Docker's init process and container removal terminate owned remaining processes. Abrupt host failure still requires inspecting task-owned leftovers, not global browser cleanup.

The default suite uses intercepted Symfony requests, not a network application server. Test SQLite storage is `var/browser/application.db`, separate from development `var/app.db`; shared test files use `var/browser/share`. The test Doctrine configuration fixes the SQLite URL explicitly rather than resolving ambient `DATABASE_URL` overrides. SQLite does not support Doctrine's database-name suffix isolation. `test` invokes `test:database:prepare`, which creates the parent directory, rejects symlinked storage, and runs `doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration` in the application runtime. SQLite creates a missing database file when Doctrine connects; pending application migrations establish or update its schema. The pinned DBAL 4 SQLite platform does not support `doctrine:database:create`. Preparation does not drop databases or reset data.

Fixture-backed intercepted scenarios use Hautelook Alice's `RefreshDatabaseTrait`: it purges and seeds only `var/browser/application.db` on the first kernel boot, then rolls back each case while retaining the seed records. `FinanceBrowserTest` applies the documented `#[DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback]` class attribute, inherited by these scenarios, so DAMA does not wrap Alice's lifecycle in a second transaction. Read named records through `FixtureStore::getFixtures()`; do not use deprecated fixture/options properties or manual ORM fixture construction. Use the inherited `loginUser()` for scenarios that are not testing authentication. Keep real browser login submissions for authentication and password behavior.

DAMA remains a test-only dependency with its PHPUnit extension enabled for other intercepted scenarios. DBAL 4 requires no additional savepoint setting. Browser scenarios observe CRUD persistence and owner isolation against the [domain schema](docs/domain-persistence.md). Development and live-stack fixture commands remain native append-only and must not purge existing data. Do not add a test application command, endpoint, invented schema, or custom lifecycle framework. Reuse the development fixture definitions, never the contents of the development database.

### Isolated live stack

Use this path for exploratory checks and future direct-network PHP browser scenarios. It does not demonstrate worker, broker, or Mercure integration by itself.

```sh
	vendor/bin/castor browser:stack:up
	vendor/bin/castor browser:stack:status
	# Browser agent: open the printed URL in a unique session; add --headed only on an owner request.
	vendor/bin/castor browser:stack:stop
```

Castor layers `compose.browser.yaml` over the base and development files. The resulting stack uses its own checkout-scoped Compose project and `http://<checkout-id>.browser.finance.localhost` route through the shared loopback-only proxy. It publishes no host ports. Source is read-only; `var/browser-live` is mounted as the entire writable `/app/var`. Its SQLite URL points to `/app/var/browser/application.db`. Development keeps separate storage and a different hostname. Never reuse development worker, queue, or upload storage when adding background services. Stop only this stack; do not remove volumes or reset data as part of normal validation.

For isolated exploration, prepare only the running browser stack's database and append the synthetic Alice dataset once:

```sh
	vendor/bin/castor browser:stack:console -- doctrine:migrations:migrate --no-interaction
	vendor/bin/castor browser:stack:console -- hautelook:fixtures:load --append --no-interaction
```

`browser:stack:console` uses the running browser stack's test environment and dedicated `var/browser-live/browser/application.db`, not development data or the intercepted suite's database. Use **admin@test.com / admin** or **demo@test.com / demo**. Append loading preserves existing records and fails on duplicate fixture identities; do not purge data to repeat it. The canonical Alice statement processor creates synthetic CSV files alongside their metadata. Download them through the statement list or detail page. CSV upload is available from Statements. Close the CLI browser session and stop the owned stack afterward. For the actual development stack, use `vendor/bin/castor dev:fixtures:load` instead.

### Real CSV upload and worker verification

#### Explicit local demo maintenance

`LOCAL_DEMO_RESET_ENABLED=1` in an ignored dev/test environment-local file enables the Settings reset only for `demo@test.com` without administrator roles. Production always hides and denies it, even with the flag set. The confirmation form validates consent and CSRF before invoking the installed native Alice loader in DELETE purge mode. This is the explicit owner-approved exception to append-only demo loading; `dev:fixtures:load` remains append-only. The action replaces all users and mapped financial data with `fixtures/*.yaml`, preserves SQLite AUTOINCREMENT sequences, removes noncanonical files from the private statement adapter's root, and uses native logout. Environment files, provider keys and separate broker storage are not purged. Existing sessions refer to deleted user IDs and must authenticate again.

Reset and native EasyAdmin statement deletion take an exclusive named Symfony Flock lock. Upload admission, retry and processing take shared locks across web/worker containers; contention fails without waiting. The lock directory is `.locks` inside private statement storage; root-only cleanup skips directories. After deploying this change, refresh all web and worker processes together before accepting imports or maintenance, since the previous implementation used a different lock path and message class name.

Reset checks queued/running statements across all users. Deletion commits the owned statement removal and pending filename together; the database cascades deletion to linked approved/draft transactions. Only then does Flysystem remove bytes. If file cleanup fails, the UI reports that records were deleted but cleanup remains pending. Retry through `vendor/bin/castor app:console -- app:statement:cleanup` (or `browser:stack:console` for the isolated stack). The command never deletes a filename still referenced by a statement. Native Alice purging excludes pending cleanup records, so reset failures retain retry information. Reset schedules root files before purging, but referenced files remain protected if purge fails. Filesystem and database operations are not one atomic transaction.

`ProcessStatementMessage` carries immutable stored-filename identity as well as owner/statement IDs. A matching queued import whose owner becomes an administrator fails before CSV interpretation; stale or deleted identities are ignored. Do not deploy the renamed message with old messages still queued: let the old worker finish them before the coordinated refresh. Never rewrite database statuses to bypass the busy guard.

Saved browser coverage deliberately does not exercise the database-reset button. Run independent import/deletion lifecycle checks against an initialized isolated browser stack with `vendor/bin/castor browser:lifecycle:test`. This task never purges or resets the database. It stops only the isolated worker, verifies refusal to delete a queued statement, promotes its owner through native administrator forms, and consumes one message with an empty provider key to prevent accidental hosted calls. The verification phase restores the role, checks the specific access-loss failure and absence of drafts, and verifies owner/admin/foreign-user deletion boundaries and CSRF. It deletes only the synthetic upload and temporary user created by the scenario. Keep the isolated queue free of unrelated pending messages. The worker is restarted in a finalizer; interrupted runs may require scoped worker recovery.

The development and browser stacks start the native sqlite-queue broker and stock `messenger:consume statement_import --keepalive=5` worker. Browser services share only `var/browser-live`: the application database is `browser/application.db`, private uploads are `browser/statements`, and broker SQLite/socket are `queue/broker.db` and `queue/broker.sock`. The broker owns its schema. Its 300-second reservation is renewed by Messenger; no provider call or broker publication runs inside an application write transaction.

Export `JEV_API_KEY` before starting the stack, or configure the appropriate ignored environment-local file. Compose passes an exported key to web and worker without embedding it in command arguments. Only synthetic data should be used for this verification. The upload form discloses the configured destination and requires consent. These are paid live provider calls, not replayed responses.

After migrating and append-loading canonical fixtures once, run:

```sh
vendor/bin/castor browser:imports:test
```

The saved scenarios upload the three October holdouts through Chromium, wait on native private Mercure/Turbo processing-fragment updates without refreshing the document, check exact factual rows and transaction meanings, reload persisted drafts, and compare the approved transaction list before/after. They also cover malformed CSV failing without drafts and administrator denial. Global import toasts are intentionally absent; ordinary native action/error flashes remain. Closing the workspace closes live delivery; persisted statement status remains authoritative.

The checkout-scoped hub is reached through the same application origin and publishes no host port. Only the signed-in ordinary user's exact import topic is granted by the native Mercure authorization cookie. Administrators are not subscribed. An ignored, mode-specific random signing key is created under `var/tooling` with mode `0600`; do not print it. Local HTTP uses the hub's explicit prefix-less cookie option, without enabling anonymous subscribers or playground mode. Logout clears subscriber cookies. The native Turbo stream source owns EventSource connection/reconnection and is preserved across Turbo navigation; no custom subscription controller is added.

After a successful baseline run, use `vendor/bin/castor browser:imports:test --privacy` for the separate ordinary-owner isolation scenario. It creates a unique synthetic user and source through browser forms. A mixed-topic EventSource must receive its own user's real import updates before and after another owner's import, but not that owner's private payload. HTTP 200 alone is not evidence of private access: Mercure filters updates against the subscriber's granted matchers. The scenario also checks statement denial and forged source submission. It retains the isolated user and referenced imports for inspection. Use `--boundary` to verify the real PHP upload boundary: exactly 10 MiB reaches the worker parser, while one extra byte is rejected on the upload form. Its intentionally malformed content avoids provider charges; this does not prove successful classification of a 10 MiB statement.

Successful uploads record their processing URLs and expected counts in ignored `var/qa/statement-imports.json`. For each saved identifier, republish using the operator command, verify worker receipt using bounded owned worker logs, then run replay assertions:

```sh
vendor/bin/castor browser:stack:console -- app:statement:dispatch STATEMENT_ID demo@test.com
vendor/bin/castor browser:imports:test --replay
```

`messenger:stats statement_import` is not delivery proof: this transport does not implement message counts, and Symfony can exit successfully while reporting that the count is unavailable. Verify worker receipt before interpreting unchanged browser results as redelivery evidence. The handler atomically claims only queued statements; completed/running/failed deliveries do not create rows. Failed imports can be retried from their owned processing page. A worker crash can leave a running statement requiring operator attention; broader crash recovery is deferred. Failed storage writes, metadata compensation and competing retry requests still need distinct browser evidence; the saved happy/failure scenarios do not prove those boundaries.

`app:console --isolated-database=IDENTIFIER -- ...` runs native Doctrine diagnostics against `var/browser/IDENTIFIER/application.db` in the development configuration, never the fixed intercepted-test database. Use a new identifier for a fresh baseline. Do not run this option with an explicit `--env=test`, whose configuration intentionally fixes its own database URL. Existing isolated databases that recorded superseded unmerged migrations must not be treated as current-schema proof or repaired by changing owner migration history.

`tests/E2E/StatementDownloadTest.php` exercises downloads through the real browser and native Alice fixtures. It checks content hashes, attachment headers, owner and role denial, path-like request inputs, and private-root URL rejection. Intercepted browser requests do not prove the web server's static-file rules. Repeat direct private-root URL attempts against the isolated live stack for that boundary. Upload validation, hostile uploaded filenames and failed-write compensation proof remain with task 07; do not add a test-only endpoint to expose them. See [ADR 0020](docs/adr/0020-private-statement-storage.md) for storage locations and the caller's compensation contract.

For a saved direct-network regression, start two owned stacks, then pass their printed URLs as one positional comma-separated argument:

```sh
	vendor/bin/castor dev:stack:up
	vendor/bin/castor browser:stack:up
	vendor/bin/castor browser:routes:test 'http://<checkout-id>.finance.localhost,http://<checkout-id>.browser.finance.localhost'
```

`tests/Live/RoutingTest.php` launches real headless Chromium without Symfony request interception. It checks HTTP success, the public landing and login link, loaded CSS, absence of signed-in controls, and independent browser storage across origins. The task attaches only its disposable tooling container to the shared proxy network and passes the inspected proxy IPv4 address to Chromium's native `--host-resolver-rules`. It does not change host DNS or add a DNS server. This separate command requires live stacks; the default `test` and `check` suites remain self-contained intercepted-browser scenarios. Routing coverage does not prove worker, broker, or Mercure integration.

For worktree-isolation verification, start stacks from two task-managed worktrees, run the routing scenario against both URLs, stop one checkout, and confirm the other URL still renders through Playwright. Inspect the stopped checkout's route failure too. Preserve evidence before rerunning a task because its report directory is reused. Stop each owned stack before task-worktree removal; the shared proxy stays running until `dev:proxy:stop` succeeds with no registered routes.

Use `browser:routes:test "$SURVIVING_URL" --absent="$STOPPED_URL"` to save the survivor and exact unregistered HTTP 404 assertions together. Omit the positional argument when all supplied routes are expected absent; pass at least two distinct URLs across both lists. Follow the [routing lifecycle regression procedures](docs/development-routing-regressions.md) to verify cleanup while the proxy is stopped, persisted configuration after restart, and SIGTERM cancellation during a controlled subprocess. Process and registry probes corroborate browser assertions; they do not replace them.

## Reviewed synthetic history

Canonical Alice fixtures contain 69 processed monthly statements (November 2024–September 2026) and 758 demo transactions: 733 approved and 25 unresolved drafts, plus the separate administrator security records. The three October CSVs remain upload holdouts. Stable references such as `demo_salary` now identify actual September history rows, not additional placeholder records. Statement bytes come from the committed monthly CSVs; Alice's processor writes them to the configured private storage and verifies metadata from their contents.

The one-off export used native migrations and append-only Alice loading in `var/browser/history-final/application.db`, with its own statements directory. `demo:history:process` invoked the real `ProcessStatementHandler` directly, including storage verification, CSV/Jev interpretation and draft persistence. It did not exercise broker transport; saved live upload scenarios cover that boundary separately. To reproduce on a fresh checkout:

```sh
vendor/bin/castor app:console --isolated-database=history-final -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor app:console --isolated-database=history-final -- hautelook:fixtures:load --append --no-interaction
vendor/bin/castor demo:history:process
vendor/bin/castor demo:history:process --export
```

The first processing run incurs hosted Jev charges; completed export records are reused on subsequent runs. Inspect ignored `var/browser/history-final/results.json` before export. Export checks exact facts against the invented input manifest, applies Symfony's approved validation group, and approves only known meanings with categories that agree with the expected finance boundary. This is synthetic fixture preparation, not production auto-approval. No provider payloads or credentials are exported.

`resources/demo-statements/history-review.json` records the review: no factual mismatches or label corrections; 23 opaque USD references remain uncategorized/unknown drafts, and two theatre purchases retain unknown meaning. Sixteen investment transfers selected Own-account Transfers rather than the expected Savings & Investments category; both describe the explicit owned-account movement, so the model labels were retained. All 433 recurrence disagreements were retained, including unknowns where a single month did not establish a schedule. No transfer pairing or cross-month recurrence inference was added. Development fixture loading remains append-only; do not load these fixture definitions over an existing owner's history expecting an update or reset.

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
- Administration: account management and role-based access boundaries.

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
- Distinguish the five stored temporary chats from provider context limits. AI admission quotas are deferred by ADR 0021. Do not infer unlimited context, cross-provider compatibility, a TTL, or permanent remote image availability from saved history.

### Upload limits and deferred quotas

Follow [ADR 0021](docs/adr/0021-fixtures-first-jev-import.md), which supersedes ADR 0011. Verify the 10 MiB per-file upload limit and existing login throttling. Upload frequency, daily bytes, retained-storage quotas, shared AI allowances, one-active-generation-per-user admission, pending/global capacity and AI budgets are deferred. Do not build quota scenarios or accounting infrastructure for the current import slice.

Rejected oversized uploads must not enqueue work. Malformed CSV processing must fail visibly without accepted draft rows. Cover storage-write failure and metadata compensation through the real upload flow once available. Exercise redelivery without duplicate drafts and owner isolation against real isolated web/broker/worker processes. Test web-server/PHP upload rejection against that stack, not only intercepted requests.

### Synthetic Jev diagnostics

`vendor/bin/castor demo:statements:generate` deterministically regenerates 72 invented monthly files and known expected outcomes. It does not access a provider or database. October 2026 remains the three upload holdouts. `expected.json` also records per-statement exact expected spending debits, refund credits, income credits and unresolved-row counts. Confirmed own-account-transfer and credit-card-payment meanings contribute to neither spending nor income, independently of category or matched counterparts. Unknown meanings require review, not automatic exclusion. These totals are synthetic expectations, not implemented SQL reporting or model outputs; do not sum CAD and USD without conversion.

With paid synthetic requests authorized and `JEV_API_KEY` exported locally, run:

```console
vendor/bin/castor demo:statements:inspect
```

This diagnostic sends only the three committed synthetic October files through the installed Symfony AI TypeSafe/Decision bridges. The disposable tooling container receives the key by environment-variable name, never through a literal command argument. Optional server-side `JEV_BASE_URL` and `JEV_MODEL` default to `https://api.typesafe.ai` and `jev-1.14.0`. A local endpoint must implement the same API; compatibility is not established merely by changing the URL. The client is not profiler-traced and provider exception payloads are not logged.

Read `var/qa/demo-statements-inspect/result.json` for task status and `var/demo-interpretation/result.json` for synthetic interpreted values. The command does not persist financial records, approve drafts, seed history or prove an upload/worker integration. It accepts no arbitrary input path. Application behavior coverage remains browser-only.

The initial parser supports UTF-8 comma-separated records, consistent 4–12 fields, ISO `YYYY-MM-DD` dates and separate unsigned decimal-string debit/credit columns with exactly one populated per transaction. Optional header detection and column selection are Jev decisions over at most eight complete, length-bounded sample rows. Sampling prioritizes distinct empty/nonempty patterns so a rare incoming payment is not lost. Currency comes from the trusted selected source; extra account identifiers never select or authorize a source. Ambiguous mappings fail instead of guessing. Other delimiters, signed-single-amount layouts, localized numbers/dates and mixed-currency files are not yet supported.

Interpretation batches at most eight same-source records and asks separate support questions for meaning, category and recurrence. Unknown is valid; generated recurrence labels may describe a schedule not observable from one month alone. Review historical outcomes before canonical fixture export. No historical finalization or production auto-approval is implemented yet.

## Evidence and privacy

Keep test output, snapshots, screenshots, traces, authentication state, and profile data private and out of Git. Use synthetic financial data only. Read short summaries first; inspect detailed artifacts only when needed.

Passing static checks does not establish browser behavior. Passing an intercepted-kernel browser scenario does not establish real background-process integration. Report only the checks actually performed.

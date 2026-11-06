# Testing

## Scope and approval policy

Keep the suite small: **one happy-path scenario per implemented view or action**. A single CRUD journey may cover its related list, create, detail, edit, and delete operations. Do not duplicate those operations in separate scenarios or hide extra cases in loops and data providers.

**Ask the owner for explicit permission before adding or running special-case tests.** This includes negative paths, boundary values, security matrices, fault injection, concurrency, recovery, and layout permutations. Fixing a bug does not automatically authorize another regression scenario. Database-reset tests remain excluded.

This policy replaces the earlier broad coverage requirements. It does not remove application authorization, validation, or privacy requirements. Do not claim that happy-path coverage proves those boundaries.

[ADR 0010](docs/adr/0010-playwright-only-testing.md) selects real Playwright browsers for application behavior. PHPUnit is the browser harness, not a separate unit, kernel, service, or API-only testing layer. Keep the existing harness and generic Castor tasks; do not add scenario-specific runners, orchestration, cross-run manifests, or supplementary datasets.

## Current suite

| Scenario | Happy path |
|---|---|
| Homepage | Public landing and login link |
| Authentication | Login and logout |
| Chat connection gate | Disconnected Chat view and connection action; no provider call |
| Settings | Save and reload reporting currency and money locale; verify localized money display |
| Sources | Create, list, edit, detail, delete |
| Transactions | List, detail, edit, reload and delete an approved transaction |
| Categories | Ordinary-owner create, list, edit, detail, delete |
| Users | Administrator create, list, edit email/password, verify login, detail, delete |
| Statements | List, detail, download |
| Live import | Upload one canonical CSV, correct draft fields and classifications, save and continue editing, save and return, delete another row, approve remaining statement rows, reload corrections, delete that uploaded statement |
| Live chat | After owner ChatGPT login, send a synthetic message and a context-dependent follow-up through the worker/Mercure stack, then reload the answer |

The intercepted scenarios are in `tests/E2E/`; real-network import and chat journeys are in `tests/Live/`. Import calls the configured Jev provider through the actual worker, broker and Mercure stack. Chat uses the owner's connected ChatGPT account in isolated storage. The journeys do not cover invalid submissions, repeat approval, ownership attacks, failure/retry, redelivery, quotas, hostile input or concurrent processing. Those require separate permission. Reset remains untested by owner instruction.

## Prepare the tooling runtime

Use Linux amd64, Docker, Docker Compose 2.24.4 or newer, Git, and host PHP 8.5 CLI with PCNTL/POSIX. Castor owns application runtime selection. Only owner-requested headed runs need display access and host `xauth`.

If `vendor/bin/castor` is missing, bootstrap the locked dependencies once:

```sh
 docker build --target frankenphp_dev -t personal-finance-agent-bootstrap .
 docker run --rm --user "$(id -u):$(id -g)" --volume "$PWD:/app" --entrypoint composer personal-finance-agent-bootstrap install --no-interaction
```

Then use the host Castor entry point:

```sh
vendor/bin/castor tooling:prepare
vendor/bin/castor cs:fix
vendor/bin/castor test
vendor/bin/castor qa:static
```

`tooling:prepare` builds the development tooling image, installs locked dependencies and Chromium. Its disposable containers run as the host UID/GID. Private browser/tooling files live under ignored `var/tooling/`. Do not invoke another PHP runner around Castor.

## Running tests

`vendor/bin/castor test` runs the nine intercepted-browser scenarios headlessly. Native PHPUnit arguments go after `--`:

```sh
vendor/bin/castor test -- tests/E2E/SourceCrudTest.php
vendor/bin/castor test -- --list-tests
```

Castor must not hardcode test filenames, method filters, or scenario selection. PHPUnit owns test discovery and selection. There is no per-scenario Castor task or custom test coordinator.

Use `test --headed` or `check --headed` only when the owner requests a visible run. Missing display access blocks a requested headed run; it does not justify silently changing modes.

Run `cs:fix`, relevant saved happy-path scenarios, and `qa:static` after code changes. The task workflow runs the literal `castor check` as the sole combined full browser/static gate at CODE-REVIEW. Do not run it prematurely during task-start. The default gate does not start a live application or make hosted model calls.

Report actual elapsed time, results, and any gaps. Do not inflate assertion counts or add test matrices. Routine validation must stay short; ask permission before expanding its scope or runtime.

## Fixtures and isolation

Both development and tests use the canonical `fixtures/central/*.yaml` account definitions and `fixtures/*.yaml` tenant definitions. Do not create a supplementary test dataset or mutate development data for verification. Manual currency-rate CRUD and its scenario have been removed; rates are maintained by canonical history and ECB refresh.

The intercepted suite uses `var/browser/application.db` for identity and `var/browser/tenants/<user-id>.db` for finance. `castor test` prepares central storage with native Doctrine migrations; it does not drop the database. The native Alice loader is decorated to provision/migrate tenant storage and load the same canonical definitions with Alice's `ObjectManagerPersister` and purger. No finance entities are persisted through the central entity manager.

Fixture-backed scenarios extend `tests/Support/FinanceBrowserTest.php`. The owner-approved `RefreshTenantDatabasesTrait` composes/aliases Hautelook Alice's native `RefreshDatabaseTrait`: Alice retains the central fixture lifecycle, while the project extension starts and rolls back tenant transactions. The test-only `TestTenantManagers` retains connections across intercepted requests and includes tenants opened during a scenario; newly created tenant files are removed after rollback. The documented DAMA `SkipDatabaseRollback` attribute remains, so Alice/project fixture transactions have one owner. DAMA remains installed for other intercepted scenarios. Read records through `FixtureStore`, not deprecated static properties. This narrow lifecycle exception does not authorize another fixture framework, dataset or runner.

The tenant redesign uses a clean fixture-backed development cutover, not an upgrade converter. Before resetting existing disposable dev/test databases, main must verify exact paths and active stack ownership. Do not delete uploads, OAuth credentials, unrelated volumes or another checkout's data. Fresh storage applies central migrations normally; fixture loading provisions tenant schemas automatically. For subsequent finance migrations, use `vendor/bin/castor app:console -- app:tenant:migrate` (or the matching browser-stack console). Generate finance migrations with `doctrine:migrations:diff --em=finance --configuration=config/migrations/finance.yaml` through Castor's isolated-database option, not against published tenant files.

Use `loginUser()` when authentication is setup rather than the action under test. Use the existing `SubmitsBrowserForms` helper for native form submissions and redirects through the pinned interception bridge. Do not replace that helper with test-only application behavior. Assert a persisted result, not just a successful click.

Database rollback does not restore filesystem changes or isolate other processes. The live import journey deletes only its own uploaded statement through the browser, not canonical fixture files.

## Isolated live stack

Use checkout-scoped tasks and their printed URL. Do not invent ports, Compose project names, or routing environment variables.

```sh
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
```

For a fresh isolated stack, migrate and append the canonical fixtures once:

```sh
vendor/bin/castor browser:stack:console -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor browser:stack:console -- hautelook:fixtures:load --append --no-interaction
```

Do not rerun fixture loading over existing fixture identities or purge data to make it pass. Use `admin@test.com / admin` or `demo@test.com / demo`. Configure the provider using the existing development setup; do not put credentials in test commands or reports.

After changing worker code or streamed templates, run `vendor/bin/castor browser:stack:restart-worker` before the live journey. It restarts only this checkout's isolated browser worker, leaving the web preview, broker and storage intact.

For explicitly authorized abrupt-worker diagnostics only, `vendor/bin/castor browser:stack:restart-worker --force` sends SIGKILL to this isolated worker before restarting it. This is not a saved recovery guarantee: native Messenger redelivery may repeat work, and the application has no claims, attempt fencing, durable busy state or checkpoint-restart engine (ADR 0029). After an approved broker configuration change, with no active owned work, `vendor/bin/castor browser:stack:restart-broker` restarts only that broker and preserves storage; the current reservation is 90 seconds with 5-second worker keepalive. Neither command purges storage or queued jobs. Do not run crash-point or concurrency checks without explicit permission.

Before the chat journey, ask the owner to sign into this stack's Finance URL as `demo@test.com / demo`, then use **Continue with ChatGPT** and complete consent. Confirm the connected status in Settings. Do not start provider tests until that login is complete. The browser must run on the same host as the loopback OAuth callback.

Chat uses the package-managed store at `var/private/chatgpt-auth` inside the application, mapped to `var/browser-live/private/chatgpt-auth` in this isolated stack. Web and worker share those files and the same user registration-store identifier. OAuth attempts and subscription topics use the connection epoch. Chat jobs carry trusted owner/conversation identifiers and native input messages; the worker rechecks ownership and uses the current owner registration, not a saved conversation epoch. Disconnect retains registration for reconnecting. The committed public client ID and installation ID are shared across worktrees and users; grants and attempts remain isolated. Fresh stores receive only the configured registration through the native atomic store API; matching saved grants are unchanged and mismatched client registrations fail without a rewrite. Fresh-store authorization reuse and provider UI deduplication remain unverified; do not reconnect or copy credentials merely to test this configuration. There is no account-switch action. Do not copy credentials from another checkout, inspect token contents, or put credentials in fixtures, cassettes, commands or reports. The live chat test sends synthetic text only and makes real provider requests. It requires access to `gpt-6-luna`; model registration alone does not establish entitlement. Missing login or model access is a failure, not a skipped/passing integration.

The editable system prompt is `prompts/chat.txt`, loaded by Symfony AI's file-based prompt processor. Defaults are `CHAT_MODEL=gpt-6-luna` and `CHAT_REASONING_EFFORT=low`; the worker uses the current configured values when executing each job. They are not saved on conversation metadata. Only provider-emitted readable summaries/commentary are displayed, so those sections may be absent. The worker binds the finance toolbox to the conversation owner and chart storage to that trusted conversation. Initial user-role context supplies the schema and `prompts/finance-context.txt` once before the first question; only real questions appear as visible turns. Progress comes from the native Agent execution and is not persisted as a separate state machine. The sanitizer still filters HTML and links, but its input-length limit is disabled for complete long replies; this removes its input-size denial-of-service bound.

Reporting examples and their supplied verification provenance live in [docs/examples/finance-reporting](docs/examples/finance-reporting/README.md). That reference directory is not loaded into the application. Use a new isolated conversation for the updated initial-context example; existing conversations receive the refreshed system policy but retain their initial snapshot. For one representative authorized reporting journey, ask an explicit-period/currency question and inspect SQL evidence, gross purchases/refunds/net labels, material coverage, a justified dark chart with matching aggregates and the final Markdown. Do not automatically execute the 12-query/six-chart reference collection as a live-model matrix. Source/schema review and supplied scratch-SQL results are not a live application pass. `get_unovis_info` now uses a text-only native MCP pass-through; generator output and private retention remain unchanged.

Prompt changes alter model request bodies, so existing strict provider cassettes may no longer match. Do not rewrite native provider frames to compensate; any fresh synthetic recording requires the normal explicit authorization and privacy review. Actual saved-suite and live outcomes must be reported separately.

Run the relevant live journey, then stop the owned stack when it is no longer needed:

```sh
vendor/bin/castor browser:live:test -- tests/Live/ChatTest.php
# Separately, when validating imports:
vendor/bin/castor browser:live:test -- tests/Live/StatementImportTest.php
vendor/bin/castor browser:stack:stop
```

### Inline chart validation

`tests/Live/ChartTest.php` is the single chart happy-path journey: actual finance SQL → discovered Unovis bar tool → known synthetic payroll value 2860 → private sandboxed interactive document → real hover tooltip → same document and tooltip after reload. It requires ordinary live mode (`CHAT_CASSETTE_REPLAY=0`), fresh isolated migrated/fixture-backed storage and the owner's ChatGPT connection. The chart container starts with the normal stack; it has no host port. Local chart files live at `var/browser-live/browser/charts` for this stack, `var/browser/charts` for intercepted tests, and `var/charts` for development. New chat opens an unstored draft without deleting existing history or files. Only explicit confirmed Delete removes that conversation and its private charts; do not delete chats or reset owner history merely to prepare tests.

Build the pinned fork without affecting running services with `vendor/bin/castor charts:build --browser`. Once the operator confirms there is no active generation, `vendor/bin/castor browser:stack:up` deploys the changed chart service and PHP/worker mounts; no reset or fixture reload is needed for an existing isolated stack. The Compose-project-scoped `chart_output` volume is staging only, not retained conversation storage. Node creates ordinary readable documents there and PHP/worker can remove them through the writable directory. Verify actual cross-container permissions during the live journey, not just source inspection. Never remove this or other volumes to update the service.

```sh
vendor/bin/castor browser:stack:restart-worker
vendor/bin/castor browser:live:test -- tests/Live/ChartTest.php
# Task 13 explicitly requests visible proof; requires working display access:
vendor/bin/castor browser:live:test --headed -- tests/Live/ChartTest.php
```

The generic live runner defaults to headless; `--headed` supplies the existing display-enabled QA runtime and `PLAYWRIGHT_HEADLESS=false`. The chart scenario honors it. No chart-specific runner, dataset or test endpoint is introduced. Tool-owned validation feedback and local renderer/MCP failure-to-model-continuation browser verification are explicitly requested by task 13, but are **not covered by this happy-path scenario** and remain required verification work. Do not infer those checks pass from source inspection or the backend integration probe.

**Existing replay is stale for the chart-enabled toolbox:** `tests/Fixtures/chat/synthetic-chat.json` captured finance-only tool definitions, while generation now advertises discovered Unovis tools as well. Stock cassette request matching includes that request body. Its recorded provider frames were not edited or fabricated. Re-record the existing synthetic journey with explicit permission and inspect/publish the native result using the recording procedure below before claiming offline chat replay works. Live chart proof must still be distinguished from provider replay; chart requests and local HTML generation are not wrapped by the inference cassette.

### Private synthetic chat recording procedure

The live chat journey opens the unstored draft route, then creates a native conversation with its first submission and follows up in that conversation; it never clears existing chats. Coordinate with the owner before recording: only wholly synthetic conversation history may enter a published cassette. Do not discard an existing conversation to make the test pass. The two deterministic prompts request one canonical synthetic transaction description through SQL and then recall a code word. The current prompt template must also contain no personal data.

For an explicitly authorized recording, recreate the owned browser stack with recording enabled, then run the existing journey. Preview-only model/effort overrides reach both web and worker; defaults remain `gpt-6-luna`/`low`. The worker uses these settings for subsequent executions, including existing conversations. New chat opens a fresh unstored draft without discarding retained history:

```sh
CHAT_MODEL=gpt-6-luna CHAT_REASONING_EFFORT=high CHAT_CASSETTE_RECORD=1 vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:console --worker -- debug:container 'App\Chat\AgentFactory'
vendor/bin/castor browser:live:test -- tests/Live/ChatTest.php
# Disable recording before any further app use, including after a failed test:
CHAT_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
```

The test-only service override lives in `config/services_test.yaml`, after the main service definitions. The targeted container inspection must show `app.chat.recording_http_client` among AgentFactory's arguments before a paid run. A successful chat test alone does not prove recording: confirm that the cassette exists afterward.

Only the Agent inference transport is wrapped with Symfony AI's stock `CassetteHttpClient`; OAuth, discovery and refresh use the original client. Output is `var/browser-live/private/chat-cassettes/synthetic-chat.json`, ignored by Git, inside a mode-0700 directory with a mode-0600 file. Existing output is refused rather than appended or overwritten. Inspect it privately before publishing anything; never print raw bodies or credentials to reports. Stock header/request redaction is not a guarantee that every response field is suitable for publication. Native message items and encrypted reasoning remain conversation data pending inspection. Report captured event types/counts without payloads; summaries and commentary are optional even with `reasoning.summary=auto`.

### Offline chat replay

The reviewed synthetic cassette is `tests/Fixtures/chat/synthetic-chat.json`, recorded from the existing two-prompt chat journey using `gpt-6-luna`, reasoning effort `high` and summary `auto`. Its three inference interactions contain one real SQL tool call, its canonical payroll result, the first answer and the follow-up code-word answer. The initial user context contains only the current context template and table definitions; the only user questions are the two saved synthetic test prompts. No prior conversation history is included. The live recording passed all 14 assertions. Offline replay must be verified separately after publishing this copy.

The publishable copy preserves event order, SQL arguments/results and readable answers. Stock-redacted authorization remains redacted; request session/trace identifiers and nonsemantic response headers (including cookies and opaque turn state) are removed, retaining SSE content type. Two opaque encrypted-reasoning values are replaced consistently across response events and later request history with nonsecret placeholders; request signatures are regenerated for those privacy-redacted bodies. No model or tool frames are invented. The private original remains unchanged. Credential/JWT/email pattern checks found no matches in the copy; this is not an absolute guarantee of absence of secrets. The recording contains encrypted reasoning items but no readable reasoning-summary or commentary events, so it does not establish coverage of either display path. Parallel tool calls are not covered.

Replay requires the isolated database migrated and the canonical fixtures loaded, with synthetic conversation history suitable for recording. The ordinary demo fixture invokes `User::initializeChatGptIdentity()` through native Alice method calls to create a fresh random, nonsecret connection epoch. It does not assign a fixed namespace or include credentials, client IDs or provider registration data. Normal live mode remains disconnected until owner OAuth; replay-only DI supplies synthetic in-memory auth state and a throwing mock OAuth client without reading real auth files. A clean fixture-backed stack therefore needs no real ChatGPT sign-in for offline replay. Existing stacks without an initialized demo epoch still require initialization through their ordinary connection flow; do not reload fixtures over existing identities. Inference uses stock `CassetteHttpClient(record: false)` without a real client. Missing cassette, unmatched requests or exhausted interactions fail rather than making live requests.

```sh
CHAT_MODEL=gpt-6-luna CHAT_REASONING_EFFORT=high CHAT_CASSETTE_REPLAY=1 CHAT_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
# Restart before each replay journey to reset the worker's in-memory FIFO cursor:
CHAT_CASSETTE_REPLAY=1 vendor/bin/castor browser:stack:restart-worker
vendor/bin/castor browser:live:test -- tests/Live/ChatTest.php
# Restore ordinary live behavior, including after a failed test:
CHAT_CASSETTE_REPLAY=0 CHAT_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
```

The same browser journey sends two deterministic messages, checks follow-up context and reload persistence, expands the real SQL call arguments/result and verifies the persisted call identifier/result after reload. It starts from the unstored draft route, creates a conversation on first submission and appends the follow-up to its native history. Existing retained chats are untouched. Native Agent execution, bridge conversion, worker, broker and private Mercure remain in use. A small test-only HTTP delegate maps the new conversation cache UUID and the complete `Current date (UTC): YYYY-MM-DD.` system header to the recorded values through native `HttpCassette` replacements. Bare dates, financial records, schema, tools, SQL, currency, model and reasoning effort are not normalized; those must match the reviewed recording. Replay therefore survives a different runtime day without weakening schema/tool matching. Use the same model/effort as the recording and a fresh synthetic conversation for each replay, without discarding existing history. Explicit Delete or reset still requires the relevant authorization. The captured requests were plain JSON; this replay configuration does not support compressed requests. The stock client buffers responses, so replay does not establish live streaming timing. To run the opt-in live smoke instead, leave both cassette flags at zero and complete the owner-login prerequisite above.

The runner supplies the current checkout URL and proxy address internally. No manual URL flags are needed. The stack uses `var/browser-live` for its application database, private uploads, broker database, and socket. The fixed intercepted database and development data are separate. Native migrations and append-only fixture loading are the only setup; there are no test endpoints, application fault hooks, or cross-run state files.

A browser timeout, failed provider call, missing tool, interrupted process, or unavailable stack is a failure, not a pass. Do not stop unrelated containers, remove volumes, or clear owner data. Stop the shared proxy only through `dev:proxy:stop` when no checkout uses it.

## Diagnostics and evidence

Main runs saved tests and QA directly through Castor. Read `var/qa/<task>/result.json` first and inspect bounded diagnostics only when needed. The browser test output is under `var/qa/test-browser/`, with JUnit at `var/qa/test/junit.xml`; live output is under `var/qa/browser-live-test/`. Reports are overwritten on the next run of the same task. Do not run the same task concurrently.

`qa:static` includes Composer validation, read-only style checking, PHPStan, and runtime-complete `symfony lsp:check`. Missing tools or incomplete analysis are failures. See [ADR 0009](docs/adr/0009-castor-and-static-checks.md) and [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for tooling and bounded diagnostics. Do not suppress rules to make the gate pass.

Exploratory browser work uses the configured browser agent and system `playwright-cli`, with a unique session and isolated profile. Obtain permission first for special-case investigations. Close owned contexts on success and failure; never use global cleanup commands or the owner's browser profile.

Keep screenshots, traces, profiles, cookies, reports, and provider data private and out of Git. Use synthetic financial inputs only. Static checks do not establish runtime behavior, and intercepted tests do not establish cross-process integration. Report only what was actually verified.

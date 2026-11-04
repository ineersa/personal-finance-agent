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

Before the chat journey, ask the owner to sign into this stack's Finance URL as `demo@test.com / demo`, then use **Continue with ChatGPT** and complete consent. Confirm the connected status in Settings. Do not start provider tests until that login is complete. The browser must run on the same host as the loopback OAuth callback.

Chat uses the package-managed store at `var/private/chatgpt-auth` inside the application, mapped to `var/browser-live/private/chatgpt-auth` in this isolated stack. Web and worker share those files and the same user registration-store identifier. Pending attempts and topics use a separate connection epoch; chat jobs carry only a conversation identifier and the worker derives the owner and epoch from that record. Disconnect retains registration for reconnecting. The configured installation ID is shared across users; there is no account-switch action. Do not copy credentials from another checkout, inspect token contents, or put credentials in fixtures, cassettes, commands or reports. The live chat test sends synthetic text only and makes real provider requests. It requires access to `gpt-6-luna`; model registration alone does not establish entitlement. Missing login or model access is a failure, not a skipped/passing integration.

The editable system prompt is `prompts/chat.txt`, loaded by Symfony AI's file-based prompt processor. Defaults are `CHAT_MODEL=gpt-6-luna` and `CHAT_REASONING_EFFORT=low`; new conversations capture them and the factory registers each conversation's saved model. Only provider-emitted readable summaries/commentary are displayed, so those sections may be absent. Finance tools and charts are not connected in this slice. Progress comes from the native Agent execution and is not persisted as a separate state machine. The sanitizer still filters HTML and links, but its input-length limit is disabled for complete long replies; this removes its input-size denial-of-service bound.

Run the relevant live journey, then stop the owned stack when it is no longer needed:

```sh
vendor/bin/castor browser:live:test -- tests/Live/ChatTest.php
# Separately, when validating imports:
vendor/bin/castor browser:live:test -- tests/Live/StatementImportTest.php
vendor/bin/castor browser:stack:stop
```

### Private synthetic chat recording

The live chat journey requires an empty conversation and refuses to clear existing messages. Coordinate with the owner before recording; do not discard an existing conversation to make the test pass. The two deterministic prompts use fictional savings amounts and a code word. The current prompt template must also contain no personal data.

For an explicitly authorized recording, recreate the owned browser worker with recording enabled, then run the existing journey:

```sh
CHAT_CASSETTE_RECORD=1 vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:console --worker -- debug:container 'App\Chat\AgentFactory'
vendor/bin/castor browser:live:test -- tests/Live/ChatTest.php
# Disable recording before any further app use, including after a failed test:
CHAT_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
```

The test-only service override lives in `config/services_test.yaml`, after the main service definitions. The targeted container inspection must show `app.chat.recording_http_client` among AgentFactory's arguments before a paid run. A successful chat test alone does not prove recording: confirm that the cassette exists afterward.

Only the Agent inference transport is wrapped with Symfony AI's stock `CassetteHttpClient`; OAuth, discovery and refresh use the original client. Output is `var/browser-live/private/chat-cassettes/synthetic-chat.json`, ignored by Git, inside a mode-0700 directory with a mode-0600 file. Existing output is refused rather than appended or overwritten. Inspect it privately before publishing anything; never print raw bodies or credentials to reports. Stock header/request redaction is not a guarantee that every response field is suitable for publication. Native message items and encrypted reasoning remain conversation data pending inspection. Report captured event types/counts without payloads; summaries and commentary are optional even with `reasoning.summary=auto`.

### Offline chat replay

The reviewed synthetic cassette is `tests/Fixtures/chat/synthetic-chat.json`. It contains two inference responses with final-answer text; it does not cover readable reasoning summaries or commentary. The original private recording remains unchanged. Inspection found no recognizable credential patterns or unredacted named sensitive fields; that is not an absolute guarantee of absence of secrets.

Replay requires the isolated database migrated and the canonical fixtures loaded, with an empty current conversation. The ordinary demo fixture invokes `User::initializeChatGptIdentity()` through native Alice method calls to create a fresh random, nonsecret connection epoch. It does not assign a fixed namespace or include credentials, client IDs or provider registration data. Normal live mode remains disconnected until owner OAuth; replay-only DI supplies synthetic in-memory auth state and a throwing mock OAuth client without reading real auth files. A clean fixture-backed stack therefore needs no real ChatGPT sign-in for offline replay. Existing stacks without an initialized demo epoch still require initialization through their ordinary connection flow; do not reload fixtures over existing identities. Inference uses stock `CassetteHttpClient(record: false)` without a real client. Missing cassette, unmatched requests or exhausted interactions fail rather than making live requests.

```sh
CHAT_CASSETTE_REPLAY=1 CHAT_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
# Restart before each replay journey to reset the worker's in-memory FIFO cursor:
CHAT_CASSETTE_REPLAY=1 vendor/bin/castor browser:stack:restart-worker
vendor/bin/castor browser:live:test -- tests/Live/ChatTest.php
# Restore ordinary live behavior, including after a failed test:
CHAT_CASSETTE_REPLAY=0 CHAT_CASSETTE_RECORD=0 vendor/bin/castor browser:stack:up
```

The same browser journey sends two deterministic messages, checks follow-up context and reload persistence, then confirms New chat and checks the empty state. It refuses to clear pre-existing messages at the start. Native Agent execution, bridge conversion, worker, broker and private Mercure remain in use. A small test-only HTTP delegate maps only the new conversation cache UUID to the cassette's UUID through native `HttpCassette` replacements. The captured requests were plain JSON; this replay configuration does not support compressed requests. The stock client buffers responses, so replay does not establish live streaming timing. To run the opt-in live smoke instead, leave both cassette flags at zero and complete the owner-login prerequisite above.

The runner supplies the current checkout URL and proxy address internally. No manual URL flags are needed. The stack uses `var/browser-live` for its application database, private uploads, broker database, and socket. The fixed intercepted database and development data are separate. Native migrations and append-only fixture loading are the only setup; there are no test endpoints, worker-fault controls, or cross-run state files.

A browser timeout, failed provider call, missing tool, interrupted process, or unavailable stack is a failure, not a pass. Do not stop unrelated containers, remove volumes, or clear owner data. Stop the shared proxy only through `dev:proxy:stop` when no checkout uses it.

## Diagnostics and evidence

Main runs saved tests and QA directly through Castor. Read `var/qa/<task>/result.json` first and inspect bounded diagnostics only when needed. The browser test output is under `var/qa/test-browser/`, with JUnit at `var/qa/test/junit.xml`; live output is under `var/qa/browser-live-test/`. Reports are overwritten on the next run of the same task. Do not run the same task concurrently.

`qa:static` includes Composer validation, read-only style checking, PHPStan, and runtime-complete `symfony lsp:check`. Missing tools or incomplete analysis are failures. See [ADR 0009](docs/adr/0009-castor-and-static-checks.md) and [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for tooling and bounded diagnostics. Do not suppress rules to make the gate pass.

Exploratory browser work uses the configured browser agent and system `playwright-cli`, with a unique session and isolated profile. Obtain permission first for special-case investigations. Close owned contexts on success and failure; never use global cleanup commands or the owner's browser profile.

Keep screenshots, traces, profiles, cookies, reports, and provider data private and out of Git. Use synthetic financial inputs only. Static checks do not establish runtime behavior, and intercepted tests do not establish cross-process integration. Report only what was actually verified.

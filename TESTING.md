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
| Chat preview | Existing placeholder view; generation is not implemented |
| Settings | Save and reload reporting currency and money locale; verify localized money display |
| Sources | Create, list, edit, detail, delete |
| Transactions | List, detail, edit, reload and delete an approved transaction |
| Categories | Ordinary-owner create, list, edit, detail, delete |
| Users | Administrator create, list, edit email/password, verify login, detail, delete |
| Statements | List, detail, download |
| Live import | Upload one canonical CSV, correct draft fields and classifications, save and continue editing, save and return, delete another row, approve remaining statement rows, reload corrections, delete that uploaded statement |

The first nine scenarios are in `tests/E2E/`. The single real-network import journey is in `tests/Live/`. It calls the configured Jev provider and uses the actual worker, broker, and Mercure stack. It verifies that edited drafts do not change the approved transaction list, deletes one draft through native confirmation, then approves the remaining statement rows and reloads corrections through the transaction editor. It does not cover invalid submissions, repeat approval, ownership attacks, failure/retry, redelivery, quotas, hostile input, or concurrent processing. Those require separate permission. Reset remains untested by owner instruction.

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

Run the single live journey, then stop the owned stack when it is no longer needed:

```sh
vendor/bin/castor browser:live:test -- tests/Live
vendor/bin/castor browser:stack:stop
```

The runner supplies the current checkout URL and proxy address internally. No manual URL flags are needed. The stack uses `var/browser-live` for its application database, private uploads, broker database, and socket. The fixed intercepted database and development data are separate. Native migrations and append-only fixture loading are the only setup; there are no test endpoints, worker-fault controls, or cross-run state files.

A browser timeout, failed provider call, missing tool, interrupted process, or unavailable stack is a failure, not a pass. Do not stop unrelated containers, remove volumes, or clear owner data. Stop the shared proxy only through `dev:proxy:stop` when no checkout uses it.

## Diagnostics and evidence

Main runs saved tests and QA directly through Castor. Read `var/qa/<task>/result.json` first and inspect bounded diagnostics only when needed. The browser test output is under `var/qa/test-browser/`, with JUnit at `var/qa/test/junit.xml`; live output is under `var/qa/browser-live-test/`. Reports are overwritten on the next run of the same task. Do not run the same task concurrently.

`qa:static` includes Composer validation, read-only style checking, PHPStan, and runtime-complete `symfony lsp:check`. Missing tools or incomplete analysis are failures. See [ADR 0009](docs/adr/0009-castor-and-static-checks.md) and [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for tooling and bounded diagnostics. Do not suppress rules to make the gate pass.

Exploratory browser work uses the configured browser agent and system `playwright-cli`, with a unique session and isolated profile. Obtain permission first for special-case investigations. Close owned contexts on success and failure; never use global cleanup commands or the owner's browser profile.

Keep screenshots, traces, profiles, cookies, reports, and provider data private and out of Git. Use synthetic financial inputs only. Static checks do not establish runtime behavior, and intercepted tests do not establish cross-process integration. Report only what was actually verified.

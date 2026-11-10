# Personal finance assistant

A planned personal finance demo for the Symfony AI hackathon, using Symfony, Symfony AI, and EasyAdmin.

## Current status

The application provides form login, tenant-local finance management, CSV import and draft review, private statement downloads, reporting preferences and a local ChatGPT connection. Administrators manage users, not tenant finances. Identity stays central; each ordinary user has an authoritative finance SQLite database with independently seeded categories and ECB-maintained rates. The signed-in chat remains a preview; generation is not implemented. See [ADR 0023](docs/adr/0023-per-user-finance-databases.md).

The lockfile pins Symfony `8.2.x-dev`, Symfony AI `1.0.0-BETA1`, and EasyAdmin `5.8.0`. Symfony 8.2 has no released tag at this bootstrap revision; using its locked development branch is an explicit project decision. The Dockerfile pins FrankenPHP `1.13.2` with PHP `8.5.12` by image digest.

## Run development

Use Linux amd64, Docker Engine, Docker Compose 2.24.4 or newer, Git, and host PHP 8.5 CLI with PCNTL/POSIX. Follow the [initial dependency bootstrap](TESTING.md#prepare-the-tooling-runtime) if `vendor/bin/castor` is missing. No host Composer, Node.js, API keys, or reference checkouts are needed.

```sh
	vendor/bin/castor tooling:prepare
	vendor/bin/castor dev:stack:up
```

Open the URL printed by `dev:stack:up`. The public landing shows **Make sense of your money.**, a **Log in** link, and the ordinary demo credentials in dev/test. It does not show the signed-in workspace. Each checkout gets a hostname such as `http://personal-finance-agent-<path-hash>.finance.localhost`. Do not set `HTTP_PORT` or `COMPOSE_PROJECT_NAME` for development.

A shared Caddy proxy publishes only `127.0.0.1:80`; application containers publish no host ports. Port 80 must be free. If another service owns it, startup fails; do not stop that service without authorization. Modern browsers resolve `*.localhost` to loopback. The saved [live-routing check](TESTING.md#isolated-live-stack) explicitly configures Chromium resolution inside tooling containers instead of relying on container DNS.

Castor supplies checkout-scoped Compose names, image tags, host UID/GID, and network aliases. It builds the `frankenphp_dev` target and bind-mounts the checkout. Direct development `docker compose` commands deliberately require Castor's identity variables; use the tasks below instead. Production commands still select files explicitly and retain their port configuration.

`dev:stack:up` creates a private `.env.local` with a random secret if absent, without overwriting an existing file. Keep it private; do not put a real secret in the public `.env`. Application runtime files stay under this checkout's ignored `var/`.

Inspect or stop only the current checkout:

```sh
	vendor/bin/castor dev:stack:status
	vendor/bin/castor app:console -- about
	vendor/bin/castor dev:stack:stop
```

Stopping a checkout runs Compose `down` without `--volumes`: it removes that stack's containers and private networks and unregisters its route. Named volumes, bind-mounted data, other checkouts, the shared proxy, and its external network are preserved; no global prune runs. The same lifecycle applies to `browser:stack:stop`. Stop each owned stack before removing its worktree. `vendor/bin/castor dev:proxy:stop` stops the proxy only when no routes remain registered. It removes no volumes. The locked route registry lives in `finance-development/` inside the repository's common Git directory, shared across its worktrees. See [ADR 0019](docs/adr/0019-worktree-development-routing.md) for boundaries and limitations.

### Upgrade the shared development proxy

Only with explicit authorization for a brief outage of **all preview URLs**, run these from the checkout containing the upgraded Dockerfile:

```sh
vendor/bin/castor dev:stack:up                 # Build/start this checkout's new development image first.
vendor/bin/castor dev:proxy:stop --for-upgrade # Stop/remove only the ownership-verified shared proxy container.
vendor/bin/castor dev:stack:up                 # Recreate the proxy from this checkout's image and retained routes.
```

The second startup reuses the prepared build cache. Other checkout stacks keep running; the shared network, route registry, databases, credentials, uploads and volumes are preserved. Normal `dev:proxy:stop` still refuses registered routes and only stops its container. There is no automatic rollback; if recreation fails, correct the reported error and rerun the final command from the prepared checkout.

## Load synthetic development data

Start the development stack first so it creates a private application secret. Apply migrations, then append the Alice fixtures to this checkout's development database:

```sh
	vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
	vendor/bin/castor dev:fixtures:load
```

Load once into a database without the fixture identities. This task always uses native Hautelook Alice append mode. It does not purge existing records or overwrite existing accounts; repeated loads fail on unique email/stored-filename constraints. Do not remove owner data to make a repeated load succeed.

The tenant cutover is a clean development rebuild, not a data upgrade. The generated central migration removes old shared finance tables. Main must verify exact disposable dev/test database paths and active stack ownership before resetting them; do not delete uploads, OAuth credentials, unrelated volumes or another checkout's storage. Fresh fixture loading creates tenant schemas automatically. For later finance migrations, run `vendor/bin/castor app:console -- app:tenant:migrate`. Generate finance migrations through isolated Castor console storage with `doctrine:migrations:diff --em=finance --configuration=config/migrations/finance.yaml`; never generate them against an active tenant file.

Development and tests share canonical account definitions in `fixtures/central/*.yaml` and tenant definitions in `fixtures/*.yaml`, without a supplementary dataset. Fixtures provide **admin@test.com / admin** and **demo@test.com / demo** with native Symfony password hashing. Native Alice loading provisions the demo tenant, independently seeds categories/history/rates, and creates the same synthetic private CSV bytes for downloads. These public demo credentials and invented records are not production provisioning or personal data.

Statement bytes stay outside `public/`. Production stores them in `/app/var/data/statements` in the existing project-owned `app_data` volume; development uses checkout-local `var/statements`. Persist only data, not `/app/var/cache`, so rebuilt images use their new compiled routes and services. Do not remove statement files or the data volume during cache clearing. Future workers must share the same storage and metadata volume. See [private statement storage](docs/adr/0020-private-statement-storage.md) for access, lifecycle and isolated test locations.

Open `/login` on the printed development URL. Login always uses the role's entry page, not a saved protected-page target: ordinary users reach Chat at `/home/chat`, while administrators reach Users at `/home/user`. Ordinary users manage finances at `/home/source`, `/home/statement`, `/home/transaction` and `/home/category`, with reporting preferences at `/home/settings`. Administrators manage only Users at `/home/user`. Manual currency-rate CRUD is removed. The former `/admin` and `/settings` URLs are not aliases. Both workspaces offer CSRF-protected POST logout. Public signup and password recovery are not implemented.

Chat and Settings are native EasyAdmin custom pages, sharing the same layout, navigation, account menu, and form theme as the CRUD screens. Public landing and login use the separate public shell. All screens use the dark theme without an appearance selector, regardless of saved browser preferences or operating-system settings. EasyAdmin retains its native search, actions, forms, and account dropdown. Its documented `Dashboard::setTheme()` configures the neutral palette, radius, and density; targeted block overrides and unlayered CSS adapt the layout without a replacement frontend.

Login uses Symfony's default throttling: five failed attempts per minute per username and client IP, with its broader IP limit. Forwarded client-IP headers are not trusted by default. Configure a verified deployment proxy explicitly before enabling those headers; the local proxy otherwise shares one observed client identity.

The reporting-currency choices are EUR and the ECB reference-feed currencies verified on 2026-10-30. Selecting a preference does not rewrite or convert original amounts. Referenced users, sources, and categories cannot be deleted while financial foreign keys still reference them. There is no implicit cascade or demo reset.

## Run the production image

Select production files explicitly so Compose does not load the development override. The `frankenphp_prod` image contains the application, locked production dependencies, and compiled AssetMapper/EasyAdmin assets. It does not mount source or host `vendor/`. It runs as UID/GID 1000 with `APP_ENV=prod` and debug disabled. Production uses `restart: unless-stopped`; development does not restart automatically.

This production-image preview leaves `IMPORT_UPDATES_ENABLED=0`. The optional Mercure hub belongs to the `live-updates` Compose profile and is not started by the commands below, so an empty Mercure key cannot break preview startup. Development Castor stacks enable the hub and generate their own private key. Enabling production live updates separately requires an external HTTPS hub/public route, `IMPORT_UPDATES_ENABLED=1`, matching publisher/subscriber signing keys and issuer, and a configured secure subscriber cookie; the preview commands do not provide that deployment.

Use a separate project and free port to run alongside development. These commands build the production image, generate a private runtime environment file if absent, and start the production configuration:

```sh
export COMPOSE_PROJECT_NAME=personal-finance-agent-prod HTTP_PORT=8081
docker compose -f compose.yaml build
if [ ! -f .env.prod.local ]; then
	(umask 077; docker compose -f compose.yaml run --rm --no-deps -T php php -r 'echo "APP_SECRET=", bin2hex(random_bytes(32)), PHP_EOL;' > .env.prod.local)
fi
docker compose -f compose.yaml -f compose.prod.yaml up -d --wait
```

Open <http://127.0.0.1:8081/>. Check that the same design-preview page renders with compiled assets, including its dark layout and **Make sense of your money.** heading. After source or lockfile changes, rebuild with `docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait`.

`.env.prod.local` is required runtime input, not an image build input. Do not commit it, print its contents, or use a real secret in the public `.env`. Private environment files, Composer credentials, development tooling directories, host dependencies, and generated host assets are excluded from the build context.

The project-scoped `app_data` volume provides writable persistent `/app/var/data`; production's SQLite URL and private statement storage point there. `STATEMENT_STORAGE_DIR` defaults to `var/statements` in development and `var/data/statements` in production, relative to the project directory. Keep any production override inside the private data mount. Tests fix their root independently of this variable. Cache and asset metadata stay outside that volume so it does not hide image-built files. The application includes Doctrine migrations for users and financial records; image startup does not apply them or create accounts. Alice fixtures are available only in development and test environments. This is a local production-image proof, not the later HTTPS/server-deployment setup.

Use the same project/port variables for production diagnostics and shutdown:

```sh
docker compose -f compose.yaml -f compose.prod.yaml ps
docker compose -f compose.yaml -f compose.prod.yaml logs --tail 50 php
docker compose -f compose.yaml -f compose.prod.yaml exec php php bin/console about
docker compose -f compose.yaml -f compose.prod.yaml down
```

Keep the data volume; do not use `down -v`. Before returning to the default development project, unset `COMPOSE_PROJECT_NAME` and `HTTP_PORT`, or restore that development stack's values.

For an isolated startup check, run `vendor/bin/castor prod:smoke`. It builds the same production configuration, verifies only PHP is enabled, allocates a loopback port automatically, and waits for the application health check. It uses a checkout-owned project and private generated runtime environment, then stops that project without removing its data volume. It does not migrate, seed or reset a database, and never reads `.env.prod.local` contents into its report.

## Development checks and diagnostics

Follow [Testing](TESTING.md#prepare-the-tooling-runtime) for the one-time dependency bootstrap. After that, use host Castor for preparation, QA, and Mate. Castor selects the authoritative Docker runtime; do not choose between host and container PHP or prefix commands with another runner. The production target does not contain Node.js, Symfony CLI, or development Composer packages.

```sh
	vendor/bin/castor tooling:prepare
	vendor/bin/castor cs:fix
	vendor/bin/castor qa:static
	vendor/bin/castor test
```

Castor prints one JSON summary per invoked task and stores diagnostics under ignored `var/qa/`. `qa:static` runs Composer validation, a read-only formatting check, strict PHPStan level 6, and complete runtime-aware Symfony Language Tools analysis. CS Fixer uses the selected sqlite-queue rules except for the approved `call_type: self` assertion policy, and retains risky Symfony rules and strict types. PHPStan follows that reference's production/tooling scope, not its tests. Browser tests receive style checks and real-browser execution, not a claimed PHPStan pass. The task workflow runs `castor check` during the CODE-REVIEW transition as the sole combined static/browser gate. It runs both groups even if either fails. Read its stdout report for child summaries; do not invoke it prematurely during task-start.

Pass native PHPUnit selectors after `--`, for example `vendor/bin/castor test -- tests/E2E/HomepageTest.php`; `check` always runs the full suite. Keep one happy-path scenario per view/action and obtain owner permission for special-case testing. Use `app:composer -- COMMAND OPTIONS` for application Composer and `app:console -- COMMAND OPTIONS` for Symfony console commands. Bare `castor composer` manages Castor itself, not the application. See [Testing](TESTING.md#running-tests) for commands and scope.

Use bounded Mate tasks after warming the relevant cache or recording local requests:

```sh
	vendor/bin/castor mate:discover
	vendor/bin/castor mate:tools
	vendor/bin/castor mate:inspect symfony-services
	vendor/bin/castor dev:cache:warmup
	vendor/bin/castor mate:call symfony-services --json='{"query":"HomeController","context":"dev","limit":1}'
	vendor/bin/castor mate:call symfony-profiler-list --json='{"context":"test","limit":1}'
	vendor/bin/castor mate:call monolog-search --json='{"environment":"dev","level":"ERROR","limit":5}'
```

`mate:call` exposes all 11 installed selected official tools through their upstream JSON parameter schemas. Service results are limited to 10 per context, profile listings to 5, log searches/tail to 20, and dotenv listings to 200. `mate:collector TOKEN COLLECTOR` reads one targeted official profiler collector. Dev and test artifacts are separate named contexts; the saved profiler scenario enables collection for its own request without changing the collection policy. See [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for filters and prerequisites. Missing artifacts fail rather than masquerading as empty results. Do not dump full profiler records into agent context. Mate-generated skills are disabled. `mate:discover` refreshes official extensions while preserving the curated Castor instructions and not retaining a newly generated editor-specific instruction file.

The `test` task applies central migrations to isolated storage and runs the saved browser scenarios headlessly. Native Alice loading provisions fixture tenant schemas. The owner-approved project trait composes `RefreshDatabaseTrait` and extends rollback to tenant connections, including those opened during a scenario; DAMA rollback stays disabled for these fixture cases. Canonical fixtures and the existing Playwright runner are retained. Development/live fixture loading stays append-only. Use `test --headed` only for owner-requested visible runs. See [Testing](TESTING.md) for storage, transaction ownership and real-stack limits.

Monolog uses the web template's configuration unchanged: development writes to `var/log/dev.log`; test logs are buffered until an error; production uses an error-triggered buffer with JSON output on stderr. There is no custom logging lifecycle subscriber, correlation generator, sanitizer, or log rotation. Standard logging does not remove personal data: callers must keep financial content, model exchanges, credentials, and sensitive exception details out of logs. See [ADR 0007](docs/adr/0007-structured-monolog-logging.md).

## Planned workflow

1. Log in and upload bank statements.
2. Review extracted transactions, correct them, and manage sources.
3. Ask questions in chat.
4. Read answers, tables, and inline interactive charts in the same conversation. There is no separate artifact workspace.

Example questions:

- "Show me how much I spent on subscriptions last month."
- "Show me my income growth over the past year."
- "Show me my internet bills over the past year."

The development fixtures already provide known demo identities and synthetic financial records for manual CRUD checks without personal statements. The completed demo will add the remaining workflows below. AI work and uploads will have configurable rate limits, quotas, and shared AI-budget controls.

PDF/XLSX conversion will run locally through official MarkItDown MCP in Docker; CSV will be parsed in PHP. Scanned-PDF/OCR support remains unresolved.

Charts use a Docker-hosted Unovis MCP service with local HTML generation and private conversation-scoped retention. Sandboxed iframes allow the packaged chart runtime, not model-generated JavaScript, and deny same-origin privileges and external network access. Existing PNG/JPG charts remain readable. [ADR 0026](docs/adr/0026-local-unovis-charts.md) records the isolation boundary and pinned fork. Build without restarting services with `vendor/bin/castor charts:build` (or `--browser` for the isolated stack).

Chat history uses the application SQLite database with automatic retention, favourites, unstored New chat drafts and owner-scoped modal full-text search. See ADRs 0027 and 0028.

## Project documents

- [Specification](SPEC.md): product scope, workflows, and open decisions.
- [Architecture decisions](docs/adr/README.md): accepted technical direction, not implementation claims.
- [Testing](TESTING.md): Playwright-only browser testing and agent verification.
- [Development rules](AGENTS.md): task workflow, worktrees, safety, and tooling requirements.

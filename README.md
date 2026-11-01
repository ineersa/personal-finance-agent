# Personal finance assistant

A planned personal finance demo for the Symfony AI hackathon, using Symfony, Symfony AI, and EasyAdmin.

## Current status

The application provides form login, owner-scoped source management, approved-transaction corrections, read-only statements, and a reporting-currency preference for ordinary users. Administrators manage only user accounts and the shared category catalog; they cannot enter financial screens or reporting settings, including for administrator-owned records. The signed-in finance chat remains a responsive design preview with disabled controls. Uploads, draft approval, chat generation, currency conversion, and reset controls are not implemented yet.

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

Stopping a checkout unregisters its route but preserves its containers, data, other checkouts, and the shared proxy. Stop each owned stack before removing its worktree. `vendor/bin/castor dev:proxy:stop` stops the proxy only when no routes remain registered. It removes no volumes. The locked route registry lives in `finance-development/` inside the repository's common Git directory, shared across its worktrees. See [ADR 0019](docs/adr/0019-worktree-development-routing.md) for boundaries and limitations.

## Load synthetic development data

Start the development stack first so it creates a private application secret. Apply migrations, then append the Alice fixtures to this checkout's development database:

```sh
	vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
	vendor/bin/castor dev:fixtures:load
```

Load once into a database without the fixture identities. This task always uses native Hautelook Alice append mode. It does not purge existing records or overwrite existing accounts; repeated loads fail on unique email/stored-filename constraints. Do not remove owner data to make a repeated load succeed.

Development and tests use the same canonical fixture definitions in `fixtures/*.yaml`, with no supplementary test dataset. Tests load these definitions into isolated test storage, never the development database. The fixtures provide **admin@test.com / admin** and **demo@test.com / demo**. Alice's password processor uses the configured Symfony password hasher. These intentionally public demo credentials are not production provisioning. The dataset includes shared categories with icons, separate owner sources, synthetic statements, approved income/spending transactions, and an incomplete draft excluded from approved screens. Alice's statement processor creates synthetic CSV bytes for owner-authorized downloads from the statement screens. It contains no real financial data. Upload and processing workflows are not implemented.

Statement bytes stay outside `public/`. Production stores them in `/app/var/data/statements` in the existing project-owned `app_data` volume; development uses checkout-local `var/statements`. Persist only data, not `/app/var/cache`, so rebuilt images use their new compiled routes and services. Do not remove statement files or the data volume during cache clearing. Future workers must share the same storage and metadata volume. See [private statement storage](docs/adr/0020-private-statement-storage.md) for access, lifecycle and isolated test locations.

Open `/login` on the printed development URL. Login always uses the role's entry page, not a saved protected-page target: ordinary users reach Chat at `/home/chat`, while administrators reach Users at `/home/user`. Ordinary users manage finances at `/home/source`, `/home/statement`, and `/home/transaction`, with reporting preferences at `/home/settings`. Administrators can use only Users and Categories at `/home/user` and `/home/category`. The former `/admin` and `/settings` URLs are not aliases. Both workspaces offer CSRF-protected POST logout. Public signup and password recovery are not implemented.

Chat and Settings are native EasyAdmin custom pages, sharing the same layout, navigation, account menu, and form theme as the CRUD screens. Public landing and login use the separate public shell. All screens use the dark theme without an appearance selector, regardless of saved browser preferences or operating-system settings. EasyAdmin retains its native search, actions, forms, and account dropdown. Its documented `Dashboard::setTheme()` configures the neutral palette, radius, and density; targeted block overrides and unlayered CSS adapt the layout without a replacement frontend.

Login uses Symfony's default throttling: five failed attempts per minute per username and client IP, with its broader IP limit. Forwarded client-IP headers are not trusted by default. Configure a verified deployment proxy explicitly before enabling those headers; the local proxy otherwise shares one observed client identity.

The reporting-currency choices are EUR and the ECB reference-feed currencies verified on 2026-10-30. Selecting a preference does not rewrite or convert original amounts. Referenced users, sources, and categories cannot be deleted while financial foreign keys still reference them. There is no implicit cascade or demo reset.

## Run the production image

Select production files explicitly so Compose does not load the development override. The `frankenphp_prod` image contains the application, locked production dependencies, and compiled AssetMapper/EasyAdmin assets. It does not mount source or host `vendor/`. It runs as UID/GID 1000 with `APP_ENV=prod` and debug disabled. Production uses `restart: unless-stopped`; development does not restart automatically.

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

## Development checks and diagnostics

Follow [Testing](TESTING.md#prepare-the-tooling-runtime) for the one-time dependency bootstrap. After that, use host Castor for preparation, QA, and Mate. Castor selects the authoritative Docker runtime; do not choose between host and container PHP or prefix commands with another runner. The production target does not contain Node.js, Symfony CLI, or development Composer packages.

```sh
	vendor/bin/castor tooling:prepare
	vendor/bin/castor cs:fix
	vendor/bin/castor qa:static
	vendor/bin/castor test
```

Castor prints one JSON summary per invoked task and stores diagnostics under ignored `var/qa/`. `qa:static` runs Composer validation, a read-only formatting check, strict PHPStan level 6, and complete runtime-aware Symfony Language Tools analysis. CS Fixer uses the selected sqlite-queue rules except for the approved `call_type: self` assertion policy, and retains risky Symfony rules and strict types. PHPStan follows that reference's production/tooling scope, not its tests. Browser tests receive style checks and real-browser execution, not a claimed PHPStan pass. The task workflow runs `castor check` during the CODE-REVIEW transition as the sole combined static/browser gate. It runs both groups even if either fails. Read its stdout report for child summaries; do not invoke it prematurely during task-start.

Pass native PHPUnit selectors after `--`, for example `vendor/bin/castor test -- --filter='testHomepage$' tests/E2E/HomepageTest.php`. Multiple files, regex filters, groups, suites, and listing flags use PHPUnit's own syntax; `check` always runs the full suite. Use `app:composer -- COMMAND OPTIONS` for application Composer and `app:console -- COMMAND OPTIONS` for Symfony console commands. Bare `castor composer` manages Castor itself, not the application. See [Testing](TESTING.md#command-contract) for examples.

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

The `test` task applies migrations to the fixed, separate SQLite test database, then runs the saved browser scenarios headlessly by default. Fixture-backed scenarios use Alice's native `RefreshDatabaseTrait` to purge and seed only that test-owned database once, then roll back each case. Their fixture base opts out of DAMA rollback through its documented class attribute; DAMA remains enabled for other intercepted scenarios. Development and live-stack fixture loading stays append-only. Use `vendor/bin/castor test --headed` only when the owner requests a visible browser. Feature-named scenarios check authentication, CRUD, ownership and rollback through browser pages; `DemoWorkspaceTest` uses the shared Alice dataset. See [Testing](TESTING.md) for storage and real-stack limits.

Monolog uses the web template's configuration unchanged: development writes to `var/log/dev.log`; test logs are buffered until an error; production uses an error-triggered buffer with JSON output on stderr. There is no custom logging lifecycle subscriber, correlation generator, sanitizer, or log rotation. Standard logging does not remove personal data: callers must keep financial content, model exchanges, credentials, and sensitive exception details out of logs. See [ADR 0007](docs/adr/0007-structured-monolog-logging.md).

## Planned workflow

1. Log in and upload bank statements.
2. Review extracted transactions, correct them, and manage sources.
3. Ask questions in chat.
4. Read answers, tables, and inline chart images in the same conversation. There is no separate artifact workspace.

Example questions:

- "Show me how much I spent on subscriptions last month."
- "Show me my income growth over the past year."
- "Show me my internet bills over the past year."

The development fixtures already provide known demo identities and synthetic financial records for manual CRUD checks without personal statements. The completed demo will add the remaining workflows below. AI work and uploads will have configurable rate limits, quotas, and shared AI-budget controls.

PDF/XLSX conversion will run locally through official MarkItDown MCP in Docker; CSV will be parsed in PHP. Scanned-PDF/OCR support remains unresolved.

Charts will use a Docker-hosted AntV MCP server and its documented free hosted renderer. Aggregated chart labels and values leave the installation, even with a local chat model. [ADR 0014](docs/adr/0014-inline-chat-and-antv-charts.md) records the decision and service limitations.

Chat history will use the existing application SQLite database: five temporary chats per user plus saved chats, with a Save chat button. Only inactive temporary chats can be pruned; queued/running work is protected. See [ADR 0015](docs/adr/0015-sqlite-conversation-history.md).

## Project documents

- [Specification](SPEC.md): product scope, workflows, and open decisions.
- [Architecture decisions](docs/adr/README.md): accepted technical direction, not implementation claims.
- [Testing](TESTING.md): Playwright-only browser testing and agent verification.
- [Development rules](AGENTS.md): task workflow, worktrees, safety, and tooling requirements.

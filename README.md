# Personal finance assistant

A planned personal finance demo for the Symfony AI hackathon, using Symfony, Symfony AI, and EasyAdmin.

## Current status

The Docker bootstrap renders a Symfony hello-world page. Castor static checks, Mate diagnostics, structured lifecycle logging, and a PHP Playwright harness are installed. Authentication, financial workflows, and chat are not implemented yet.

The lockfile pins Symfony `8.2.x-dev`, Symfony AI `1.0.0-BETA1`, and EasyAdmin `5.8.0`. Symfony 8.2 has no released tag at this bootstrap revision; using its locked development branch is an explicit project decision. The Dockerfile pins FrankenPHP `1.13.2` with PHP `8.5.12` by image digest.

## Run development

Install Docker Engine with Docker Compose. Run these commands from the repository root on a Unix-like host. No host PHP, Composer, Node.js, API keys, or reference checkouts are needed.

```sh
export LOCAL_UID="$(id -u)" LOCAL_GID="$(id -g)"
docker compose build
docker compose run --rm --no-deps php php -r 'if (!is_file(".env.local")) { file_put_contents(".env.local", "APP_SECRET=".bin2hex(random_bytes(32)).PHP_EOL); chmod(".env.local", 0600); }'
docker compose run --rm --no-deps php composer install --no-interaction
docker compose up -d --wait
```

Open <http://127.0.0.1:8080/>. The page heading is **Hello, world!**

The PHP service binds only to loopback. If port 8080 is occupied, set `export HTTP_PORT=8081` before starting and use that port in the browser. Do not stop another project's container to free the port. To run a second checkout alongside this one, also set a distinct `COMPOSE_PROJECT_NAME` and keep those variables set for its lifecycle commands.

Compose automatically combines shared `compose.yaml` with local `compose.override.yaml`. It builds the `frankenphp_dev` target and bind-mounts the checkout, including locally installed dependencies. `.env.local` contains the generated local secret and is ignored by Git and the Docker build context. Keep it private; do not replace the blank public `APP_SECRET` default with a real secret. Application runtime files belong under ignored `var/`. No financial database or demo data is created by this bootstrap.

Inspect or stop this project with the same environment variables used at startup:

```sh
docker compose ps
docker compose logs --tail 50 php
docker compose exec php php bin/console about
docker compose down
```

`down` stops this Compose project; do not add volume-deletion flags.

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

Open <http://127.0.0.1:8081/>. The same hello-world page should render with compiled assets. After source or lockfile changes, rebuild with `docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait`.

`.env.prod.local` is required runtime input, not an image build input. Do not commit it, print its contents, or use a real secret in the public `.env`. Private environment files, Composer credentials, development tooling directories, host dependencies, and generated host assets are excluded from the build context.

The project-scoped `app_data` volume provides writable persistent `/app/var/data`; production's SQLite URL points there for later database work. Cache and asset metadata stay outside that volume so it does not hide image-built files. The bootstrap still creates no financial database. This is a local production-image proof, not the later HTTPS/server-deployment setup.

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

Castor prints one JSON summary per invoked task and stores diagnostics under ignored `var/qa/`. `qa:static` runs Composer validation, a read-only formatting check, strict PHPStan level 6, and complete runtime-aware Symfony Language Tools analysis. CS Fixer uses the selected sqlite-queue rules unchanged, including risky Symfony rules, strict types, and instance assertion calls. PHPStan follows that reference's production/tooling scope, not its tests. Browser tests receive style checks and real-browser execution, not a claimed PHPStan pass. The task workflow runs `castor check` during the CODE-REVIEW transition as the sole combined static/browser gate. It runs both groups even if either fails. Read its stdout report for child summaries; do not invoke it prematurely during task-start.

Use bounded Mate tasks after warming the development cache or recording local requests:

```sh
	vendor/bin/castor mate:discover
	vendor/bin/castor mate:tools
	vendor/bin/castor mate:inspect symfony-services
	vendor/bin/castor dev:cache:warmup
	vendor/bin/castor mate:services RequestLifecycleSubscriber
	vendor/bin/castor mate:profiles
	vendor/bin/castor mate:logs CORRELATION_ID
```

Service results are limited to 10, profile listings to 5, and correlation searches to 20. Missing artifacts fail rather than masquerading as empty results. Do not dump full profiler records into agent context. Mate-generated skills are disabled. `mate:discover` refreshes official extensions while preserving the curated Castor instructions and not retaining a newly generated editor-specific instruction file.

The `test` task creates the fixed, separate SQLite test database non-destructively through Doctrine, then runs the homepage scenario headlessly by default. Use `vendor/bin/castor test --headed` only when the owner requests a visible browser. DAMA is registered only for test and its PHPUnit extension supplies per-test rollback for intercepted same-process scenarios. No application schema exists yet; entity fixtures and observable rollback scenarios belong with the features that introduce that schema. See [Testing](TESTING.md) for storage and real-stack limits.

HTTP lifecycle logs record server-generated correlation IDs, status codes, durations, and exception class names. They omit URLs, request bodies, exception messages, and raw framework context. Other framework messages become `framework.record`, so detailed exception text is deliberately unavailable in logs. Development JSON-lines files rotate under `var/log/`, retaining seven lifecycle/framework files and three deprecation files. Production sends sanitized lifecycle output to stdout and framework/deprecation output to stderr. Logging processors keep no request state between calls. Future jobs must explicitly propagate correlation IDs and add their own lifecycle events; this bootstrap does not implement those workflows.

## Planned workflow

1. Log in and upload bank statements.
2. Review extracted transactions, correct them, and manage sources.
3. Ask questions in chat.
4. Read answers, tables, and inline chart images in the same conversation. There is no separate artifact workspace.

Example questions:

- "Show me how much I spent on subscriptions last month."
- "Show me my income growth over the past year."
- "Show me my internet bills over the past year."

The intended demo will be locally installable and include a demo user and synthetic financial history so judges can explore it without uploading personal statements. AI work and uploads will have configurable rate limits, quotas, and shared AI-budget controls.

PDF/XLSX conversion will run locally through official MarkItDown MCP in Docker; CSV will be parsed in PHP. Scanned-PDF/OCR support remains unresolved.

Charts will use a Docker-hosted AntV MCP server and its documented free hosted renderer. Aggregated chart labels and values leave the installation, even with a local chat model. [ADR 0014](docs/adr/0014-inline-chat-and-antv-charts.md) records the decision and service limitations.

Chat history will use the existing application SQLite database: five temporary chats per user plus saved chats, with a Save chat button. Only inactive temporary chats can be pruned; queued/running work is protected. See [ADR 0015](docs/adr/0015-sqlite-conversation-history.md).

## Project documents

- [Specification](SPEC.md): product scope, workflows, and open decisions.
- [Architecture decisions](docs/adr/README.md): accepted technical direction, not implementation claims.
- [Testing](TESTING.md): Playwright-only browser testing and agent verification.
- [Development rules](AGENTS.md): task workflow, worktrees, safety, and tooling requirements.

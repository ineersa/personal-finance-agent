# Personal finance assistant

A planned personal finance demo for the Symfony AI hackathon, using Symfony, Symfony AI, and EasyAdmin.

## Current status

The Docker bootstrap renders a Symfony hello-world page. EasyAdmin and Symfony AI are installed, but authentication, financial workflows, chat, and development QA tooling are not implemented yet.

The lockfile pins Symfony `8.2.x-dev`, Symfony AI `1.0.0-BETA1`, and EasyAdmin `5.8.0`. Symfony 8.2 has no released tag at this bootstrap revision; using its locked development branch is an explicit project decision. The Dockerfile pins FrankenPHP `1.13.2` with PHP `8.5.12` by image digest.

## Run the bootstrap

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

The source and installed dependencies are bind-mounted from the checkout. `.env.local` contains the generated local secret and is ignored by Git and the Docker build context. Keep it private; do not replace the blank public `APP_SECRET` default with a real secret. Application runtime files belong under ignored `var/`. No financial database or demo data is created by this bootstrap.

Inspect or stop this project with the same environment variables used at startup:

```sh
docker compose ps
docker compose logs --tail 50 php
docker compose exec php php bin/console about
docker compose down
```

`down` stops this Compose project; do not add volume-deletion flags. Full Castor static checks and headed Playwright test commands will be added in task 02. Their absence is not a passing QA result.

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
- [Testing](TESTING.md): headed Playwright-only browser testing and agent verification.
- [Development rules](AGENTS.md): task workflow, worktrees, safety, and tooling requirements.

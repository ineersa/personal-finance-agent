<p align="center"><img src="docs/images/pf-logo.svg" width="80" alt="pf — Personal finance"></p>

# Personal finance

**A little more clarity.** A locally installable personal-finance assistant built for the Symfony AI hackathon with Symfony, Symfony AI and EasyAdmin. Review bank-statement transactions, explore your finances in conversation, and get interactive charts without sending chart rendering to a hosted service.

[![Watch the demo on YouTube](https://img.youtube.com/vi/FLsiqc9wyTI/hqdefault.jpg)](https://www.youtube.com/watch?v=FLsiqc9wyTI)

## Try the demo

### Prerequisites

- Linux amd64 with Bash, Docker Engine and Docker Compose **2.24.4 or newer**; a browser on the same computer.
- Internet access for image/dependency builds, Jev, ChatGPT and ECB reference rates.
- A **Jev API key** from [TypeSafe](https://typesafe.ai/), and your own ChatGPT account with permission to use the selected models. Jev and ChatGPT are separate connections; an OpenAI API key is not used for chat.
- Free loopback ports **8080** (application) and **1455** (ChatGPT callback). The installer does not stop another service to free a port.

After cloning this repository:

```bash
./setup-demo.sh
```

The installer builds locked application dependencies and compiled assets inside Docker—no host PHP, Composer or Node.js is needed. On first installation it asks for your Jev key with hidden input, creates private `.env.prod.local` settings, applies central/tenant migrations and loads the existing canonical synthetic Alice fixtures. It starts FrankenPHP, the sqlite-queue broker, the Messenger worker/scheduler, Mercure and local Unovis charts in production mode (`APP_DEBUG=0`). Initial image builds can take several minutes.

Open **<http://127.0.0.1:8080/>**, then log in:

| Account | Password | Purpose |
|---|---|---|
| `demo@test.com` | `demo` | Finance workspace and synthetic financial history |
| `admin@test.com` | `admin` | User administration, not access to tenant finances |

These are public demo credentials. **Keep this installation local; do not expose it to the internet or put personal bank statements into it.** Public signup and password recovery are not implemented. The development-only destructive Settings reset is not enabled in this production-mode demo.

### Suggested walkthrough

1. Log in as `demo@test.com`. Browse **Sources**, **Statements** and **Transactions** to inspect the synthetic history and download a synthetic CSV.
2. In **Settings**, choose your reporting currency/format and **Connect ChatGPT**. Complete the provider sign-in, then follow the completion page back to Settings. The callback is loopback-only, so browser and Docker must run on the same computer. Account/model availability and provider charges depend on your provider account.
3. Open **Chat**, choose a model/reasoning option, and ask: “Show my spending by category for the latest month in the data as a bar chart.” Ask a follow-up, hover the local chart, then reload or reopen the retained conversation.
4. To try import, upload one CSV from [`resources/demo-statements/`](resources/demo-statements/). Consent to sending its samples/details to Jev. Watch processing, review/edit the draft rows, then approve them. Uploading seeded statements adds new records; use synthetic data only.

## What works—and what is unfinished

- Form login; ordinary-user finance CRUD; administrator-only user management; private statement downloads; reporting preferences and ECB-backed rates.
- CSV processing through Jev, editable drafts and explicit approval, with live updates from the real worker.
- Per-user ChatGPT connection; multi-turn chat using Symfony AI's native agent/tool loop; read-only tenant SQL; private streaming; locally generated interactive Unovis charts.
- Automatically retained native conversation history, favourites, new-chat drafts and owner-scoped full-text search. There is no temporary/save mode or one-active-generation subsystem; Messenger uses native concurrency of four.
- **PDF/XLSX extraction is unfinished.** Local MarkItDown conversion is planned, not installed by this script. Scanned-PDF/OCR support is unresolved. Use CSV for the current demo.
- Financial amounts are stored as decimal strings; application conversion uses BCMath and transaction-date rates. **SQLite SQL arithmetic/aggregates and model answers are approximate**, not authoritative accounting or financial advice. SQL can read drafts; draft exclusion is prompt guidance, not a separate access boundary.
- The upload limit is **10 MiB**. Broader AI quotas, budgets, hard SQL execution deadlines and advanced recovery are not implemented. This local demo is not a security audit or a hardened public deployment.

CSV interpretation is provider-assisted rather than a promise to accept every bank format; the headerless debit/credit and signed-amount files under `resources/demo-statements/` are the supported walkthrough inputs. Reporting currency choices are EUR plus the [listed ECB reference currencies](src/Enum/ReportingCurrency.php), including USD and GBP. Changing the reporting preference does not rewrite original amounts/currencies. Conversion uses stored transaction-date reference rates; absent rates need an ECB refresh, and locale-aware formatting owns display rounding.

## Configuration and lifecycle

`.env.prod.local` is a private, ignored runtime file excluded from Docker builds. The installer never sources it as shell code or prints its values. It generates `APP_SECRET` and `MERCURE_JWT_SECRET`, and writes:

```dotenv
JEV_API_KEY='your-key'
JEV_BASE_URL=https://api.typesafe.ai
JEV_MODEL=jev-1.14.0
```

Edit the file to change Jev configuration; keep mode `0600`. Do not commit it or paste its contents into diagnostics. For unattended setup, the installer also accepts `JEV_API_KEY` from its process environment. On rerun it preserves the existing file and secrets, migrates existing schemas and **does not load fixtures again**.

The default Compose project is `personal-finance-demo`. Always select production files explicitly; plain `docker compose up` loads the development override and is not the demo command.

```bash
# Status
 docker compose --project-name personal-finance-demo --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml ps
# Stop: keep every volume
 docker compose --project-name personal-finance-demo --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml down
# Restart the existing installation
 docker compose --project-name personal-finance-demo --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml up -d --wait php broker worker mercure charts
# Rebuild after source/dependency updates, migrate and restart without reseeding
 ./setup-demo.sh
```

**Never use `down --volumes`, remove volumes, or delete a database to make setup succeed.** A single `.demo-ready` completion file identifies successful initial provisioning. Nonempty storage without it (including an interrupted first fixture load or an older preview database) is refused, not purged, adopted or repaired. Inspect that installation; to try a genuinely fresh demo, choose a different project and free ports instead. Setup is not a backup/restore or recovery tool. Stop an active demo before applying code updates with schema changes.

For a separate installation, override these values **consistently on setup and later lifecycle commands**. Keep its private env file distinct too:

```bash
export COMPOSE_PROJECT_NAME=personal-finance-demo-second
export HTTP_PORT=8082 CHATGPT_CALLBACK_PORT=1456
export DEMO_ENV_FILE=.env.prod.second.local
./setup-demo.sh
# Use the exact status/stop/restart commands printed by the installer.
```

There are no published broker, MCP, database, upload-storage or Mercure ports. Only the application and callback listeners bind to host loopback; the application proxies authorized Mercure subscriptions.

### Storage and privacy

Compose-project-scoped named volumes preserve:

| Volume | Container path | Contents |
|---|---|---|
| `app_data` | `/app/var/data` | Central identity/conversation DB, `tenants/*.db`, private statement bytes and setup completion file |
| `app_private` | `/app/var/private` | Plaintext, mode-protected ChatGPT credentials/attempts and shared native filesystem locks |
| `app_charts` | `/app/var/charts` | Privately retained conversation charts |
| `broker_data` | `/app/var/queue` | Separate broker SQLite database and Unix socket |
| `chart_output` | `/chart-output` | Local chart staging shared only with PHP/worker/one-off setup and Unovis |

Mercure also retains its project-owned data/config volumes. App cache and compiled assets are **not** hidden behind a persistent `var/` mount. Runtime PHP/broker/worker run as UID/GID 1000 with no source/vendor bind mounts; development dependencies exist only in the one-off setup image, not the running production image.

“Local” does not mean offline: selected statement samples/details go to Jev; chat context, tool results and financial information needed for answers go to ChatGPT. Charts render locally, but their input may already be in the chat provider context. Financial data, OAuth records and model exchanges must not be added to logs, commits or bug reports. Local filesystem permissions are not encryption and do not protect against another process with the same OS identity.

### Troubleshooting

- **Port already allocated:** select free `HTTP_PORT`/`CHATGPT_CALLBACK_PORT` values as above; do not stop unrelated stacks.
- **Setup fails:** read the concise failing command output. Preserve the private env file and volumes. Interrupted initial provisioning is deliberately not automatically reseeded.
- **Chat unavailable:** log in as the ordinary demo user, connect ChatGPT in Settings, and select an available model. Jev's key does not authorize chat. A remote browser cannot complete this loopback flow.
- **Import/chat not progressing:** inspect service status and bounded worker/broker/PHP logs using the same project/file arguments as above, replacing `ps` with `logs --tail 50 worker broker php`. Logs can contain sensitive exception details; inspect locally, do not publish raw output. Do not use `docker compose config` to share configuration—it can disclose secrets.
- **Image build fails:** internet access and the pinned development/beta dependencies are required. The application locks Symfony `8.2.x-dev`, Symfony AI `1.0.0-BETA1` and EasyAdmin `5.8.0`; FrankenPHP/PHP are pinned in `Dockerfile`. This is deliberate, not a claim that Symfony 8.2 is released.

## Development and verification

Development/QA still use **host `vendor/bin/castor`**, not the demo installer. Follow [TESTING.md](TESTING.md#prepare-the-tooling-runtime) for the initial dependency bootstrap and host PHP prerequisites, then:

```bash
vendor/bin/castor tooling:prepare
vendor/bin/castor dev:stack:up
vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor dev:fixtures:load
```

Load development fixtures once into empty fixture identities: this native Alice task is append-only, never a reset. Development uses checkout-scoped `*.finance.localhost` URLs printed by Castor and a shared loopback proxy; do not set manual development ports/project names. Stop only your checkout with `vendor/bin/castor dev:stack:stop`; never stop unrelated stacks or remove their volumes. Stop the shared proxy only through `dev:proxy:stop` when no routes remain.

For changes, run `vendor/bin/castor cs:fix`, relevant `vendor/bin/castor test -- …` happy-path scenarios and `vendor/bin/castor qa:static`. The workflow's `castor check` is the combined full gate. Tests use isolated storage and real headless Playwright browsers; special-case coverage needs owner permission. `vendor/bin/castor prod:smoke` checks only the compiled PHP/charts images, not migrations, fixture initialization, provider access, workers or live updates. A passing image smoke is not proof that the full demo works.

## Further reading

- [ARCHITECTURE.md](ARCHITECTURE.md): runtime diagrams, import/chat flows and trust boundaries.
- [SPEC.md](SPEC.md): product requirements and deferred work, not an implementation checklist.
- [Accepted ADRs](docs/adr/README.md): technical choices and their limitations.
- [TESTING.md](TESTING.md): browser scenarios, isolation and validation policy.
- [AGENTS.md](AGENTS.md): contributor workflow, safety and tooling rules.
- [Mate diagnostics](mate/AGENT_INSTRUCTIONS.md): bounded, privacy-aware development inspection.

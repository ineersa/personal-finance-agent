# Architecture

Personal finance is a local Symfony application, not a separate chat frontend plus financial API. EasyAdmin owns the authenticated workspace; Twig, AssetMapper, Stimulus and Turbo provide the UI. Symfony AI owns the agent/tool loop and native message storage. SQLite stores identities and conversations centrally, with a separate authoritative finance database for each ordinary user.

This document describes the current implementation. Accepted [ADRs](docs/adr/README.md) explain decisions; [SPEC.md](SPEC.md) also includes future requirements. [README.md](README.md) covers installation.

## Runtime overview

```mermaid
flowchart LR
    Browser["Local browser"]
    Jev["Jev / TypeSafe API"]
    ChatGPT["ChatGPT OAuth + model service"]
    ECB["ECB reference feed"]
    subgraph Docker["Local demo Compose project"]
        PHP["FrankenPHP / Symfony\nEasyAdmin + Twig + Turbo"]
        Broker["sqlite-queue broker\nUnix socket + separate SQLite"]
        Worker["Messenger worker\nchat + imports + Scheduler\nnative concurrency: 4"]
        Hub["Private Mercure hub"]
        Charts["Local Unovis MCP\npackaged HTML runtime"]
        Central[("Central SQLite\nusers + conversations + messages")]
        Tenants[("Per-user finance SQLite")]
        Files[("Private files\nstatements / OAuth / charts")]
        PHP --> Broker
        Broker <--> Worker
        PHP --> Central
        PHP --> Tenants
        PHP --> Files
        Worker --> Central
        Worker --> Tenants
        Worker --> Files
        Worker --> Charts
        Worker --> Hub
        PHP --> Hub
    end
    Browser <-->|"HTTP loopback app + Turbo/SSE"| PHP
    Browser -->|"Loopback callback-only listener"| PHP
    Browser <-->|"Account authorization"| ChatGPT
    PHP <-->|"OAuth exchange / refresh"| ChatGPT
    Worker <-->|"Native agent / tool loop"| ChatGPT
    Worker <-->|"CSV interpretation"| Jev
    Worker -->|"Scheduled rates update"| ECB
```

Only the application listener and callback-only listener publish loopback host ports. SSE reaches Mercure through Caddy's application route; the broker, MCP service and hub have no published host ports. No public financial/database API or raw file directory is exposed. The Unovis service sees only chart staging—not tenant databases, uploads or OAuth records.

Production PHP, broker and worker use the same compiled `--no-dev` image as UID/GID 1000. The one-off `frankenphp_demo_setup` image includes development dependencies solely to invoke the existing dev-mode canonical Alice loader. It is not a production service. `setup-demo.sh` applies Doctrine migrations, appends canonical fixtures only to fresh storage and records successful completion. A normal rerun migrates without reseeding; ambiguous/incomplete nonempty storage is refused. It never purges or removes volumes. See [ADR 0031](docs/adr/0031-local-demo-packaging.md).

## Import and review

```mermaid
sequenceDiagram
    actor User
    participant Web as Symfony workspace
    participant Disk as Private statement storage
    participant Finance as Owner finance SQLite
    participant Broker as sqlite-queue
    participant Worker as Messenger import handler
    participant Jev as TypeSafe / Jev
    participant Hub as Private Mercure
    User->>Web: Upload CSV + explicit provider consent
    Web->>Web: Authenticate owner and enforce 10 MiB limit
    Web->>Disk: Store private bytes
    Web->>Finance: Persist statement metadata
    Web->>Broker: Dispatch statement-processing message
    Worker->>Broker: Consume message
    Worker->>Finance: Resolve persisted owner / statement
    Worker->>Disk: Read CSV
    Worker->>Jev: Typed interpretation / classification questions
    Jev-->>Worker: Typed decisions
    Worker->>Finance: Short writes: drafts + processing status
    Worker->>Hub: Owner-scoped private Turbo update
    Hub-->>User: Progress and review UI via app proxy
    User->>Web: Edit draft rows, save, then approve
    Web->>Finance: Validate and approve reviewed rows
```

Parsing/provider work is outside database write transactions. Categories and transactions belong to the tenant database, not to a role-wide shared financial store. Drafts remain editable before approval. Statement downloads require ordinary-user access and tenant ownership; stored filenames are not public URLs.

Jev handles CSV interpretation, meaning and recurrence decisions. It is independent of ChatGPT sign-in. PDF/XLSX conversion through local MarkItDown is an accepted future direction, **not a running service or implemented extraction path in this release**. OCR remains unresolved. The packaged walkthrough uses CSV and the existing synthetic fixture definitions.

## Chat, tools and retained history

```mermaid
sequenceDiagram
    actor User
    participant Web as Chat workspace
    participant Store as Central SQLite / native message store
    participant Broker as sqlite-queue
    participant Worker as Messenger / Symfony AI Agent
    participant GPT as Connected ChatGPT account
    participant SQL as Owner-bound read-only SQLite tool
    participant Chart as Local Unovis MCP + private chart files
    participant Hub as Private Mercure
    User->>Web: Question + model / reasoning selection
    Web->>Store: Persist owned conversation and submission
    Web->>Broker: GenerateChat
    Worker->>Broker: Consume with native worker context
    Worker->>Store: Load native history and conversation owner
    Worker->>GPT: Current instructions + retained context
    loop Native agent tool calls as needed
        GPT-->>Worker: Tool request
        alt Financial query
            Worker->>SQL: SQL bound to conversation owner's DB
            SQL-->>Worker: Bounded rows / diagnostic result
        else Chart
            Worker->>Chart: Data/options with application-selected output path
            Chart-->>Worker: Retained private chart reference
        end
        Worker->>GPT: Tool result
        Worker->>Hub: Private activity / text updates
        Hub-->>User: Turbo updates over authenticated SSE
    end
    GPT-->>Worker: Final response
    Worker->>Store: Native messages + linked application metadata
    Worker->>Hub: Final private update
    User->>Web: Reopen / reload / search conversation
    Web->>Store: Read only this owner's retained history
    Web-->>User: Markdown, activity and sandboxed chart iframe
```

ChatGPT credentials are per Finance account, handled through the installed OAuth package's file store; the application supplies the owner namespace and short-lived callback handoff. An ordinary user connects in Settings. Jev keys and OpenAI API keys do not replace that connection.

Symfony AI's native agent, message bag, tool dispatch and Doctrine message store remain authoritative. There is no parallel transcript, custom generation recovery engine, temporary/save history mode or per-user one-active-generation gate. New-chat drafts are unstored until submission; owned retained conversations support favourites and native SQLite FTS5 search. Model/reasoning selection is per turn, and target-model history conversion is request-only. Existing messages are not rewritten to accommodate a model switch.

The SQL tool opens only the resolved owner's finance file read-only, with a default-deny SQLite authorizer and bounded input/result sizes. It has no external database/host file capabilities or model-selected owner. **It does not have a hard execution deadline**; expensive queries can occupy/exhaust a worker. SQLite arithmetic and aggregates may be approximate. Decimal-string financial storage and the application's BCMath converter do not make arbitrary model-authored SQL exact. Draft visibility is intentional; exclusion from ordinary reports is prompt guidance.

Unovis renders locally using its pinned packaged runtime. The application overrides output path/type and retains the resulting document privately against conversation metadata. It serves charts only after owner/membership checks, inside an iframe with `sandbox="allow-scripts"` and restrictive response CSP, without same-origin privileges or external network access. Model-generated arbitrary JavaScript is not an application extension point. Chart arguments/tool results and financial context may already have been sent to ChatGPT; local rendering is not an offline/privacy guarantee.

## Storage and trust boundaries

```mermaid
flowchart TB
    subgraph Host["Host / local operator trust boundary"]
        Env["Private .env.prod.local\napp + Mercure secrets / Jev config"]
        subgraph App["PHP + worker application boundary"]
            Gate["Authenticated principal\nowner resolved server-side"]
            Auth["Package OAuth store\nshared native flock locks"]
        end
        subgraph Data["app_data volume"]
            Central[("app.db\nidentity + native chat + search metadata")]
            Alice[("tenants / owner A.db\nA's finance records")]
            Bob[("tenants / owner B.db\nB's finance records")]
            Statements["statements/\nprivate raw bytes"]
        end
        Private["app_private volume\nplaintext OAuth records + attempts + locks"]
        Retained["app_charts volume\nconversation-scoped retained files"]
        Queue["broker_data volume\nbroker DB + Unix socket\nnot the application DB"]
        Staging["chart_output volume\nprivate internal staging"]
        MCP["Unovis service\nno DB / credentials / uploads"]
    end
    Providers["External Jev / ChatGPT\nselected financial context leaves host"]
    Env --> App
    Gate --> Central
    Gate -->|"Only the resolved owner"| Alice
    Gate -->|"Only the resolved owner"| Bob
    Gate --> Statements
    Auth --> Private
    App --> Retained
    App <--> Queue
    App <--> Staging
    MCP <--> Staging
    App <--> Providers
```

Administrators manage central users; that role does not confer access to tenant finances. HTTP endpoints, EasyAdmin actions, handlers, tools, downloads and event subscriptions must resolve ownership, not just test roles. The central database locates tenants; the model cannot choose a tenant path. Worker calls use the persisted conversation/statement owner, not a browser-supplied identity.

Volumes are Compose-project scoped and shared at identical paths by the relevant nonroot processes. Private records are protected by OS permissions, not encryption at rest; another process with the same UID or access to Docker can read them. Keep the environment file and all related volumes together when retaining an installation. Caches and image-compiled assets are not placed in the data volume. There is no backup/restore tooling or automatic cleanup/recovery promise in this packaging slice.

Uploaded bytes, record text, Markdown and model/tool output are untrusted. Rendered prose is sanitized/escaped; chart execution is isolated from the parent application. Do not log or commit raw statements, model exchanges, OAuth payloads or credentials. Structured Monolog output does not sanitize sensitive data automatically.

## Verification boundaries

[TESTING.md](TESTING.md) defines the small Playwright-only suite. Intercepted browser scenarios verify request/UI behavior with isolated Alice fixtures and native rollback. They do not prove broker/worker/Mercure integration; that requires an isolated running stack and actual browser interaction. A successful image smoke, source inspection or Compose validation is not proof of provider access, a completed import/chat journey, failure recovery or security certification. Special-case verification requires explicit owner approval.

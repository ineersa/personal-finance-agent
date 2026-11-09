# ADR 0031: Package a complete local production-mode demo

- Status: Accepted
- Date: 2026-11-09

## Context

The owner approved a judge-facing README, `setup-demo.sh` and root architecture diagrams for task 20. A fresh clone should build and start through Docker without host PHP/Composer/Node, prompt only for Jev's API key, and initialize the existing synthetic data. The previous production Compose configuration proved only an image preview: it omitted the broker/worker, disabled live updates, and did not provision accounts. Alice and its tenant decorator intentionally exist only in dev/test, while running demo services must use production dependencies and compiled assets.

## Decision

Keep the owner-approved Bash installer narrowly scoped to local-demo installation. Development and QA continue through host Castor. Reuse the existing Dockerfile, explicit base/production Compose files, native Doctrine commands and canonical Alice definitions. Add a one-off Docker target/service containing development dependencies to run the existing dev-mode append loader against the newly migrated demo storage; never include those dependencies in the final production image or run the application server in dev mode. No alternative dataset, fixture framework, production reset or public provisioning endpoint is added.

Start production PHP, sqlite-queue broker, Messenger worker/Scheduler, Mercure and local Unovis together. Use native concurrency of four and existing transports. Publish only application and callback listeners on host loopback; proxy private Mercure through the application. Broker and MCP remain internal. This is a local demo, not public HTTPS deployment (task 21).

Preserve project-scoped volumes for central/tenant databases and private statements, OAuth records/attempts, retained charts, separate broker data/socket and chart staging. Use matching UID/GID 1000 and identical shared paths. Point the existing native Symfony filesystem lock store at a shared private directory so web and worker OAuth operations use the same locks; this is configuration of the existing package mechanism, not new coordination infrastructure. Do not persist the entire `var/` directory or hide image-built cache/assets behind a volume.

Generate random application/Mercure secrets and store Jev's key with the existing provider URL/model defaults in ignored, mode-0600 `.env.prod.local`, excluded from image builds. Never execute/source that file or print its contents. Preserve existing settings on rerun. Initial provisioning appends fixtures only when the application data directory is empty; one completion file records success. Existing nonempty storage without that file is refused, including partial first setup or an older preview. There is no adoption, reseed, automatic repair, purge, volume deletion or recovery subsystem. An ordinary recognized rerun applies central/tenant migrations without loading fixtures again. Stop an active demo before code/schema upgrades.

Use optional Compose project, loopback application/callback port and private env-file overrides for distinct local installations and isolated task verification. Keep the selected values consistent on later lifecycle commands; do not stop unrelated stacks to free ports.

## Alternatives considered

- Dev-mode application server or dev dependencies in production: rejected; only the initial native fixture loader needs development packages.
- A second production dataset or custom account/fixture loader: rejected; reuse canonical definitions and processors.
- Repeat Alice loading on every startup, or purge on rerun: rejected; public fixture identities are not a license to erase existing data.
- Treat the old PHP-only preview as the demo: insufficient to demonstrate actual imports, worker chat and live updates.
- Extend into public deployment, failure recovery or a security audit: outside the approved packaging scope.

## Consequences

Judges get one installer and a complete production-mode local runtime. A separate setup image takes additional build space/time and includes development tools, but is not a running service. Build-time network access is required for pinned dependencies and images. Jev and ChatGPT remain external services, and the browser must share loopback with Docker for OAuth. Private filesystem permissions are not encryption. Compiled-image smoke checks remain deliberately narrower than full installation/browser/provider verification.

Interrupted first provisioning requires inspection or a distinct fresh project rather than automatic repair. The script deliberately preserves ambiguous storage and all volumes. PDF/XLSX extraction, OCR, broader quotas, public deployment and new recovery machinery remain deferred. Documentation must distinguish implemented flows from these gaps and source/syntax checks from actual runtime evidence.

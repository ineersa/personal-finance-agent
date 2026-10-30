# ADR 0012: Configure AI providers and expose chat model selection

- Status: Accepted
- Date: 2026-10-29

## Context

The demo needs a local model option and selected hosted providers. Installations need to control which integrations are available without exposing credentials to the browser. Chat users must be able to select a provider, model, and supported reasoning option.

The project owner selected the provider scope and selector UI. This decision records that scope only; it does not authorize implementation, task creation, or provider experiments.

## Decision

### Initial integrations and installation configuration

Support exactly these three chat-provider integrations initially:

- Local llama.cpp.
- z.ai.
- DeepSeek.

Make enabled providers configurable through server configuration or an environment-provided allowlist. An installation may enable a subset of these integrations; this decision does not select the enabled or default set for every installation.

[ADR 0018](0018-finance-review-sql-and-jev.md) selects structured transaction extraction through these same three integrations, with extraction provider/model configuration independent of chat. Jev is a separate categorization and decision backend, not a fourth chat-provider integration or automatic fallback.

Keep credentials and other secrets server-side in environment or secret configuration. Do not send them to the browser or include them in queued generation payloads.

### Chat selection and queued work

- Provide provider, model, and reasoning selection in chat. The selector UI is an accepted requirement, not a tentative enhancement.
- Expose only enabled and configured providers, allowed models, and reasoning options supported by each model. Do not assume all providers implement the same reasoning controls.
- Validate every provider/model/reasoning combination on the server. Hiding an option in the UI is not validation.
- Capture the validated selected choices for each queued generation. Later UI selection changes must not retarget in-flight work.
- No automatic cross-provider failover has been selected.

### Symfony AI and reference reuse

Use Symfony AI facilities first. Selectively adapt Agent Core's provider compatibility handling and per-model reasoning mappings where needed. Do not copy its Hatfield catalog machinery or tests.

Use these sources as read-only references, not installation dependencies:

- `/home/ineersa/projects/agent-core/src/CodingAgent/Config/ReasoningOptionsResolver.php`.
- `/home/ineersa/projects/agent-core/src/AgentCore/Infrastructure/SymfonyAi/ReasoningContentFeatureShaper.php`.
- `/home/ineersa/projects/agent-core/src/AgentCore/Infrastructure/SymfonyAi/ZaiToolStreamFeatureShaper.php`.

Verify compatibility with the pinned Symfony AI packages and selected models during bootstrap. Reference code does not establish that this application's provider integration works.

### ChatGPT is not a delivery goal

ChatGPT sign-in is optional future exploration, not an MVP goal, delivery prerequisite, or fourth supported provider. It must not block the three selected integrations. This decision neither authorizes nor reports an OAuth experiment; its package, callback, registration, and usage-permission questions remain deferred.

## Alternatives considered

- Treating ChatGPT sign-in as a prerequisite would make the selected three-provider implementation depend on optional exploratory work. It is explicitly excluded from that delivery scope.
- Importing Hatfield's provider catalog would bring unrelated machinery into a small demo. Adapt only the compatibility and reasoning behavior needed for the selected integrations.

## Consequences

- Exact model IDs, allowed reasoning options, installation defaults, API/configuration schema, packages, compatible APIs, credentials, and network requirements remain bootstrap details.
- Provider selection does not bypass ownership checks, private streaming, or shared quotas and budgets. Follow [ADR 0003](0003-shared-sqlite-database.md), [ADR 0005](0005-mercure-streaming.md), and [ADR 0011](0011-layered-rate-limiting.md).
- Provider and selector behavior remains subject to the headed Playwright-only policy in [ADR 0010](0010-playwright-only-testing.md) and [TESTING.md](../../TESTING.md).
- No application setup, provider configuration, dependency installation, provider call, or experiment is implemented or validated by this documentation decision.

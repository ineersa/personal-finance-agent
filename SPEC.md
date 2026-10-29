# Personal finance assistant

## Project goal

Build a personal finance demo for the Symfony AI hackathon. Users upload bank statements, review the extracted financial data, and ask questions about their finances through a chat interface.

Judges must be able to install and run the demo locally. Prioritize a small, working MVP and fast development while keeping code quality high.

This document defines the product requirements. Technical decisions and their rationale are recorded in the [architecture decision records](docs/adr/README.md).

## Core workflow

1. The user logs in.
2. The user uploads bank statements.
3. The application parses the statements and extracts transactions and associated information, including categories and sources.
4. The user reviews the extracted data for correctness, edits transactions, and creates or edits sources.
5. The user asks financial questions in the main chat interface.
6. The assistant presents answers with artifacts such as tables and graphs.

## Main interface

Use a two-panel workspace:

- Left panel: the conversation and chat input.
- Right panel: artifacts produced by the assistant, including tables, graphs, and other structured results.

The chat is the primary entry point for financial questions. The review workflow lets users correct the data on which those answers depend.

## Example questions

- "Show me how much I spent on subscriptions last month."
- "Show me my income growth over the past year."
- "Show me my internet bills over the past year."

## MVP scope

- User login.
- Bank statement upload and transaction extraction.
- Transaction categorization and source identification.
- Review and editing of parsed transactions.
- Creation and editing of sources.
- Financial questions through chat.
- Streamed assistant responses while generation is in progress.
- Answers presented as text and structured artifacts in the two-panel workspace.
- A locally installable demo for judges.

## User roles

- Users manage their own financial data, statement uploads, reviews, and conversations. They must not access another user's personal data.
- Administrators can create, view, edit, and delete user accounts and perform authorized demo-reset operations. Reset scope and safeguards remain to be defined.

## Demo proposal

Provide synthetic financial fixtures and a demo user so judges can explore the application without uploading personal bank statements.

The fixtures should cover enough history and transaction types to demonstrate the example questions, including subscriptions, income, and internet bills.

Support restoring the demo to a known state. A manual reset and a scheduled reset were proposed; the mechanism and schedule remain undecided. Any reset must be limited to demo data and clearly communicate that demo edits will be lost.

## Development priorities

- Deliver a working demonstration before expanding the scope.
- Keep financial calculations and data handling testable.
- Make local setup and the demo walkthrough reproducible.
- Use synthetic data in fixtures and automated tests.
- Keep planned behavior distinct from implemented behavior.

## Resource restrictions

Limit AI work and statement uploads primarily per authenticated user, with coarse IP-based abuse protection and login throttling. Chat and AI-powered statement extraction share the user's AI allowance and an instance-wide daily AI budget. Bound active and pending work, file sizes, daily uploaded bytes, and retained storage.

Explain refused actions and when retrying is possible. Rejected requests must not enqueue work; users must still be able to read existing financial data and conversations. Normal cache clearing and financial demo resets must preserve quota and AI-budget accounting. Configurable initial limits and implementation boundaries are recorded in [ADR 0011](docs/adr/0011-layered-rate-limiting.md).

## Technical decisions

- [Application stack](docs/adr/0001-application-stack.md).
- [Selective reuse of the existing projects](docs/adr/0002-project-foundation.md).
- [Shared SQLite application database](docs/adr/0003-shared-sqlite-database.md).
- [sqlite-queue with Symfony Messenger](docs/adr/0004-sqlite-queue.md).
- [Mercure streaming](docs/adr/0005-mercure-streaming.md).
- [AssetMapper and Symfony UX without Encore](docs/adr/0006-assetmapper-and-symfony-ux.md).
- [Structured Monolog logging](docs/adr/0007-structured-monolog-logging.md).
- [Mate development diagnostics](docs/adr/0008-mate-development-tools.md).
- [Castor and final static checks](docs/adr/0009-castor-and-static-checks.md).
- [Playwright-only browser testing](docs/adr/0010-playwright-only-testing.md), with the verification workflow in [TESTING.md](TESTING.md).
- [Layered rate limits and persistent AI budgets](docs/adr/0011-layered-rate-limiting.md).

## Open decisions

- Supporting technologies, exact dependency releases within the accepted stack, and deployment setup.
- Private storage for uploaded statements and file-lifecycle handling.
- Artifact schemas, rendering, persistence, and the model-to-data contract.
- Supported bank statement formats and banks.
- Whether "source" means an account, bank, merchant, income source, or another domain concept.
- Categorization rules and the assistant's role in extraction and categorization.
- AI provider, model, credentials, and network requirements for the local demo.
- Supported currencies and monetary representation.
- How answers reference the transactions used to produce them.
- Whether the assistant can propose or apply edits, or only analyze reviewed data.
- Manual versus scheduled demo reset and its safeguards.
- Resource-policy thresholds not fixed in ADR 0011, including daily byte/storage quotas, shared AI-budget amounts, global capacity, coarse IP limits, and the daily reset boundary.

## Planned repository documentation

Development uses the external task board and the project task workflow described in [AGENTS.md](AGENTS.md#task-workflow). Keep task status and execution tracking there. Reserve `docs/plans/` for descriptions of specific functionality, not a separate backlog or project-wide development plan.

The agreed documentation layout is:

```text
AGENTS.md
README.md
SPEC.md
ARCHITECTURE.md
TESTING.md
docs/
  adr/
    README.md
    0001-application-stack.md
    0002-project-foundation.md
    0003-shared-sqlite-database.md
    0004-sqlite-queue.md
    0005-mercure-streaming.md
    0006-assetmapper-and-symfony-ux.md
    0007-structured-monolog-logging.md
    0008-mate-development-tools.md
    0009-castor-and-static-checks.md
    0010-playwright-only-testing.md
    0011-layered-rate-limiting.md
  plans/
.agents/
  skills/
```

This specification, `README.md`, `AGENTS.md`, `TESTING.md`, the ADRs listed above, and the [project-local UX skills](.agents/skills/README.md) exist. The other planned documents and application setup remain future work. Add ADRs for monetary representation and financial-data history when those decisions are made.

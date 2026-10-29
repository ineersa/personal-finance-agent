# Personal finance assistant

A planned personal finance demo for the Symfony AI hackathon, using Symfony, Symfony AI, and EasyAdmin.

## Current status

This repository contains planning and documentation only. The application is not bootstrapped. There are no application installation, run, or test commands available yet; commands described in the development documents are future requirements.

## Planned workflow

1. Log in and upload bank statements.
2. Review extracted transactions, correct them, and manage sources.
3. Ask questions in the left-hand chat panel.
4. Explore tables, graphs, and other artifacts in the right-hand panel.

Example questions:

- "Show me how much I spent on subscriptions last month."
- "Show me my income growth over the past year."
- "Show me my internet bills over the past year."

The intended demo will be locally installable and include a demo user and synthetic financial history so judges can explore it without uploading personal statements. AI work and uploads will have configurable rate limits, quotas, and shared AI-budget controls.

## Project documents

- [Specification](SPEC.md): product scope, workflows, and open decisions.
- [Architecture decisions](docs/adr/README.md): accepted technical direction, not implementation claims.
- [Testing](TESTING.md): headed Playwright-only browser testing and agent verification.
- [Development rules](AGENTS.md): task workflow, worktrees, safety, and tooling requirements.

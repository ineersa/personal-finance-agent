# ADR 0001: Use Symfony, Symfony AI, and EasyAdmin

- Status: Accepted
- Date: 2026-10-29

## Context

The project is a personal finance demo for the Symfony AI hackathon. Judges need to install and run it locally. Development must prioritize a working MVP while keeping code quality high.

The project owner selected Symfony 8.2 and Symfony AI 1.0 beta. The main interface is a two-panel chat workspace, with financial artifacts displayed alongside the conversation. Source management and transaction corrections should require less custom UI work.

## Decision

- Use Symfony 8.2 as the application framework.
- Use Symfony AI 1.0 beta for the application's AI integration.
- Use EasyAdmin for source management and transaction corrections.
- Build the chat and artifact workspace as application-specific UI rather than treating an admin dashboard as the main product interface.

These are target versions, not a claim that dependencies have been installed or compatibility has been verified. Select the exact Symfony release, Symfony AI beta tags, and compatible EasyAdmin release during bootstrap. If the targets are unavailable or incompatible, report the blocker instead of silently substituting versions.

PHP, the runtime server, database, frontend packages, AI provider, and model remain undecided. This ADR does not select testing tools or a testing architecture.

## Alternatives considered

- A TypeScript application with Next.js was suggested before the hackathon context was known. It does not match the subsequently agreed Symfony stack.
- Keeping the legacy application's Symfony 7.3 version or the template's Symfony 8.0 version would preserve their existing constraints, but would not meet the selected Symfony 8.2 target.

## Consequences

- Existing Symfony entities, authentication patterns, and EasyAdmin screens can inform the new application.
- EasyAdmin reduces custom UI work for data management, leaving more time for the chat workspace.
- Symfony AI's beta status introduces potential API changes. Pin concrete dependency versions and commit the lockfile when setup begins.
- Dependency availability and compatibility must be checked before implementation. No application dependencies have been installed at the time of this decision.

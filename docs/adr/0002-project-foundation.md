# ADR 0002: Adapt selected parts of the existing projects

- Status: Accepted
- Date: 2026-10-29

## Context

Two existing projects contain useful starting points:

- `/var/www/finance` has financial entities, authentication, EasyAdmin screens, migrations, fixtures, and functional tests.
- `/home/ineersa/projects/symfony-web-template` has a FrankenPHP runtime setup, Docker Compose configuration, Symfony frontend configuration, Castor tasks, and agent guidance.

These paths identify local reference material, not dependencies that judges must have installed.

The finance application does not implement the proposed extraction, review, and chat workflows. The template also includes indexing and agent tooling that is not needed for the MVP. The project owner will define the testing requirements separately.

## Decision

Use the existing projects selectively rather than copying either application wholesale.

### Finance application

- Use entities, authentication, and EasyAdmin screens as references. Adapt useful code to this project's requirements.
- Build statement processing, review state, financial queries, and the chat/artifact workspace around the new specification.
- Do not reuse the legacy test suite or assume its test configuration will carry over.

### Symfony web template

- Use selected runtime and frontend configuration as a starting point when bootstrapping. Review each part before adopting it.
- Exclude `ineersa/ai-index`, `ineersa/call-graph`, generated index files, index-watcher integrations, and indexing-specific agent rules.
- Do not inherit mandatory dependencies on Vera or editor-specific agent tools.
- Review retained skills for relevance and compatibility with this project's agent environment.
- Do not include AI Mate development tooling merely because the application uses Symfony AI. Application AI features and development tools serve different purposes.
- Do not inherit the template's testing setup before the testing requirements are defined.
- Leave the original reference projects unchanged.

FrankenPHP and Castor are candidates, not accepted requirements under this ADR. PHP versions, runtime image versions, database choice, frontend packages, and deployment details require separate agreement.

## Alternatives considered

- Copying the entire finance application would bring existing CRUD and tests, but also behavior and assumptions that do not match the new MVP.
- Copying the entire web template would bring unrelated indexing, editor integrations, and testing choices.
- Using both projects only as reading material would avoid inherited configuration but require recreating useful setup. Selective adaptation allows reuse without committing to the full template.

## Consequences

- Reuse can reduce setup work while keeping the new specification authoritative.
- Adopted code needs review, particularly financial-data ownership, upload storage, transaction editing, and file-deletion behavior.
- The template's startup tasks can stop unrelated containers occupying configured ports. Do not carry over that behavior; startup should report a conflict without stopping another project.
- Local installation must work without either reference project or optional agent tooling.
- Testing requirements were initially deferred to the project owner; [ADR 0010](0010-playwright-only-testing.md) now selects Playwright-only browser testing. The reference projects' testing setups remain excluded.
- No source code, dependencies, or runtime configuration have been copied at the time of this decision.

## Subsequent decisions

[ADR 0008](0008-mate-development-tools.md) explicitly selects Mate with the official Symfony and Monolog extensions. [ADR 0009](0009-castor-and-static-checks.md) selects Castor and the static-check reporting workflow. Those later choices resolve the corresponding open tooling questions without authorizing wholesale import of the template or its testing setup.

[ADR 0010](0010-playwright-only-testing.md) resolves the testing-approach decision with real Playwright browsers and no unit or other non-browser suites.

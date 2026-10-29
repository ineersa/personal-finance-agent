# ADR 0010: Test application behavior only through Playwright

- Status: Accepted
- Date: 2026-10-29

## Context

The project owner selected the real-browser PHP/Symfony approach described in the SymfonyCasts article. The owner does not want unit tests or another non-browser application test suite. Agents must be able to investigate and verify behavior themselves, using visible browser windows and closing them afterward.

The system already has `playwright-cli`. Its command help confirms headed operation and named sessions. No browser or application integration has been exercised yet.

## Decision

### One application-testing approach

Use Playwright for all application behavior tests. Write repeatable browser scenarios using `playwright-php/playwright` and its Symfony integration, following the article. Do not add unit, kernel-only, service-only, or API-only test suites. Do not copy tests or test configuration from the reference applications.

PHPUnit may serve as the PHP browser-suite harness required by this integration. Its presence does not authorize unit tests. The Symfony kernel may handle browser requests in-process; every application test still exercises a real browser rather than replacing it with a simulated client.

Use the installed `playwright-cli` for agent-led exploration and focused verification. Keep durable regression scenarios in the project suite; a CLI session does not replace repeatable test coverage.

### Visible, agent-operated browsers

- Run both automated scenarios and exploratory sessions in headed mode so the owner can see them. Do not silently fall back to headless operation when a display is unavailable.
- Agents must perform relevant browser checks themselves. Do not ask the owner to click through behavior that an agent can verify.
- Give exploratory sessions unique names and isolated profiles. Do not use the owner's personal browser state.
- Close owned browser sessions and contexts after each testing run, including failure paths. Arrange teardown and interruption handling; never use global browser cleanup that could close someone else's session.
- Report missing display access, failed cleanup, missing tools, and incomplete scenarios as blockers rather than passes.

### Real background integrations

The Symfony bridge intercepts browser requests and handles them in the test's PHP process. This is useful for UI workflows but does not prove that separate workers, broker processes, or Mercure delivery work.

Keep testing those integrations through Playwright against an isolated running application stack. Assert streamed output, completion, failures, and recovery in the browser. Do not claim cross-process verification from an in-process or mocked run.

Use committed synthetic fixtures and dedicated application, queue, and file storage for cross-process scenarios. Do not assume a transaction rolled back inside the test process also isolates or cleans up independent workers. Do not adopt DAMA or parallel execution automatically because the article demonstrates them.

### Commands and prerequisites

Use Castor for the browser-suite task and the combined validation gate. Extend the concise report convention from [ADR 0009](0009-castor-and-static-checks.md): preserve failure codes, save diagnostics, and print short summaries. Keep formatting fixes separate from validation.

Playwright's Node.js runtime, browser binaries, and display requirements are development/test prerequisites. They do not introduce Encore or a Node-based frontend build into [ADR 0006](0006-assetmapper-and-symfony-ux.md).

## Alternatives considered

- Unit and other non-browser suites are explicitly rejected by the project owner.
- CLI-only exploratory checks are useful but do not provide a durable regression suite.
- Headless-only browser tests would hide the activity the owner wants to observe.

## Consequences

- Browser scenarios must cover financial correctness, ownership boundaries, failure paths, and asynchronous workflows, not just successful page rendering.
- Static analysis remains mandatory and separate from application behavior testing.
- Exact compatible package versions, browser harness configuration, provider test behavior, and live-stack lifecycle need verification during bootstrap. Do not silently replace Symfony 8.2 to accommodate a testing dependency.
- [TESTING.md](../../TESTING.md) defines the agent workflow and initial coverage requirements. This decision resolves the previously deferred choice of testing approach.
- No application, test packages, browser configuration, or test runner has been installed at the time of this decision.

## References

- [SymfonyCasts: Playwright-PHP Changes the Game for Symfony Testing](https://symfonycasts.com/blog/playwright-php-symfony).
- [Playwright PHP](https://github.com/playwright-php/playwright).
- [Playwright Symfony integration](https://github.com/playwright-php/playwright-symfony).
- [Symfony integration architecture](https://github.com/playwright-php/playwright-symfony/blob/main/docs/architecture.md).

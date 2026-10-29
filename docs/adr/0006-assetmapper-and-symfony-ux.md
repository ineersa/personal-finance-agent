# ADR 0006: Use AssetMapper and Symfony UX without Encore

- Status: Accepted
- Date: 2026-10-29

## Context

The main interface combines chat with tables, graphs, and other financial artifacts. It needs streaming updates and interactive behavior, but those requirements do not inherently require a JavaScript bundler.

Judges must be able to install the demo locally. Avoiding a Node.js toolchain reduces installation dependencies. The existing web template and Symfony AI demo contain relevant Symfony frontend patterns.

## Decision

- Use Twig with Symfony UX for the application interface.
- Use AssetMapper and importmaps for browser assets.
- Use Stimulus for browser-side behavior and Turbo for navigation and partial updates where appropriate.
- Do not require Webpack Encore, Node.js, or npm for the current MVP's application asset workflow.
- Select browser libraries that work with this workflow. Revisit this decision explicitly if a required feature introduces a compilation or bundling requirement.

Artifacts remain part of the two-panel product interface. Their exact schemas, chart library, persistence representation, renderer structure, and model-to-data contract are deferred implementation details. This decision does not authorize executing untrusted model-generated HTML or JavaScript.

Installing the project-local UX skills does not select every package described by those skills. Add TwigComponent, LiveComponent, icons, charts, or other UX packages only when the implementation needs them.

## Alternatives considered

- Webpack Encore would provide a Node-based asset build pipeline, but no current requirement justifies that extra toolchain.
- Assuming that charts or streaming automatically require Encore would add dependencies without establishing a need. Both can be implemented within an AssetMapper-based Symfony interface.

## Consequences

- The frontend can use browser-native JavaScript modules and Symfony UX integrations without a mandatory Node.js installation.
- AssetMapper is not a TypeScript or JSX compiler. Dependencies that require those transformations would need a separate decision.
- Production assets still need preparation through Symfony's asset workflow. No mandatory Node toolchain does not mean no release preparation or dependency downloads.
- The detailed artifact implementation remains open rather than being fixed prematurely by the asset-pipeline choice.
- No frontend packages, assets, or application skeleton have been installed at the time of this decision.

## References

- [Symfony frontend tools](https://symfony.com/doc/current/frontend.html).
- [Symfony UX Chart.js](https://symfony.com/bundles/ux-chartjs), an example of chart integration that supports AssetMapper, not a selected artifact library under this ADR.
- [Project-local Symfony UX skills](../../.agents/skills/README.md).

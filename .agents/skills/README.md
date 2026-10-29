# Project skills

## Symfony UX skills

The Symfony UX skill directories are an unmodified snapshot of [smnandre/symfony-ux-skills](https://github.com/smnandre/symfony-ux-skills) by Simon Andre.

- Upstream commit: `1e99301a6255724eca9c49ce9cdb8c241771ab05`.
- Imported: 2026-10-29.
- License: MIT, preserved in [LICENSE.symfony-ux-skills](LICENSE.symfony-ux-skills).
- Scope: the seven upstream skill directories, including their reference files, and the upstream `AGENTS.md` copied verbatim as the `Symfony UX Frontend Stack` section in the project's `AGENTS.md`. Project-specific rules are kept above that section. No plugins, editor configuration, or application packages were imported.

| Skill | Purpose |
|---|---|
| `symfony-ux` | Choose between UX tools. |
| `stimulus` | Client-side behavior and JavaScript library integration. |
| `turbo` | Navigation, partial updates, and server-pushed DOM updates. |
| `twig-component` | Reusable server-rendered components. |
| `live-component` | Interactive components that communicate with the server. |
| `ux-icons` | SVG icons. |
| `ux-map` | Map integration, available only if a future feature needs it. |

Reproduce the installed skill inventory with:

```sh
find .agents/skills -mindepth 2 -maxdepth 2 -name SKILL.md | sort
```

Installing a skill does not select or install its corresponding application package. Upstream documents Symfony UX 2.22 through 2.28+ and Symfony through 8.0; verify examples against the versions selected for this project's Symfony 8.2 application.

## Updating the snapshot

1. Inspect the intended upstream revision and its changes.
2. Copy complete skill directories, including references, rather than only `SKILL.md`.
3. Preserve the upstream license and update the commit and import date above.
4. Verify that local references resolve and review the project guidance in [AGENTS.md](../../AGENTS.md).

Keep project-specific rules in `AGENTS.md` rather than silently modifying the upstream snapshot. Report incorrect or version-incompatible skill instructions before changing them.

# ADR 0019: Route development worktrees through one local Caddy proxy

- Status: Accepted
- Date: 2026-10-30

## Context

The owner approved experimenting with worktree-friendly development after the UI foundation. Fixed Compose project names, host ports, and tooling image tags made concurrent worktrees interfere. A different port for each preview avoided one collision but did not isolate lifecycle commands or images.

A disposable probe served two isolated application containers through the existing FrankenPHP image's Caddy binary on loopback port 80. Headless host browsers loaded both `a.finance.localhost` and `b.finance.localhost`, including CSS, without console errors. Browser storage remained separate across the two origins. This established the routing topology; it did not by itself prove isolation between Git worktrees.

## Decision

Use one shared HTTP proxy per local Git repository and host user. Reuse the development image's Caddy binary; mount neither application source nor the Docker socket into the proxy. Publish only `127.0.0.1:80`. Keep application port 8080 internal. Production Compose files and explicit production port selection are unchanged.

Castor derives a readable checkout identifier plus a hash of its absolute path. Use it for application and tooling image tags, Compose project names, network aliases, and `*.finance.localhost` hostnames. Development and live-browser stacks have separate projects, hostnames, and writable storage. The browser stack retains read-only source and its dedicated `var/browser-live` mount.

Castor registers exact hostname-to-container routes in the repository's common Git directory. A shared filesystem lock serializes registration and proxy reloads across worktrees. The proxy uses an external Docker network shared only by HTTP-facing application services and explicitly networked live-browser tooling containers. No custom DNS service or automatic Docker discovery is introduced.

Normal stack stop unregisters only the current stack and leaves its data, the shared proxy, and other stacks intact. Explicit proxy stop refuses while any routes remain registered. Stop owned stacks before removing their worktrees. A private development secret is generated only if `.env.local` does not exist.

Host browsers use localhost hostname handling. Container browser checks use Chromium's native hostname resolver rules targeting the inspected proxy address; host resolution is not evidence of container DNS behavior. The saved direct-network routing scenario runs separately from the self-contained intercepted-browser suite.

## Alternatives considered

- Assign a different port and Compose name manually for each worktree: leaves image collisions and lifecycle mistakes to the operator.
- Traefik with Docker-label discovery: convenient, but adds an image and Docker API access that this small local setup does not need.
- Per-worktree proxy processes: still collide on the host HTTP port.
- A custom DNS server: unnecessary for the browser paths exercised here.
- A home-directory registry: the execution environment made that location read-only. The common Git directory is writable and already identifies the worktrees that share routing state.

## Consequences

- Concurrent worktrees need no manually assigned ports or Compose project variables.
- Port 80 must be free. Startup must fail rather than kill its occupant or silently choose a different URL.
- Compose 2.24.4 or newer is required for explicit port and volume merge overrides.
- Moving a checkout changes its identity. Stop it before moving or removing it; abrupt deletion can leave a registered route that requires inspecting the owned registry and resources.
- Separate clones have separate proxies and cannot both claim port 80. This decision supports worktrees of one repository, not a machine-wide proxy manager.
- Application containers on the shared development network are not a security boundary between mutually untrusted code. Keep database and broker services off that network. Production isolation is unchanged.
- The proxy keeps the image it was created with; building another worktree does not replace its running process. An infrastructure upgrade requires an explicit owned-proxy maintenance step after stopping registered stacks.
- Direct-network browser checks establish routing and browser-origin isolation, not worker delivery, finance ownership enforcement, or data migrations.

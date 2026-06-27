# Build and Docker

How the frontend is built and served across environments, the Windows-Docker
dev-server problem, and the public-prerender path. Distribution of the SDK as a
package is separate and lives in [sdk-packaging.md](sdk-packaging.md).

## The environment and test matrix

| Environment | Runs against |
|---|---|
| dev | the Vite dev server (HMR), from source |
| unit (vitest) | source |
| e2e (Playwright) | the built artifact + a booted daemon |
| staging / prod | the built artifact |

Source is used only for dev and unit tests; everything that resembles production
— e2e, staging, prod — runs the build.

## Tooling runs in containers, never on the host

Every Node step — install, build, lint, unit, and e2e — runs inside a Docker
container that the project defines and pins (the Node and npm versions), never the
host Node. This mirrors the PHP side, where Composer and PHPUnit already run via
`docker compose … run`, and it buys OS/arch parity (a Windows host against a Linux
runtime), reproducible pinned versions, and no native-module drift.

Each project owns its container: the framework defines one under `framework/docker/`
for the SDK ([sdk-packaging.md](sdk-packaging.md)); each consumer — the chat demo, and later the
React / Angular demos ([multiframework-core.md](multiframework-core.md)) — defines its own under its
project `docker/`, mounting the repo so the local `file:` dependency on the SDK
resolves.

The container only provides the toolchain. `node_modules` lives in the project on
the **host bind-mount** — the normal install layout, never a named volume — so the
IDE resolves the SDK packages exactly as in any other project; hiding `node_modules`
in a volume breaks that resolution.

## One build for e2e, staging, and prod

A single `vite build` produces the artifact that e2e, staging, and prod all run:
you **test what you ship**. e2e drives that artifact against a booted daemon
([testing-strategy.md](testing-strategy.md)), which is also why e2e needs a deterministic backend
reset per test.

## Windows-Docker dev server (the HMR spike)

The goal is that `npm run dev` — the Vite dev server with HMR — works under
Windows in Docker. The known problem is that HMR often does not pick up changes
through a Windows bind-mount, forcing a container restart. This is a well-known,
usually solvable issue, run as a focused research spike rather than treated as
unsolvable. The order to try:

1. **project inside the WSL2 filesystem** — native filesystem events, no polling
   overhead (preferred);
2. else **`server.watch.usePolling`** (with an interval) plus a correct
   **`server.hmr.clientPort`** mapping — while keeping `node_modules` on the host
   bind-mount (a named volume would hide it from the IDE, so that trick is off the
   table; reconcile dev-server performance with that constraint during the spike);
3. fallback: container restart on change, and proceed — HMR is a convenience, not
   a gate.

Adopt whichever works. The environment contract is unchanged either way: dev is
the only environment that uses the dev server.

## Per-page code-splitting

Each route component is a dynamic `import()`, so the bundler splits one chunk per
page automatically — "just config", no special build setup. The loading state
during a chunk fetch is the standard skeleton ([sdk-packaging.md](sdk-packaging.md)), and a stale
chunk after a redeploy is caught by the build-version → forced-refresh check
([wire-protocol.md](wire-protocol.md)).

## SSG and the public surface

The build is **hybrid**. The authenticated, real-time area is a pure SPA shell
(skeletons fill it as data streams — there is nothing for SSG to prerender behind
auth). The public, SEO-relevant surface — the framework's footer pages (About,
Terms, Privacy, License; `HILOS_FOOTER_LINKS`) — is **statically prerendered**:
each is a framework-declared static page whose content needs no socket, so a
post-build step renders it to a static `<route>.html` through the view
framework's own server renderer (Vue and React via a Vite SSR build of a
prerender entry; Angular via `@angular/platform-server`). The same step emits
`robots.txt` and a `sitemap.xml` of those routes.

nginx then serves `<route>.html` for a public path and falls back to the SPA
shell (`index.html`) only for the app's own deep links, so the authed area is
never forced through the prerender path
([core-and-connection.md](core-and-connection.md)). SSG is low priority but part
of v1; a project adds a public route by mapping a content component to its page
key — the prerender step picks it up from `HILOS_FOOTER_LINKS`.

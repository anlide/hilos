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

## Two `.env` files: the container env_file vs `docker/.env`

A demo's local stack reads two different `.env` files, and confusing them is a
silent trap. The demo's `../.env` (next to `composer.json`) is wired into each
service as its **`env_file`**: it populates the *container* environment and is
read only after a container starts. Compose's own `${VAR:-default}`
**interpolation** — which resolves the host-side port publishes, the network
subnet, and other compose-level values *before any container exists* — does not
look at that file at all. Compose interpolation reads the shell environment and a
`.env` sitting **next to the compose file**, i.e. `docker/.env`.

So a host-port override (`NGINX_HTTPS_PORT`, `MYSQL_HOST_PORT`, `PHPMYADMIN_PORT`,
`FRONTEND_DEV_PORT`, and — for demos that publish the daemon directly —
`HTTP_STATUS_HOST_PORT` / `WORKER_COMM_HOST_PORT` / `WEBSOCKET_HOST_PORT`) only
takes effect from `docker/.env` (or the shell); the same line placed in the demo
`../.env` is silently ignored, because a published port is chosen before the
container the env_file would feed ever exists.

`docker/.env` is gitignored (host-specific), with a committed
`docker/.env.example` per demo listing the overridable knobs and their compose
defaults. It is optional: every knob has a `:-default` in the compose file, so a
fresh clone with no `docker/.env` publishes the demo's default host ports and
needs no setup step. The same split holds across all three reference demos
(simple-poll, tasks, chat); `docker/.env` is also where a host keeps other
non-committable interpolation overrides (e.g. the chat demo's `LLM_LOCAL_URL`).

## Published ports bind to loopback (the bind-host knob)

Every host publish in the dev, test, and framework stacks binds to `127.0.0.1`
by default, so a fresh clone on any machine — including a public dev box — starts
closed: MySQL (root, with a repo-published dev password), phpMyAdmin, the daemon
status/worker/ws ports, the Vite/Angular dev server and the local/test nginx are
reachable only from that machine. The bind address is a single knob per stack,
`${HILOS_BIND_HOST:-127.0.0.1}` prefixed onto every publish; a host that must
expose the stack to a LAN or tailnet sets `HILOS_BIND_HOST` in its gitignored
`docker/.env` (the same interpolation seam as the port knobs above) instead of
editing the repo. One value is one address — docker takes a single bind per
publish, and a bogus one fails at `up` with docker's own "bind: cannot assign
requested address".

Only two surfaces face outward on purpose and keep no knob: prod `nginx`
(`docker-compose.prod.yml`), which is the public front door, and the preview
lane's Caddy (`framework/docker`, host-network), which fronts `*.hilos`. The
`hilos-preview-control` service stays hardcoded on `127.0.0.1` so Caddy is its
sole entry.

Ollama is the one carve-out: its consumer is the chat daemon in **another**
compose project, reached across the docker bridge rather than over loopback, so
it has its own `HILOS_OLLAMA_BIND_HOST` (same `127.0.0.1` default) on the
`x-ollama-base` anchor shared by the three GPU variants. A host that serves the
LLM to demo stacks sets it to `172.17.0.1` — the docker0 gateway, reachable from
any bridge network and stable across demo network re-creation — and points the
chat demo's `LLM_LOCAL_URL` at the same address.

**Reaching a stack from another machine** keeps the loopback default and tunnels:

- HTTP surfaces (vite, phpMyAdmin, daemon status, ws) are already reachable over
  the preview lane `*.hilos` on Tailscale — Caddy runs host-network and proxies
  host loopback, so nothing needs to move off `127.0.0.1`.
- MySQL and the local/test nginx ports have no proxy; reach them by SSH tunnel
  over Tailscale.

**On a preview host, leave the knob alone.** Caddy proxies hardcoded `127.0.0.1`
targets, so moving `HILOS_BIND_HOST` off loopback there silently breaks `*.hilos`.
The Caddyfile stays unparameterised on purpose: a second knob nobody remembers is
worse than one explicit rule — on a host running the preview lane, reach the stack
through `*.hilos`, not by rebinding the ports.

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

## A single application chunk (no per-page code-splitting)

Each demo ships as **one application chunk** — there is no per-page splitting.
Route components are resolved **statically** through the page registry:
`createAppPageRouter` builds a page-key → path map from the pages declared in
`@hilos/core`, and there is no dynamic `import()` anywhere in the source, so the
bundler emits a single chunk (measured 2026-07-27: simple-poll ~596 KB, chat
~336 KB, tasks similar). For demos this size a single chunk is the right
default; there is no per-page chunk and no chunk-fetch loading state.

The build-version → forced-refresh check ([wire-protocol.md](wire-protocol.md))
is a **separate, whole-app** mechanism, unrelated to code-splitting: a build
timestamp carried in the handshake welcome frame is compared on every connect, and
a mismatch (a long-lived SPA outliving a backend redeploy) forces a page refresh
onto the new build.

Per-page code-splitting as a real framework capability — lazy route chunks for
genuinely large apps, not demos — is a possible future addition, not something the
current build does.

## SSG and the public surface

The build is **hybrid**. The authenticated, real-time area is a pure SPA shell
(skeletons fill it as data streams — there is nothing for SSG to prerender behind
auth). The public, SEO-relevant surface — the framework's footer pages (About,
Terms, Privacy, License; `HILOS_FOOTER_LINKS`) — is **statically prerendered**:
each is a framework-declared static page whose content needs no socket, so it is
prerendered to static HTML through the view framework's own server renderer. Vue
and React run a Vite SSR build of a prerender entry that writes a flat
`<route>.html` (and `robots.txt` + `sitemap.xml`). Angular uses its **native
static output** (`@angular/build` `outputMode: static` + `@angular/ssr` route
render modes): the client app routes through the framework's `HilosRouter` and
never loads `@angular/router`, so a server-only bootstrap (`src/prerender/`)
declares the public pages as an `@angular/router` config and marks them
`RenderMode.Prerender`; the builder emits one `<route>/index.html` per page and
`index.csr.html` as the client-render shell (there is no root `index.html`
because `/` — the authed SPA — is not prerendered). `robots.txt` / `sitemap.xml`
are static assets in the Angular demo's `public/`.

nginx then serves the prerendered file for a public path
(`try_files $uri $uri.html $uri/index.html`) and falls back to the SPA shell
(`index.html` for the Vite demos, `index.csr.html` for the Angular static build)
only for the app's own deep links, so the authed area is never forced through the
prerender path ([core-and-connection.md](core-and-connection.md)). SSG is low
priority but part of v1; a project adds a public route by mapping a content
component to its page key — the prerender step picks it up from
`HILOS_FOOTER_LINKS`.

## Building a demo against the vendored SDK

A demo vendors `@hilos/core` and its view layer via `file:` dependencies that npm
symlinks into `framework/frontend/*`, whose `dist/` is a gitignored build
artifact — **absent on a fresh clone**. A production build resolves the SDK to
that `dist` (to build against the same artifact a real vendored consumer ships —
"test what you ship"), so it fails until the SDK is built once. Each demo has a
`prebuild` npm hook — `node ../../../framework/frontend/scripts/prebuild-sdk.mjs
<view>` — so `npm run build` builds the SDK first automatically, the same
lifecycle idiom the SDK already uses internally
(`@hilos/angular`'s `prebuild` builds `@hilos/core`, through the same script in
its `core` mode).

The hook names a **per-view** script (`build:vue`, `build:react`,
`build:angular`), which builds `@hilos/core` plus that demo's own view layer and
nothing else. Reaching for the full-workspace `build` instead would couple the
demos that exist to prove the core is framework-agnostic: a broken or
version-drifted sibling layer would fail an unrelated demo's build, and every
demo would pay for the Angular layer's `ng-packagr` pass. The full-workspace
`build` stays the entry point for `pack` and for the SDK's own checks. The
Angular path asks for the core twice — the explicit list plus the layer's own
`prebuild`, which keeps the core current however the layer is built — but the
second ask now costs a `stat` sweep instead of a `tsc` run, because the first
one has just made it current. Dev needs no hook: every
demo resolves the SDK to `src` in dev (the Vite demos through the `development`
export condition, the Angular demo through its `tsconfig.dev.json` paths), so a
`dist` is never required to `npm run dev`.

### The build and install guards

`prebuild-sdk.mjs` does not rebuild what is already built, and its sibling
`npm-install-if-stale.mjs` does not reinstall what is already installed. A full
Verify run walks the SDK past four consumers — the workspace itself and three
demos — and without the guards it built the SDK four times and installed the same
unchanged lockfiles four more, for about two minutes of the run (HIL-519).

A `dist` is stale when it is missing, empty, or when the newest of the package's
sources — `src/**` plus every `*.json` at the package root, so `tsconfig*.json`
and `ng-package.json` are covered without enumerating them — is newer than the
**oldest** file inside `dist`. Oldest, not newest: a build that died halfway
leaves fresh files beside stale ones, and only the oldest of them reports that
the artifact as a whole predates its sources. An install is skippable when
`node_modules` carries a stamp naming the sha256 of the current
`package-lock.json` *and* the npm mode (`ci` or `install`) that produced it. The
modes are **ordered, not merely different**: `npm ci` wipes `node_modules` and
installs the lockfile exactly, so the tree it leaves also satisfies an
`npm install` of that lockfile — never the reverse. Treating them as simply
unequal makes them mutually exclusive, and the full run uses both on the same
tree (`test:framework:frontend:install` with ci, the demo hook with install), so
each would keep undoing the other's stamp and neither would ever skip. The stamp
lives inside `node_modules` on purpose: whatever removes the tree removes the
claim that it is current along with it.

Both guards state their verdict on stdout — `sdk: core, vue current — skipped`,
or the exact file that forced the rebuild — because a step that silently does
nothing is indistinguishable from a step that silently did the wrong thing.

Two properties make them safe to leave on. **A fresh clone still builds:** `dist`
is a gitignored artifact, so it is absent, and absent reads as stale. And
**every uncertainty falls through to the real command** — an unreadable stamp, a
missing lockfile, sources that cannot be listed. A guard that wrongly skips turns
a red run green; a guard that wrongly runs costs seconds. The decision rules are
pure functions in `scripts/staleness.mjs`, covered by the `scripts` test project.

This `prebuild` is a **monorepo-dev convenience**, not part of the shipped
consumer contract: a real project vendors the SDK as a prebuilt Composer tarball
(`dist` baked in, `framework/frontend` absent), so it neither has nor needs this
hook — see [sdk-packaging.md](sdk-packaging.md).

The agnostic `@hilos/core` uses `@vue/reactivity` (the standalone reactive
primitives, **not** Vue) as its signal engine, so it is a runtime dependency of
the built SDK that every consumer — including the Angular demo — pulls in. `core`
builds with plain `tsc` (no bundler; it stays npm-publishable), so resolving that
dependency is the consumer's concern: the Angular demo lists `@vue/reactivity`
under `allowedCommonJsDependencies` to accept its CommonJS entry without an
optimization-bailout warning. The Vite demos handle it through their own bundler.

## What the build actually costs

Measured 2026-07-27, each step alone in its own container on an otherwise idle
machine (WSL2, docker, `node:22-bookworm-slim`):

| | app build | typecheck | together |
|---|---|---|---|
| Angular (simple-poll) | 7.3–8.4s `ng build` | inside the build | **~8s** |
| React (tasks) | 5.3s (2.60 + 1.91 + 0.76) | 3.0s `tsc` | **8.3s** |
| Vue (chat) | 5.8s (3.14 + 1.90 + 0.75) | 4.3s `vue-tsc` | **10.1s** |

Full `npm run build`, SDK prebuild included: Angular 15.8s, React 10.3s,
Vue 12.3s. The dev server starts in 4.5–5.5s (`ng serve`) against 0.7–0.8s for
the Vite demos, and its HMR round-trip settles at ~0.4s, for an app edit and an
SDK-source edit alike.

Those prebuild figures are the **cold** path — the one a fresh clone and any
touched SDK source take. When the SDK is already current the hook costs a
directory walk (well under a second) and the app build is all that remains, which
is the usual case for the second and third demo of a Verify run.

Read those numbers with three rules:

- **Do not compare `vite build` against `ng build` as they stand.** `vite build`
  does no type checking at all — the Vite demos keep it in a separate `check`
  script — while Angular's is inside the compiler. Only build+check is a
  like-for-like pair, and on that basis Angular is not the slow one.
- **Angular's AOT cost is fixed, not size-driven.** simple-poll is 573 source
  lines and chat is 7 764, and they still land within seconds of each other.
  Expect the gap to close, not widen, as an app grows.
- **Measure alone on an idle machine, or do not record the figure.** A dev-start
  of 11.6s and a prod build of 45s were once recorded here from a sweep that
  booted all three stacks at once; both stood for eleven days, drove a spike
  (HIL-359) and an out-of-scope decision, and neither reproduced. A number
  without its measurement conditions is not evidence.

The Angular disk cache does nothing for `ng serve` but is worth ~35% of
`ng build` (6.3s → 3.6–4.1s of builder time). Each Angular cli container
therefore mounts its **own** `.angular` volume, keeping the cache enabled while
staying off the lmdb database the long-lived dev server holds; do not disable it
again with `CI=true`.

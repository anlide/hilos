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

So a host-port override (`NGINX_HTTPS_PORT`, `MYSQL_HOST_PORT`,
`MAILPIT_HTTP_PORT`, `FRONTEND_DEV_PORT`, `HTTP_STATUS_HOST_PORT`,
`WORKER_COMM_HOST_PORT`, `WEBSOCKET_HOST_PORT`, or `FRONTEND_HTML_HOST_PORT`) only
takes effect from `docker/.env` (or the shell); the same line placed in the demo
`../.env` is silently ignored, because a published port is chosen before the
container the env_file would feed ever exists.

`docker/.env` is gitignored (host-specific), with a committed
`docker/.env.example` per demo listing the overridable knobs and their compose
defaults. It is optional: every knob has a `:-default` in the compose file, so a
fresh clone with no `docker/.env` publishes the demo's default host ports and
needs no setup step. The same split holds across all three reference demos
(polls, tasks, chat); `docker/.env` is also where a host keeps other
non-committable interpolation overrides (e.g. the chat demo's `LLM_LOCAL_URL`).

## Published ports bind to loopback (the bind-host knob)

Every host publish in the dev, test, and framework stacks binds to `127.0.0.1`
by default, so a fresh clone on any machine — including a public dev box — starts
closed: MySQL (root, with a repo-published dev password), the daemon
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

- HTTP surfaces (vite, daemon status, ws) are already reachable over
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
you **test what you ship**. e2e drives that artifact against a booted daemon.
For backend test state, follow
[Backend state — clean runs and test-owned preconditions](testing-strategy.md#backend-state--clean-runs-and-test-owned-preconditions).

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

## Lazy page code in the client build

All six demos defer their admin page implementations with explicit
`lazyPage(() => import(...).then(...))` bindings. Common shell code comes from
the SDK's `/base`, default lazy metadata from `/admin-views`, and a wrapper's
admin implementation from `/admin/<section>`. The compatibility root remains
available, but reaches more code than an initial performance consumer wants.
The package graph and conditions are in
[sdk-packaging.md](sdk-packaging.md#public-entrypoints-and-their-graph).

`createAppPageRouter` still declares routes and access flags; it does not load
components. `HilosView` waits for the selected code and its first permitting
server answer, using the departure copy and delayed skeleton described in
[multiframework-core.md](multiframework-core.md#page-code-and-first-answer-state).
Several pages of a logical SDK section can load together, with shared chunks;
neither the descriptor nor the build promises one chunk per page. Public/footer,
Profile, User and Bot views outside the admin migration remain static imports.

The build-version forced-refresh check ([wire-protocol.md](wire-protocol.md))
is still separate: a welcome reporting a different build refreshes the whole
application. It does not guarantee a successful same-URL import retry after a
chunk failure. This change adds no service worker, prefetch or HTTP-cache policy.

## Initial client graph and budget

The build-only `@hilos/core/build/initial-bundle-budget` export provides
`initialBundleBudget(options?: InitialBundleOptions)` for Vite and the graph,
measurement and verdict APIs (`viteBundleGraph`, `angularBundleGraph`,
`measureInitialBundle`, `checkInitialBundleBudget`, `runInitialBundleBudget`).
It uses Node during the build, not a client runtime dependency. Vue and React
install the plugin only for a client build; it also checks `build.ssr` itself:

```ts
import { initialBundleBudget } from '@hilos/core/build/initial-bundle-budget'

// Inside defineConfig's factory; keep the other plugins in this array.
command === 'build' && !isSsrBuild && initialBundleBudget({
  warningBytes: 4000000,
  errorBytes: 5000000,
  projectSources: {
    routes: 'src/pages/routes.ts',
    views: 'src/App.vue', // React: 'src/App.tsx'
  },
})
```

Angular production enables `statsJson: true` and retains its native `initial`
budgets `maximumWarning: '4MB'`, `maximumError: '5MB'`. Its `ng build` runs with
`NG_BUILD_OPTIMIZE_CHUNKS=0`: from Angular 22.2 the builder's Rolldown
chunk-optimizer pass also runs on a build with a server entry (the static
prerender), re-cuts the esbuild chunk graph and trims each APF forwarding chunk
to the names in use, and the forwarding proof below refuses such a chunk. The
variable keeps the graph the gate proves; Angular 22.0 never ran the pass here.
After that `ng build` (including static prerender) and theme-head pass, its
build script runs the same graph policy through the shipped CLI:

```sh
node node_modules/@hilos/core/bin/initial-bundle-budget.mjs angular dist --project-sources src/app/pages/routes.ts src/app/app.ts
```

The raw budget is **1,500,000 warning / 2,000,000 error bytes**, for the unique
static JS+CSS closure from final client HTML. Script, modulepreload and stylesheet
roots and their static dependencies count once; dynamic entries are reported
separately with files already initial removed from each lazy closure. SSR outputs,
maps, fonts and images are excluded from the byte sum. Gzip is the sum of each
output independently compressed and is informational. A single chunk filename,
the size of `main`, or Vite's `chunkSizeWarningLimit` cannot establish this total.
Chat, tasks and polls currently exceed the warning threshold; that is a warning,
not a reason to raise the thresholds.

The build retains `initial-bundle-report.json`: roots, initial and lazy files,
static/dynamic edges, raw/gzip totals, input modules, expanded SDK source evidence,
forbidden modules, project proof and budget verdict. A Vite SSR pass does not
overwrite the client report, and serve does not run this gate. Angular reads
`browser-stats.json`, final `index.csr.html` (or `index.html`) and the actual emitted
bytes. Missing, malformed or unresolved roots, edges or provenance refuse the
build instead of appearing clean. Preserve the report with the production
artifact when assessing its graph.

### Project binding proof

Both `InitialBundleOptions.projectSources` and
`InitialBundleGraph.projectSources` accept `{ routes: string, views: string }`,
paths to existing declaration files relative to the consumer root. Adapt the
paths to the project; this is not another page catalog. The reader obtains
TypeScript from the consumer's installed build toolchain with
`createRequire(consumer/package.json)` and inspects syntax. It executes neither
routes nor loaders and adds no browser dependency.

The supported build-reader shape is deliberately narrower than the runtime
`PageView` API:

- The installed production core supplies its verified framework route flags.
  The configured project route input has one direct, imported
  `createAppPageRouter(map)` call (core root or base), with a literal map or a
  same-file `const` map. Every route has literal `path` and `admin` values;
  keys can resolve through stable constants/imports.
- The configured outlet input has exactly one literal `const pages` (Vue/React)
  or `readonly pages` property (Angular), bound to exactly one imported
  `HilosView` from the view kit's `/base`. Vue/Angular use `:pages="pages"` /
  `[pages]="pages"`; React uses `pages={pages}`. The allowed spread is the
  imported `/admin-views` `hilosAdminViews()` factory.
- Explicit bindings are eager value identifiers or
  `lazyPage(() => import('relative-literal').then(m => m.NamedExport))`
  (including `m.default`). Import/const aliases and named/default/star reexports
  resolve to the actual component owner; `.js` references can resolve their
  `.ts`/`.tsx`/`.vue` source. Keep the declarations stable, without mutations or
  escaping references.
- Configured routes/outlet and resolved components must be actual client inputs.
  Missing, dynamic, ambiguous, cyclic, type-only, external or escaping targets,
  unsupported source forms, and decoy declarations beside another included
  router/outlet refuse proof. Every project route needs a binding. Unsupported
  runtime forms are not thereby invalid runtime API; they need a supported
  declaration shape to use this build proof.

Only the resolved component owner inherits its route's `admin: true` verdict;
shared profile/legal contexts do not inherit it from a directory or importer.
The report records `projectProof.status: 'verified'`, source hashes, route and
binding lines, resolved owner/export/mode, and concrete owner chains in refusals.
All six ordinary demo configurations supply these anchors. Omitting
`projectSources` preserves legacy raw measurement and SDK-only scope, labelled
`'not-configured'`; zero errors in that scope do **not** prove the project's
admin implementations absent. A declared but bad configuration refuses.

### Compiled SDK evidence

The proof follows actual modules, aliases, shared chunks, source snapshots and
static edges. SDK roots and admin implementations/section entries are forbidden
in initial, even below the byte threshold. Vue/React record
`module-provenance.json`; APF contributes compiler maps and source snapshots;
core's real `tsc` output contributes maps with inline sources and
`core-provenance.json` receipts. A source-less installed SDK still has this
evidence. Shared/headless/protocol names alone grant no permission.

An Angular compiler chunk with empty `inputs` is attributable only when its
actual `entryPoint` is a verified generated APF public input and the entire
output is a pure forwarding wrapper. Export names must match the APF and stats;
imported names must exist in the target outputs; static edges must match the
compiler graph, with valid maps/snapshots. Unknown or executable wrappers refuse.
Attribution grants no exemption to an initial root or admin entry, and a filename
is never proof of its contents.

Core's generated receipts are distinct from the checked-in
`core/bin/core-initial-sources.json` permission inventory. An ordinary build
records compiler evidence; it does **not** refresh that inventory. When eager
sources or compiler outputs change, review their owners, bodies, static edges
and actual `tsc` outputs, then update exactly the affected source/output
fingerprints. Rehashing the whole inventory merely to make a build green loses
the check it exists to provide.

## SSG and the public surface

The build is **hybrid**. The authenticated, real-time area is a pure SPA shell
(skeletons fill it as data streams — there is nothing for SSG to prerender behind
auth). The public, SEO-relevant surface — the framework's footer pages (About,
Terms, Privacy, License; `HILOS_FOOTER_LINKS`) — is **statically prerendered**,
all four pages, through the view framework's own server renderer. A public page
is no longer prose alone — all four pages carry a framework-owned presentation,
while Terms and Privacy also carry catalog text and a line that differs per
reader
([sdk-packaging.md](sdk-packaging.md), tier 2) — and no page leaves
the prerendered set for having a per-reader part. What
is prerendered is the **guest view**: what a person with no session sees. For the
legal pages, the catalog document and any reader-specific part arrive over the
page subscription once the SPA has mounted, on a page that is by then a live SPA
page like any other. /terms and /privacy are
the most linked-to surfaces the product has and the reason this pipeline
exists; dropping one to avoid a line only a signed-in reader sees would trade
the page for the line. Three rules follow, and every public page keeps them:

- **The client re-mounts; it does not hydrate.** On a cold load nginx serves
  the static file, the SPA boots and builds the page again over the prerendered
  markup, and the page subscribes like any other. The mount call is the plain
  one in every demo — Vue's `createApp().mount`, React's `createRoot().render`,
  Angular's `bootstrapApplication` — and stays so: do not switch it to React's
  `hydrateRoot` or add Angular's `provideClientHydration`. The guest view and
  the signed-in view differ on purpose, and a hydrating client would report
  that difference as a mismatch on every signed-in load — the framework
  shouting about the thing that was designed on purpose.
- **A behavior block renders with no browser present.** Every public page is
  executed at build time by a server renderer — `vite build --ssr` of the
  prerender entry for Vue and React, `@angular/ssr` with `RenderMode.Prerender`
  for Angular — so a block does not touch `window`, `document`, `localStorage`
  or `sessionStorage` while rendering; it reads them only when the person acts.
  The core already holds this line and says why: `protectedModePass.ts` reaches
  storage through a guard because "the core also runs where there is no browser
  at all (prerender)". The erase button renders as a button and a description;
  the registry sweep happens on the click.
- **The license inventory is a build-time snapshot.** It is gathered while the
  project's frontend is built, carried into the artifact and supplied to both
  License SDK pages. `/license` remains the footer page and its static file
  renders the project's license text; `/license/dependencies` renders the
  dependency inventory from that same snapshot in a separate page. Neither page
  asks the backend to gather or serve the inventory. Gathering it at runtime
  would make the static file — the one a crawler and a person without a socket
  see — the only version of the page without its main content; it would buy no
  freshness, because the list changes when dependencies change and dependencies
  change by rebuilding; and it would have the daemon parse `composer.lock` and
  `package.json` per request and then cache it — the same snapshot, built later
  and in the wrong process.
  It is written by `framework/frontend/scripts/generate-license-inventory.mjs`
  into the project's `src/generated/hilosLicenseInventory.ts`, out of that
  project's own two lockfiles (`composer.lock` and `frontend/package-lock.json`)
  — and a `link:`-ed SDK package in the latter is a door rather than a row, so
  what the SDK itself brings in is read out of
  `framework/frontend/package-lock.json`. The generator also reads the project's
  root `LICENSE` file and places it into the `projectLicenseText` field of the
  `hilosLicenseInventory.ts` snapshot alongside the project's name and
  dependencies; a missing or unreadable `LICENSE` file fails the build with an
  error stating the path and reason. The snapshot also carries the project's name
  from its own `package-lock.json`, and downloads from the page are named after
  it. The hook is `prebuild`, `predev` and `precheck`, so the file exists before a
  build, a dev start or a type check reads it.

What each static file contains: /about the prose, the support block and the
modal in its closed state; /privacy the heading, reader and policy loading
states, the project's introduction and the erase block, button included, inert
until the SPA mounts; /license the project's root LICENSE text and shared page
frame; /terms the heading, reader loading state, project introduction and
policy loading state, with the revision history absent from the file and
arriving over the subscription. The dependency inventory is rendered by the
separate `/license/dependencies` page and is not added to the footer prerender
set here; extra-address discovery and production SSG serving remain HIL-216.
The legal catalog lives on the server, and the frontend build does not read it.
The backend pages behind About and License answer their subscriptions with
nothing; Terms and Privacy answer with their own legal sections
([legal-documents.md](../architecture/legal-documents.md), *Public Terms and
Privacy pages*).

Vue and React run a Vite SSR build of a prerender entry that writes a flat
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
`HILOS_FOOTER_LINKS`. For the framework's four that component is usually thin:
the project's prose inside the framework page. Failure stays where it is: a
footer link with no route or no component fails the build in the prerender
package's discovery, and a failed render fails the build before anything is
written — a page that now renders more can fail in more ways, and it fails in
the same place.

Every demo build ends with `node node_modules/@hilos/core/bin/theme-head.mjs
<dir>` after prerendering or `ng build`, so the pass sees every final HTML page.
It inserts the first-frame theme script and refuses an absent or unreadable
output directory, no HTML pages, a missing `</head>`, or a deferred stylesheet.

The public prerender entry imports only its static public component map, not the
client App/admin map. Adding lazy admin views does not change footer discovery,
public HTML content or the guest-view contract. In Angular the budget CLI follows
the theme pass; in Vite the client plugin writes its report before the separate
SSR/SSG/theme steps, which retain it.

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

A `dist` must be both current **and complete**. The inputs include `src/**`,
`bin/**` (proof helpers and permission inventory included), root JSON/package
exports and Vite configuration, nested Angular `ng-package.json` files, shared
`tsconfig.base.json`, and the prebuild/staleness scripts. A missing/empty dist or
an input newer than the **oldest** dist file rebuilds. The oldest timestamp
catches a build that left fresh files beside old ones; it is not sufficient
evidence of completion by itself.

`sdkDistStale` also checks every public production/type target in package exports
and, for core, every source owner's JS, declaration, map and receipt with matching
source/output/map hashes. Missing or uncertain evidence in those checks rebuilds.
This completeness check follows the source manifest's `exports`: Angular's plain
source manifest has none, so it does not independently enumerate all secondary
FESMs/types. Installed APF targets, compiler maps and source snapshots are checked
by the production graph proof and package smoke checks. The prebuild skip alone
is not that proof. The Angular layer's `prebuild-sdk.mjs core` mode never installs:
it runs inside the workspace build already in progress and only builds core if
needed.

An install is skippable when
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
| Angular (polls) | 7.3–8.4s `ng build` | inside the build | **~8s** |
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
- **Angular's AOT cost is fixed, not size-driven.** polls is 573 source
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

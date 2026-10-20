# New Hilos frontend: Angular

Reference implementations:
[demo/online-testing/frontend](../../demo/online-testing/frontend), the minimal
one — copy it — and [demo/polls/frontend](../../demo/polls/frontend), the one
with every framework feature switched on.
Common ground (containers, connection, e2e, stable ids) is in
[README.md](README.md); this part covers only what is Angular-specific.

## Toolchain

- Canonical **Angular CLI** (v22), NOT a Vite app: `angular.json` with the
  `@angular/build:application` / `dev-server` builders behind `npm run dev` /
  `npm run build` scripts. The canonical shapes were obtained by running
  `ng new probe --skip-git --skip-install --defaults --zoneless --ssr=false
  --routing=false --skip-tests --style=css` in a throwaway container and
  mirroring the output — repeat that method when the CLI major changes.
- Angular 22 canon kept as-is: `experimentalDecorators: true`, solution-style
  tsconfig (root `references` → `tsconfig.app.json`), `module: "preserve"`,
  no `"type": "module"` in package.json, and `src/index.html` WITHOUT a
  script tag (the builder injects it).
- **Zoneless is the v22 default** — no provider needed, zone.js simply absent;
  `app.config.ts` keeps only `provideBrowserGlobalErrorListeners()`.
- Flat dist: `outputPath: { "base": "dist", "browser": "" }` so the nginx
  mount stays `../frontend/dist`, uniform with the Vite demos.
- After `ng build`, run
  `node node_modules/@hilos/core/bin/theme-head.mjs dist`, then the initial-bundle
  CLI below. In the production
  configuration set `optimization.styles.inlineCritical: false` so Bootstrap's
  stylesheet loads before the first themed frame; the theme-head pass refuses
  deferred stylesheets.
- deps `@angular/{common,compiler,core,platform-browser}`, `rxjs`,
  `tslib`, `@vue/reactivity` and `qrcode-generator` (see below),
  `bootstrap` + `bootstrap-icons` (see Styling), and `@hilos/angular` pinned
  at the **package root**: `file:../../../framework/frontend/angular`. The Angular view layer is
  built with **ng-packagr** (Angular Package Format: a FESM2022 bundle + a
  generated manifest), unlike the Vue/React layers' Vite library build, because
  only an Angular-aware compiler can emit the shell's declarables. devDeps
  `@angular/{build,cli,compiler-cli}`, `typescript ~6.0.x`, and the SDK lint
  and format ranges (`@eslint/js`, `eslint`, `eslint-config-prettier`,
  `prettier`, `typescript-eslint`); `lint` is `eslint . --max-warnings 0`.
  `eslint.config.mjs`, `.prettierrc.json` and `.prettierignore` repeat the flat
  SDK baseline, and the ignores cover `dist/`, `out-tsc/`, `.angular/` and
  `src/generated/`. `cli.analytics: false` for container runs. Like the Vite demos, the SDK
  resolves to **`src` in dev** and to its built **`dist` in production** (the
  package `main`/`types`), so a dev edit shows up with no ng-packagr rebuild and
  only e2e/prod — which run the production build — need the dist rebuilt first.
  How that split is wired is *Dev-source consumption* below.
- The version of every `@angular/*` in both lists above is not this recipe's
  to name, nor the generator's: it is set by
  [../agents/frontend/sdk-packaging.md](../agents/frontend/sdk-packaging.md),
  *Angular versions: exact in every root, a range only in the peers* — the
  project's frontend is one more root that carries Angular.
- **`preserveSymlinks: true`** in `angular.json` build options: the SDK is a
  symlinked `file:` dependency whose FESM imports `@angular/core` (a peer); with
  symlinks resolved to their real path the import lands on the SDK workspace's
  own `@angular/core`, a second copy whose injection context the app cannot see
  — bootstrap then dies with **NG0203** and every shell signal renders blank.
  Preserving symlinks resolves the SDK's bare imports from the consumer's own
  `node_modules`, collapsing `@angular/core`, `@angular/common`, `@hilos/core`,
  and `@vue/reactivity` to one copy each.
- `angular.json` is NOT JSONC-tolerant in PhpStorm — keep comments out of it;
  put explanations in `tsconfig.json` (JSONC-tolerant) instead.
- The Angular disk cache (`frontend/.angular/cache`) is an **lmdb** database
  and must never be open in two containers at once: containers have
  overlapping PID namespaces, which corrupts lmdb's reader lock table and
  crashes `ng build` (`TypeError ... refCount` from lmdb, or a secondary
  esbuild panic). The repo bind-mount would share the cache between the
  long-lived dev server and every on-demand npm container, so the dev server
  OWNS the repo copy and each `*-frontend-cli-*` service mounts a private named
  volume over `frontend/.angular` (`ng-cache-cli-local`, `ng-cache-cli-test`).
  Kept apart this way the cli containers keep the cache ENABLED, which is worth
  about a third of `ng build`. This is Angular-only: the Vite demos keep no
  lmdb-backed cache.

## SDK wiring

The src root stays thin and the boot wiring lives in `src/app/bootstrap/`
([../agents/frontend/bootstrap-structure.md](../agents/frontend/bootstrap-structure.md)):

- `src/index.ts`: one import, `./app/bootstrap/main`, and nothing else.
- `src/app/bootstrap/connection.ts`: `createHilosConnection()` from
  `@hilos/core/base` — one connection for the app, on the same-origin `/ws` ONLY
  (Angular has no `import.meta.env` mechanism — see dev-mode below), the
  framework schemas merged and the stale-build reload wired by the call itself.
  It exports `connection` and `actions`.
- `src/app/bootstrap/session.ts`: the app's `ScopeManager` and the session
  selectors over it (`sessionUserName`, `sessionUserId`, `sessionUserIsAdmin`,
  `sessionPendingAuthStep`, `sessionPendingAck`).
- `src/app/bootstrap/main.ts`: `bootHilos({ viewLayer: HILOS_VIEW_LAYER,
  connection, actions, scopes, router, pageTitles, appName })` binds the scopes,
  builds the navigator and OPENS THE SOCKET — never call `connection.connect()`
  by hand. Then `createAuthGate(…)` and `bootstrapApplication(App, …)` with the
  navigator provided as `HILOS_ROUTER` and the gate as `HILOS_AUTH_GATE`.
- `src/app/pages/`: `keys.ts`, `routes.ts` (`createAppPageRouter`) and
  `pageTitles.ts` ([../agents/frontend/page-registry.md](../agents/frontend/page-registry.md)).
- `src/app/app.ts`: `hilos-layout` with the `[brand]` and `[user]` slots (the
  user region projected as ONE node through `ngProjectAs`) and a `hilos-view`
  over the page map, the page skeletons and the project's `AuthSurface` (a
  wrapper closing the project's `HilosAuthContext` over the framework
  `HilosAuthSurface`).
- State in components via `hilosSignal(…)` and `connectionStateSignal(connection)`
  from `@hilos/angular/base` — call them in an injection context (e.g. a component
  field initializer) or pass `{ injector }`; they mirror `toSignal` semantics
  and unsubscribe on the injector's `DestroyRef`.
- The public footer pages are prerendered by Angular's native prerenderer:
  production `outputMode: "static"` with the `src/prerender/main.server.ts`
  server entry, which declares only those pages; every other path cold-loads
  the SPA shell `index.csr.html`, which the nginx template falls back to.

## Lazy admin bindings and the production graph

Common components/tokens come from `@hilos/angular/base`, metadata from
`@hilos/angular/admin-views`, and the lazy wrapper imports its implementation
from `@hilos/angular/admin/<section>`. Keep a readonly `pages` field created once
for the App instance; `HilosView` needs no `@defer` wrapper. This binding excerpt
omits the surrounding layout/auth wiring; retain the real app's eager Main,
public/footer and profile keys and its skeleton/auth inputs:

```ts
import { Component } from '@angular/core'
import type { Type } from '@angular/core'
import { HilosPages, lazyPage, type PageView } from '@hilos/core/base'
import { HilosView } from '@hilos/angular/base'
import { hilosAdminViews } from '@hilos/angular/admin-views'

@Component({
  selector: 'app-root',
  imports: [HilosView],
  template: `<hilos-view [pages]="pages" />`,
})
export class App {
  protected readonly pages: Record<string, PageView<Type<unknown>>> = {
    ...hilosAdminViews(),
    [HilosPages.SETTINGS]: lazyPage(() =>
      import('./views/hilos/settings/settings.js').then(m => m.Settings),
    ),
  }
}
```

`Settings` remains a page module mounting `HilosSettingsPage` from
`@hilos/angular/admin/settings`; its context type comes from
`@hilos/core/admin/settings`. Eager classes remain valid map values. Construction
starts no import, and a loaded component mounts only after a permitting server
reply. The compatibility root still works, while performance consumers use
subpaths. See [page bindings](../agents/frontend/page-module-structure.md#binding-eager-and-lazy-page-modules)
and [runtime semantics](../agents/frontend/multiframework-core.md#page-code-and-first-answer-state)
for the outlet cache, continuous skeleton deadline, refusals and failed-ESM retry
limits.

In `angular.json` production, set `statsJson: true` and keep the native initial
budgets `4MB` warning / `5MB` error. The ordinary package build script is:

```json
"build": "NG_BUILD_OPTIMIZE_CHUNKS=0 ng build && node node_modules/@hilos/core/bin/theme-head.mjs dist && node node_modules/@hilos/core/bin/initial-bundle-budget.mjs angular dist --project-sources src/app/pages/routes.ts src/app/app.ts"
```

Keep `NG_BUILD_OPTIMIZE_CHUNKS=0` in front of `ng build`: Angular's chunk
optimizer re-cuts the chunk graph this gate proves
([build-and-docker.md](../agents/frontend/build-and-docker.md)).
Adapt both source paths to the project. This CLI runs after the production,
static-prerender and theme passes; it reads the final client HTML, stats and
bytes, leaving `initial-bundle-report.json`. Require
`projectProof.status: 'verified'` before asserting project-admin absence;
`'not-configured'` is legacy measurement/SDK scope only. It counts unique initial
JS+CSS, reports per-output gzip separately, and refuses eager admin implementations
even under the byte budget. Native `ng serve` is not gated. The public prerender
entry stays separate from the client App/admin map. Supported source forms and
compiler evidence are in
[build-and-docker.md](../agents/frontend/build-and-docker.md#initial-client-graph-and-budget).

## SDK primitives

The SDK components mirror the core controllers
([../agents/frontend/multiframework-core.md](../agents/frontend/multiframework-core.md))
the Angular way. Some attach to a **host element** rather than wrapping one, so
the Bootstrap class, the native event, and ARIA fall through with no extra
element. `LoadingButton` is an attribute on a native button:

```html
<button
  hilosLoadingButton
  [loading]="saving()"
  class="btn-primary"
  (click)="save()"
>
  Save
</button>
```

The host IS the button: `[disabled]` is bound for you (a disabled button
suppresses the native click), and the spinner timer is the core controller — the
component only mirrors it through `hilosSignal`.

## Styling — Bootstrap and Bootstrap Icons

`@hilos/angular` declares `bootstrap` and `bootstrap-icons` as peer
dependencies; the Angular app fulfills them and delivers the CSS through
`angular.json` `styles` (Angular has no transitive global-stylesheet channel —
see [../agents/frontend/styling-rules.md](../agents/frontend/styling-rules.md)):

```json
"styles": [
  "node_modules/bootstrap/dist/css/bootstrap.min.css",
  "node_modules/bootstrap-icons/font/bootstrap-icons.css",
  "node_modules/@hilos/angular/src/hilos-styles.scss"
]
```

The Bootstrap-Icons font is a real dependency in the app's own `node_modules`
(not a cross-root SDK asset), so unlike the Vite demos `ng serve` needs no
file-system allow-list. Bootstrap raises the bundle past the CLI's default
budgets — set `initial` `maximumWarning`/`maximumError` to `4MB`/`5MB`.

## Dev-mode WebSocket

No env override exists under `ng serve`, so the dev server proxies the app's
same-origin `/ws` to the daemon instead: `frontend/proxy.conf.json`

```json
{ "/ws": { "target": "http://<daemon-local-service>:8092", "ws": true } }
```

wired via `serve.options.proxyConfig` in `angular.json`. The proxy target is
the compose service name — the dev container and the daemon share the local
network.

## Dev-source consumption

The demo consumes the SDK from **`src` in dev** and from **`dist` in
production** — the same split the Vite demos get, so an SDK edit shows up in
`ng serve` with no ng-packagr rebuild, while e2e and prod test the shipped FESM.
Angular reaches the split differently because ng-packagr owns its dist package's
`exports` and the AOT compiler will not emit declarables for a library it treats
as pre-compiled:

- **Production** uses the app/server production tsconfigs without SDK source
  redirects. Local `file:` installs resolve the plain package and secondary-entry
  forwarders to dist FESMs/types; the published APF dist manifest has generated
  `types`/`default` exports. Rebuild the SDK before e2e/prod.
- **Dev** (`ng serve` and the `development` build it drives) uses
  `tsconfig.dev.json`, which `paths`-redirects the SDK to `src`:

  ```jsonc
  "paths": {
    "@angular/core": ["./node_modules/@angular/core"],
    "@angular/common": ["./node_modules/@angular/common"],
    "@hilos/angular": ["./node_modules/@hilos/angular/src/index.ts"],
    "@hilos/angular/base": ["./node_modules/@hilos/angular/src/base/index.ts"],
    "@hilos/angular/admin-views": ["./node_modules/@hilos/angular/src/admin/hilosAdminViews.ts"],
    "@hilos/angular/admin/analytics": ["./node_modules/@hilos/angular/src/admin/analytics/index.ts"],
    "@hilos/angular/admin/appearance": ["./node_modules/@hilos/angular/src/admin/appearance/index.ts"],
    "@hilos/angular/admin/backup": ["./node_modules/@hilos/angular/src/admin/backup/index.ts"],
    "@hilos/angular/admin/billing": ["./node_modules/@hilos/angular/src/admin/billing/index.ts"],
    "@hilos/angular/admin/change-log": ["./node_modules/@hilos/angular/src/admin/changeLog/index.ts"],
    "@hilos/angular/admin/communications": ["./node_modules/@hilos/angular/src/admin/communications/index.ts"],
    "@hilos/angular/admin/daemon": ["./node_modules/@hilos/angular/src/admin/daemon/index.ts"],
    "@hilos/angular/admin/dashboard": ["./node_modules/@hilos/angular/src/admin/dashboard/index.ts"],
    "@hilos/angular/admin/i18n": ["./node_modules/@hilos/angular/src/admin/i18n/index.ts"],
    "@hilos/angular/admin/legal": ["./node_modules/@hilos/angular/src/admin/legal/index.ts"],
    "@hilos/angular/admin/logs": ["./node_modules/@hilos/angular/src/admin/logs/index.ts"],
    "@hilos/angular/admin/maintenance": ["./node_modules/@hilos/angular/src/admin/maintenance/index.ts"],
    "@hilos/angular/admin/mcp-skills": ["./node_modules/@hilos/angular/src/admin/mcpSkills/index.ts"],
    "@hilos/angular/admin/roles": ["./node_modules/@hilos/angular/src/admin/roles/index.ts"],
    "@hilos/angular/admin/security": ["./node_modules/@hilos/angular/src/admin/security/index.ts"],
    "@hilos/angular/admin/settings": ["./node_modules/@hilos/angular/src/admin/settings/index.ts"],
    "@hilos/angular/admin/sil": ["./node_modules/@hilos/angular/src/admin/sil/index.ts"],
    "@hilos/angular/admin/users": ["./node_modules/@hilos/angular/src/admin/users/index.ts"],
    "@hilos/core": ["./node_modules/@hilos/core/src/index.ts"],
    "@hilos/core/base": ["./node_modules/@hilos/core/src/base/index.ts"],
    "@hilos/core/admin/analytics": ["./node_modules/@hilos/core/src/admin/analytics/index.ts"],
    "@hilos/core/admin/appearance": ["./node_modules/@hilos/core/src/admin/appearance/index.ts"],
    "@hilos/core/admin/backup": ["./node_modules/@hilos/core/src/admin/backup/index.ts"],
    "@hilos/core/admin/change-log": ["./node_modules/@hilos/core/src/admin/changeLog/index.ts"],
    "@hilos/core/admin/communications": ["./node_modules/@hilos/core/src/admin/communications/index.ts"],
    "@hilos/core/admin/daemon": ["./node_modules/@hilos/core/src/admin/daemon/index.ts"],
    "@hilos/core/admin/i18n": ["./node_modules/@hilos/core/src/admin/i18n/index.ts"],
    "@hilos/core/admin/legal": ["./node_modules/@hilos/core/src/admin/legal/index.ts"],
    "@hilos/core/admin/logs": ["./node_modules/@hilos/core/src/admin/logs/index.ts"],
    "@hilos/core/admin/maintenance": ["./node_modules/@hilos/core/src/admin/maintenance/index.ts"],
    "@hilos/core/admin/security": ["./node_modules/@hilos/core/src/admin/security/index.ts"],
    "@hilos/core/admin/settings": ["./node_modules/@hilos/core/src/admin/settings/index.ts"],
    "@hilos/core/admin/users": ["./node_modules/@hilos/core/src/admin/users/index.ts"]
  }
  ```

  This is the whole `paths` object, including the Angular pins: a child tsconfig
  replaces it rather than merging it with its parent. Keep public names explicit;
  a wildcard cannot translate `change-log` to `changeLog` or `mcp-skills` to
  `mcpSkills`. Compare the current reference
  [tsconfig.dev.json](../../demo/online-testing/frontend/tsconfig.dev.json)
  when copying the configuration.

  The dev config also lists SDK component sources in `include` (by their node_modules path —
  the one `preserveSymlinks` resolves to) so the AOT compiler owns them as
  program inputs (a `*.test.ts` `exclude` keeps `vitest`-importing SDK tests
  out). `angular.json` points the `development` build configuration at this
  tsconfig (`"tsConfig": "tsconfig.dev.json"`), and `serve.options.prebundle`
  excludes `@hilos/angular`/`@hilos/core` so Vite hands them to the compiler
  rather than pre-bundling a stale copy. `npm run check` runs against this same
  tsconfig, so type-checking sees the SDK `src` too.

**Why `paths`, not the package `development` export condition (the Vite demos'
mechanism):** ng-packagr generates the dist `exports` and warns if the source
package.json declares conflicting `types`/`default` conditions. So
`@hilos/angular`'s source package stays plain (`main`/`module`/`types`) and the
dev-source redirect lives in the consumer's `tsconfig.dev.json`.

### The duplicate-copy collapse — NG0203 / NG3004

Compiling the SDK from `src` pulls its bare `@angular/core` and `@angular/common`
imports into the app build, and the `file:` SDK reaches a SECOND copy of each
through its real path (the SDK-workspace install the adapter unit tests use). Two
copies and the SDK's `inject()` cannot see the app's injection context —
bootstrap dies with **NG0203** (`@angular/core`); or the compiler cannot import a
symbol such as `NgComponentOutlet` and fails with **NG3004** (`@angular/common`).
Two layers collapse them to one copy each:

1. **`preserveSymlinks: true`** (`angular.json` build options) resolves the SDK's
   bare imports from the consumer's own `node_modules` rather than the symlink's
   real path — the primary collapse, covering `ng build` and `ng serve`.
2. **tsconfig `paths` pins** for `@angular/core` and `@angular/common` onto the
   app's single copy, since the `@angular/build` esbuild pipeline resolves bare
   imports through tsconfig paths:

   ```jsonc
   "paths": {
     "@angular/core": ["./node_modules/@angular/core"],
     "@angular/common": ["./node_modules/@angular/common"]
   }
   ```

Keep these explanations in `tsconfig.json` / `tsconfig.dev.json` JSONC comments,
since `angular.json` cannot hold comments.

## The core's signal engine — `@vue/reactivity` as a direct dependency

`@hilos/core` ships a private signal engine, `@vue/reactivity` (app code
never imports it). A real tarball install hoists it automatically; the
monorepo `file:` link instead satisfies it from the SDK workspace's own copy
and never places it in the consumer's `node_modules`. The Vite-Vue/React demos
do not notice — they prebundle the whole graph through the symlink's real path,
where the SDK-workspace copy sits. The Angular consumer does: the core's
`@vue/reactivity` import is external to the SDK artifact in both modes — dev
compiles the core `src` (resolved to its node_modules path under
`preserveSymlinks`) and production consumes the core's compiled ES modules — so the bare import
resolves from the consumer root, where the link-satisfied dep is absent:
`Failed to resolve import "@vue/reactivity"`.

The fix is one line: declare `@vue/reactivity` as a direct dependency of the
Angular consumer so npm installs it locally:

```json
"dependencies": { "@vue/reactivity": "^3.5.35" }
```

Every other runtime dependency of the core is in the same position and gets the
same line. Today that is `qrcode-generator`, the QR library behind the core's
`qrMatrix` (HIL-494) — without it the build stops at
`Could not resolve "qrcode-generator"`:

```json
"dependencies": { "qrcode-generator": "^2.0.4" }
```

# New Hilos frontend: Angular

Reference implementation:
[demo/simple-poll/frontend](../../demo/simple-poll/frontend).
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
- deps `@angular/{common,compiler,core,platform-browser}` `^22`, `rxjs`,
  `tslib`, `@vue/reactivity` (see below), `bootstrap` + `bootstrap-icons` (see
  Styling), and `@hilos/angular` pinned at the **package root**:
  `file:../../../framework/frontend/angular`. The Angular view layer is
  built with **ng-packagr** (Angular Package Format: a FESM2022 bundle + a
  generated manifest), unlike the Vue/React layers' Vite library build, because
  only an Angular-aware compiler can emit the shell's declarables. devDeps
  `@angular/{build,cli,compiler-cli}` `^22`, `typescript ~6.0.x`;
  `cli.analytics: false` for container runs. Like the Vite demos, the SDK
  resolves to **`src` in dev** and to its built **`dist` in production** (the
  package `main`/`types`), so a dev edit shows up with no ng-packagr rebuild and
  only e2e/prod — which run the production build — need the dist rebuilt first.
  How that split is wired is *Dev-source consumption* below.
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
  esbuild panic). The repo bind-mount shares the cache between the long-lived
  dev server and every on-demand npm container, so the dev server OWNS the
  cache and all cli/build containers set `CI: "true"` (the default
  `cache.environment: "local"` disables the disk cache under CI) — see the
  `*-frontend-cli-*` services in both compose files. This is Angular-only:
  the Vite demos keep no lmdb-backed cache.

## SDK wiring

- `src/app/connection.ts`: one module-level `HilosConnection`; URL =
  same-origin `/ws` ONLY (Angular has no `import.meta.env` mechanism — see
  dev-mode below); `buildMismatch` → `location.reload()`.
- `main.ts`: `connection.connect()` before `bootstrapApplication(...)`.
- State via `connectionStateSignal(connection)` from `@hilos/angular` — call
  it in an injection context (e.g. a component field initializer) or pass
  `{ injector }`; mirrors `toSignal` semantics, unsubscribes on the
  injector's `DestroyRef`.

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
  "node_modules/bootstrap-icons/font/bootstrap-icons.css"
]
```

The Bootstrap-Icons font is a real dependency in the app's own `node_modules`
(not a cross-root SDK asset), so unlike the Vite demos `ng serve` needs no
file-system allow-list. Bootstrap raises the bundle past the CLI's default
budgets — set `initial` `maximumWarning`/`maximumError` to `1.5MB`/`2MB`.

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

- **Production** uses `tsconfig.app.json` unchanged — `@hilos/angular` resolves
  through the package `main`/`types` to its ng-packagr **dist** (FESM). Rebuild
  the SDK before e2e/prod.
- **Dev** (`ng serve` and the `development` build it drives) uses
  `tsconfig.dev.json`, which `paths`-redirects the SDK to `src`:

  ```jsonc
  "paths": {
    "@hilos/angular": ["./node_modules/@hilos/angular/src/index.ts"],
    "@hilos/core": ["./node_modules/@hilos/core/src/index.ts"]
  }
  ```

  and lists the SDK component sources in `include` (by their node_modules path —
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
`preserveSymlinks`) and production consumes the core FESM — so the bare import
resolves from the consumer root, where the link-satisfied dep is absent:
`Failed to resolve import "@vue/reactivity"`.

The fix is one line: declare `@vue/reactivity` as a direct dependency of the
Angular consumer so npm installs it locally:

```json
"dependencies": { "@vue/reactivity": "^3.5.35" }
```

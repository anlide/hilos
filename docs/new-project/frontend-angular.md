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
  `tslib`, `@hilos/angular` (`file:`); devDeps `@angular/{build,cli,
  compiler-cli}` `^22`, `typescript ~6.0.x`; `cli.analytics: false` for
  container runs. Under the CLI the SDK resolves to its built **dist** in
  BOTH modes (`ng build` natively; `ng serve` via the dev-configuration
  `conditions` override below) — build the SDK first, and rebuild it to see
  SDK changes in a running dev server.
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

## Dev-mode WebSocket

No env override exists under `ng serve`, so the dev server proxies the app's
same-origin `/ws` to the daemon instead: `frontend/proxy.conf.json`

```json
{ "/ws": { "target": "http://<daemon-local-service>:8092", "ws": true } }
```

wired via `serve.options.proxyConfig` in `angular.json`. The proxy target is
the compose service name — the dev container and the daemon share the local
network.

## Module duplication — two layers, both required

The `file:` SDK reaches a second `@angular/core` copy through its real path
(the SDK-workspace install used by the adapter unit tests). With two copies
the SDK's `inject()` cannot see the app's injection context and bootstrap
dies with **NG0203**. The fix has TWO independent layers — `ng build` and
`ng serve` resolve modules through different pipelines, and each layer
covers one of them:

1. **Build path**: `tsconfig.json` pins the bare import onto the app's
   single copy:

   ```jsonc
   "paths": { "@angular/core": ["./node_modules/@angular/core"] }
   ```

   The `@angular/build` esbuild pipeline resolves bare imports through
   tsconfig paths, so `ng build` (and therefore e2e/prod) emit one core.

2. **Dev path**: the dev server's Vite dependency PREBUNDLER ignores
   tsconfig paths and inlines the second core into the prebundled
   `@hilos/angular` chunk (verifiable in
   `frontend/.angular/cache/<ver>/<project>/vite/deps/@hilos_angular.js` —
   look for `framework/frontend/node_modules/@angular/core` in its header
   comments). The dev page then dies with NG0203 at bootstrap while the
   WebSocket itself (opened from `main.ts` before bootstrap) connects and
   pings happily — a confusing symptom. The fix is TWO edits in
   `angular.json`, applied TOGETHER:

   ```json
   "serve": { "options": { "prebundle": { "exclude": ["@hilos/angular", "@hilos/core"] } } }
   ```

   ```json
   "build": { "configurations": { "development": { "conditions": ["module"] } } }
   ```

   Both are needed. Prebundle-exclude alone pulls the SDK **TypeScript
   source** (development-condition export) into the app build and fails
   with "file not found in TypeScript compilation"; `"conditions":
   ["module"]` drops the `development` condition so the SDK resolves to its
   **dist** in dev (matching `ng build`), and the exclude must stay or the
   optimizer re-inlines the second core anyway. The trade-off: the dev
   server serves the BUILT SDK — rebuild the SDK workspace to see SDK
   changes (app-code HMR is unaffected). Keep the explanation in
   `tsconfig.json`'s JSONC comment, since `angular.json` cannot hold
   comments.

## The core's signal engine — `@vue/reactivity` as a direct dependency

`@hilos/core` ships a private signal engine, `@vue/reactivity` (app code
never imports it). A real tarball install hoists it automatically; the
monorepo `file:` link instead satisfies it from the SDK workspace's own copy
and never places it in the consumer's `node_modules`. The Vite-Vue/React
demos do not notice — they resolve the core to `src` in dev and prebundle the
whole graph through the symlink's real path. The Angular dev server does: it
resolves the core to **dist** and lists it in `prebundle.exclude` (the
module-duplication fix above), so Vite serves the dist untouched and resolves
its bare `@vue/reactivity` import from the consumer root, where the
link-satisfied dep is absent — `Failed to resolve import "@vue/reactivity"`.

The fix is one line: declare `@vue/reactivity` as a direct dependency of the
Angular consumer so npm installs it locally:

```json
"dependencies": { "@vue/reactivity": "^3.5.35" }
```

Production `ng build` is unaffected — esbuild resolves the import through the
symlink's real path either way; only the dev server reads it from the
consumer root.

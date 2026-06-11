# New Hilos frontend: Angular

Reference implementation:
[demo/simple-poll/frontend](../../demo/simple-poll/frontend).
Common ground (containers, connection, e2e, stable ids) is in
[README.md](README.md); this part covers only what is Angular-specific.

> **KNOWN BLOCKING ISSUE (open): `ng serve` dev mode crashes with NG0203.**
> See the section at the bottom. `ng build`, the e2e stack, and production
> serving are NOT affected — only the dev-server page. Until it is fixed, an
> Angular consumer cannot run the live dev loop against the SDK.

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
  container runs. Under the CLI the SDK resolves to its built **dist** (no
  development-condition pass-through at build time) — build the SDK first.
- `angular.json` is NOT JSONC-tolerant in PhpStorm — keep comments out of it;
  put explanations in `tsconfig.json` (JSONC-tolerant) instead.

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

## Module duplication — two layers, one still open

The `file:` SDK reaches a second `@angular/core` copy through its real path
(the SDK-workspace install used by the adapter unit tests). With two copies
the SDK's `inject()` cannot see the app's injection context and bootstrap
dies with **NG0203**.

1. **Build path — SOLVED**: `tsconfig.json` pins the bare import onto the
   app's single copy:

   ```jsonc
   "paths": { "@angular/core": ["./node_modules/@angular/core"] }
   ```

   The `@angular/build` esbuild pipeline resolves bare imports through
   tsconfig paths, so `ng build` (and therefore e2e/prod) emit one core.

2. **Dev path — OPEN, BLOCKING**: the dev server's Vite dependency
   PREBUNDLER ignores tsconfig paths and inlines the second core into the
   prebundled `@hilos/angular` chunk (verifiable in
   `frontend/.angular/cache/<ver>/<project>/vite/deps/@hilos_angular.js` —
   look for `framework/frontend/node_modules/@angular/core` in its header
   comments). The dev page then dies with NG0203 at bootstrap while the
   WebSocket itself (opened from `main.ts` before bootstrap) connects and
   pings happily — a confusing symptom. Excluding the SDK from prebundling
   (`serve.options.prebundle.exclude`) alone does NOT work: the dev pipeline
   then pulls the SDK **TypeScript source** (development-condition export)
   into the app build and fails with "file not found in TypeScript
   compilation". The prepared next step is prebundle-exclude PLUS
   `"conditions": ["module"]` in the development build configuration (forces
   the SDK to resolve to dist in dev, matching `ng build`) — not yet
   verified. Until this is fixed, treat the Angular dev loop as broken and
   develop against the built artifact through nginx
   (`composer run daemon-start-build`).

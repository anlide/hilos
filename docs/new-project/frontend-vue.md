# New Hilos frontend: Vue

Reference implementations: [demo/binance-btc-tracker/frontend](../../demo/binance-btc-tracker/frontend),
the minimal one — copy it — and [demo/chat/frontend](../../demo/chat/frontend),
the full one.
Common ground (containers, connection, e2e, stable ids) is in
[README.md](README.md); this part covers only what is Vue-specific.

## Toolchain

- Vite + `@vitejs/plugin-vue`; type checks via `vue-tsc` (`npm run check`).
- `package.json`: dep `vue@^3.5`; devDeps `vite@^7`, `@vitejs/plugin-vue@^6`,
  `vue-tsc`, `typescript`, `sass-embedded`, and the SDK lint and format ranges
  (`@eslint/js`, `eslint`, `eslint-config-prettier`, `eslint-plugin-vue`,
  `prettier`, `typescript-eslint`); deps `@hilos/vue` + `@hilos/core` as local
  `file:` paths into `framework/frontend/{vue,core}`. The `prebuild` hook
  builds the SDK when it is stale; `prebuild`, `precheck` and `predev` write the
  license inventory the License page draws. `lint` is `eslint . --max-warnings 0`.
- `eslint.config.mjs`, `.prettierrc.json` and `.prettierignore` repeat the SDK
  baseline (`framework/frontend`), including `eslint-plugin-vue`.
- Start the lockfile from an existing Vue demo's `package-lock.json` and let
  `npm install` prune it, rather than resolving from scratch: `vue` must be the
  SAME version the SDK workspace (`framework/frontend`) resolved. Two different
  versions are two different `Ref` brands to `vue-tsc`, and every template
  binding of a signal fails the type check with "`Readonly<Ref<…>>` is not
  assignable" — while the same versions are folded into one by TypeScript.
- `index.html` uses a RELATIVE script entry (`./src/index.ts`) — keeps the
  markup self-contained for the IDE without Resource Root marks; the built
  artifact still emits absolute `/assets/*` URLs.
- `vite.config.ts`: `server.host: true`, fixed in-container `port` with
  `strictPort`; native HMR (no polling); `server.fs.allow: ['../../..']`
  (README.md, "Frontend (common ground)"); a build plugin that writes
  `dist/build-timestamp.txt`, which the daemon ships in the handshake welcome.

## SDK wiring

The src root stays thin and the boot wiring lives in `src/bootstrap/`
([../agents/frontend/bootstrap-structure.md](../agents/frontend/bootstrap-structure.md)):

- `src/index.ts`: one import, `./bootstrap/main`, and nothing else.
- `src/bootstrap/connection.ts`: `createHilosConnection({ url:
  import.meta.env.VITE_WS_URL })` from `@hilos/core` — one connection for the
  app, the same-origin `/ws` by default, the framework schemas merged and the
  stale-build reload wired by the call itself. It exports `connection`,
  `actionErrors` and `actions`.
- `src/bootstrap/session.ts`: the app's `ScopeManager` and the session
  selectors over it (`sessionUserName`, `sessionUserId`, `sessionUserIsAdmin`,
  `sessionPendingAuthStep`, `sessionPendingAck`).
- `src/bootstrap/main.ts`: `bootHilos({ viewLayer: HILOS_VIEW_LAYER, connection,
  actions, scopes, router, pageTitles, appName })` binds the scopes, builds the
  navigator and OPENS THE SOCKET — never call `connection.connect()` by hand.
  Then `createAuthGate(…)`, `createApp(App, { authGate })`,
  `app.provide(hilosRouterKey, …)` and `app.provide(hilosAuthGateKey, …)`.
- `src/pages/`: `keys.ts`, `routes.ts` (`createAppPageRouter`) and
  `pageTitles.ts` ([../agents/frontend/page-registry.md](../agents/frontend/page-registry.md)).
- `src/App.vue`: `HilosLayout` with the `#brand` and `#user` slots and a
  `HilosView` over the page map, the page skeletons and the project's
  `AuthSurface` (a wrapper closing the project's `HilosAuthContext` over the
  framework `HilosAuthSurface`).
- State in components via `useSignal(…)` and `useConnectionState(connection)`
  from `@hilos/vue` (each a `Readonly<Ref<…>>`; unsubscribes on scope dispose).

## SDK primitives

The SDK ships slot-first components over the headless core controllers
([../agents/frontend/multiframework-core.md](../agents/frontend/multiframework-core.md)).
Bind them the Vue way — props in, a slot for content, events out:

```vue
<LoadingButton :loading="saving" class="btn-primary" @click="save">
  Save
</LoadingButton>
```

`@vitejs/plugin-vue` makes the `useSignal`-mirrored state reactive with no extra
step; the component owns the spinner timing, the disabled state, and its a11y.

## Dev-mode WebSocket

The dev server proxies the app's same-origin `/ws` to the daemon: `vite.config.ts`

```ts
server: {
  proxy: {
    '/ws': {
      target: env.VITE_WS_TARGET || 'http://<daemon-local-service>:8092',
      ws: true,
    },
  },
}
```

The proxy target uses the compose service name — the dev container and the
daemon share the local network. `/_hilos/data-export` is proxied the same way
to the daemon's `:8090`, so a personal data copy downloads in dev as well.

## Module duplication

Not an issue at run time on Vue: `@vitejs/plugin-vue` dedupes `vue`
automatically, so the SDK's copy never splits the runtime. (Contrast with React
and Angular — both need explicit measures; see their parts.) The type check is
the one place a second copy shows — see the lockfile note under Toolchain.

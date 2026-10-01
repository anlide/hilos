# New Hilos frontend: React

Reference implementations: [demo/ecommerce-shop/frontend](../../demo/ecommerce-shop/frontend),
the minimal one — copy it — and [demo/tasks/frontend](../../demo/tasks/frontend),
the one with every framework feature switched on.
Common ground (containers, connection, e2e, stable ids) is in
[README.md](README.md); this part covers only what is React-specific.

## Toolchain

- Vite + `@vitejs/plugin-react` — mind the peer window: the 5.x plugin line
  supports vite 7 (6.x requires vite 8).
- `package.json`: deps `react@^19` + `react-dom`; devDeps `vite@^7`,
  `@vitejs/plugin-react@^5`, `@types/react`, `@types/react-dom`, `typescript`,
  `sass-embedded`, and the SDK lint and format ranges (`@eslint/js`, `eslint`,
  `eslint-config-prettier`, `prettier`, `typescript-eslint`); deps
  `@hilos/react` + `@hilos/core` as local `file:` paths into
  `framework/frontend/{react,core}`. The `prebuild` hook builds the SDK
  when it is stale; `prebuild`, `precheck` and `predev` write the license
  inventory the License page draws. Type checks via plain `tsc`
  (`npm run check`); `tsconfig.json` adds `"jsx": "react-jsx"`. `lint` is
  `eslint . --max-warnings 0`.
- `eslint.config.mjs`, `.prettierrc.json` and `.prettierignore` repeat the flat
  SDK baseline (no React plugin; the SDK has none).
- Start the lockfile from an existing React demo's `package-lock.json` and let
  `npm install` prune it, rather than resolving from scratch, so `react` stays on
  the version the SDK workspace resolved.
- `index.html` uses a RELATIVE script entry (`./src/index.ts`) — keeps the
  markup self-contained for the IDE without Resource Root marks; the built
  artifact still emits absolute `/assets/*` URLs.
- `vite.config.ts`: `server.host: true`, fixed in-container `port` with
  `strictPort`; native HMR (no polling); `server.fs.allow: ['../../..']`
  (README.md, "Frontend (common ground)"); `resolve.dedupe` (below); a build
  plugin that writes `dist/build-timestamp.txt`, which the daemon ships in the
  handshake welcome.
- The public footer pages are prerendered by `scripts/prerenderEntry.tsx`,
  built for Node (`vite build --ssr`) and run after the client build.

## SDK wiring

The src root stays thin and the boot wiring lives in `src/bootstrap/`
([../agents/frontend/bootstrap-structure.md](../agents/frontend/bootstrap-structure.md)):

- `src/index.ts`: one import, `./bootstrap/main.js`, and nothing else.
- `src/bootstrap/connection.ts`: `createHilosConnection({ url:
  import.meta.env.VITE_WS_URL })` from `@hilos/core` — one connection for the
  app, the same-origin `/ws` by default, the framework schemas merged and the
  stale-build reload wired by the call itself. It exports `connection` and
  `actions`.
- `src/bootstrap/session.ts`: the app's `ScopeManager` and the session
  selectors over it (`sessionUserName`, `sessionUserId`, `sessionUserIsAdmin`,
  `sessionPendingAuthStep`, `sessionPendingAck`).
- `src/bootstrap/main.tsx`: `bootHilos({ viewLayer: HILOS_VIEW_LAYER, connection,
  actions, scopes, router, pageTitles, appName })` binds the scopes, builds the
  navigator and OPENS THE SOCKET — never call `connection.connect()` by hand.
  Then `createAuthGate(…)` and `createRoot(…).render(…)` with `StrictMode`,
  `HilosRouterContext.Provider` and `HilosAuthGateContext.Provider` around the
  app.
- `src/pages/`: `keys.ts`, `routes.ts` (`createAppPageRouter`) and
  `pageTitles.ts` ([../agents/frontend/page-registry.md](../agents/frontend/page-registry.md)).
- `src/App.tsx`: `HilosLayout` with the `brand`, `isAdmin` and `user` props and a
  `HilosView` over the page map, the page skeletons and the project's
  `AuthSurface` (a wrapper closing the project's `HilosAuthContext` over the
  framework `HilosAuthSurface`).
- State in components via `useSignal(…)` and `useConnectionState(connection)`
  from `@hilos/react` (implemented over `useSyncExternalStore`).

## SDK primitives

The SDK components mirror the core controllers
([../agents/frontend/multiframework-core.md](../agents/frontend/multiframework-core.md))
the React way — props in, `children` for content, `onClick` out — and unknown
attributes fall through to the underlying element:

```tsx
<LoadingButton loading={saving} className="btn-primary" onClick={save}>
  Save
</LoadingButton>
```

The `dedupe` config below is what lets the component's hooks
(`useSyncExternalStore`) run on the app's single React copy.

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

## Module duplication — REQUIRED config

The `file:` SDK resolves through the symlink's real path and reaches ITS OWN
react copy (an SDK-workspace install for the adapter unit tests). With two
copies the component renders on the app's React while the SDK hook runs on the
second one — a `TypeError` from a null dispatcher and the app never mounts,
with a WARNING-FREE build. The fix is mandatory in `vite.config.ts`:

```ts
resolve: {
  dedupe: ['react', 'react-dom'],
}
```

This is the npm-link canon and covers both dev and build. Do not remove it.

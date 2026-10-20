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
- End `npm run build` after prerendering with
  `node node_modules/@hilos/core/bin/theme-head.mjs dist`, so every emitted HTML
  page gets the first-frame theme script.

## SDK wiring

The src root stays thin and the boot wiring lives in `src/bootstrap/`
([../agents/frontend/bootstrap-structure.md](../agents/frontend/bootstrap-structure.md)):

- `src/index.ts`: one import, `./bootstrap/main.js`, and nothing else.
- `src/bootstrap/connection.ts`: `createHilosConnection({ url:
  import.meta.env.VITE_WS_URL })` from `@hilos/core/base` — one connection for the
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
- `src/App.tsx`: `HilosLayout` with the `brand` and `user` props and a
  `HilosView` over the page map, the page skeletons and the project's
  `AuthSurface` (a wrapper closing the project's `HilosAuthContext` over the
  framework `HilosAuthSurface`).
- State in components via `useSignal(…)` and `useConnectionState(connection)`
  from `@hilos/react/base` (implemented over `useSyncExternalStore`).

## Lazy admin bindings and the production graph

Keep descriptors at module scope outside `App` rendering. Common components come
from `/base`, default metadata from `/admin-views`, and admin wrappers load their
own section. This binding excerpt retains the normal `HilosView` contract; keep
the real app's eager Main, public/footer and profile keys and auth/skeleton props:

```tsx
import type { ComponentType } from 'react'
import { HilosPages, lazyPage, type PageView } from '@hilos/core/base'
import { HilosView } from '@hilos/react/base'
import { hilosAdminViews } from '@hilos/react/admin-views'

const pages: Record<string, PageView<ComponentType>> = {
  ...hilosAdminViews(),
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.js').then(m => m.default),
  ),
}

export default function App() {
  return <HilosView pages={pages} />
}
```

The wrapper imports `HilosSettingsPage` from `@hilos/react/admin/settings`;
its context type comes from `@hilos/core/admin/settings`. Each page stays a real
module, with identity supplied by the backend. An eager function component is
still a component, never inferred to be a loader. `lazyPage` does not invoke the
import until selection, and needs no `React.lazy` or `Suspense`. The compatibility
root remains available; performance consumers use these subpaths. See
[page-module-structure.md](../agents/frontend/page-module-structure.md#binding-eager-and-lazy-page-modules)
and [runtime semantics](../agents/frontend/multiframework-core.md#page-code-and-first-answer-state)
for the combined loading state, refusals, outlet cache and retry limits.

Add the build-only plugin to the existing Vite configuration, preserving its
proxy and timestamp plugin. Include both source anchors and the React dedupe:

```ts
import react from '@vitejs/plugin-react'
import { initialBundleBudget } from '@hilos/core/build/initial-bundle-budget'
import { defineConfig } from 'vite'

export default defineConfig(({ command, isSsrBuild }) => ({
  plugins: [
    react(),
    command === 'build' && !isSsrBuild && initialBundleBudget({
      warningBytes: 1500000,
      errorBytes: 2000000,
      projectSources: {
        routes: 'src/pages/routes.ts',
        views: 'src/App.tsx',
      },
    }),
  ],
  resolve: { dedupe: ['react', 'react-dom'] },
}))
```

Adapt the paths to the project's actual route/outlet sources. Require
`projectProof.status: 'verified'` when asserting project-admin absence;
`'not-configured'` retains only legacy measurement/SDK scope. Initial JS+CSS is
counted once, gzip is informational, and an eager admin implementation refuses
even under the byte budget. The client-only plugin retains its report through
the separate public SSR/SSG and theme passes. The prerender entry stays independent
of the client App/admin map. See
[build-and-docker.md](../agents/frontend/build-and-docker.md#initial-client-graph-and-budget)
for source forms, evidence, report and thresholds.

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

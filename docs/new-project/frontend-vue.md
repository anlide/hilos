# New Hilos frontend: Vue

Reference implementation: [demo/chat/frontend](../../demo/chat/frontend).
Common ground (containers, connection, e2e, stable ids) is in
[README.md](README.md); this part covers only what is Vue-specific.

## Toolchain

- Vite + `@vitejs/plugin-vue`; type checks via `vue-tsc` (`npm run check`).
- `package.json`: dep `vue@^3.5`; devDeps `vite@^7`, `@vitejs/plugin-vue@^6`,
  `vue-tsc`; deps `@hilos/vue` + `@hilos/core` as local `file:` paths into
  `framework/frontend/{vue,core}`.
- `index.html` uses a RELATIVE script entry (`./src/main.ts`) — keeps the
  markup self-contained for the IDE without Resource Root marks; the built
  artifact still emits absolute `/assets/*` URLs.
- `vite.config.ts`: `server.host: true`, fixed in-container `port` with
  `strictPort`; native HMR (the repo lives on the WSL2 filesystem — no
  polling).

## SDK wiring

- `src/connection.ts`: one module-level `HilosConnection`; URL =
  `import.meta.env.VITE_WS_URL ?? sameOrigin /ws`; `buildMismatch` →
  `location.reload()`.
- `main.ts`: `connection.connect()` before `createApp(App).mount('#app')`.
- State in components via `useConnectionState(connection)` from `@hilos/vue`
  (a `Readonly<Ref<ConnectionState>>`; unsubscribes on scope dispose).

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

The dev page is served by Vite, so the same-origin `/ws` default cannot reach
the daemon. The compose dev service sets
`VITE_WS_URL: "ws://localhost:<published WS host port>"` (plain `ws://` — TLS
terminates at nginx, the daemon port itself is not TLS). `env.d.ts` augments
`ImportMetaEnv` with the optional `VITE_WS_URL`.

## Module duplication

Not an issue on Vue: `@vitejs/plugin-vue` dedupes `vue` automatically, so the
SDK's copy never splits the runtime. (Contrast with React and Angular — both
need explicit measures; see their parts.)

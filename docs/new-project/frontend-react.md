# New Hilos frontend: React

Reference implementation:
[demo/simple-todo/frontend](../../demo/simple-todo/frontend).
Common ground (containers, connection, e2e, stable ids) is in
[README.md](README.md); this part covers only what is React-specific.

## Toolchain

- Vite + `@vitejs/plugin-react` — mind the peer window: the 5.x plugin line
  supports vite 7 (6.x requires vite 8).
- `package.json`: dep `react@^19` + `react-dom`; deps `@hilos/react` +
  `@hilos/core` as local `file:` paths; `tsconfig.json` adds
  `"jsx": "react-jsx"`; type checks via plain `tsc` (`npm run check`).
- `index.html` uses a relative `./src/main.tsx` entry (same IDE rationale as
  the Vue part).
- `main.tsx`: `StrictMode` + `createRoot`; `connection.connect()` before
  render.

## SDK wiring

- `src/connection.ts`: identical shape to the Vue part (`VITE_WS_URL ??
  same-origin /ws`, `buildMismatch` → reload).
- State in components via `useConnectionState(connection)` from
  `@hilos/react` (implemented over `useSyncExternalStore`).

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

Same as Vue: the compose dev service sets `VITE_WS_URL` to the published WS
host port; `env.d.ts` augments `ImportMetaEnv`.

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

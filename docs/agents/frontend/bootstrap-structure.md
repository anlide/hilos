# Frontend Bootstrap and Src Root

Read this before adding, moving, renaming, or reviewing a file at the `src/`
root of a frontend project, or the application's boot wiring.

## Core Rule

The `src/` root holds only the thin entry and the root view component. Every
Hilos boot-wiring file lives in `src/bootstrap/` (Angular: `src/app/bootstrap/`):
`connection.ts`, `session.ts`, and `main`. The entry imports the bootstrap and
nothing else. Do not scatter setup files across the src root, and do not
re-implement in a project what the core boot helpers already do.

## The composition root

A Hilos SPA boots in one shape, lifted into `@hilos/core` so a project
configures rather than re-implements:

- `createHilosConnection(options)` — the single connection, with the framework
  session and page schemas merged, the action-error store attached, and the
  stale-build reload wired.
- `ensureSessionTokenCookie()` — mint the persistent session-token cookie before
  the socket opens.
- `bindSessionScope` / `sessionUserName` — route the handshake response into the
  session scope and expose the current user.
- `bootHilos({ connection, scopes, router, pageEntityTypes?, pageTitles?, appName? })`
  — bind the session scope, the page scope, and the page-ready gate, build the
  navigator, open the socket, apply the URL, and return the navigator to provide
  to the view. `pageTitles` (the project's page key → browser-tab title) and
  `appName` feed the navigator's `currentTitle`, which the app shell binds to
  `document.title` and a page-change live region (WCAG 2.4.2); framework admin
  and footer pages are titled from their own catalogs, so a project lists only
  its own pages.

The page-ready gate is the part of that sequence a project never sees but a
return route depends on. `bindPageReady` latches the first page answer — a
`page_response` or a `subscription_page_error` alike — before the socket opens,
and `whenPageReady` resolves once it has. A route entered by a full browser load
(`/auth/magic`, `/auth/callback`) mounts its relay in the same tick the socket
starts opening, and a frame sent before the socket connects is DROPPED, not
queued: without the gate the relay reports that the server could not be reached
for a request the server never received (HIL-607). What is waited on is the
page's answer rather than the handshake, because an action is routed by the
backend through the connection's page subscription — the readiness that matters
is the one that subscription reports. The gate itself never times out; the relay
screen showing the spinner owns the backstop, since it is the only layer that can
say what to do next.

## Workflow

1. **Entry** `src/index.ts` is a single `import './bootstrap/main'` (Angular:
   `import './app/bootstrap/main'`). It is referenced by `index.html` (Vite) or
   `angular.json` `browser` (Angular). Put no application logic here, ever.
2. **`bootstrap/connection.ts`** owns the connection singleton from
   `createHilosConnection(...)`. State only the project's endpoint policy
   (`url: import.meta.env.VITE_WS_URL` for Vite; Angular passes nothing and uses
   same-origin) and any extra project signal schemas. Export `connection`, and
   `actionErrors` only when the project reads action errors.
3. **`bootstrap/session.ts`** owns the `ScopeManager` singleton, mints the
   session-token cookie at module load with `ensureSessionTokenCookie()` (so it
   rides the handshake, before the socket opens), and exports `currentUserName`
   from `sessionUserName(scopes)`.
4. **`bootstrap/main`** (`main.ts` / `main.tsx`) calls `bootHilos(...)` with the
   project's `connection`, `scopes`, page `router`, and optional
   `pageEntityTypes`, `pageTitles`, and `appName` (the latter two from
   `pages/pageTitles.ts`), then mounts the view and provides the returned
   navigator (Vue `hilosRouterKey`, React `HilosRouterContext`, Angular
   `HILOS_ROUTER`).
5. A new cross-cutting app-setup concern (error reporting, analytics, i18n,
   feature flags) gets its own `bootstrap/` file, wired from `bootstrap/main`. It
   never lands at the src root.

## Why connection and scopes are project singletons

`scopes` and `connection` are imported at module level across `src/types/*`
(`entityCollection(scopes, …)`) and `views/*`. They must therefore be module
singletons, created in `bootstrap/connection.ts` and `bootstrap/session.ts` and
imported from there. `bootHilos` **wires and starts** them rather than creating
and returning them, so the singletons stay statically importable and the entry
never has to import `App` to hand them down (which would cycle the entry against
the view tree).

## Preferred Shape

```
src/
  index.ts            # import './bootstrap/main' — and nothing else
  App.vue             # the root view component (stays at the root)
  bootstrap/
    connection.ts     # createHilosConnection → connection (+ actionErrors)
    session.ts        # ScopeManager + ensureSessionTokenCookie + currentUserName
    main.ts           # bootHilos(...) + mount + provide navigator
  pages/              # page registry (keys, routes, entity-slot types, titles)
  types/              # domain entities and shared value types
  views/              # one folder per page
```

```ts
// bootstrap/connection.ts
import { createHilosConnection } from '@hilos/core'

export const { connection } = createHilosConnection({
  url: import.meta.env.VITE_WS_URL,
})
```

```ts
// bootstrap/session.ts
import {
  ensureSessionTokenCookie,
  ScopeManager,
  sessionUserName,
} from '@hilos/core'

export const scopes = new ScopeManager()
ensureSessionTokenCookie()
export const currentUserName = sessionUserName(scopes)
```

Angular keeps the same three files under `src/app/bootstrap/`, and the root view
component (`App`) stays in `src/app/`, as the Angular layout already places it.

## Anti-Patterns

- Application logic, store creation, or the mount in `src/index.ts` — put it in
  `bootstrap/`; keep the entry a single import.
- `connection.ts` / `session.ts` / `main` left at the src root — move them under
  `bootstrap/`.
- Re-implementing cookie minting, the handshake-response ingest, the current-user
  selector, or the boot sequence in the project — use `ensureSessionTokenCookie`,
  `bindSessionScope` / `sessionUserName`, and `bootHilos` from `@hilos/core`.
- Calling `connection.connect()` by hand — `bootHilos` opens the socket after the
  scopes are bound.
- A barrel `index.ts` inside `bootstrap/`.

## Exceptions

- The root view component (`App.vue` / `App.tsx`) stays at the src root; Angular's
  `App` stays in `src/app/`. It is the view tree root, not boot wiring.
- Toolchain shims the bundler expects at the root (`env.d.ts`,
  `vite-env.d.ts`) stay where the toolchain looks for them.

## Contract Gate

This rule governs file layout and client wiring only — not gated. Changing what
the session or page handling does on the wire (the handshake-response signal, the
scope payload shape, signal or route constants) is contract-gated in
[agents.md](../../../agents.md); the bootstrap files only consume the SDK that
owns those.

## Validation

- Per demo: `composer -d demo/<name> run test:check` (typecheck) and
  `composer -d demo/<name> run test:e2e-build` (entry wiring + bundling).
- SDK: `composer test:framework:frontend` (check, unit, lint, format).

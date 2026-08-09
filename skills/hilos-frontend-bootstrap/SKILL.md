---
name: hilos-frontend-bootstrap
description: Keep the frontend src root thin and the bootstrap module thematic — the one-import entry, the connection/session singletons, and the bootHilos boot sequence. Use when adding, moving, or reviewing a file at the src root, the src/bootstrap module (connection, session, main), the application entry, or any app-wide setup wiring across the Vue/React/Angular demos.
---

# Hilos Frontend Bootstrap and Src Root

Use this skill when you add, move, rename, or review a file at the `src/` root,
the `bootstrap/` module, the application entry, or any application boot wiring.
Start with `agents.md`, then read the canonical rule.

## Read First

- Bootstrap and src root (canonical rule): `docs/agents/frontend/bootstrap-structure.md`
- The page registry the boot wires (keys, routes, entity types): `docs/agents/frontend/page-registry.md`
- One folder per page; where view files and types live: `docs/agents/frontend/page-module-structure.md`
- The agnostic core and per-framework view adapters: `docs/agents/frontend/multiframework-core.md`
- How the boot files themselves must look: `$hilos-code-style-typescript` (and
  its `-vue` / `-react` / `-angular` wrapper)

## Workflow

1. Keep `src/index.ts` a single `import './bootstrap/main'` (Angular:
   `./app/bootstrap/main`) — referenced by `index.html` or `angular.json`
   `browser`. No application logic at the root.
2. Put every boot file under `src/bootstrap/` (Angular: `src/app/bootstrap/`):
   `connection.ts` (owns the connection from `createHilosConnection`),
   `session.ts` (owns the `ScopeManager`, mints the cookie with
   `ensureSessionTokenCookie`, exposes `sessionUserName`), and `main`
   (`bootHilos(...)` + mount + provide the navigator).
3. Configure, do not re-implement: use `createHilosConnection`,
   `ensureSessionTokenCookie`, `bindSessionScope` / `sessionUserName`, and
   `bootHilos` from `@hilos/core`; never hand-roll the cookie, the handshake
   ingest, or the boot sequence, and never call `connection.connect()` by hand.
4. Add a new app-wide setup concern as its own `bootstrap/` file wired from
   `bootstrap/main` — never at the src root.
5. A change to the session/page wire (the handshake-response signal, the scope
   payload, signal/route constants) passes the Contract approval gate in
   `agents.md`; bootstrap files only consume the SDK that owns those.

## Hard Rules

- The src root holds only the entry and the root view component (`App.vue` /
  `App.tsx`; Angular's `App` stays in `src/app/`).
- `connection.ts`, `session.ts`, and `main` live under `bootstrap/`, never at the
  src root.
- `connection` and `scopes` are module singletons created in their bootstrap
  files (so `types/*` and `views/*` import them); `bootHilos` wires and starts
  them.
- No barrel `index.ts` inside `bootstrap/`.

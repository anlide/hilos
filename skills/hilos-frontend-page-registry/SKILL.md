---
name: hilos-frontend-page-registry
description: Set up and review a frontend project's page registry — the pages/ folder (page keys, routes, entity-slot types) and its @hilos/core binding. Use when adding or moving page keys or routes, wiring the page scope (bindPageScope), declaring page entity types, building the app router (createAppPageRouter), or registering the page_response schema (PAGE_SIGNAL_SCHEMAS) across the Vue/React/Angular demos.
---

# Hilos Frontend Page Registry

Use this skill when you set up or review the app-level page topology of a
frontend project — the `pages/` folder and the entry-point wiring that turns it
into a live page subscription. This is distinct from a single page's view files
(`hilos-frontend-page-structure`). Start with `agents.md`, then read the
canonical rule.

## Read First

- Page registry (canonical rule): `docs/agents/frontend/page-registry.md`
- One page's view files (the related but separate rule): `docs/agents/frontend/page-module-structure.md`
- Frontend routing matrix and overview: `docs/agents/frontend/README.md`
- Entity store, scopes, and the page entity-slot types: `docs/agents/frontend/data-model.md`

## Workflow

1. Keep the app's page topology in one `pages/` folder under `src/` (Angular:
   `src/app/pages/`): `keys.ts` (page-key constants) and `routes.ts`
   (`APP_ROUTES` + the exported `router`) always, and `entityTypes.ts` only when
   the app's page payloads carry entity slots.
2. Build the router with `createAppPageRouter(APP_ROUTES, { fallback })` — it
   mounts the framework `hilos_*` admin catalog under the app routes. Never
   spread `HILOS_PAGE_ROUTES` by hand; keep `createPageRouter` for the engine.
3. Bind the page scope with `bindPageScope(connection, scopes, { entityTypes? })`
   in the entry point and hand the returned manager to `createHilosRouter`. Do
   not re-implement the `projectSignal` → `ingestPageResponse` plumbing (no
   hand-written `pageScope.ts`).
4. Spread `PAGE_SIGNAL_SCHEMAS` into the connection's `projectSchemas`; never
   restate the `{ page_response: pageResponseSchema }` pair in a project.
5. Page keys mirror the backend `PageConstants` byte-for-byte; the app shell and
   views import keys from `pages/keys`, never from `routes.ts`.
6. Page keys, routes, and the `page_response` payload shape are FE↔BE wire
   contract — a change passes the Contract approval gate in `agents.md` before
   implementation. This rule governs file layout and SDK binding, not the wire.

## Hard Rules

- One `pages/` folder holds the app's page topology: `keys.ts` + `routes.ts`
  always, `entityTypes.ts` only with entity-bearing page slots.
- Bind via `bindPageScope`, route via `createAppPageRouter`, validate via
  `PAGE_SIGNAL_SCHEMAS` — never re-implement these three in a project.
- No barrel `index.ts` inside `pages/`; framework `hilos_*` keys are not
  restated in `keys.ts`.

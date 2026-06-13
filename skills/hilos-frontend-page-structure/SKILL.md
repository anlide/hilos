---
name: hilos-frontend-page-structure
description: Organize frontend page files into one folder per page and place page types correctly. Use when creating, moving, or reviewing the files of a frontend page (view, selectors, actions, error handling), choosing a page folder or file name across the Vue/React/Angular demos, or deciding whether a TypeScript type belongs in src/types or a page's own types folder.
---

# Hilos Frontend Page Module Structure

Use this skill when you create, move, rename, or review the files that make up a
frontend page, or when you place a frontend TypeScript type. Start with
`agents.md`, then read the canonical rule.

## Read First

- Page module structure (canonical rule): `docs/agents/frontend/page-module-structure.md`
- Frontend routing matrix and overview: `docs/agents/frontend/README.md`
- Entity store, scopes, and the normalizer boundary the types describe: `docs/agents/frontend/data-model.md`
- The agnostic core and per-framework view adapters: `docs/agents/frontend/multiframework-core.md`

## Workflow

1. Put every page in one folder under `views/` (Angular: `src/app/views/`); the
   folder name is the page key — `PascalCase` for Vue/React, `kebab-case` for
   Angular.
2. Name the files by page-key prefix + role, cased by the engine idiom: the
   view file is the page name with no suffix (`Main.vue` / `Main.tsx` /
   `main.ts`), plus `…Page.ts` (selectors), `…Actions.ts` (outbound actions),
   and `…Error.ts` only when error logic is not tied to one action.
3. Keep `…Page.ts` read-only (payload → view-models) and `…Actions.ts` to
   client→server actions plus their `action_error`; inbound signals stay in
   `pageScope.ts`.
4. Place page-local types in `views/<Page>/types/`: group list-row `…Item`
   view-models under `types/lists/`, keep page-local value types directly in
   `types/`; keep domain entities and shared value types in `src/types/`.
5. Do not add a barrel `index.ts` inside a page folder, an empty `…Error.ts`, or
   a `…View`-suffixed view file inside a same-named folder.
6. A frontend FE↔BE contract change (signals, signal/action DTO payloads,
   routes, DB/RT shapes) still passes the Contract approval gate in `agents.md`
   before implementation — this rule only governs file layout, not the wire.

## Hard Rules

- One folder per page under `views/`; the folder name is the page key.
- The view file is the page name with no suffix; in Angular the class and
  selector drop the legacy suffix too (`class Main`, `app-main`).
- Page-specific types live in the page's `types/` (list-row `…Item` view-models
  under `types/lists/`); domain entities and shared value types live in `src/types/`.
- No barrel `index.ts` inside a page folder.

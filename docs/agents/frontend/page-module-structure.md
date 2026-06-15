# Frontend Page Module Structure

How the files of one page are grouped, named, and where a page's types live.
Read this before creating, moving, or reviewing the files of a page in a
frontend project (the Vue, React, and Angular demos, and any real consumer).

This is a file-layout and naming rule, not a behavior spec — it composes with
[data-model.md](data-model.md) (where the entity store and scopes live) and
[multiframework-core.md](multiframework-core.md) (the agnostic core and the
per-framework view adapters).

## One folder per page

Every page is a folder under the project's `views/` directory (Angular keeps
its `views/` under `src/app/`). The folder name **is the page key** — the same
identifier the page subscribes under (`pages/keys.ts`, mirroring the backend
`PageConstants` — see [page-registry.md](page-registry.md)) — cased by the
engine idiom:

- **Vue / React** — `PascalCase` folder: `views/Main/`, `views/Dashboard/`.
- **Angular** — `kebab-case` folder: `src/app/views/main/`, `.../dashboard/`.

The page key is the single source of the folder name: a page keyed `main` lives
in `Main/` (Vue/React) or `main/` (Angular), never `Home/` or `chat-main/`.

A family of framework pages sharing a key prefix — the Hilos admin section, whose
keys are all `hilos_*` — groups under one container folder named for the prefix
(`views/Hilos/`), each page's folder named for the key's remainder
(`hilos_billing` → `views/Hilos/Billing/`, `hilos_billing_provider` →
`views/Hilos/BillingProvider/`). The container stands in for the shared prefix,
so the key is recovered as prefix + folder; this mirrors the backend's
`Pages/Hilos/Billing/` grouping. The container is a grouping device only — it is
not itself a page and holds no view file of its own.

## Files in a page module

Each file's name carries the page-key prefix and a role suffix, cased by the
engine idiom (`PascalCase` view file in Vue/React, `kebab-case` everywhere in
Angular). Using the `main` page as the example:

| Role | Vue | React | Angular |
|---|---|---|---|
| View (markup) | `Main.vue` | `Main.tsx` | `main.ts` |
| Selectors (display data) | `mainPage.ts` | `mainPage.ts` | `main-page.ts` |
| Actions (outbound + their errors) | `mainActions.ts` | `mainActions.ts` | `main-actions.ts` |
| Error handling (optional) | `mainError.ts` | `mainError.ts` | `main-error.ts` |
| View-layer helper (optional) | a composable (`useComposerUpload.ts`) | a hook (`useComposerUpload.ts`) | a service / signal helper |
| Page-local types | `types/` | `types/` | `types/` |

The **view file is the page name with no suffix** — `Main.vue`, not
`MainView.vue`; the folder already says it is a view. In Angular the class and
selector follow suit and drop the legacy suffix: `class Main`, selector
`app-main` (the v20+ style guide already drops the `.component` suffix).

Do **not** add a barrel `index.ts` inside a page folder — import each file by
its own path. A page module is small and self-contained; a barrel only adds an
indirection to maintain.

## File responsibilities

- **View** (`Main.vue` / `Main.tsx` / `main.ts`) — the markup. It reads the
  page's signals from `…Page.ts` and calls into `…Actions.ts`; it never touches
  a raw store or the connection directly.
- **`…Page.ts` — selectors (read only).** Resolves the page payload's lists and
  data slots into the view-models the page renders. Pure projection: no writes,
  no actions.
- **`…Actions.ts` — outbound actions and their errors.** The client→server
  actions the view fires (through the core `sendAction` primitive) plus the
  reactive read of each action's framework `action_error`. Inbound server
  signals are **not** handled here — they are ingested centrally by the SDK
  page-scope binder (`bindPageScope`, see [page-registry.md](page-registry.md)),
  not per page.
- **`…Error.ts` — optional.** Add it only when a page grows error-handling
  logic that is **not** tied to a single action (aggregation, classification, a
  page-level banner). A single action's `action_error` stays in `…Actions.ts`,
  next to the action whose contract it belongs to. Do not create an empty
  `…Error.ts` ahead of a real need.
- **A view-layer helper (composable / hook) — optional.** Imperative, stateful
  view logic that outgrows the view file — a file-upload engine, a drag/drop
  queue, a wizard's step machine — moves into a page-local composable (Vue/React
  `use…`; an Angular service or signal helper) the view consumes, keeping the
  view file markup-first. It stays view-layer: it may own reactive UI state and
  call `…Actions.ts`, but it is **not** a selector (it projects no payload) and
  **not** an action module (it adds no outbound actions). It is named for what
  it does in the `use…` idiom (`useComposerUpload.ts`), not the page-key prefix
  the other files carry. Add it only when a view file genuinely grows one.
- **`types/` — page-local types.** See the next section.

## Mounting a framework-owned page

A framework admin page that has graduated to a real implementation is a **tier-2
page-chunk component** the SDK ships (`@hilos/vue` `HilosUsersPage` /
`HilosUserPage`; [sdk-packaging.md](sdk-packaging.md)). Its view, selectors, and
actions are the framework's — the headless lives in `@hilos/core`
(`createHilosUsersTable` / `createHilosUserDetail` / `createHilosUserRename`,
the `HilosUserRow` view-model), the markup in the view package — and are **not**
restated per project. The project's page module then shrinks to:

- the **view file** — a thin wrapper that mounts the framework page and fills its
  slots (`Users.vue` mounts `HilosUsersPage` and supplies the `#row-actions` cell
  with a link to the detail page; `User.vue` mounts `HilosUserPage`);
- an optional **context module** (`…Context.ts`) — the one place the project binds
  the framework page to its own `scopes`, `connection`, and entity collections
  (`hilosUsersContext.ts`), shared by the list and detail wrappers. It is a
  binding, not a selector: the selectors are the framework's.

Folder-per-page still holds — each page is its own folder with its own wrapper,
mapped explicitly in the app shell — and there is still no shared page map. What
moved is the *implementation level*, not the page count: a project overrides a
framework page by swapping its wrapper or filling more slots, never by copying
the framework template ("template extension, not re-implementation").

## Never a shared page map

A page's identity (its label and place in the navigation tree), its content, and
its render logic live in that page's own module — never collected into a file
that enumerates many pages. A single file holding the titles, leads,
parents/children, or render logic of more than one page — a "page map", an "admin
map", a `Record<pageKey, …>` of page metadata wired into one shared component
that renders them all — is a **gross Hilos violation**
([rules-and-violations.md](rules-and-violations.md) §F). It re-centralizes
exactly what one-folder-per-page exists to split, and it mirrors nothing on the
backend, where every page is its own class file.

The line is **catalog of identity** versus **page module**:

- A **catalog of page identity** — a flat key→identity map such as the route
  table (`HILOS_PAGE_ROUTES`), the footer set (`HILOS_FOOTER_LINKS`), or the
  framework admin tree (`HILOS_ADMIN_PAGES`: key → label / lead / parent) — is a
  registry, the companion of the route table. One such catalog is allowed and
  expected; it carries identity only — no page's render logic, no per-page view
  content.
- A **page module** — how one page looks and behaves (its view, selectors,
  actions, page-local types, and on-page content) — is one-per-file under
  `views/`, never many pages merged into one file.

Reusing *presentation* is not a violation. A page may render through a shared,
**page-agnostic** component — a breadcrumb, an admin-page shell — as long as that
component knows nothing about any specific page (it is parametrized by a page key
and reads the identity catalog) and each page is still its own module mapped
explicitly in the app shell. The violation is the inverse: a God-component that
reads the navigator itself, backed by a God-map of every page's content. A
parametrized shell each page invokes with its own key is the sanctioned form.

**Default framework views for un-implemented pages.** The framework may ship a
factory that maps each admin catalog key to the page-agnostic shell —
`hilosAdminViews()` in `@hilos/vue`, one `HilosAdminPage` stub per
`HILOS_ADMIN_PAGES` key. A project spreads it into its app page map and overrides
a key only when it implements that page as its own module (e.g. `Hilos/Users/`).
This keeps the 50-odd not-yet-built admin pages in the framework instead of
recopying an identical stub into every project. It is **not** the revoked
God-map: there is no page content or metadata map in the project (the catalog
stays in `@hilos/core`), each entry renders the page-agnostic shell, and the
navigator is still read only by `HilosView`. An un-implemented page has no
project module by design; implementing it creates one.

## Type placement: domain vs page-local

- **`src/types/`** holds **domain entities** (the normalized wire entities that
  resolve through the entity store — e.g. `User`, `Bot`, `Event`) and **shared
  value types** used across more than one page (e.g. `Presence`). These are not
  owned by any one page.
- **`views/<Page>/types/`** holds **types specific to that one page**. Group the
  list-row view-models (an `…Item` per list row, e.g. `ParticipantItem`,
  `BotItem`, `EventItem`) under a `types/lists/` subfolder — they are a kind of
  their own. Keep other page-local types — a page-local value type such as the
  composer's `SelfConnection` — directly in `types/`.

The test is ownership, not shape: if a type is meaningful only to one page, it
lives in that page's `types/`; if it is a domain entity or is shared across
pages, it lives in `src/types/`. A page-local type that later gets reused by a
second page graduates to `src/types/`.

## Violations

- A page's files scattered in a flat `src/` (`mainPage.ts`, `mainActions.ts`,
  `MainView.vue` side by side at the root) instead of a `views/<Page>/` folder.
- A view file named `…View` (`MainView.vue`) inside a `Main/` folder — the
  suffix duplicates the folder.
- A page-specific view-model (`…Item`) or page-local value type left in
  `src/types/` instead of the page's `types/`.
- A domain entity or a genuinely shared value type pushed down into one page's
  `types/`, forcing other pages to reach across into it.
- A list-row `…Item` view-model left directly in the page `types/` instead of
  its `types/lists/` subfolder.
- A barrel `index.ts` inside a page folder.
- A page folder named anything other than the page key.
- A "page map" / "admin map": one file enumerating many pages' titles, leads,
  parents, or render logic and wiring every key to a single shared component,
  instead of one module per page (see "Never a shared page map"). Mixing more
  than one page's content or render into a single file is a gross violation.

## Example — the chat main page (Vue)

```
src/
  types/                     domain entities + shared value types
    User.ts  Bot.ts  Event.ts  Presence.ts  index.ts
  views/
    Main/                    folder name = page key "main"
      Main.vue               view (markup; reads the signals below)
      mainPage.ts            selectors: participants / bots / events / selfConnection
      mainActions.ts         sendChatMessage + its messageError
      types/                 page-local types
        SelfConnection.ts    page-local value type
        lists/               list-row view-models
          ParticipantItem.ts  BotItem.ts  EventItem.ts
    Dashboard/
      Dashboard.vue
```

React mirrors this with `Main.tsx`; Angular mirrors it as
`src/app/views/main/main.ts` (class `Main`, selector `app-main`),
`main-page.ts`, `main-actions.ts`.

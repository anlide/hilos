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
keys are all `hilos_*` — groups **by section**, mirroring the backend's
`Pages/Hilos/<Section>/` layout. The section is the folder; every page of that
section lives directly inside it, and a deep sub-group nests a further folder (the
I18n screens split into `i18n/lists/`, `i18n/details/`, `i18n/translate/`, like the
backend `I18n/Lists|Details|Translate/`). The section prefix lives in the folder
path; there is **not** a separate top-level folder per page.

This canon applies at two levels, identically:

- **In the SDK** the Hilos admin pages are framework-owned, so they live under
  `@hilos/vue/src/admin/<section>/` (the view) with any headless logic under
  `@hilos/core/src/admin/<section>/` — e.g. `admin/billing/HilosBillingProviderPage.vue`,
  `admin/daemon/HilosDaemonWorkersPage.vue`, `admin/i18n/lists/HilosI18nLanguagesPage.vue`.
  An SDK admin page file is named `Hilos<Remainder>Page` — the `Hilos` SDK-export
  prefix, the key's remainder after `hilos_` in `PascalCase`, and the `Page`
  suffix (`hilos_billing_provider` → `HilosBillingProviderPage`). Vue ships these
  today; React/Angular gain them when those view primitives are ported. See
  [sdk-packaging.md](sdk-packaging.md).
- **In a consumer** the project keeps a `views/Hilos/<Section>/` folder, grouped
  the same way, only for the admin pages it actually implements or customizes —
  its thin wrappers and full pages (e.g. `views/Hilos/Users/{Users.vue, User.vue,
  hilosUsersContext.ts}`, `views/Hilos/Settings/`). An un-implemented page has no
  consumer folder at all; it is served by the SDK default map (see "Default
  framework views" below).

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
| View-layer helper (optional) | a composable (`use….ts`) | a hook (`use….ts`) | a service / signal helper |
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
  not per page. An action fired on mount to obtain data the first render needs
  is a violation, not a pattern: that data rides in the page's `page_response`
  payload, assembled on the backend — see
  [signals/subscriptions.md](../signals/subscriptions.md), "One subscription
  answers everything the page renders".
- **`…Error.ts` — optional.** Add it only when a page grows error-handling
  logic that is **not** tied to a single action (aggregation, classification, a
  page-level banner). A single action's `action_error` stays in `…Actions.ts`,
  next to the action whose contract it belongs to. Do not create an empty
  `…Error.ts` ahead of a real need.
- **A view-layer helper (composable / hook) — optional.** Imperative, stateful
  view logic that outgrows the view file — a wizard's step machine — moves into
  a page-local composable (Vue/React `use…`; an Angular service or signal
  helper) the view consumes, keeping the view file markup-first. It stays
  view-layer: it may own reactive UI state, call `…Actions.ts` and drive a
  client the core ships, but it is **not** a selector (it projects no payload)
  and **not** an action module (it adds no outbound actions). The step machine
  is the shape: it holds which step is open and what each step still lacks
  before the next one opens, and calls `…Actions.ts` once the last one is done.
  It is named for what it does in the `use…` idiom, not the page-key prefix the
  other files carry. Add it only when a view file genuinely grows one. Picking
  files — the picker, drag and drop, paste and the chips — is not such a
  helper: that is the SDK's upload field
  ([sdk-packaging.md](sdk-packaging.md)).
- **`types/` — page-local types.** See the next section.

## Mounting a framework-owned page

A framework admin page that has graduated to a real implementation is a **tier-2
page-chunk component** the SDK ships (`@hilos/vue/admin/users` `HilosUsersPage` /
`HilosUserPage`, `@hilos/vue/admin/settings` `HilosSettingsPage`;
[sdk-packaging.md](sdk-packaging.md)). Its view, selectors, and actions are the
framework's and are **not** restated per project. Headless users APIs
(`createHilosUsersTable`, `createHilosUserDetail`, `createHilosUserRename`,
`HilosUserRow`) live in `@hilos/core/admin/users`; settings APIs
(`createHilosSettingsTable`, `createHilosSettingsActions`, `HilosSettingRow`)
live in `@hilos/core/admin/settings`. Markup lives in the corresponding view
section. The project's page module then shrinks to:

- the **view file** — a thin wrapper that mounts the framework page and fills its
  slots (`Users.vue` mounts `HilosUsersPage` and supplies the `#row-actions` cell
  with a link to the detail page; `User.vue` mounts `HilosUserPage`; `Settings.vue`
  mounts `HilosSettingsPage` with no slots);
- an optional **context module** (`…Context.ts`) — the one place the project binds
  the framework page to its own `scopes`, `connection`, and (when the page resolves
  an entity) its entity collections: `hilosUsersContext.ts` passes `scopes` +
  `connection` + the `Users` collection (shared by the list and detail wrappers),
  while `hilosSettingsContext.ts` passes just `scopes` + `connection` (settings are
  page-scoped, no entity). It is a binding, not a selector: the selectors are the
  framework's.

A framework page need not take a project context at all. The dashboard
(`HilosDashboardPage`) reads only the framework catalog, so it stays in the
`hilosAdminViews` default map (a project gets it for free) and a project extends it
purely through a **slot**: a wrapper can fill `#top` above the framework sections.
The current demos use the default dashboard binding. Slot extension and a
context are independent — a page may use either, both, or neither.

Folder-per-page still holds — each page is its own folder with its own wrapper,
mapped explicitly in the app shell — and there is still no shared content map.
What moved is the *implementation level*, not the page count: a project overrides a
framework page by swapping its wrapper or filling more slots, never by copying
the framework template ("template extension, not re-implementation").

## Binding eager and lazy page modules

The app's `pages` map binds keys to ready components or explicit descriptors;
it carries no titles, leads, breadcrumbs or page content. Import common SDK
symbols from `/base`, default bindings from `/admin-views`, and the page's own
implementation from `/admin/<section>` inside its wrapper. For example, a Vue
App setup can bind its existing Settings wrapper this way:

```ts
import type { Component } from 'vue'
import { HilosPages, lazyPage, type PageView } from '@hilos/core/base'
import { hilosAdminViews } from '@hilos/vue/admin-views'

const pages: Record<string, PageView<Component>> = {
  ...hilosAdminViews(),
  [HilosPages.SETTINGS]: lazyPage(() =>
    import('./views/Hilos/Settings/Settings.vue').then(m => m.default),
  ),
}
```

`Settings.vue` remains a real module mounting `HilosSettingsPage` from
`@hilos/vue/admin/settings`; its context type comes from
`@hilos/core/admin/settings`. React uses `PageView<ComponentType>` and the
wrapper's `.js` import with its named/default export. Angular uses
`PageView<Type<unknown>>`, a readonly field and the wrapper's named class export.
Keep descriptor identities stable: module scope in React, setup scope in Vue,
or a once-per-instance Angular field. Constructing the descriptor does not start
its import. Eager components remain valid, including function components.

The default map is spread before explicit project bindings. A project binding
suppresses the default at that key; its unused loader is not called. This
override behavior is unit-tested, though the current demos' default and explicit
admin key sets are disjoint. The existing unknown/unbuilt-page guards recognize
literal lazy targets and public reexports and still refuse false declarations.
The router's keys, admin flags, server subscription and first `page_response`
remain unchanged. Runtime loading/refusal/cache behavior is specified once in
[multiframework-core.md](multiframework-core.md#page-code-and-first-answer-state);
the narrower source form accepted by the production proof is in
[build-and-docker.md](build-and-docker.md#project-binding-proof).

## Never a shared page map

A page's content and render logic live in that page's own module. Its catalog
identity (label and place in the navigation tree) arrives in the backend's page
reply. A single file collecting many pages' content or render logic — a "page
map", an "admin map", a `Record<pageKey, …>` wired into one shared component
that renders them all — is a **gross Hilos violation**
([rules-and-violations.md](rules-and-violations.md) §F). It re-centralizes
exactly what one-folder-per-page exists to split, and it mirrors nothing on the
backend, where every page is its own class file.

The line is **catalog of identity** versus **page module**:

- A **catalog of page identity** — a flat key→identity map such as the route
  table (`HILOS_PAGE_ROUTES`), the footer set (`HILOS_FOOTER_LINKS`), or the
  backend admin catalog (`PageCatalogProviderInterface` / `PageCatalogResolver`)
  — is a registry. Admin identity reaches the page through its `page_response`;
  do not mirror the catalog in the frontend. A catalog carries identity only,
  with no page render logic or per-page view content. For head identity and
  adoption, read [page-head.md](../architecture/page-head.md).
- A **page module** — how one page looks and behaves (its view, selectors,
  actions, page-local types, and on-page content) — is one-per-file under
  `views/`, never many pages merged into one file.

Reusing *presentation* is not a violation. A page may render through a shared,
**page-agnostic** component — a breadcrumb, an admin-page shell — as long as that
component knows nothing about any specific page (it is parametrized by a page key
and reads identity from the page's answer) and each page is still its own module
mapped explicitly in the app shell. The violation is the inverse: a God-component
that reads the navigator itself, backed by a God-map of every page's content.
A parametrized shell each page invokes with its own key is the sanctioned form.
The entity block's content stays in its page module; the shared head's boundary
and adoption sequence are in [page-head.md](../architecture/page-head.md).

**Default framework views for un-implemented pages.** The framework ships a
real, one-per-file admin page under
`@hilos/vue/src/admin/` (grouped by section as above) — each a thin component
that renders the page-agnostic `HilosAdminPage` shell for its key until it grows
real content. `hilosAdminViews()` from the view kit's `/admin-views` returns
`Record<string, PageView<Component>>` in Vue (React uses `ComponentType`, Angular
`Type<unknown>`). It creates lazy descriptors importing the public section
entries; it imports no page implementation eagerly. A project calls it once for
its stable map and spreads the result before its own bindings.

Pages needing a project context are deliberately absent: for example users/user,
settings, backup, maintenance, live logs/daemon/communications pages, Change Log,
Analytics person/browser-session and the built I18n lists/details. The current
layer's `src/admin/hilosAdminViews.ts` is the exact default-key set, including
staged defaults; do not infer inclusion from the section name. A consumer mounts
each context-bound wrapper explicitly. Unbuilt defaults remain real files an
author can fill, rather than copies in every project.
It is **not** the revoked God-map: every page is its own module file (no page
content or metadata map in any project), the catalog of identity stays in the
backend and arrives with the page's answer, each file renders the page-agnostic
shell, and `HilosView` selects the page from the navigator. To implement a page,
replace its file's shell body with real content and strike that layer from
`HILOS_UNBUILT_PAGES` in `@hilos/core` (remove the entry when no layers remain);
a consumer overriding an unbuilt key names it and its section in `bootHilos`
`projectViews`. An unbuilt page is listed for each layer that has not built it:
the router draws 404 `not_served` without subscribing, and dashboard/child cards
omit it. A section stays in the registry while none of its pages is built in
that layer; checked automatically by `UNBUILT-PAGE` against the views and the
PHP catalog tree. A child page may be built in a layer before its parent only
when the parent is a page with content of its own — a list or a table that its
mockup draws — and never when the parent is a navigation section: a section
opens with its first built child. The child is then served by direct URL while
the parent still answers `not_served`. The leaf whose plan builds that child
adds one row to `STAGED_CHILDREN` in
`framework/frontend/codestyle/stagedChildren.ts` — layer, parent key, child key
and its own `HIL-<n>` — in the same change and without another interview: the
plan that staged the two pages already decided it, and the row only records the
decision. A row expires by itself: once the parent is built in that layer, or
the child is listed unbuilt again, `UNBUILT-PAGE` reports the row as spent, and
the change that spent it removes it. The rows of the languages and the countries
lists were removed when those lists were built in Vue (HIL-1474, HIL-1475). No
other built child may hide under an unbuilt parent.

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
- A page folder named anything other than the page key (outside the Hilos admin
  section, which groups by section rather than one folder per page — see "One
  folder per page" above).
- A "page map" / "admin map": one file enumerating many pages' titles, leads,
  parents, or render logic and wiring every key to a single shared component,
  instead of one module per page (see "Never a shared page map"). Mixing more
  than one page's content or render into a single file is a gross violation.
- An action, a companion signal, or a standalone catalog request the page needs
  before it can render — a second round trip for the first render. The page
  subscription answers with everything the page renders; see
  [signals/subscriptions.md](../signals/subscriptions.md).

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

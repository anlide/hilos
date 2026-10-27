# Frontend Page Registry

How an end project declares the set of pages it has and binds them to the SDK:
the project's `pages/` folder (page keys, routes, entity-slot types) and the
entry-point wiring that turns them into a live page subscription.

This is the **app-level page topology**, distinct from
[page-module-structure.md](page-module-structure.md), which governs the files of
**one** page under `views/`. The registry says *which pages exist and how a URL
maps to them*; a page module says *how one page renders*. The page keys in
`pages/keys.ts` feed both — the router in the registry and the page→view map in
the app shell.

The framework owns the repetitive half of this wiring, so a project declares
only what is its own (its keys, routes, and entity-slot types) and binds the
rest through three `@hilos/core` exports. A project does **not** re-implement
the page-subscription plumbing.

## The `pages/` folder

A project keeps its page registry in one `pages/` folder under `src/` (Angular
keeps it under `src/app/`). It holds the app's page topology — never a page's
view files, which live in `views/<Page>/`:

| File | Holds | Required |
|---|---|---|
| `keys.ts` | the app's page-key constants | always |
| `routes.ts` | the page-key → route-declaration map and the `router` | always |
| `entityTypes.ts` | per-slot canonical entity types for page payloads | only when page payloads carry entity slots |
| `pageTitles.ts` | the page-key → browser-tab title map and the `appName` | recommended (an accessible document title) |

- **`keys.ts` — page keys.** A `const` per page, mirroring the demo-specific
  rows of the backend `PageConstants`. Each value is the subscription wire
  identity, so it must stay byte-for-byte equal to its backend page key. The
  framework `hilos_*` admin keys are **not** restated here — they come from
  `@hilos/core` (`HilosPages` / `HILOS_ROUTE_DECLARATIONS`). `keys.ts` is the
  single source of the page-key names: the app shell's page→view map and the
  router both import from it.
- **`routes.ts` — the route registry.** The app's `APP_ROUTES` map (page key →
  `HilosRouteDeclaration`) and the exported `router`. A declaration is
  `{ path, admin }`: the cold-load URL template, `{name}` marking a route param,
  and whether the page is an **administrative surface**. The router is built with
  `createAppPageRouter` (below), which mounts the framework admin catalog under
  the app's routes — `routes.ts` never spreads `HILOS_ROUTE_DECLARATIONS` by
  hand.

  `HilosRouteDeclaration.paramPatterns` is an optional
  `Readonly<Record<string, string>>` of regular-expression sources. Each source
  must match the entire decoded value captured for that parameter; `path()` also
  checks the raw parameter value before encoding it. An absent optional slot is
  unchecked. A mismatch continues to the next route declaration, while an
  invalid value passed to `path()` returns `undefined`. A capture that cannot be
  decoded is a route miss, and successful captures are decoded exactly once.
  Static routes keep their existing precedence. The Analytics browser-session
  detail declaration precedes the optional-period list declaration and constrains
  its id to digits with an optional leading plus: `/64` and `/+64` resolve to the
  detail page, while `/today` and `/unknown` fall through to the list. This is a
  route-specific constraint, not a general singular-URL naming convention.

  **`admin` is required on every row and has no default.** It names the surface
  *type*, not any one feature that reads it — say `admin`, never
  `showsThePassForm`. An optional flag would make a forgotten row silently
  public, which is the failure this declaration exists to prevent: the
  protected-mode verifier's code field is shown only where `admin` is true, so a
  missed row hides the field from the person who needs it. The address is not the
  rule — a project is free to mount its admin outside `/hilos` — which is why the
  verdict is declared per route rather than derived from the URL. The match the
  router returns carries the flag as `PageRouteMatch.admin`, so a reader that
  already knows the page needs no second lookup.
- **`entityTypes.ts` — page payload entity types (optional).** The
  `pageEntityTypes` map from a wire slot key (its backend collection name) to
  the canonical entity type the normalizer dedupes it under
  ([data-model.md](data-model.md)). It exists **only** when the app's page
  payloads carry entity slots; a one-page app with no entity-bearing slots omits
  it. It is frontend config, never emitted on the wire — keep it in sync with
  the backend browser sources ([rules-and-violations.md](rules-and-violations.md)).
- **`pageTitles.ts` — document titles (recommended).** The `pageTitles` map from
  a page key to its browser-tab title, plus the `appName` composed into every
  title. `bootHilos` merges it with the framework admin/footer labels into
  `HilosRouter.currentTitle`, which the app shell binds to `document.title` and a
  page-change live region (WCAG 2.4.2 Page Titled). A project lists only its
  **own** pages — framework admin and footer pages are titled from their
  catalogs. Frontend config, never emitted on the wire.
  For an adopted entity owner, read
  [page-head.md](../architecture/page-head.md#one-reply-and-live-name) before
  binding its live name. A page view calls
  `bindPageInstanceName(scopes, ownerPageKey, name)` during setup and disposes
  the returned function when it unmounts. The binder keeps the reactive source;
  `pageIdentity()` uses it for matching existing crumbs and the optional
  `pageHeadOwner` label while a page scope is active. It is exported from
  `@hilos/core/base` and the compatibility root from the same implementation.
  `bootHilos` reads the identity inside its reactive title closure, and
  `resolvePageTitle` chooses project `pageTitles`, framework footer label, live
  owner label, then the page's catalog label. Country's Vue pages bind their
  page-scoped card name this way. This adds no static name map or boot setting,
  and does not change the route: country detail pages keep their stable
  `countryCode` address when a display name changes. Owners not adopted keep
  their current path.

Do **not** add a barrel `index.ts` inside `pages/` — import each file by its own
path (`./pages/keys`, `./pages/routes`). The folder is small and self-contained.

## The public Status route

`HilosPages.PUBLIC_STATUS` is the narrow exception to page-key mirroring: the
framework defines it as `'status'` with `{ path: '/status', admin: false }` in
`framework/frontend/core/src/routing/hilosPages.ts`. It is a framework-owned
client route, so an app does not duplicate it in `pages/keys.ts` or
`APP_ROUTES`. There is deliberately no matching backend `PageConstants`,
`Hilos::PAGES` entry or catalog record: nginx files supply its contents, and it
does not subscribe or consume `page_response`. Do not invent a backend page or a
generic subscription bypass for it.

The router checks the view layer's unbuilt set before this exception. A built
view leaves any previous subscription and does not open another; an unbuilt
React or Angular view is still refused by the ordinary unbuilt-page guard.
Chat and Binance BTC Tracker mount the Vue reader through their lazy view maps
with `lazyPage()`. The shared title resolver supplies the framework label
`Status`; no backend catalog heading is involved. These are implementation
facts for this route, not a new rule for ordinary pages. App page-key mirroring,
subscription waiting, access handling and the action lifecycle remain unchanged
for every ordinary page.

## What the SDK provides

Three `@hilos/core` exports carry the half of the wiring that is identical in
every project, so a project never restates it:

- **`createAppPageRouter(appRoutes)`** — builds the app's router
  as the framework admin catalog (`HILOS_ROUTE_DECLARATIONS`) overlaid with the
  project's declarations (a project route wins on a key collision, taking over
  its `admin` flag along with its path). `createPageRouter(routes)` stays the
  pure, project-agnostic matching engine; a project always uses
  `createAppPageRouter` so the admin section mounts the same way everywhere.
  Both take only the route map. An unmatched path resolves to
  `{ page: null, params: {}, admin: false }`: the result stays an object, and
  `null` is only on `page`. That miss subscribes nobody — no stand-in page, no
  URL key, and no fallback — so a typo in an administrative url is not an
  administrative surface, because the miss carries `admin: false`.
  `HILOS_PAGE_ROUTES` remains the page-key → path map, derived from the
  declarations, for the readers that only ever want a URL (the shell's gear and
  footer hrefs, breadcrumb paths, the prerender entries).
- **`PAGE_SIGNAL_SCHEMAS`** — the `{ page_response: pageResponseSchema }` map,
  ready to spread into the connection's `projectSchemas` so the parse boundary
  validates page payloads. The framework owns this schema; a project never
  restates the pair.
- **`bindPageScope(connection, scopes, { entityTypes? })`** — opens the
  `PageSubscription` manager over the app's scopes and routes every
  `page_response` project signal into the current page scope (dropping a late
  one for a page already left). It returns the manager the navigator subscribes
  the URL's page through. The only project input is the per-slot `entityTypes`
  for the page payloads; an app with no entity slots passes none.

## Entry-point wiring

The registry is consumed in two places at startup:

- **`connection.ts`** spreads the page schema into the connection so page
  payloads pass the parse boundary:

  ```ts
  import { HilosConnection, PAGE_SIGNAL_SCHEMAS } from '@hilos/core'
  // ...
  projectSchemas: { ...appSignalSchemas, ...PAGE_SIGNAL_SCHEMAS }
  ```

- **the entry point** (`main.ts` / `main.tsx`) binds the page scope and hands
  the returned manager to the navigator:

  ```ts
  import { bindPageScope, createHilosRouter } from '@hilos/core'
  import { scopes } from './session'
  import { router } from './pages/routes'
  import { pageEntityTypes } from './pages/entityTypes' // when the app has them
  // ...
  const pages = bindPageScope(connection, scopes, { entityTypes: pageEntityTypes })
  const hilosRouter = createHilosRouter(router, pages, browserNavigationEnvironment())
  ```

The app shell (`App.vue` / `App.tsx` / `app.ts`) maps page keys to components,
importing the keys from `pages/keys` — never the router:

```ts
import { PAGE_MAIN } from './pages/keys'
import { HilosPages } from '@hilos/core'
// ...
const pages = { [PAGE_MAIN]: Main, [HilosPages.DASHBOARD]: Dashboard }
```

## Per-demo specifics

The three conformance demos exercise the registry at different sizes:

- **chat (Vue)** — the full registry: seven page keys, static and `{id}` param
  routes, and an `entityTypes.ts` (`users` / `bots` / `events` /
  `eventAttachments`) wired into `bindPageScope` so a message author dedupes
  against the bot the list delivered. `pages/` holds `keys.ts`, `routes.ts`,
  `entityTypes.ts`, `pageTitles.ts`.
- **tasks (React)** — `pages/keys.ts`, `pages/routes.ts`, and
  `pages/pageTitles.ts`. One page, no entity-bearing page slots, so there is no
  `entityTypes.ts` and the bind is `bindPageScope(connection, scopes)`.
- **polls (Angular)** — the same registry as tasks, kept under
  `src/app/pages/` per the Angular layout.

## Violations

- Restating `{ page_response: pageResponseSchema }` in a project instead of
  spreading `PAGE_SIGNAL_SCHEMAS`.
- Re-implementing the `projectSignal` → `ingestPageResponse` plumbing in a
  project (a hand-written `pageScope.ts`) instead of `bindPageScope`.
- Spreading `HILOS_ROUTE_DECLARATIONS` (or `HILOS_PAGE_ROUTES`) into a route map
  by hand instead of using `createAppPageRouter`.
- Declaring a route without saying whether it is administrative — or naming the
  flag after the feature that reads it rather than after the surface type.
- A barrel `index.ts` inside `pages/`.
- A page key in `keys.ts` that diverges from its backend `PageConstants` value,
  or framework `hilos_*` keys restated in `keys.ts`.
- An `entityTypes.ts` present when the app's page payloads carry no entity
  slots.
- The app shell or a view importing the page key from `routes.ts` (pulling in
  the router) instead of from `keys.ts`.

## Contract note

The page keys, routes, and the `page_response` payload shape are part of the
FE↔BE wire contract: adding, removing, or renaming a page key or route, or
changing the page payload shape, passes the Contract approval gate in
[agents.md](../../../agents.md) — the backend `PageConstants` and the page
catalog mirror it. This rule governs the project's file layout and SDK binding,
not the wire.

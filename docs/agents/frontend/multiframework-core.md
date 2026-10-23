# Multi-Framework Core

The product is built in Vue, but Hilos is a framework, so it provides for React
and Angular from the start — this is the one moment to organize cross-framework
compatibility cheaply, before pages scale. The mechanism is a **framework-agnostic
core** in plain TypeScript with **thin per-framework view layers** over it.

## The agnostic core owns all non-visual logic

A single core, plain TypeScript, owns everything that is not rendering:

- WS transport, handshake, and reconnect ([wire-protocol.md](wire-protocol.md),
  [core-and-connection.md](core-and-connection.md));
- the signal / action protocol and its discriminated-union parsers
  ([wire-protocol.md](wire-protocol.md));
- the subscription manager — page, group, and the sort-key table window
  ([table-subscription.md](table-subscription.md));
- the normalized entity store and the per-scope stores ([data-model.md](data-model.md));
- table logic (filter / sort / paginate / pending) as **headless state
  machines**;
- modal and conflict logic as **headless state machines**
  ([conflict-resolution.md](conflict-resolution.md)).

A view layer renders over this core and emits user intents; it holds no protocol,
store, or table logic of its own.

## The core never imports a framework

The agnostic core **never imports Vue** (or React, or Angular) — only a neutral
signal primitive and plain TypeScript. This is the rule that keeps it portable: a
Vue import in the core is a leak that the conformance demos below exist to catch.

## The signal primitive

"Off Pinia" means adopting an **existing** reactive signal primitive, never
hand-rolling one. The default is **`@vue/reactivity`** — a standalone package that
runs without Vue: the Vue adapter is then nearly free, React adapts via
`useSyncExternalStore`, and Angular via signals / `effect`. The choice is
reversible because it sits **behind the store API**; neutral alternatives
(`alien-signals`, `@preact/signals-core`) could replace it without touching
consumers.

## Per-framework view adapters

Each framework gets a thin adapter over the core:

- **Vue** — the primary view layer and the canonical product (the chat demo);
- **React** — adapts via `useSyncExternalStore`; the likeliest real adopter;
- **Angular** — adapts via signals / `effect`; it stresses the seam hardest (DI,
  zones, RxJS), so it is the most valuable portability proof.

The reference resolver (`useEntity` in Vue) is a per-framework wrapper over the
same plain-function core selector ([data-model.md](data-model.md)).

## Page code and first-answer state

`@hilos/core/base` owns the runtime page descriptor and the outlet state:

```ts
interface LazyPageView<T> {
  readonly kind: 'lazy-page'
  readonly load: () => Promise<T>
}
type PageView<T> = T | LazyPageView<T>
// lazyPage<T>(load: () => Promise<T>): LazyPageView<T>
// createPageViewState<T>(router: HilosRouter): PageViewState<T>
```

`lazyPage(() => import('./views/Settings/Settings.js').then(m => m.default))`
constructs a descriptor without invoking the loader. An eager component remains
an ordinary `T`, including a React function component: callability is never the
loader heuristic. Create descriptors once at module scope or once per component
instance (Vue setup, Angular field), outside repeated rendering. Cache identity
is the descriptor object, not the page key, URL or loader function.

`PageViewState<T>` exposes `view: ReadonlySignal<T | undefined>`,
`loading: ReadonlySignal<boolean>`, `error: ReadonlySignal<PageError | null>`
and `retainedOnError: ReadonlySignal<boolean>`, plus
`setPages(pages: Readonly<Record<string, PageView<T>>>): void`,
`retainForResourceDeletion(page: string): Unsubscribe` and `dispose(): void`.
All three `HilosView` implementations adapt this one core machine to their
framework's signals and render it; no `React.lazy`/`Suspense`,
`defineAsyncComponent` or Angular `@defer` wrapper is required.

`retainedOnError` is true while `view` keeps the component already mounted under
the current refusal: `error` is that refusal, `loading` is false, and the
adapter draws no `ErrorPage` of its own, so the same page instance and the
windows it keeps open stay for its shell to draw the error. The shell registers
with `retainForResourceDeletion(page)` once its page is mounted and gets a
disposer that removes only its own registration. A live 404 `not_found` carrying
`transitionMessage` starts the hold on that page, route and component, and a
later plain 404 of the same subscription continues it. A full answer, any other
status or code (a loss of access included), a navigation, a params change, a new
descriptor and `dispose` release it. The registration lives in one outlet's
closure, not in `Hilos::$db` or `Hilos::$rt`.

Each `HilosView` hands the shell only that function, never the state, through an
internal bridge: `hilosPageErrorBoundaryKey` (Vue provide/inject),
`HilosPageErrorBoundaryContext` (React context) and `HILOS_PAGE_ERROR_BOUNDARY`
(Angular token, set through the outlet's `ngComponentOutletInjector`). Outside
an outlet nothing is provided and a shell stands alone, drawing the error
itself. The shell side is in
[page-head.md](../architecture/page-head.md#deleted-while-open).

Each outlet instance owns its cache. Selections sharing a descriptor share an
in-flight promise; successful components are reused on return. A late completion
may fill that outlet's cache but cannot publish over a newer selection: a
generation and the selected descriptor guard publication. `setPages` notices a
replacement descriptor at the active key. Rejected entries leave the cache;
after leaving and selecting the page again, core invokes the loader again.
`dispose` detaches subscriptions, clears the cache and prevents late publication.
It does not cancel a JavaScript import or create a process-wide component cache.

`HilosRouter.pageLoading` still means waiting for a server answer. The composed
outlet state waits for **both** code and the first permitting page reply before
exposing `view`. The router calls `onLeave` before publishing the next route;
core keeps the previous view settled until all departure listeners have copied
it. `bindPageDeparture` accepts optional composed `loading` and `error` signals
(defaulting to the router's server-only signals). The adapters supply them, so
the inert, `aria-hidden` copy stays until readiness, refusal or the existing
300 ms delayed skeleton replaces it. One continuous code/data wait has one
skeleton deadline: finishing code while data is pending, or vice versa, does
not restart the timer. A reconnect resubscription does not hide an already shown
page or raise a fresh skeleton.

The server's page error wins over a loader outcome. An import can start while
the access verdict is unknown; it grants no permission to mount, and its late
success cannot erase a refusal. An anonymous 401 uses the registered auth
surface; other errors use `ErrorPage`. Unknown addresses, unbuilt pages and
known refusals need no artificial loader to show their existing outcome.

A loader rejection uses the existing local `PageError` presentation shape:
`httpCode: 503`, `errorCode: 'page_load_failed'`,
`title: 'Page could not be loaded'`,
`message: 'Please try opening this page again.'`. Raw `Error.message` never
reaches this fallback. Like `ROUTE_NOT_FOUND_ERROR`, it carries a `TODO(i18n)`
at its definition; it is not a signal or a new wire DTO. The existing ErrorPage
and SPA home navigation handle it, without a new Retry control or forced reload.

Invoking the loader again does not promise another HTTP fetch. In Chromium's
native failed-ESM module map, importing the same failed URL can reject again
without a request; this was observed against all six production artifacts.
Core retries the loader on a later selection, not the browser's module cache.
The build-mismatch whole-app refresh remains a separate, unchanged mechanism
([build-and-docker.md](build-and-docker.md#lazy-page-code-in-the-client-build)).

## Eager protocol and shared ownership

`createHilosConnection` merges every framework schema group before it returns
the unopened connection, hence before the socket or a first admin visit. Project
schemas are spread last. Lazy admin code does not delay registration or change
schema shapes, permissiveness, values or merge order.

Existing mixed definitions have their single implementation under `protocol/`:
backup, logs, maintenance, legal-acceptance and account-standing signals, plus
setting-preset schemas. Root and former direct-module exports reexport those
same values/types; they do not build new schema objects. Shared shell selectors
still live at `admin/identity/hilosPageIdentity.ts` and `admin/viewMode.ts` — an
`admin/` directory name alone does not make a module a lazy implementation.
Restore progress and its formatters/clock have their common owner at
`connection/restoreProgress.ts`, reexported by base, root, `admin/backup` and the
former `hilosBackups` module. The root-facing `hilosLegalDocumentLabel` has its
separate owner at `legal/legalDocumentLabel.ts`, shared by root/base/legal section
and the former direct admin module. The distinct label inside `legalAgreements`
retains its existing behavior. Each implementation has one `tsc` owner.

Controllers, models, table factories and page-only validators remain with their
lazy admin sections. There is no blanket exemption for anything called
headless or protocol: the initial-graph proof checks actual source bodies,
compiled owners and static edges. See
[sdk-packaging.md](sdk-packaging.md#public-entrypoints-and-their-graph) and
[build-and-docker.md](build-and-docker.md#initial-client-graph-and-budget).

## Templates cannot be shared

An honest constraint: templates **cannot** be shared across Vue, React, and
Angular. Two options were weighed:

- **(a) per-framework view layers** — templates are triplicated but each is thin,
  over the one shared core (**chosen**);
- **(b) a web-components layer** (e.g. Lit) all three embed — rejected for v1 due
  to interop friction (forms, SSR, styling).

So the triplication is deliberate and bounded: only the thin view renders, never
the logic.

## Primitives: a headless controller plus a thin view

A UI primitive — `LoadingButton`, `HilosModal`, `HilosViewportTable`, the conflict views —
is split the same way the whole SDK is: a **headless controller** in the core and a
**thin view** per framework.

- **The controller** (`core/src/primitives/`, plain TypeScript, no DOM) owns the
  primitive's state and behaviour and exposes it as signals plus imperative
  methods, computing a ready-to-render view-model where that saves the view work.
  `createLoadingButtonState` is the smallest example: it owns the delayed-spinner
  timer and exposes `showSpinner` plus `setLoading` / `dispose`.
- **The view** (each view package's `src`) renders that controller: it mirrors the
  controller's signals through the one generic adapter — `useSignal` (Vue / React),
  `hilosSignal` (Angular) — drives user intent back in by calling methods, disposes
  on unmount, and holds no logic of its own.

**The floor.** Only logic with state, timing, or subtlety goes to the controller —
a debounce, a focus trap, a 3-way merge, table sort / paginate. A trivial pure
derivation (`disabled || loading`) or a one-line event guard stays in the view;
pulling a one-liner into the core is noise, not portability. That derivation is
the button's lock: `loading` keeps the action from running as surely as
`disabled` does, whatever the button looks like while its spinner waits. The
`LoadingButton` of Vue and of React feeds it to `aria-disabled` and its click
guard, and only the independent reasons to the native `disabled`; Angular still
feeds the whole of it to the native attribute. Angular feeds it the Vue way
(not in the code yet — HIL-1768).

**The slot seam.** Templates can't be shared, but a primitive that projects
consumer content still shares one contract: the **core owns the data** that flows
into a slot (the view-model the controller resolves — e.g. a table's resolved
row), and **each framework owns the slot mechanism** (a Vue scoped slot, a React
render prop or `children`, an Angular `ng-content` / `ng-template` context). The
controller never knows how content is projected.

**Shared DOM effects.** When an effect is identical across frameworks and must
touch the DOM — a focus trap, a scroll lock — it is written once as a browser-only
`core/dom` helper the views call, not retriplicated. It is introduced with the
first primitive that needs it.

The idiomatic binding of one primitive in each framework is shown in the
per-engine guides ([frontend-vue.md](../../new-project/frontend-vue.md),
[frontend-react.md](../../new-project/frontend-react.md),
[frontend-angular.md](../../new-project/frontend-angular.md)).

## Conformance demos, not full parity

This is about the **demo apps** — the React and Angular *pages*. They are **not**
held to full parity with the Vue product — that is unsustainable. Instead, a small
slice that exercises the core's hardest seams — connect / auth → subscribe → table
+ pending → edit-in-modal + conflict — is built on React and Angular as **minimal
conformance demos**, kept green in CI.

The SDK **primitives** are the exception: the reusable toolkit (`LoadingButton`,
`HilosModal`, `HilosViewportTable`, `HilosDropdown`, the conflict and breadcrumb
views) *is* built in all three view layers at full parity, because a React or
Angular adopter needs the real component, not a stub. The bound demo is minimal;
the primitive it binds is complete. An SDK page may land in Vue before React and
Angular, and `SHELL-PARITY` learns it from `HILOS_UNBUILT_PAGES` instead of from
an exception of its own. The languages and the countries lists were two
(HIL-1474, HIL-1475): built in Vue first, and compared in React and in Angular
only from the ports that struck those layers (HIL-1502, HIL-1503). A page's
files are its Vue view — the file that
binds its key to `HilosAdminPage`, or its entry in `hilosAdminViews.ts` — and
every file under `vue/src/admin/` that the view reaches by relative import
without leaving `admin/`, together with a root `index.ts` export resolving to
one of them. Such a file needs no counterpart in a layer where every page
reaching it is still listed unbuilt; as soon as one of them is built there, it
is compared there. Everything outside `vue/src/admin/` is an SDK primitive and
is always compared — a primitive lives there even while only staged pages use
it. The port that builds the page strikes its layer from the registry, which
`UNBUILT-PAGE` demands, and that is what turns the page's parity on. Two thin
extra consumers *prove* the core is agnostic; a Vue-only codebase cannot (one
consumer hides leaked assumptions). These demos are the simplest of the
project's planned demos, so they double as real demos and as portability proofs.

Unit tests run framework-free against the core (most logic needs no browser); the
React slice uses vitest + `@testing-library/react`, and the Angular slice uses
vitest directly, mounting components through `TestBed` in jsdom — not the CLI's
native unit-test builder, which is experimental and leaves the DOM adapter
unreachable ([testing-strategy.md](testing-strategy.md)).

## Sequencing

Build the slice in **Vue first**, then port that same slice to **React, then
Angular, early** — before scaling pages — so portability is proven while the core
is still small and cheap to change. After that, every new core capability stays
green in all three demos as it lands.

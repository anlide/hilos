# SDK and Packaging

Part of the code lives in the framework SDK and part in the consumer project. The
SDK ships full, slot-extensible components so a project customizes by filling
slots, not by re-implementing templates — and a project's IDE resolves everything
correctly. Two concerns interlock here: how the code is developed (a monorepo)
and how it is shipped (vendored through Composer).

## The framework / project split

The SDK provides components, the agnostic core, and the framework machinery; the
project supplies its pages, its credential verifier, and its customizations.
Ideally a great many framework features appear in the project as **"empty
inheritance"** — a one-line re-export of an SDK component, customized only through
props and slots, never re-implemented. Duplicated component code is the anti-goal
(it is what the rewrite exists to remove).

## Dev monorepo workspace

Two needs pull in opposite directions: real package `exports` / `main` (so
consumers and PhpStorm resolve the SDK without deep-pathing into `src`) versus
live HMR into SDK source during development. A **monorepo workspace** (pnpm or npm
workspaces) with **conditional exports** resolves both:

- a `development` (source) condition resolves to `src` — HMR works while
  developing the SDK and a demo together;
- a build condition resolves to `dist` — consumers and the IDE get the built,
  typed package.

The workspace contains only the SDK packages — the agnostic core and the view
layers. Demos and end-projects (including the React / Angular conformance demos,
[multiframework-core.md](multiframework-core.md)) are **consumers**, never
members: each lives in its own project and pulls the SDK (a local `file:`
dependency in dev, the vendored tarball when shipping). The `development`
dev-link is what lets a Vite consumer's bundler resolve the SDK to `src` for HMR
while the two are developed together; an Angular consumer reaches the same
dev-`src` / prod-`dist` split through tsconfig `paths` instead, because
ng-packagr owns its dist package's export conditions
([new-project/frontend-angular.md](../../new-project/frontend-angular.md)).

**Dedupe the view framework in every consumer.** The `file:` dev-link resolves
through the symlink's real path, so the SDK can reach a SECOND copy of the view
framework in the SDK workspace's own `node_modules` (installed there for the
adapter unit tests). Two copies break framework-internal context — React's
dispatcher dies on hook calls, Angular's `inject()` dies with NG0203 — while
`build` output may stay warning-free (a bloated bundle is the tell). Each
consumer pins its single copy by its own bundler's mechanism: Vite + React =
`resolve.dedupe: ['react', 'react-dom']`; Vite + Vue = nothing (plugin-vue
auto-dedupes); Angular CLI = `preserveSymlinks: true` plus tsconfig `paths`
pins for `@angular/core` and `@angular/common` (the SDK is compiled from `src`
in dev, which pulls a second copy of each into the app build — NG0203 / NG3004)
— the full recipe is in
[docs/new-project/frontend-angular.md](../../new-project/frontend-angular.md).

**Angular consumers also declare `@vue/reactivity`.** The core's signal engine
is `@vue/reactivity` (private — app code never imports it). A real tarball
install hoists it; the monorepo `file:` link satisfies it from the SDK
workspace copy and leaves it out of the consumer's `node_modules`. The core's
`@vue/reactivity` is external to the SDK artifact in both Angular modes (dev
compiles the core `src`, prod consumes its FESM), so the bare import resolves
from the consumer root and fails — so an Angular consumer adds `@vue/reactivity`
as a direct dependency. The Vite-Vue/React demos prebundle it through the
symlink's real path and need nothing. Full recipe in
[docs/new-project/frontend-angular.md](../../new-project/frontend-angular.md).

## Distribution: a Composer-vendored tarball

Distribution is **separate** from the dev monorepo and is **Composer-only for
v1** — no public npm publish. The SDK build runs `npm pack` to produce a `.tgz`,
which ships inside the Composer package; a consumer project references it as a
`file:` dependency into `vendor/`. Every package manager **extracts a tarball as a
copy** — never a symlink (symlinks are rejected; they have caused real pain).
There is no custom installer: the only recurring step is `npm install` after a
`composer update` re-pulls Hilos, and that step is documented (a README note
and/or a composer `post-update-cmd` reminder) so a consumer never runs a stale
vendored SDK.

A consumer project is **not** part of the monorepo — it vendors Hilos through
Composer. (A spike alternative, if the built `dist` is self-contained, is a pure
Vite `resolve.alias` + tsconfig `paths` straight into `vendor/.../dist`, removing
the install hop; see [build-and-docker.md](build-and-docker.md).)

## Keep the agnostic core a separate package

The agnostic core is its **own** workspace package, cleanly split from the Vue
view layer. v1 ships only through Composer, but keeping the core separate means
publishing **just the core** to npm later — for a pure-JS or non-PHP consumer —
stays a cheap, additive option, never a v1 channel.

## Template extension, not re-implementation

TS inheritance is clear; "template inheritance" is the real question. The answer
in Vue: the SDK ships **full** components with extension points via **slots,
scoped slots, and composables**. A project customizes by filling slots, swapping
sub-components, or passing config — and **never** by re-implementing a template.
**No mixins.** Re-implementing an SDK component's template to change it is the
duplication this model exists to prevent.

## Two tiers of components

The SDK component library is two tiers, differing in how much extension surface
they expose.

### Tier 1 — universal components

Modal, loading-button, toast, inputs, table shell, and the like. These offer the
**maximum** extension surface, authored slot-first with scoped slots and
composables so a project can customize them heavily.

### Tier 2 — page-chunk components

Feature pages (logs, backups, users, settings). These are more opinionated, with **fewer**
extension points; a project customizes them by composing tier-1 primitives and a
few slots, and replaces one wholesale only when it truly diverges.

The Hilos admin pages are the bulk of tier 2. They live under
`@hilos/vue/src/admin/<section>/` (view) with any headless under
`@hilos/core/src/admin/<section>/`, grouped by section to mirror the backend
`Pages/Hilos/<Section>/` layout — one file per page, named `Hilos<Remainder>Page`.
The framework ships a real default page for every admin key, collected by
`hilosAdminViews()`, so a project mounts the whole section for free and overrides
only the pages it customizes (see
[page-module-structure.md](page-module-structure.md)).

The mechanism across both tiers is the same — slots + scoped slots + shared
composables, no mixins — and "empty inheritance" (a one-line re-export) is the
default when nothing is customized.

## Tier-1 cross-cutting components

Several tier-1 components are part of the contract, so pages never reinvent them:

- the **`HilosLayout`** application shell — the navbar (project brand and nav
  slots, the admin gear, the live connection indicator) and a footer of the
  public framework pages, around the routed page content. The shell is a
  fixed-height viewport column whose main region owns the scroll, so a page
  either scrolls inside it or fills it and scrolls an inner region; the footer
  links come from the framework (`HILOS_FOOTER_LINKS`), so every project shows
  the same About / Terms / Privacy / License set and supplies only each page's
  content;
- **`HilosStaticPage`** — the frame for a static, content-only page (the public
  About / Terms / Privacy / License pages and the like): a centered reading
  column with a heading, the project filling the body. These framework pages are
  declared in `@hilos/core` (`HilosPages` / `HILOS_PAGE_ROUTES`) and subscribe
  like any page, but the backend page carries no payload — the visible content is
  the project's own view;
- a **`HilosErrorBoundary`** wrapping each page or major block, so one
  component's runtime error degrades locally instead of blanking the long-lived
  SPA;
- standard **skeleton / empty / error** components for a data block's three
  states — the concrete form of "a placeholder for everything"
  ([core-and-connection.md](core-and-connection.md)).

The skeleton is a **data-block** loading state — it fills a block while that
block's data streams in, not a wait on a code chunk: the app ships as a single
application chunk with no per-page splitting ([build-and-docker.md](build-and-docker.md)).

## IDE correctness

Real `exports` / `main` plus the built `dist` and types are what let PhpStorm and
the TypeScript language service resolve SDK symbols correctly in a consumer
project — the reason distribution ships a built package, not raw `src`.

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
symlink's real path and need nothing. The same holds for every runtime
dependency the core takes — today also `qrcode-generator`, the QR library behind
`qrMatrix` (HIL-494). Full recipe in
[docs/new-project/frontend-angular.md](../../new-project/frontend-angular.md).

## Angular versions: exact in both roots, a range only in the peers

**Both npm roots that carry Angular declare every `@angular/*` of their
`dependencies` / `devDependencies` exactly, and both declare the same version.**
The roots are the SDK workspace `framework/frontend` — its Angular entries live
in the manifest of the `@hilos/angular` view layer,
`framework/frontend/angular/package.json` — and `demo/polls/frontend`, that
layer's consumer and the Angular conformance demo. No other `package.json` in
the tree names an `@angular/*` package. In those two fields the SDK manifest
declares `@angular/compiler`, `@angular/compiler-cli` and
`@angular/platform-browser`; the demo declares `@angular/common`,
`@angular/compiler`, `@angular/core` and `@angular/platform-browser`, plus
`@angular/build`, `@angular/cli`, `@angular/compiler-cli`,
`@angular/platform-server`, `@angular/router` and `@angular/ssr`. Every one of
them reads `22.0.1`, with no caret and no tilde. `22.0.1` is today's fact, not a
target to keep: what the rule fixes is that the entries are exact and equal
across both roots, whatever the number is. The reason sits inside Angular. Its
packages hold each other to an exact version in their own `peerDependencies` —
`@angular/core@22.0.1` declares `{"@angular/compiler": "22.0.1"}`, readable in
`framework/frontend/package-lock.json` — so one caret resolved a minor ahead of
its siblings drags the whole framework in, and the lockfile shows it as an
ordinary neighbour bump.

**Three kinds of entry beside them are ranges on purpose; do not "fix" them into
exact versions.** The `peerDependencies` of `@hilos/angular` —
`"@angular/common": "^22"` and `"@angular/core": "^22"` — stay a range: a peer
range is what a consuming project resolves against, and the SDK declares the
major it is compatible with, not the version a project must install.
`"ng-packagr": "^22.0.0"` in the same manifest stays a caret: its own peer on
`@angular/compiler-cli` is a range (`^22.0.0 || ^22.1.0-next.0`), so it does not
drag Angular by itself — but it belongs to the same set when Angular is lifted.
`"typescript": "~6.0.3"`, in `demo/polls/frontend/package.json` and in the
workspace root `framework/frontend/package.json`, stays a tilde because
`@angular/compiler-cli` requires `typescript >=6.0 <6.1`. This section rules on
the Angular set and on that tilde; it says nothing about any other range in
either root.

**Add a new `@angular/*` package at the version already in the tree; lift
Angular as one change to both roots.** A new `@angular/*` entry, in either root,
is written as the exact version its siblings already carry. Do not write a
caret, and do not take a newer version because the registry offers one — a bare
`npm install @angular/<name>` does both at once, so name the version
(`--save-exact @angular/<name>@<the version in the tree>`) or write the manifest
line by hand. Upgrading Angular is one deliberate edit of both roots in the same
change: in each root, install that root's whole Angular set at the new version
in one command. Never let Angular move as a side effect of installing something
else, and never leave one root ahead of the other.

**Do not delete a `package-lock.json` to get past an `ERESOLVE` conflict.** The
lockfile is the resolution of every range in its root, not only of the entry
being changed, so deleting it re-resolves all of them. On HIL-974 that carried
137 unrelated versions along in `demo/polls/frontend` (+46 / −100 packages)
while the intended change was ten Angular packages. Instead, install the version
already in the tree, or lift the whole Angular set of that root in one install.

The only step that fails on a mixed Angular is the AOT build of `@hilos/angular`
(`ng-packagr`), i.e. `composer run test:framework:frontend:build`; check, unit,
lint and format-check all stay green on it (HIL-848), and no automated check
reads the manifests for this rule. How an already-declared lockfile is installed
and when that install is skipped is not restated here — see
[build-and-docker.md](build-and-docker.md), *The build and install guards*.

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

The public framework pages are tier 2 as well. A public page is no longer prose
alone — License carries a dependency inventory with a search, a filter, a
per-row modal and an export; Privacy an erase of what this browser holds, behind
a confirmation; About a support modal; Terms the revision this reader accepted —
and none of that behavior has a project-specific half, so the framework owns
it: `HilosAboutPage`, `HilosTermsPage`, `HilosPrivacyPage` and
`HilosLicensePage` under `@hilos/vue/src/public/` (and the React / Angular
twins), one file per page. Each renders the tier-1 `HilosStaticPage` frame with
the page's own title around two things: the project's prose, taken through the
**default slot** and placed above, and the framework's behavior block for that
page, placed below. The framework owns the frame and the behavior; the project
owns the words. Leaving the behavior as blocks a project pastes in would make
"Privacy without the erase button" indistinguishable from a deliberate choice.

No aggregate collects the four — there is no `hilosPublicViews()`. Each needs a
project-supplied input, which is the same exception `hilosAdminViews.ts` already
makes for `HilosUsersPage` / `HilosSettingsPage` / `HilosBackupPage`: a project
mounts them directly under their `HilosPages` keys. A project that wants its own
License page mounts its own component under `HilosPages.LICENSE` and gets none
of the framework behavior — the existing extension model, and the reason the
prose comes through a slot rather than through configuration. The project's own
view keeps its file and its name (`views/License/License.vue` and the twins) and
wraps its paragraphs in the framework page instead of in `HilosStaticPage`;
nothing else in the project moves — not the page key, not the route, not the
footer link, not the prerender entry's component map. A behavior block takes
its data as an input, never as a fetch from inside the page: `HilosLicensePage`
takes the inventory as a prop, and the project passes the snapshot its own build
produced ([build-and-docker.md](build-and-docker.md), *SSG and the public
surface*). Each page is created by the leaf that first needs it:
`HilosLicensePage`, `HilosPrivacyPage` (not in the code yet — HIL-839),
`HilosAboutPage` (not in the code yet — HIL-840), `HilosTermsPage` (not in the
code yet — HIL-501).

The mechanism across both tiers is the same — slots + scoped slots + shared
composables, no mixins — and "empty inheritance" (a one-line re-export) is the
default when nothing is customized.

## Tier-1 cross-cutting components

Several tier-1 components are part of the contract, so pages never reinvent them:

- the **`HilosLayout`** application shell — the navbar (project brand and nav
  slots, the admin gear, the live connection indicator), a full-width banner
  region below the nav a project fills with an app-wide status strip (e.g. an
  impersonation banner) — empty and zero-height otherwise — and a footer of the
  public framework pages, around the routed page content. The region is the same
  in all three shells and only its delivery differs: Vue takes it through the
  `#banner` slot, React through the `banner` prop, Angular through a projected
  `[banner]` node. The shell is a
  fixed-height viewport column whose main region owns the scroll, so a page
  either scrolls inside it or fills it and scrolls an inner region; the footer
  links come from the framework (`HILOS_FOOTER_LINKS`), so every project shows
  the same About / Terms / Privacy / License set and supplies only each page's
  content;
- **`HilosStaticPage`** — the frame for a static page: a centered reading
  column with a heading, the project filling the body. It frames a project's own
  static pages, and it is what the four public framework pages (tier 2, above)
  render inside themselves; it is neither the public pages' component nor
  widened for their behavior. The public pages are declared in `@hilos/core`
  (`HilosPages` / `HILOS_PAGE_ROUTES`) and subscribe like any page; their
  backend pages carry no payload today — Terms grows one (not in the code yet —
  HIL-501) — so the visible content is the project's prose plus the framework's
  behavior block;
- a **`HilosErrorBoundary`** wrapping each page or major block, so one
  component's runtime error degrades locally instead of blanking the long-lived
  SPA;
- standard **skeleton / empty / error** components for a data block's three
  states — the concrete form of "a placeholder for everything"
  ([core-and-connection.md](core-and-connection.md)). Of the three, the skeleton
  is built — **`HilosSkeleton`** in each view package, which `HilosView` also
  draws in a page's place while the page waits for its first answer.

The skeleton is a **data-block** loading state — it fills a block while that
block's data streams in, not a wait on a code chunk: the app ships as a single
application chunk with no per-page splitting ([build-and-docker.md](build-and-docker.md)).

## IDE correctness

Real `exports` / `main` plus the built `dist` and types are what let PhpStorm and
the TypeScript language service resolve SDK symbols correctly in a consumer
project — the reason distribution ships a built package, not raw `src`.

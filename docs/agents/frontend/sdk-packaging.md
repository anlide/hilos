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
while the two are developed together; the current Angular demo setup reaches a
dev-source / prod-dist split through explicit tsconfig `paths`, because
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
auto-dedupes); Angular CLI = `preserveSymlinks: true`, tsconfig `paths` pins
for `@angular/core` and `@angular/common`, and explicit paths for each public
SDK entry to its real `framework/frontend/{angular,core}/src` root outside
`node_modules`. The source aliases keep nested lazy imports on one SDK graph;
the Angular pins collapse the framework copies that cause NG0203 / NG3004.
Production has no SDK source redirects and keeps consuming APF dist through the
consumer package path. The full recipe is in
[docs/new-project/frontend-angular.md](../../new-project/frontend-angular.md).

**Angular consumers also declare `@vue/reactivity`.** The core's signal engine
is `@vue/reactivity` (private — app code never imports it). A real tarball
install hoists it; the monorepo `file:` link satisfies it from the SDK
workspace copy and leaves it out of the consumer's `node_modules`. The core's
`@vue/reactivity` is external to the SDK artifact in both Angular modes: dev
resolves core directly from `framework/frontend/core/src`, while production
consumes its compiled ES modules. In both cases the bare import resolves from
the consumer root and fails, so an Angular consumer adds `@vue/reactivity` as a
direct dependency. The Vite-Vue/React demos prebundle it through the
symlink's real path and need nothing. The same holds for every runtime
dependency the core takes — today also `qrcode-generator`, the QR library behind
`qrMatrix` (HIL-494). Full recipe in
[docs/new-project/frontend-angular.md](../../new-project/frontend-angular.md).

## Public entrypoints and their graph

Use the public subpath that owns the code. The compatibility root (`@hilos/core`,
`@hilos/vue`, `@hilos/react`, `@hilos/angular`) retains the existing exports and
their identities; it also reaches the admin implementations. A consumer keeping
admin code out of its initial graph imports common code from `/base`, default
view metadata from `/admin-views`, and implementations from `/admin/<section>`
inside the page module loaded for that section.

| Package | Runtime public subpaths beside the compatibility root |
|---|---|
| `@hilos/core` | `/base`; `/admin/analytics`, `/admin/appearance`, `/admin/backup`, `/admin/change-log`, `/admin/communications`, `/admin/daemon`, `/admin/i18n`, `/admin/legal`, `/admin/logs`, `/admin/maintenance`, `/admin/moderation`, `/admin/security`, `/admin/settings`, `/admin/status`, `/admin/users` |
| `@hilos/vue` | `/base`, `/admin-views`; `/admin/analytics`, `/admin/appearance`, `/admin/backup`, `/admin/billing`, `/admin/change-log`, `/admin/communications`, `/admin/daemon`, `/admin/dashboard`, `/admin/i18n`, `/admin/legal`, `/admin/logs`, `/admin/maintenance`, `/admin/mcp-skills`, `/admin/moderation`, `/admin/roles`, `/admin/security`, `/admin/settings`, `/admin/sil`, `/admin/status`, `/admin/users` |
| `@hilos/react` | `/base`, `/admin-views`; `/admin/analytics`, `/admin/appearance`, `/admin/backup`, `/admin/billing`, `/admin/change-log`, `/admin/communications`, `/admin/daemon`, `/admin/dashboard`, `/admin/i18n`, `/admin/legal`, `/admin/logs`, `/admin/maintenance`, `/admin/mcp-skills`, `/admin/moderation`, `/admin/roles`, `/admin/security`, `/admin/settings`, `/admin/sil`, `/admin/users` |
| `@hilos/angular` | `/base`, `/admin-views`; `/admin/analytics`, `/admin/appearance`, `/admin/backup`, `/admin/billing`, `/admin/change-log`, `/admin/communications`, `/admin/daemon`, `/admin/dashboard`, `/admin/i18n`, `/admin/legal`, `/admin/logs`, `/admin/maintenance`, `/admin/mcp-skills`, `/admin/roles`, `/admin/security`, `/admin/settings`, `/admin/sil`, `/admin/users` |

Core has no `/admin-views` or view-only billing, dashboard, MCP skills, roles or
SIL entrypoint. Public `change-log` maps to source `admin/changeLog`; view
`mcp-skills` maps to `admin/mcpSkills`. The build-only
`@hilos/core/build/initial-bundle-budget` exports the graph/report tooling and
its types; it belongs in build configuration, never the browser entry.

The React moderation section entry exports `HilosModerationPage`,
`HilosModerationKindPage` and `HilosModerationKindRulesPage`, each with its
`Props` type. Add, Edit and Delete forms stay internal to the Rules page. The
React compatibility root exports only `HilosModerationPage` from moderation,
preserving the existing `/base`, `/admin-views` and lazy section boundaries.
Angular has no moderation entry yet.

The dependency graph is acyclic: sections use base/shared/protocol code;
`/admin-views` creates `lazyPage` descriptors with dynamic imports of the
sections; base imports neither the admin implementations, the default map nor
the compatibility root. A section entry can include several page modules and
shared dependencies. A page selection is not a promise of exactly one chunk.

Core, Vue and React declare `development`, `types`, `import` and `default`
conditions for their runtime entries. Development resolves to source; the other
targets resolve to built JavaScript and declarations. Core preserves its `tsc`
layout (`dist/base/index.js`, `dist/admin/<source-section>/index.js`). Vue and
React emit `dist/base.js`, `dist/admin-views.js`, `dist/admin/<public-section>.js`
and shared chunks; declarations retain the source directory layout. Their Vite
library builds externalize core **and every core subpath**, plus the view
framework, so classes, state and tokens have one owner. This externalization is
for the SDK library build; the consuming application bundles those dependencies.
CSS imports are injected into the library output automatically.

Angular uses real ng-packagr/APF secondary entries. Its source manifest stays
plain (`main`/`module`/`types`), and local `base/`, `admin-views/` and
`admin/<section>/package.json` forwarders point to the corresponding dist FESM
and declaration. ng-packagr generates the published dist manifest's `types` and
`default` exports, FESM2022 modules and implementation compiler maps. The pure
compatibility barrel owns no implementation and has no compiler map. The thin
`src/base.ts` forwards to `src/base/index.ts` so the APF compilation has one
source root and one owner of each class/token. Do not add a second implementation
to make a secondary entry work. Dev uses explicit consumer `paths`, production
uses APF/AOT; both expose the same public symbols. The source Sass asset remains
available at `@hilos/angular/src/hilos-styles.scss`.

Built consumers need no SDK source checkout: core carries `dist` and `bin`,
Vue/React carry dist and provenance, and Angular carries its APF outputs, maps,
forwarders and Sass asset. Source snapshots and receipts support build proof
without turning source into a runtime dependency. See
[build-and-docker.md](build-and-docker.md#initial-client-graph-and-budget) for
the output graph and the separate permission inventory.

## Angular versions: exact in every root, a range only in the peers

**Every npm root in the tree that carries Angular declares every `@angular/*`
of its `dependencies` / `devDependencies` exactly, and all of them declare the
same version.** An npm root is a directory with its own `package-lock.json`; it
carries Angular when its manifest, or the manifest of a member of its
workspace, names an `@angular/*` package in those two fields — every
`package.json` outside `node_modules` that names `@angular/` points at one.
Today there are three: the SDK workspace `framework/frontend` — its Angular
entries live in the manifest of the `@hilos/angular` view layer,
`framework/frontend/angular/package.json` — and the two consumers of that layer,
`demo/polls/frontend`, the Angular conformance demo, and
`demo/online-testing/frontend`. In those two fields the SDK
manifest declares `@angular/compiler`, `@angular/compiler-cli` and
`@angular/platform-browser`; each demo declares `@angular/common`,
`@angular/compiler`, `@angular/core` and `@angular/platform-browser`, plus
`@angular/build`, `@angular/cli`, `@angular/compiler-cli`,
`@angular/platform-server`, `@angular/router` and `@angular/ssr`. Every one of
them reads `22.2.3`, with no caret and no tilde. The frontend of a new project
([docs/new-project/README.md](../../new-project/README.md), *Project layout*)
joins them with the first `@angular/*` its manifest names, and the rule binds
it from its first install. `22.2.3` and the three roots are today's facts, not a
list to keep: what the rule fixes is that the entries are exact and equal
across every root that carries Angular, whatever the number is and however
many roots there are. The reason sits inside Angular. Its packages hold each
other to an exact version in their own `peerDependencies` —
`@angular/core@22.2.3` declares `{"@angular/compiler": "22.2.3"}`, readable in
`framework/frontend/package-lock.json` — so one caret resolved a minor ahead of
its siblings drags the whole framework in, and the lockfile shows it as an
ordinary neighbour bump.

**Three kinds of entry beside them are ranges on purpose; do not "fix" them into
exact versions.** The `peerDependencies` of `@hilos/angular` —
`"@angular/common": "^22"` and `"@angular/core": "^22"` — stay a range: a peer
range is what a consuming project resolves against, and the SDK declares the
major it is compatible with, not the version a project must install.
`"ng-packagr": "^22.0.0"` in the same manifest stays a caret: its own peer on
`@angular/compiler-cli` is a range (`^22.0.0 || ^22.2.0-next || ^22.3.0-next`),
so it does not drag Angular by itself — but it belongs to the same set when
Angular is lifted.
The `typescript` entry stays a tilde (`"~6.0.3"` today) in every root that
carries Angular — today `demo/polls/frontend/package.json`,
`demo/online-testing/frontend/package.json` and the workspace root
`framework/frontend/package.json` — because `@angular/compiler-cli`
requires `typescript >=6.0 <6.1`. This section rules on the Angular set and on
that tilde; it says nothing about any other range in any of these roots.

**Add a new `@angular/*` package at the version already in the tree; lift
Angular as one change to every root.** A new `@angular/*` entry, in any root,
is written as the exact version the tree already carries. Do not write a
caret, and do not take a newer version because the registry offers one — a bare
`npm install @angular/<name>` does both at once, so name the version
(`--save-exact @angular/<name>@<the version in the tree>`) or write the manifest
line by hand. A new project's frontend is no exception: its first install
writes that root's whole Angular set in one command, exactly, at the version
the tree already carries — not at the ranges of the manifest a generator
writes. Upgrading Angular is one deliberate edit of every root in the same
change: in each root, install that root's whole Angular set at the new version
in one command. Never let Angular move as a side effect of installing something
else, and never leave one root ahead of the rest.

**Do not delete a `package-lock.json` to get past an `ERESOLVE` conflict.** The
lockfile is the resolution of every range in its root, not only of the entry
being changed, so deleting it re-resolves all of them. On HIL-974 that carried
137 unrelated versions along in `demo/polls/frontend` (+46 / −100 packages)
while the intended change was ten Angular packages. Instead, install the version
already in the tree, or lift the whole Angular set of that root in one install.

In the SDK workspace, the only step that fails on a mixed Angular is the AOT
build of `@hilos/angular` (`ng-packagr`), i.e.
`composer run test:framework:frontend:build`; check, unit, lint and
format-check all stay green on it (HIL-848), and no automated check reads the
manifests for this rule. How an already-declared lockfile is installed and when
that install is skipped is not restated here — see
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

The Vue `HilosUserPage` is a built example of a typed page extension. It accepts
an optional `project-overview` scoped slot with `{ detail: HilosUserDetailRow }`;
the detail row is readonly and comes from the permitted user page data. The slot
is rendered inside the shared user shell's `HilosAdminPage`, so its content is
inside the existing admin/view-mode providers. With no slot, no project section
is rendered. `@hilos/vue/admin/users` also exports
`HilosUserPageShell(context: HilosUsersContext, page: string)`, whose optional
`default` and `modals` slots hold the page body and dialogs; it has no header
mutation slot, and `HilosUserPage` uses this shell.

Chat fills the Overview slot with its existing participant link:

```vue
<HilosUserPage :context="hilosUsersContext">
  <template #project-overview="{ detail }">
    <section class="mb-4">
      <h2 class="h5">Chat participant</h2>
      <div class="card">
        <div class="card-body">
          <HilosLink
            :to="router.path(PAGE_USER, { id: String(detail.id) })!"
          >
            Open participant card
          </HilosLink>
        </div>
      </div>
    </section>
  </template>
</HilosUserPage>
```

`router` and `PAGE_USER` are Chat's existing route binding; this link uses the
detail id already on the page and adds no data, scopes, or fetch. Binance mounts
`HilosUserPage` without the slot. This user-page adoption is Vue-only: React and
Angular still use their legacy user cards.

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
The framework ships one module per admin page. `hilosAdminViews()` from
`/admin-views` binds the context-free defaults lazily; a project adds the
context-bound wrappers and any overrides it needs. Pages its view layer has not
built are hidden through `HILOS_UNBUILT_PAGES` (see
[page-module-structure.md](page-module-structure.md)).

The public framework pages are tier 2 as well. The four footer pages have
framework-owned presentations: Privacy reads the policy and reader's standing
and offers an erase of what this browser holds behind a confirmation; About has
a support modal; Terms reads the revision and reader's standing; License shows
the project's license text. Terms and Privacy also carry catalog text. The
dependency inventory has its own public SDK page, separate from the footer
License text page. The framework owns these parts: `HilosAboutPage`,
`HilosTermsPage`, `HilosPrivacyPage`, `HilosLicensePage` and
`HilosLicenseDependenciesPage` under `@hilos/vue/src/public/` (and the React /
Angular twins). The two License components share the same static-page frame and
presentation values:

| Page | `title` | `icon` | `subtitle` |
|---|---|---|---|
| About | `About` | `bi-info-circle` | `What this project is, who makes it, and how it keeps going.` |
| Terms | `Terms` | `bi-file-earmark-text` | `The terms of using this project: the revision in force and every one before it.` |
| Privacy | `Privacy` | `bi-shield-lock` | `What this project keeps about you: the revision in force, every one before it, and wiping this browser.` |
| License | `License` | `bi-journal-code` | `The license of this project, and of everything it is built on.` |

`HilosLicensePage` is text-only: its `inventory` input supplies the project's
literal `projectLicenseText`. `HilosLicenseDependenciesPage` takes the complete
build-time `inventory` and optional `navigation` inputs, then renders the
dependency table with package search and License / Language filters, a reader
for each package, and copy / download actions. It uses the same License title,
icon and subtitle. The core exports
`HilosLicenseDependenciesController`, `createHilosLicenseDependencies(inventory)`
and `HILOS_LICENSE_TABS` for the per-mount table state and the shared License /
Dependencies navigation from `/base` and the compatibility root. Each view kit
exports the page from `/base` and its compatibility root; React also exports
`HilosLicenseDependenciesPageProps`.

Terms and Privacy use the shared
`HilosLegalDocumentReader` and take `context: HilosLegalContext`, an object with
`connection`, `scopes` and `actions`. Their reader comes before the project's
optional introduction; the legal text follows it. Their revision histories
have separate pages. Privacy keeps its browser erase block after the policy and
its optional `values?: readonly HilosBrowserValue[]` input for browser values
it erases.
About, Terms and Privacy take project prose through the **default slot**
(children in React and projected content in Angular). `HilosLicensePage` is the
exception: its project license text comes from the root `LICENSE` file through
`projectLicenseText` in the `inventory` snapshot, not through slot/children. The
framework owns the frame, the behavior and the legal catalog text; the project
owns its introduction and the words of its other public content. Leaving the
behavior as blocks a project pastes in would make "Privacy without the erase
button" indistinguishable from a deliberate choice.

`HilosLegalHistoryPage` is the shared public history view in Vue, React and
Angular. It takes the existing `HilosLegalContext`, a `document` key (`terms`
or `privacy`), and optional `HilosStaticPageNavigation`. Each view kit exports
the component from `/base` and its compatibility root. The core exports
`createHilosLegalHistory(context, document)` and
`hilosPublicLegalNavigation(page, resolvePath)` from `/base` and its
compatibility root. The history factory is inert until `start()`; mounted view
adapters start it and call its terminal `cleanup()` when they unmount. It owns
the document and agreement projections, typed row resolver, native viewport
controller and lazy revision-text reader. The first table window rides in the
page response; later windows and rendered-field reports use the native table
transport. The view opens revision text in a read-only modal and offers no
acceptance or comparison. Its table rows arrive in the `slots.revision` wire
slot; core computes the in-force and accepted marks from the current document
and the reader's agreement projection.

Each of `HilosAboutPage`, `HilosTermsPage`, `HilosPrivacyPage` and
`HilosLicensePage` in Vue, React and Angular also accepts
`navigation?: HilosStaticPageNavigation` and passes it unchanged to
`HilosStaticPage`. The existing `context`, `inventory` and `values` inputs and
project-content slot (children in React, projected content in Angular) remain
unchanged; built-in pages add no title override. The Terms and Privacy mounts
pass the shared legal navigation, and their history views receive the same
navigation for the complementary tab row. Both License pages accept the same
optional navigation input; the Dependencies page uses the shared License tabs.
Custom static pages can supply their own navigation.

No aggregate collects the footer pages — there is no `hilosPublicViews()`.
Each needs a project-supplied input, which is the same exception
`hilosAdminViews.ts` already
makes for `HilosUsersPage` / `HilosSettingsPage` / `HilosBackupPage`: a project
mounts them directly under their `HilosPages` keys. A project that wants its own
License page mounts its own component under `HilosPages.LICENSE` and gets none
of the framework behavior — the existing extension model, and the reason About,
Privacy and Terms take their project introduction through a slot rather than
through configuration. `HilosLicensePage` takes `inventory` as a prop/input and
renders only the project's own text, so a project mounts it thinly without
prose slots (`<HilosLicensePage :inventory="hilosLicenseInventory" />`). The
project's License text view keeps its file and name
(`views/License/License.vue` and the twins) and mounts the framework page
instead of `HilosStaticPage`; its page key, `/license` route, footer link and
prerender entry stay as they are. A project mounts
`HilosLicenseDependenciesPage` under `HilosPages.LICENSE_DEPENDENCIES` at
`/license/dependencies`, passing the same build-time snapshot and shared
License navigation. It is a separate public SPA route, not another footer or
prerender entry. The Dependencies page adapts that complete input to a
synchronous local table window; it does not request the inventory from the
backend or send server table frames. A behavior block takes
its first-render data in its own page subscription. The legal catalog is read by
the backend; the legal pages use their page context and add no companion
first-render request. The project passes the snapshot its own build produced
to both License components
([build-and-docker.md](build-and-docker.md), *SSG and the public surface*). Each
page is created by the leaf that first needs it:
`HilosLicensePage`, `HilosLicenseDependenciesPage`, `HilosPrivacyPage`,
`HilosAboutPage`, `HilosTermsPage`.

The mechanism across both tiers is the same — slots + scoped slots + shared
composables, no mixins — and "empty inheritance" (a one-line re-export) is the
default when nothing is customized.

## Tier-1 cross-cutting components

Several tier-1 components are part of the contract, so pages never reinvent them:

- **`HilosFormError`** keeps a form refusal to one line with a details panel and
  holds its room with an invisible twin before any refusal exists;
- **`HilosActionReason`** explains why an action is unavailable. It is exported
  from `@hilos/vue/base`, `@hilos/react/base` and `@hilos/angular/base`, and from
  each package's compatibility root; it adds no entrypoint. Its required
  `reason: string | null` and optional `label` / `title` props default to
  `Why this action is unavailable` and `Action unavailable`. A non-null reason
  gives a native enabled question button whose `title` is the reason and whose
  accessible name is `label`; the icon is hidden from assistive technology.
  The button opens `HilosModal` with the reason snapshot, a Close action and
  `initialFocus="dialog"`. If the source reason becomes null while the modal is
  open, the opener disappears but the open explanation stays until Close; the
  next opening reads the current reason. Unmounting releases the modal through
  its normal focus and scroll cleanup. The primitive does not dispatch an
  action, confirm a mutation, show a toast or write runtime state.

  The Vue `HilosActionReason` also takes `reason: Hideable<string | null>`
  (`@hilos/core/base`), for a reason the server hides from a viewer of the admin
  view mode. A hidden reason still gives the opener, but with no `title`: the
  words are never turned into a string, a title or markup. The modal body draws
  the snapshot through `HilosHideable`, so the standard hidden mark stands in
  the dialog. A plain `string | null` works as before; the React and Angular
  inputs stay `string | null`.

  Vue and React render a wrapperless native opener beside the modal. Angular's
  selector is `button[hilosActionReason]`; the caller owns that native button
  and its static `data-id`. The Angular primitive creates a local embedded view
  from its declared `TemplateRef` and moves the view's roots to `document.body`
  through `ViewContainerRef`, preserving Angular DI, change detection and view
  ownership while keeping modal content outside the button and its action row.
  `DestroyRef` releases that view on teardown. Angular's shared `HilosModal`
  itself still renders in place.

  ```vue
  <script setup lang="ts">
  import { HilosActionReason } from '@hilos/vue/base'
  defineProps<{ deleteReason: string | null }>()
  </script>
  <template>
    <HilosActionReason :reason="deleteReason" />
  </template>
  ```

  ```tsx
  import { HilosActionReason } from '@hilos/react/base'

  function Actions({ deleteReason }: { deleteReason: string | null }) {
    return <HilosActionReason reason={deleteReason} />
  }
  ```

  ```ts
  import { Component } from '@angular/core'
  import { HilosActionReason } from '@hilos/angular/base'

  @Component({
    selector: 'hilos-action-reason-example',
    imports: [HilosActionReason],
    template: '<button hilosActionReason [reason]="deleteReason" data-id="delete-reason"></button>',
  })
  class Actions {
    readonly deleteReason: string | null = null
  }
  ```

- **`HilosSendProgress`** draws one session code-send line and the timed Send again
  control above a profile code field (HIL-1186). Its text and lock rules come from
  `@hilos/core` `primitives/sendProgress`; Vue, React, and Angular only render
  them. It reserves the line and repeat row before the first send, opens the
  complete line in `HilosModal`, and carries no live region of its own, so the
  changing line is never read twice. The profile windows' regions voice their
  refusals only, so the line itself is not announced yet (P-486);
- the **upload field** (HIL-140) is three tags over one state: the page creates
  `createHilosUploadField({ target })` from `@hilos/core` `primitives/uploadField`,
  hands it to every tag, and disposes it itself. **`HilosUploadDrop`** wraps the
  project's own content as the drop and paste door; **`HilosUploadFiles`** draws a
  chip per file and, above the chips, the last refusal in a `HilosFormError`
  whose room is held from the first file to the last — with no files it takes no
  room at all; **`HilosUploadPicker`** is the paperclip over a hidden chooser. The
  voice lives on the wrapper, not on the line: `HilosUploadDrop` keeps two
  permanent live regions — the refusal assertive, the file received polite — and
  the refusal line carries no role, so nothing is heard twice
  ([accessibility.md](accessibility.md), "The room belongs to the block, the
  voice to the surface"). Words and classes come from the core; Vue, React, and
  Angular only render them;

- the **`HilosLayout`** application shell — the navbar (project brand and nav
  slots, the admin gear, the live connection indicator, and, last, the sign-out
  control), a full-width banner region below the nav — empty and zero-height
  while nothing is up — and a footer of the public framework pages, around the
  routed page content. The SDK draws sign-out while a person stands behind the
  session (`hilosSignedIn`, bound by `bootHilos`) and runs it as a tracked
  `hilos_logout` answered behind the anonymous identity (HIL-1063). A project
  mounts nothing for it; its user region carries only its own controls (the
  bell, the profile link, a guest's Sign in). The admin gear is the SDK's the
  same way: it is drawn from the session (`hilosAdminAccess`, bound by
  `bootHilos`) for an admin and, in the admin view mode, for a viewer, and the
  project passes nothing (HIL-1253). The banner region carries the
  framework's own strips first: protected mode, then impersonation, which the
  SDK draws from the session (`hilosImpersonation`, bound by `bootHilos`) and
  whose Stop is a tracked action answered behind the restored identity
  (HIL-1064) — a project mounts nothing for either. Below them comes the strip a
  project passes (e.g. a trial notice); that delivery is the same in all three
  shells and only its form differs: Vue takes it through the `#banner` slot,
  React through the `banner` prop, Angular through a projected `[banner]` node.
  The shell is a fixed-height viewport column whose main region owns the scroll,
  so a page either scrolls inside it or fills it and scrolls an inner region;
  the footer links come from the framework (`HILOS_FOOTER_LINKS`), so every
  project shows the same About / Terms / Privacy / License set and supplies the
  page's project content: prose for About, Terms and Privacy, optional browser
  values for Privacy's erase, and the generated inventory used by both License
  pages; the footer License page reads only its project text;
- **`HilosStaticPage`** — the reusable frame for a static page, used by projects,
  the four footer pages, both legal history pages and the License Dependencies
  page (tier 2, above). It
  takes required `title: string` and optional `icon?: string`,
  `subtitle?: string`, and
  `navigation?: HilosStaticPageNavigation`. Calls that supply only `title` keep
  working: the frame renders its title and body without navigation. The public
  `HilosStaticPageNavigation` interface is exported as a type from
  `@hilos/core/base` (and the compatibility root); it has `page: string`,
  `tabs: readonly HilosPageCrumb[]`, and `resolvePath: HilosPathResolver`, with
  optional `params?: Record<string, string>` (default `{}`) and `label?: string`
  (default `Page sections`). `page` marks the current tab, and `tabs` arrive in
  display order with the owner first. The frame uses the first tab's page key as
  its local head owner, falling back to `page` when the list is empty. The shared
  head resolves each tab through `resolvePath`, drops tabs without an address,
  and hides the row when fewer than two links remain.

  In Vue, React and Angular, the frame builds a local section presentation for
  `HilosPageHead`: the title and subtitle, an empty breadcrumb and child list,
  the optional icon, and the supplied tabs. The head owns the visible heading;
  the frame provides the same heading id to its projected page content through
  each kit's heading-id context. This keeps one `h1` and its existing table-name
  relationship. The frame remains available to a project with its own static
  page; this does not activate project routes or server catalog adoption. The
  public pages are declared in `@hilos/core` (`HilosPages` / `HILOS_PAGE_ROUTES`)
  and subscribe like any page. About, License and License Dependencies carry no
  server page payload; the project supplies their build snapshot as an input.
  Terms
  carries its current legal document and reader state; Privacy carries
  `legalPrivacy` and, for a signed-in reader with a published policy,
  `legalAgreements`. Each history page carries its matching legal document,
  the signed-in `legalAgreements` projection when that document is available,
  and the first native table window. The history routes are separate from the
  four footer links and their prerender entries. The Dependencies route is also
  separate from that footer and prerender set. The legal pages receive the
  connection, scopes and tracked actions through `HilosLegalContext`, and the
  project gives the Terms and Privacy pages an introduction only;
- a **`HilosErrorBoundary`** wrapping each page or major block, so one
  component's runtime error degrades locally instead of blanking the long-lived
  SPA;
- standard **skeleton / empty / error** components for a data block's three
  states — the concrete form of "a placeholder for everything"
  ([core-and-connection.md](core-and-connection.md)). Of the three, the skeleton
  is built — **`HilosSkeleton`** in each view package, which `HilosView` also
  draws in a page's place while the page waits for its first answer;
- **`HilosAvatar`** in each view package — a person's circle, with initials
  from `formatInitials` in `@hilos/core`, one color and three sizes: `sm` for
  the header, `md` for the admin card, `lg` for the profile. The circle is
  hidden from screen readers; its surroundings carry the name, in the header
  as hidden text and a `title`. The project's header user area places it
  instead of the name (HIL-301). The optional `photo` prop fills the circle
  with the public image variant; a failed image falls back to initials. The
  optional `icon` prop, a Bootstrap Icons class, draws that icon instead of
  both — for a circle that stands for no face, such as a merged account
  (HIL-1300); it is hidden from screen readers with the rest of the circle.
  `HilosProfilePhoto` in each view package draws the change-photo modal over
  `createHilosProfilePhotoFlow` from core (HIL-1205);
- **Profile head state** — `@hilos/core/base` and the compatibility root export
  `createHilosProfileHeadStore(context, name?)` and
  `HilosProfileHeadStore`. `HilosProfilePageContext` carries the connection,
  scopes and tracked actions. The store exposes readonly signals for the
  current name, photo, photo feature flag, verified email, passkey-only state,
  hidden sections and page identity. It reads the name from the session by
  default, while the profile Overview passes its existing `binding.name`; the
  photo also follows session state. The current page's identity and eligible
  tabs come from its answer, and verified email and passkey-only status follow
  the existing `profileIdentities` list. The store owns the second-factor
  listener and the profile name binding through restartable `start()` and
  `dispose()` methods. `createHilosProfileRootStore()` exposes this same store
  as `root.head`; its lifecycle owns that store, so a root view borrowing it
  does not start a second listener. The Overview keeps its Account, name,
  verified email, Theme, Time zone and deletion controls, while Sections and
  summary listeners are gone. Its legacy session/device count inputs remain
  optional and unused, `root.summaries` remains an empty readonly map, and the
  section icon/id exports remain available for compatibility;
- **`HilosProfileHead`** — the shared profile presentation exported from
  `@hilos/vue/base`, `@hilos/react/base` and `@hilos/angular/base`, plus each
  package's compatibility root. It adds no package entrypoint or eager admin
  import. Every kit requires `context: HilosProfilePageContext` and accepts an
  optional borrowed `HilosProfileHeadStore`; without a store, the component
  creates and owns the store lifecycle, and with the Overview's store it leaves
  that lifecycle to the root. Vue calls `claimNameBinding()` when it selects a
  borrowed, already-started store. For the same context and scopes, this lets
  that store reclaim its live name after the optional `store` becomes undefined
  and the component uses its own store, including a project-provided name signal
  that changed in the meantime. Vue does not start or dispose the borrowed
  store, and the caller needs no extra lifecycle call or remount for this
  handoff. React also exports `HilosProfileHeadProps`.
  The component mounts the existing `HilosPageHead` as an entity with the
  `Profile pages` row: the live name is the single `h1`, an `lg` avatar opens
  the existing `HilosProfilePhoto` flow when photo changes are enabled, and the
  description shows the passkey-only warning or verified address. The existing
  `h1` uses `titleDataId="profile-identity-name"`. Each mount owns its photo
  flow; React creates a fresh flow on every StrictMode effect setup so cleanup
  can dispose the prior one safely. The Notifications page takes the broader
  page context, including actions needed by the shared head, while the core
  `HilosProfileNotificationsContext` and
  `startHilosNotificationPreferences()` remain connection-and-scopes APIs.
  Its stable hooks are `profile-identity`, `profile-identity-name`,
  `profile-identity-email`, `profile-photo-open`, and
  `profile-sign-in-passkey-only-line`. Project wrappers pass their existing
  actions. The project extension is covered by the test-only
  `project_profile_example` at `/profile/example`, not by a demo product route;
- **`HilosHiddenMark`** and **`HilosHideable`** — the one mark of a value the
  server keeps from a viewer of the admin view mode, and the wrapper that draws
  a hideable value through its slot (a render function in React, a projected
  `ng-template` in Angular) or the mark in its place
  ([../architecture/admin-view-mode.md](../architecture/admin-view-mode.md)).

The skeleton fills a data block while its data streams in. At the routed outlet,
the same delayed skeleton also covers the combined code and first-answer wait
of a lazy page. The shared state machine, departure copy, refusal and retry
semantics are in [multiframework-core.md](multiframework-core.md#page-code-and-first-answer-state);
the production graph is in [build-and-docker.md](build-and-docker.md#lazy-page-code-in-the-client-build).

## IDE correctness

Real `exports` / `main` plus the built `dist` and types are what let PhpStorm and
the TypeScript language service resolve SDK symbols correctly in a consumer
project — the reason distribution ships a built package, not raw `src`.

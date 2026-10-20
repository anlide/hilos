# Hilos frontend SDK

The framework frontend SDK as an npm workspace: the framework-agnostic core
(`@hilos/core`) and the view layers — `@hilos/vue` (the canonical product
layer), `@hilos/react` (the React adapter), and `@hilos/angular` (the Angular
adapter). Consumer projects — the chat and binance-btc-tracker Vue demos,
the tasks and ecommerce-shop React demos, and the polls and online-testing
Angular demos — are not members of this workspace; they vendor the SDK. The normative spec lives under `docs/agents/frontend/`
(`sdk-packaging.md`, `build-and-docker.md`, `multiframework-core.md`).

## Tooling runs in Docker, never on the host

Every Node step runs in the pinned container, never the host Node — the same
rule as the PHP CLI. The container only provides the toolchain; `node_modules`
lives in the project on the host bind-mount, so the IDE resolves the packages.
Run from the repo root:

```
docker compose -f framework/docker/docker-compose.frontend.yml \
  run --rm hilos-frontend-cli <command>
```

For example, append `npm install` or `npm run build` as `<command>`.

## Angular versions

Every npm root in the tree that carries Angular — today this workspace, through
`angular/package.json`, `demo/polls/frontend` and `demo/online-testing/frontend`
— declares every `@angular/*`
in `dependencies` / `devDependencies` as an exact version, and all of them
declare the same one. Three kinds of entry beside them are ranges on purpose:
the `^22` `peerDependencies` of `@hilos/angular` (a peer range is what a
consuming project resolves against), the `ng-packagr` caret, and the
`typescript` tilde (`@angular/compiler-cli` requires `typescript >=6.0 <6.1`).
Angular's own packages hold each other to an exact version in their
`peerDependencies`, so one caret resolved a minor ahead of its siblings drags
the whole framework with it, and only the AOT build (`ng-packagr`, i.e.
`npm run build` for `@hilos/angular`) notices (HIL-848). Adding an `@angular/*`
package or upgrading Angular follows `docs/agents/frontend/sdk-packaging.md`,
section "Angular versions: exact in every root, a range only in the peers".

## Commands

| Command | What it does |
|---|---|
| `npm install` | install the workspace toolchain into the host `node_modules` |
| `npm run check` | type-check every package (`tsc` / `vue-tsc`, plus `ngc` over the Angular templates; no emit) |
| `npm run test` | run the unit tests (vitest, against source) |
| `npm run build` | build every package from `src/` into `dist/` |
| `npm run build:vue` | build `@hilos/core` + `@hilos/vue` only |
| `npm run build:react` | build `@hilos/core` + `@hilos/react` only |
| `npm run build:angular` | build `@hilos/core` + `@hilos/angular` only |
| `npm run pack` | build, then `npm pack` each package into `dist-pack/` |

The per-view scripts are what a demo's `prebuild` hook calls, so building one
demo never builds a view layer it does not consume — see
`docs/agents/frontend/build-and-docker.md`. Use the full `build` for `pack` and
for a workspace-wide check.

`@hilos/core` builds with `tsc` — it is a pure-TS, agnostic package we keep
npm-publishable later, so it carries no bundler; `@hilos/prerender` (the static
render orchestrator) builds the same way. `@hilos/vue` and `@hilos/react` build
with Vite library mode plus a declaration-only `vue-tsc` / `tsc` pass, and
`@hilos/angular` builds with `ng-packagr`, the Angular Package Format tool an
Angular consumer expects. Core emits a JS/declaration/map per source owner plus
compiler receipts. Vue/React have multiple public entries, shared chunks,
automatically imported CSS, declarations and module provenance. Angular emits
real APF secondary entries, FESM2022 modules, declarations and compiler maps;
its classes and injection tokens have one owner across root/base/section paths.

Unit tests use vitest: Vue SFC tests sit beside the SFC; other implementation
tests live in their package's `test/` mirror; source-less scripts/codestyle
packages keep their tests beside the module
([testing-strategy.md](../../docs/agents/frontend/testing-strategy.md)). The workspace-root
`vitest.config.mts` aggregates each package as a test project, so one run
covers the whole SDK; the build tsconfigs exclude test files, so nothing
test-related reaches `dist/`.

From the repo root every step has a Composer wrapper —
`composer run test:framework:frontend:<install|build|check|unit|lint|format-check>`
— and `composer run test:framework:frontend` aggregates the checks.
`composer run test:frontend:all` goes further: the SDK suite plus every demo's
app typecheck and full e2e cycle, building the SDK first so the demos' `dist`
resolution works on a fresh clone. When to use which is in
`docs/agents/frontend/testing-strategy.md`.

## Dev versus build resolution

A `development` export condition resolves core/Vue/React runtime entrypoints to
source; `types`, `import` and `default` resolve to built dist targets. Angular's
source manifest stays plain, with local secondary-entry forwarders into dist;
ng-packagr generates the published APF manifest's `types`/`default` exports.
Angular consumers redirect every runtime subpath with explicit dev tsconfig
`paths`, including their single-copy `@angular/core`/`common` pins; production
uses dist/AOT. See the [Angular guide](../../docs/new-project/frontend-angular.md#dev-source-consumption).
`dist/` and `dist-pack/` are build artifacts and are gitignored.

## Common code, metadata and lazy sections

Use `@hilos/core/base` and the view kit's `/base` for common APIs/components,
`@hilos/<view>/admin-views` for `hilosAdminViews()` metadata, and
`@hilos/<package>/admin/<section>` inside the page implementation. Root `.`
exports remain compatible. The complete subpath lists and graph ownership are in
[SDK and Packaging](../../docs/agents/frontend/sdk-packaging.md#public-entrypoints-and-their-graph).
Sections depend on base/shared/protocol; metadata dynamically imports sections;
base depends on neither the default map, implementations nor root.

`PageView<T>` accepts a ready component or an explicit
`lazyPage(() => import(...).then(...))` descriptor, created once outside render.
`createPageViewState<T>(router)` owns the per-outlet cache and combines code
readiness with the first permitting server reply; `HilosView` adapts that state
in Vue/React/Angular. Server refusals take precedence; local import failure uses
the existing safe 503 ErrorPage. See [runtime semantics](../../docs/agents/frontend/multiframework-core.md#page-code-and-first-answer-state)
for departure/skeleton timing, disposal and native failed-ESM retry limits.

Production graph tooling is build-only:
`@hilos/core/build/initial-bundle-budget`. Vite uses the client-build plugin with
`projectSources: { routes, views }`; Angular uses production stats and the Node
CLI with `--project-sources <routes> <views>` after SSG/theme. The retained
`initial-bundle-report.json` counts unique initial JS+CSS, separates lazy/SSR
outputs and carries provenance plus `projectProof`. A verified project proof is
required to assert project-admin absence; omitted anchors mean legacy
`not-configured` scope. Budgets stay 1,500,000 warning / 2,000,000 error bytes.
See [build proof and configuration](../../docs/agents/frontend/build-and-docker.md#initial-client-graph-and-budget).

## Distribution (a Composer-vendored tarball)

Distribution is Composer-only for v1 — no public npm publish. `npm run pack`
produces a `.tgz` per package under `dist-pack/`; a release step ships those
tarballs inside the Composer package, and a consumer references each as a `file:`
dependency into `vendor/`. Every package manager extracts a tarball as a copy,
never a symlink. The only recurring consumer step is `npm install` after a
`composer update` re-pulls Hilos, so the consumer never runs a stale vendored
SDK — the chat demo's `composer.json` carries a `post-update-cmd` reminder.

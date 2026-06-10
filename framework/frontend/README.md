# Hilos frontend SDK

The framework frontend SDK as an npm workspace: the framework-agnostic core
(`@hilos/core`) and the view layers — `@hilos/vue` (the canonical product
layer) and `@hilos/react` (the React adapter; an Angular adapter follows).
Consumer projects — the chat demo, the simple-todo React demo, and later the
Angular demo — are not members of this workspace; they vendor the SDK. The
normative spec lives under `docs/agents/frontend/` (`sdk-packaging.md`,
`build-and-docker.md`, `multiframework-core.md`).

## Tooling runs in Docker, never on the host

Every Node step runs in the pinned container, never the host Node — the same
rule as the PHP CLI. The container only provides the toolchain; `node_modules`
lives in the project on the host bind-mount, so the IDE resolves the packages.
Run from the repo root:

```
docker compose -f framework/docker/docker-compose.frontend.yml --profile cli \
  run --rm hilos-frontend-cli <command>
```

For example, append `npm install` or `npm run build` as `<command>`.

## Commands

| Command | What it does |
|---|---|
| `npm install` | install the workspace toolchain into the host `node_modules` |
| `npm run check` | type-check every package (`tsc` / `vue-tsc`, no emit) |
| `npm run test` | run the unit tests (vitest, against source) |
| `npm run build` | build every package from `src/` into `dist/` |
| `npm run pack` | build, then `npm pack` each package into `dist-pack/` |

`@hilos/core` builds with `tsc` — it is a pure-TS, agnostic package we keep
npm-publishable later, so it carries no bundler. `@hilos/react` is plain TS
hooks over the core, so it builds with `tsc` too. `@hilos/vue` builds with Vite
library mode because it compiles `.vue` single-file components. All emit
`dist/index.js` plus `dist/index.d.ts`.

Unit tests are vitest, colocated with the source as `*.test.ts`
(`docs/agents/frontend/testing-strategy.md`). The workspace-root
`vitest.config.ts` aggregates each package as a test project, so one run covers
the whole SDK; the build tsconfigs exclude test files, so nothing test-related
reaches `dist/`.

## Dev versus build resolution

A `development` export condition resolves each package to its `src`, so a
consumer developed alongside the SDK gets HMR into source; every other condition
resolves to the built `dist`, so a vendored consumer and the IDE get the typed
package. `dist/` and `dist-pack/` are build artifacts and are gitignored.

## Distribution (a Composer-vendored tarball)

Distribution is Composer-only for v1 — no public npm publish. `npm run pack`
produces a `.tgz` per package under `dist-pack/`; a release step ships those
tarballs inside the Composer package, and a consumer references each as a `file:`
dependency into `vendor/`. Every package manager extracts a tarball as a copy,
never a symlink. The only recurring consumer step is `npm install` after a
`composer update` re-pulls Hilos, so the consumer never runs a stale vendored
SDK — the chat demo's `composer.json` carries a `post-update-cmd` reminder.

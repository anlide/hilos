# Testing — Agent Guide

Quick reference for running tests across the repo. All test commands go
through Docker (`docker compose ... run --rm chat-cli-test ...`) and are
wrapped in `composer` scripts — **do not invoke `phpunit` / `vendor/bin/phpunit`
directly from the host**, especially on Windows (vendor binaries and MySQL
live inside the test container).

---

## Framework backend (`framework/`)

Composer scripts live in the repo-root `composer.json`:

| Script | What it does |
|---|---|
| `composer run test:framework:install-deps` | Install PHPUnit & framework dev deps inside the test container. |
| `composer run test:framework:up` | Start `mysql-framework-test`. |
| `composer run test:framework:unit` | Run framework unit tests (`framework/tests/Unit`). |
| `composer run test:framework:integration` | Run framework integration tests (`framework/tests/Integration`). Requires DB. |
| `composer run test:framework:phpunit` | Run both PHPUnit suites. |
| `composer run test:framework:frontend-install` | First-time `npm install` for the framework Vitest project (also regenerates `package-lock.json`). |
| `composer run test:framework:frontend` | Run framework frontend Vitest suite (`framework/tests/frontend`). No DB. |
| `composer run test:framework:all` | `install-deps` → `frontend` → `up` → `phpunit` → `down`. Runs every available test type for the framework. |
| `composer run test:framework:down[-volumes]` | Stop (and optionally wipe volumes). |

---

## demo/chat backend (`demo/chat/`)

Composer scripts live in `demo/chat/composer.json`. Run from `demo/chat/`:

| Script | What it does |
|---|---|
| `composer run test:up` | Start `mysql-test`. |
| `composer run test:db-wait` | Wait until the test DB is reachable. |
| `composer run test:db-reset` | Drop + recreate the test schema (`cli.php db:test:reset`). |
| `composer run test:unit` | Run unit tests (`tests/Unit`). No DB needed. |
| `composer run test:integration` | Run integration tests (`tests/Integration`). Requires DB. |
| `composer run test:phpunit` | Run both PHPUnit suites. |
| `composer run test:frontend-install` | First-time `npm install` for the demo Vitest project (also regenerates `package-lock.json`). |
| `composer run test:frontend` | Run demo frontend Vitest suite (`tests/frontend`). No DB. |
| `composer run test:all` | `frontend` → `db-reset` → `phpunit` → `down` → `e2e-full`. Runs every available test type for demo/chat (Vitest, then PHPUnit, then Playwright e2e). Slow — full pass before a PR. |
| `composer run test:down[-volumes]` | Stop (and optionally wipe volumes). |

**Typical local loops:**

- Pure unit test iteration: `composer run test:unit` (fast, no DB).
- Integration iteration: `composer run test:up && composer run test:db-reset && composer run test:integration` (first run only; subsequent iterations can skip `db-reset` if the test doesn't mutate schema).
- Frontend iteration: `composer run test:frontend` (fast, no DB; first run is slower while npm caches in the docker volume).
- Full pass before a PR: `composer run test:all` (Vitest + PHPUnit + Playwright e2e, several minutes).

---

## demo/chat end-to-end (`demo/chat/tests/e2e/`)

Playwright, runs the full chat demo (chat + nginx + mysql) against a real
browser. **The Playwright runner itself runs in a docker container**
(`chat-e2e-runner`, image `mcr.microsoft.com/playwright:vX.Y.Z-noble`
with browsers baked in). The runner reaches the app over the docker
network at `https://chat-nginx-test`, not via host ports — so a working
e2e prerequisite is just docker, not host Node/browsers.

Composer scripts are in `demo/chat/composer.json`:

| Script | What it does |
|---|---|
| `composer run test:e2e-build` | Build frontend assets for the test container. |
| `composer run test:e2e-up` | Bring up mysql-test + chat-test + chat-nginx-test. |
| `composer run test:e2e` | `npm ci` + `npx playwright test` inside `chat-e2e-runner`. |
| `composer run test:e2e-down` | Tear everything down. |
| `composer run test:e2e-full` | End-to-end: build → up → db-wait → db-reset → run → down. |

The Playwright image tag (`v1.X.Y-noble` in `docker-compose.test.yml`)
**must** match the `@playwright/test` version pinned in
`tests/e2e/package.json` — otherwise Playwright cannot locate its
browser executables at `/ms-playwright`. Bump them together.

---

## Frontend unit tests (Vitest)

Two self-contained Vitest projects, mirroring the layout of `tests/e2e/`:

- `framework/tests/frontend/` — covers `framework/frontend/src/**` (the SDK).
- `demo/chat/tests/frontend/` — covers `demo/chat/frontend/src/**`; can also
  reach framework code through the `@hilos/sdk/*` alias.

Both run in a dedicated `node:22-alpine` container with `node_modules`
on an anonymous volume (the host repo never gets a `node_modules/`
directory under `tests/frontend/`). Default DOM environment is `jsdom`.

| Script | What it does |
|---|---|
| `composer run test:framework:frontend` | Framework Vitest suite (run from repo root). |
| `composer run test:frontend` | Demo Vitest suite (run from `demo/chat/`). |
| `composer run test:framework:frontend-install` | First-time `npm install` to generate `package-lock.json` (rerun after editing dev deps in `framework/tests/frontend/package.json`). |
| `composer run test:frontend-install` | Same for the demo Vitest project. |

The first run is slow (downloads `node:22-alpine` + ~160 npm packages
into the anonymous volume). Subsequent runs are ~1 second of `npm ci`
plus the actual test time. `docker compose down -v` wipes the cache.

---

## Writing new tests

- **PHPUnit unit tests** (`tests/Unit/`): pure, no DB, no Hilos runtime.
  Use PHPUnit's `TestCase` directly. See
  `demo/chat/tests/Unit/MessageActionDTOTest.php` and
  `demo/chat/tests/Unit/ActionFailSignalDataTest.php` for reference.
- **PHPUnit integration tests** (`tests/Integration/`): extend
  `IntegrationTestCase` for a prepared test DB and Hilos bootstrap.
- **Vitest unit tests** (`tests/frontend/tests/*.test.ts`): pure
  TypeScript, no Vue mounting unless you actually need it. Import
  `describe`, `it`, `expect` from `'vitest'` (no globals). Use the
  `@/` alias for the project's own source and `@hilos/sdk/*` for the
  framework SDK (demo only). See
  `framework/tests/frontend/tests/tableSignals.test.ts` and
  `demo/chat/tests/frontend/tests/hilosUserUpdate.test.ts` for reference.
- For signal-layer DTOs that cross the worker → daemon IPC boundary,
  always cover the `fromArray(toArray())` roundtrip — a missing or
  broken `fromArray` silently falls back to generic `SignalData` and
  drops any `WebSocketEnvelopeAware` metadata. See
  `ActionSuccessSignalDataTest::testRoundtripPreservesConcreteTypeAndEnvelopeMarker`.
- The frontend signal parsers (`SignalDefinition.parse(...)`) are the
  TypeScript counterpart of those backend DTO contracts — when you add
  a new wire signal, cover both sides: `fromArray(toArray())` on PHP
  and `parse(validShape) / parse(invalidShape) === null` on TS.

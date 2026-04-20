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
| `composer run test:framework:phpunit` | Run both suites. |
| `composer run test:framework:all` | `install-deps` → `up` → `phpunit` → `down`. |
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
| `composer run test:phpunit` | Run both suites. |
| `composer run test:all` | `db-reset` → `phpunit` → `down`. Use this for a full local pass. |
| `composer run test:down[-volumes]` | Stop (and optionally wipe volumes). |

**Typical local loops:**

- Pure unit test iteration: `composer run test:unit` (fast, no DB).
- Integration iteration: `composer run test:up && composer run test:db-reset && composer run test:integration` (first run only; subsequent iterations can skip `db-reset` if the test doesn't mutate schema).
- Full pass before a PR: `composer run test:all`.

---

## demo/chat end-to-end (`demo/chat/tests/e2e/`)

Playwright, runs the full chat demo (chat + nginx + mysql) against a real
browser. Composer scripts are also in `demo/chat/composer.json`:

| Script | What it does |
|---|---|
| `composer run test:e2e-build` | Build frontend assets for the test container. |
| `composer run test:e2e-up` | Bring up mysql-test + chat-test + chat-nginx-test. |
| `composer run test:e2e-install` | `npm ci` + `npx playwright install --with-deps` inside `tests/e2e/`. |
| `composer run test:e2e` | Run the Playwright test suite. |
| `composer run test:e2e-down` | Tear everything down. |
| `composer run test:e2e-full` | End-to-end: build → up → db-wait → db-reset → install → run → down. |

---

## Frontend unit tests

Not configured yet. Neither `framework/frontend` nor `demo/chat/frontend`
has a JS test runner (`vitest` / `jest`) wired up — the only frontend
test layer today is Playwright e2e in `demo/chat/tests/e2e/`.

When adding one, keep it consistent with the Docker-first pattern above
(composer script wrapping a containerized command) so the agent workflow
stays uniform.

---

## Writing new tests

- **Unit tests** (`tests/Unit/`): pure, no DB, no Hilos runtime. Use
  PHPUnit's `TestCase` directly. See
  `demo/chat/tests/Unit/MessageActionDTOTest.php` and
  `demo/chat/tests/Unit/ActionFailSignalDataTest.php` for reference.
- **Integration tests** (`tests/Integration/`): extend
  `IntegrationTestCase` for a prepared test DB and Hilos bootstrap.
- For signal-layer DTOs that cross the worker → daemon IPC boundary,
  always cover the `fromArray(toArray())` roundtrip — a missing or
  broken `fromArray` silently falls back to generic `SignalData` and
  drops any `WebSocketEnvelopeAware` metadata. See
  `ActionSuccessSignalDataTest::testRoundtripPreservesConcreteTypeAndEnvelopeMarker`.

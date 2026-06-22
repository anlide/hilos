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
| `composer run test:framework:all` | `install-deps` → `up` → `phpunit` → `down`. Runs every available test type for the framework. |
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
| `composer run test:all` | `db-reset` → `phpunit` → `down`. Runs every available test type for demo/chat. |
| `composer run test:down[-volumes]` | Stop (and optionally wipe volumes). |

**Typical local loops:**

- Pure unit test iteration: `composer run test:unit` (fast, no DB).
- Integration iteration: `composer run test:up && composer run test:db-reset && composer run test:integration` (first run only; subsequent iterations can skip `db-reset` only if the test mutates neither schema nor data — a data-mutating test needs a reset before each rerun).
- Full pass before a PR: `composer run test:all` (PHPUnit suites).

---

## Re-running tests and state between runs

- A test that **mutates data** is not idempotent across runs on the same database.
  Reset before re-running it (`composer run test:db-reset`, or `test:e2e-up` for
  e2e); the full pass (`test:all` / `test:e2e-full`) resets for you. **Do not treat a
  failure on a repeated run *without* a reset as a bug** — reset is the contract;
  re-running against a dirty database is not a supported scenario.
- To test an **irreversible or time-delayed** operation repeatedly (deleting an
  orphan row, an account deleted N days after the request), do **not** engineer
  idempotency into the test. The designed path is a **test-only CLI command** —
  gated to refuse on production — that sets up or tears down the state, not an
  ad-hoc reset hack inside the test.

---

## Writing new tests

- **PHPUnit unit tests** (`tests/Unit/`): pure, no DB, no Hilos runtime.
  Use PHPUnit's `TestCase` directly. See
  `demo/chat/tests/Unit/MessageActionDTOTest.php` and
  `demo/chat/tests/Unit/ActionFailSignalDataTest.php` for reference.
- **PHPUnit integration tests** (`tests/Integration/`): extend
  `IntegrationTestCase` for a prepared test DB and Hilos bootstrap.
- For signal-layer DTOs that cross the worker → daemon IPC boundary,
  always cover the `fromArray(toArray())` roundtrip — a missing or
  broken `fromArray` silently falls back to generic `SignalData` and
  drops any `WebSocketEnvelopeAware` metadata. See
  `ActionSuccessSignalDataTest::testRoundtripPreservesConcreteTypeAndEnvelopeMarker`.

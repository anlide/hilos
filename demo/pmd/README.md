# PMD Demo Project

PMD is an active Vue demo with a dedicated Hilos service database.

## What runs today

- The Vue application shell has an empty home page and the standard sign-in flow.
- Signed-in users have the standard profile and Security pages.
- The `/hilos` admin area has Analytics, Users, Settings, Appearance and Communications.
- Local development and the test stand each use this project's MariaDB service.

The browser app does not provide a SQL editor, external database targets, query
history or schema-management screens. PMD's service database supports the Hilos
application itself.

## Prerequisites and first setup

Use Composer 2.8 or newer, Docker Compose v2 and an available Docker daemon. Use
Composer to invoke the documented scripts; PHP and Node project dependencies,
checks and builds run in project containers.

From `demo/pmd`:

```bash
composer run setup-env       # create .env from .env.example (tests/.env is tracked)
composer run install-deps    # install PHP dependencies in the CLI container
```

Once per checkout, install the framework frontend SDK workspace from the
repository root. Repeat this after framework frontend dependency changes:

```bash
docker compose -f framework/docker/docker-compose.frontend.yml \
  run --rm hilos-frontend-cli npm install
```

The frontend project uses the linked framework packages. `frontend:install`
installs PMD's own frontend dependencies in its container. From `demo/pmd`, run
it after the framework workspace install:

```bash
composer run frontend:install
```

## Local application

From `demo/pmd`, start the local stack:

```bash
composer run daemon-start
```

This starts MariaDB, Mailpit, the daemon and the Vite development server. The
daemon applies pending migrations during startup. Register or sign in through
the app; local verification and recovery messages can be read in Mailpit.

| Local endpoint | Default address |
|---|---|
| Vue dev server with HMR | <http://localhost:5179> |
| Built app behind nginx | <https://localhost:8176> |
| Mailpit inbox | <http://localhost:8180> |
| Daemon status | <http://localhost:8170/status> |
| Daemon WebSocket | <ws://localhost:8172> |
| MariaDB from the host | `localhost:33073` |

The built app is optional. Build it before starting the local nginx profile:

```bash
composer run frontend:build
composer run daemon-start-build
```

The default admin area is at `/hilos`. Grant a local signed-in session
administrator access with `composer run cli -- admin:create <sessionToken>`;
the account and session commands are documented in
[`docs/agents/cli/commands.md`](../../docs/agents/cli/commands.md).

Stop the local stack with `composer run daemon-stop`. This stops its containers
and preserves the local MariaDB data under `data/mysql`.

The host ports below are the defaults in PMD's Compose files. Their published
values and stand identity can follow the shared Compose override and stand
instance configuration; the daemon's in-container ports remain 8090, 8091 and
8092.

## Database migrations

PMD's Schema track starts with the immutable
`backend/Database/Migration/Schema/001_framework_baseline.sql` pair and the
generated framework deltas `002` through `007` (HIL-1924). Keep these historical
files unchanged. PMD is registered in the repository's
[`framework-projects.json`](../../framework-projects.json), so
`composer db:framework:pull` from the repository root includes PMD. For a
PMD-only update, run the normal pull from this project, review and commit the
generated migration pairs, then apply pending migrations:

```bash
composer run cli -- db:framework:pull
composer run db:migration:up
composer run db:migration:status
composer run db:schema:status
```

The baseline command is for a new, empty Schema track; it does not regenerate
PMD's existing history. See the
[migration guide](../../docs/agents/orm/migrations.md) for the framework pull
and migration rules.

## Tests

PHP commands run in the test CLI container:

| Command | What it does |
|---|---|
| `composer run test:install-deps` | Install the locked PHP dependencies in the test CLI container |
| `composer run test:unit` | Run the unit suite and topology registry guard |
| `composer run test:integration` | Run the integration suite against the test database |
| `composer run test:phpunit` | Run all PHPUnit suites |

For an integration run, prepare and clean up the test database from `demo/pmd`:

```bash
composer run test:up
composer run test:db-reset
composer run test:integration
composer run test:down
```

Frontend and end-to-end commands:

| Command | What it does |
|---|---|
| `composer run test:check` | Install if needed, type-check/lint/format-check the app and lint/format-check e2e |
| `composer run test:e2e-build` | Build the app and table-geometry fixture for the test stack |
| `composer run test:e2e-install` | Install the Playwright dependencies |
| `composer run test:e2e-check` | Type-check the e2e test code |
| `composer run test:e2e` | Run the suite against an already raised test stack |
| `composer run test:e2e-full` | Run the full clean e2e lifecycle |

The clean lifecycle tears down the prior test stack, prepares a fresh test
database, builds the frontend, installs and checks Playwright, starts the daemon
and nginx, runs the tests and tears the stack down. To point the same clean
cycle at one real spec, run from `demo/pmd`:

```bash
composer run test:e2e-full -- tests/appearance.spec.ts
```

The test browser reaches nginx inside Compose as `https://pmd-nginx-test`. For
manual inspection from the host, use `https://localhost:8178`. The test nginx
also publishes HTTP on port `8177`, which redirects to the default HTTPS port.
Test MariaDB is published at `localhost:33074`; its data directory is in memory
and is discarded with the container. The test daemon publishes status on `8173`
and WebSocket on `8175`. The test Mailpit service has no host port: the e2e
runner reads its mailbox at `http://pmd-mailpit-test:8025` inside the Compose
network.

If a run stops before its final cleanup, use `composer run test:down`. To also
remove Compose's named volumes, use `composer run test:down-volumes` when their
data should be discarded.

For test selection and stand lifecycle details, see
[`docs/agents/testing.md`](../../docs/agents/testing.md) and the
[frontend testing strategy](../../docs/agents/frontend/testing-strategy.md).

## License

This project is licensed under the MIT License - see the LICENSE file in the
root of the Hilos framework for details.

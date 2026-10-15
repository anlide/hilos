# Tasks Demo Project

**Complexity: 2/5**

Task list with synchronization between users.

## What is this?

Add, delete, mark completed tasks. Changes by one user are visible to all others in real-time. Simple task list synchronization through WebSocket.

### Features

- **Task management**: Add, delete, mark completed tasks
- **Real-time sync**: Changes by one user visible to all others in real-time
- **Simple synchronization**: Task list synchronization through WebSocket
- **Frontend**: React 19 + TypeScript over `@hilos/react` with task list
  interface, add, delete, mark completion

### Technical Highlights

- Work with data collections
- List synchronization
- Real-time updates
- Simple CRUD functionality

## React conformance demo

This demo is built on **React** (not Vue): it is one of the two minimal
conformance demos that prove the framework-agnostic core
(`docs/agents/frontend/multiframework-core.md`). It consumes the Hilos frontend
SDK through `@hilos/react` and tracks each core capability as it lands — it is
not held to parity with the Vue chat demo.

The backend is a minimal Hilos daemon (one agent, one page, no actions or
signals yet): enough for the frontend transport to reach a live `connected`
state. The tasks views and the CRUD contract land with the data-on-screen
rewrite steps.

## Change Log

The demo declares `CHANGE_LOG` and journals exactly `hilos_user`, `hilos_identity`,
`hilos_second_factor` and `hilos_setting`. The journal is the primary database name
plus `-change-log`, on the same MariaDB server with the same host, port, user,
password and charset. No additional environment keys are needed. The primary
migration track creates its six tables; connection 1 runs no separate migrator.
Backup includes the pair and the journal's personal-data verdicts.

Before updating an existing local volume, provision the derived database once
(or repeat the same command safely). Run from this demo's directory:

```bash
docker compose -f docker/docker-compose.local.yml exec tasks-mysql-local \
  /usr/local/bin/provision-change-log.sh
```

This only creates a missing database and grants access; it does not reset or
remove existing data. Fresh local and test databases run the same provisioning
from the MariaDB init directory. Local keeps its volume; test keeps its tmpfs.
The framework's shared `test:db:reset`, invoked by
`composer run test:db-reset`, recreates both test databases because the demo
declares `CHANGE_LOG`. To regenerate triggers
on the test stand after migrations, use `composer run test:change-log:generate`;
repeating it leaves matching files untouched. Commit the complete generated set
before starting a daemon. Startup prepares monthly partitions and installs the
files under its existing schema rollout claim; a repeated start keeps matches.

For dev with an external MariaDB, its operator provisions the derived database
before startup using the actual primary name, application user and host:

```sql
CREATE DATABASE IF NOT EXISTS `<primary>-change-log`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `<escaped-primary>-change-log`.* TO '<app-user>'@'<app-host>';
```

Escape `_` and `%` in the database identifier used by `GRANT`, where they are
patterns even inside backticks. The provisioning script validates names and the
64-character limit and performs that escaping. It never clears a database.

The backend registers all three journal pages and their table bindings.
The React views mount all three: the dashboard at `/hilos/change-log`, Tracked
tables at `/hilos/change-log/tables`, and one table with its history at
`/hilos/change-log/tables/{tableId}/{record?}`.

## First run

All tooling runs in project-defined docker containers — never on the host
(`docs/agents/frontend/build-and-docker.md`).

```bash
composer run setup-env          # create .env and tests/.env from the examples
composer run install-deps       # composer install in the PHP container
# once per checkout, from the repo root: install the framework SDK npm workspace.
# @hilos/* are linked via file: symlinks, so their deps (e.g. zod) resolve against
# framework/frontend/node_modules — without this the dev server fails with
# "Failed to resolve import zod":
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + phpMyAdmin + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5174 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:444 |
| phpMyAdmin | http://localhost:8081 |
| Mailpit (mail, SMS and Telegram of the stand) | http://localhost:8026 |
| Daemon status API | http://localhost:8098/status |
| Daemon WebSocket | ws://localhost:8100 |
| MySQL (from host) | localhost:33063 |

Every channel of the stand ends in that inbox: mail goes to Mailpit over SMTP,
and an SMS or Telegram message is caught by the stand gateway and forwarded
there as a letter to `<number>@sms.stand` or `<number>@telegram.stand`, with
the message text as its subject. There is no file with the code on disk;
the dev stack (`docker-compose.dev.yml`) publishes its own Mailpit on
http://localhost:8029.

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18090 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, phpMyAdmin, daemon, Vite dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`tsc`) |
| `composer run frontend:lint` | lint the frontend (`eslint . --max-warnings 0`) |
| `composer run frontend:format-check` | check frontend formatting (`prettier --check .`) |
| `composer run frontend:build` | production build into `frontend/dist` |
| `composer run frontend:logs` | follow the dev-server logs |

## Database commands

| Command | What it does |
|---|---|
| `composer run db:migration:up` | apply pending migrations |
| `composer run db:migration:down` | roll back the last migration |
| `composer run db:migration:status` | migration status |
| `composer run db:schema:status` | compare schema against entities |
| `composer run pma` | start phpMyAdmin at http://localhost:8081 |

## PHP tests

| Command | What it does |
|---|---|
| `composer run test:install-deps` | install PHPUnit into the test toolchain |
| `composer run test:unit` | run the unit suite (topology registry guard) |
| `composer run test:phpunit` | run the whole PHPUnit suite |

## End-to-end tests

e2e runs against the **built** frontend artifact served by the prod-parity
nginx (TLS, `/ws` upgrade proxy) with a booted daemon behind it
(`docs/agents/frontend/testing-strategy.md`). Agent flow:
`composer run test:e2e-full -- <spec>` or `-- --grep "…"` — the full clean cycle
pointed at a subset.

| Command | What it does |
|---|---|
| `composer run test:check` | install + typecheck, lint and format-check the frontend app; lint and format-check the e2e suite (test toolchain) |
| `composer run test:e2e-build` | install + build the frontend for the test stack |
| `composer run test:e2e-install` | install the Playwright deps |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack: MySQL (reset) + daemon + nginx |
| `composer run test:e2e` | run the e2e suite (`-- --grep "..."` filters) |
| `composer run test:e2e-down` | tear the e2e stack down |
| `composer run test:e2e-full` | build → install → check → up → test → down; `-- <spec>` or `-- --grep "…"` points the same clean cycle at a subset |

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.

# Simple Todo Demo Project

**Complexity: 2/5**

Simple todo list with synchronization between users.

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
state. The todo views and the CRUD contract land with the data-on-screen
rewrite steps.

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
#   docker compose -f framework/docker/docker-compose.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + phpMyAdmin + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5174 |
| phpMyAdmin | http://localhost:8081 |
| Daemon status API | http://localhost:8098/status |
| Daemon WebSocket | ws://localhost:8100 |
| MySQL (from host) | localhost:33063 |

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
(`docs/agents/frontend/testing-strategy.md`). Agent flow: one `test:e2e-up`,
any number of pointed `test:e2e` runs, one `test:e2e-down`.

| Command | What it does |
|---|---|
| `composer run test:check` | install + typecheck the frontend app (test toolchain) |
| `composer run test:e2e-build` | install + build the frontend for the test stack |
| `composer run test:e2e-install` | install the Playwright deps |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack: MySQL (reset) + daemon + nginx |
| `composer run test:e2e` | run the e2e suite (`-- --grep "..."` filters) |
| `composer run test:e2e-down` | tear the e2e stack down |
| `composer run test:e2e-full` | build → install → check → up → test → down |

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.

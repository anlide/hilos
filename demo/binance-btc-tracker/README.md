# Binance BTC Tracker Demo Project

**Complexity: 4/5**

Background computation over a live market: BTC/USDT from Binance, folded into
timeframes and read by an indicator, on a real-time Hilos application.

## What is this?

### Today

The empty shell of the application, and the smallest complete Hilos project
on Vue — the one to copy (`docs/new-project/README.md`):

- **Sign-in** by password, on the framework surface; registration and recovery
  confirm the address with a code sent by mail.
- **Home page** that says who is looking: "Browsing anonymously" for a visitor,
  "Signed in as …" with an account.
- **Admin dashboard** at `/hilos` with the **Maintenance** section — the
  verifier circle a protected-mode freeze lets through; the other admin
  sections arrive one by one.
- **Public pages** About, Terms, Privacy and License, prerendered into the build.

The backend is the base set: one app agent owning the connections and the
home page, the Hilos index agent with the dashboard, the Maintenance section
and the footer pages, and the framework sign-in libraries. It keeps no table of
its own — people, sessions, the tables of signing in and the verifier circle
are the framework's.

### What it is going to show

The design lives in `hilos-ops/mockups/demos/binance-btc-tracker`:

- **One pair, BTC/USDT**: every minute taken from the Binance stream, with the
  history back-filled from the start of the pair.
- **Fifteen timeframes** folded from the minutes, and **RSI(14)** on each.
- **Signals**: chains of RSI zones and divergences across the timeframes,
  ending in a recommendation.
- **Paper trades** a robot opens and closes on those signals — no money is
  involved.
- **Telegram**: a bot that says what the robot recommended, entered and closed.
- **Chart**: candles with an RSI panel, the zones, divergences and trade
  levels marked on it, and the RSI of all fifteen timeframes in one strip.

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
composer run daemon-start       # MySQL + phpMyAdmin + Mailpit + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5176 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8116 |
| phpMyAdmin | http://localhost:8119 |
| Mailpit (the codes a registration and a recovery send) | http://localhost:8120 |
| Daemon status API | http://localhost:8110/status |
| Daemon WebSocket | ws://localhost:8112 |
| MySQL (from host) | localhost:33067 |

An administrator is made from the command line: `composer run cli -- admin:create
<sessionToken>` makes a browser session an administrator, minting its account when
it has none, and `admin:grant` / `admin:revoke` move the flag on an existing one
(`docs/agents/cli/commands.md`).

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18110 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, phpMyAdmin, Mailpit, daemon, Vite dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`vue-tsc`) |
| `composer run frontend:build` | production build into `frontend/dist` |
| `composer run frontend:logs` | follow the dev-server logs |

## Database commands

| Command | What it does |
|---|---|
| `composer run db:migration:up` | apply pending migrations |
| `composer run db:migration:down` | roll back the last migration |
| `composer run db:migration:status` | migration status |
| `composer run db:schema:status` | compare schema against entities |
| `composer run pma` | start phpMyAdmin at http://localhost:8119 |

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
| `composer run test:check` | install + typecheck the frontend app (test toolchain) |
| `composer run test:e2e-build` | install + build the frontend for the test stack |
| `composer run test:e2e-install` | install the Playwright deps |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack: MySQL (reset) + daemon + nginx |
| `composer run test:e2e` | run the e2e suite (`-- --grep "..."` filters) |
| `composer run test:e2e-down` | tear the e2e stack down |
| `composer run test:e2e-full` | build → install → check → up → test → down; `-- <spec>` or `-- --grep "…"` points the same clean cycle at a subset |

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.

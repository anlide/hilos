# Online Testing Demo Project

**Complexity: 3/5**

"Hilos Testing": online tests on a real-time Hilos application — scores,
attempts and verdicts that move on every open page at once.

## What is this?

### Today

The empty shell of the testing room, and the smallest complete Hilos project on
Angular — the one to copy (`docs/new-project/README.md`):

- **Sign-in** by password, on the framework surface; registration and recovery
  confirm the address with a code sent by mail.
- **Home page** that says who is looking: "Browsing anonymously" for a visitor,
  "Signed in as …" with an account.
- **Admin dashboard** at `/hilos`, empty — the admin sections arrive one by one.
- **Public pages** About, Terms, Privacy and License, prerendered into the build.

The backend is the base set: one app agent owning the connections and the
home page, the Hilos index agent with the dashboard and the footer pages, and
the framework sign-in libraries. It keeps no table of its own — people,
sessions and the tables of signing in are the framework's.

### What it is going to show

The design lives in `hilos-ops/mockups/demos/online-testing`:

- **Tests**: a live table of the tests you have not taken yet, marked when they
  carry pictures or are checked by an AI; a visitor sees the list, and tests are
  taken after signing in.
- **Taking a test** in one sitting: every question at once, pictures above the
  options, one or several right answers; the score and the review come right
  after — or, for a test an AI checks, answers in your own words and a verdict
  that arrives later.
- **Passed tests**: how many, the average score and what is left, and the
  review of any attempt.
- **Test administration**: tests of either kind, their questions with pictures
  and right answers, reference answers for the AI, publishing and closing.
- **Reports**: a live summary of every test and the report of one — the spread
  of scores, the attempts, and how each question was answered.
- **AI check**: the chain that checks answers — an API, a local model, and your
  own AI over MCP last — and the queue of attempts waiting in it.
- **Your AI**: the tools an administrator's AI uses over MCP to read the answers
  to check and write its verdict.

## First run

All tooling runs in project-defined docker containers — never on the host
(`docs/agents/frontend/build-and-docker.md`).

```bash
composer run setup-env          # create .env and tests/.env from the examples
composer run install-deps       # composer install in the PHP container
# once per checkout, from the repo root: install the framework SDK npm workspace.
# @hilos/* are linked via file: symlinks, so their deps (e.g. zod) resolve against
# framework/frontend/node_modules — without this the build fails to resolve them:
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + phpMyAdmin + Mailpit + daemon + Angular dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5178 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8156 |
| phpMyAdmin | http://localhost:8159 |
| Mailpit (the codes a registration and a recovery send) | http://localhost:8160 |
| Daemon status API | http://localhost:8150/status |
| Daemon WebSocket | ws://localhost:8152 |
| MySQL (from host) | localhost:33071 |

An administrator is made from the command line: `composer run cli -- admin:create
<sessionToken>` makes a browser session an administrator, minting its account when
it has none, and `admin:grant` / `admin:revoke` move the flag on an existing one
(`docs/agents/cli/commands.md`).

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18150 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, phpMyAdmin, Mailpit, daemon, Angular dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact (run `frontend:build` first) |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`tsc` and a development `ng build`) |
| `composer run frontend:lint` | lint the frontend (`eslint . --max-warnings 0`) |
| `composer run frontend:format-check` | check frontend formatting (`prettier --check .`) |
| `composer run frontend:build` | production build into `frontend/dist`, footer pages prerendered |
| `composer run frontend:logs` | follow the dev-server logs |

## Database commands

| Command | What it does |
|---|---|
| `composer run db:migration:up` | apply pending migrations |
| `composer run db:migration:down` | roll back the last migration |
| `composer run db:migration:status` | migration status |
| `composer run db:schema:status` | compare schema against entities |
| `composer run pma` | start phpMyAdmin at http://localhost:8159 |

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

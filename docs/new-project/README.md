# Creating a new Hilos project (backend + frontend)

How to stand up a new end project on the Hilos framework: a PHP backend
(daemon + workers + agents) and a browser frontend on the Hilos SDK, both
running in project-owned docker stacks. The reference implementations, from
minimal to full:

| Project | Backend | Frontend | Role |
|---|---|---|---|
| [demo/tasks](../../demo/tasks) | minimal (1 agent, 1 page) | React | the smallest complete shape — copy this |
| [demo/simple-poll](../../demo/simple-poll) | minimal (1 agent, 1 page) | Angular | same shape, Angular toolchain |
| [demo/chat](../../demo/chat) | full | Vue | every subsystem in real use |

The frontend specifics are split per view framework:
[frontend-vue.md](frontend-vue.md) · [frontend-react.md](frontend-react.md) ·
[frontend-angular.md](frontend-angular.md). Everything below applies to all
three.

Before writing backend code, read `agents.md` at the repo root — especially
the **Contract approval gate**: a new project declares pages, agent types, and
router defaults, which are gated contract surfaces.

## Project layout

```
demo/<name>/
  composer.json        # path repo -> /hilos, PSR-4 Demo\<Name>\ -> backend/
  composer.lock        # committed (generated in the cli container)
  .env.example         # committed; .env and tests/.env are gitignored
  backend/             # the PHP application
  frontend/            # the SDK consumer app (see the per-framework docs)
  tests/
    phpunit.xml        # unit suite; bootstrap = backend/Bootstrap/phpunit.php
    Unit/              # at minimum: the topology registry test
    .env.example       # test DB coordinates
    e2e/               # Playwright package (own package.json)
  docker/
    Dockerfile         # php:8.4-cli + sockets/pcntl/posix/mysqli/pdo + pecl event
    Dockerfile.nginx   # nginx:alpine + envsubst + self-signed TLS entrypoint
    nginx.conf.template
    docker-entrypoint-nginx.sh
    mysql/init.sql     # first-boot DB + grants (project DB name hardcoded)
    docker-compose.local.yml
    docker-compose.test.yml
  data/                # mysql datadir bind, daemon logs (gitignored content)
```

## Minimal backend file set

Namespace `Demo\<Name>\`, autoload root `backend/`. The tasks backend is
the canonical minimal set (~24 files); mirror it file by file:

1. **Bootstrap** (`backend/Bootstrap/`): `docker.php` (container PID-1:
   env init → DB connect with retry → migrations → `Hilos::init()` → watchdog
   over `daemon.php`), `daemon.php` (servers + `/status` route + main loop),
   `worker.php`, `cli.php`, `phpunit.php`. All five are boilerplate — copy and
   rename the namespace.
2. **Facade** `backend/Hilos.php` extends `\Hilos\Hilos`: `PAGES`, `AGENTS`
   registries, `createDb()` (the only abstract member), optional
   `ENV_CATALOG`. `GROUPS`/`TABLES`/`BROWSER_TABLES`/`PAGE_TABLES` default to
   empty — omit until needed. `createBrowser()` defaults to `null`, valid only
   for the pure transport-only start; a real project activates settings (the
   first admin feature) early, which needs a browser, so it ships a project
   `BrowserContext` then — an empty subclass is the floor, delivering the table
   snapshot through the self-snapshot path — and returns it from
   `createBrowser()`. Treat that `BrowserContext` as part of the base set, not
   an optional extra deferred indefinitely (see
   [admin-feature-scaffold.md](../agents/architecture/admin-feature-scaffold.md)).
3. **One agent**: class with `AGENT_TYPE` and an (empty) `onStop()` — the only
   abstract method. Its daemon proxy extends `AbstractAgentDaemon` and MUST
   implement `requiresMonopolisticProcess()` — it is declared on
   `AgentDaemonInterface`, not on the abstract base; omitting it is a fatal.
4. **One page**: `PAGE` + `SUBSCRIPTION_AGENT_TYPE` constants suffice
   (`ACTIONS`/`SIGNALS` stay empty until the domain contract lands).
5. **Signal router** extending the framework `SignalRouter`: `hilosClass()`
   (without it routing reads the empty framework facade and signals silently
   vanish), `getDefaultSystemBootstrapAgentTypes()` (start the agent at boot,
   fail-fast), `getDefaultWebSocketLifecycleAgentType()` (handshake/close
   owner).
6. **Core plumbing** (one thin subclass each): `DaemonManager`
   (`createSignalRouter` + `createAgentManagerDaemon`), `AgentManagerDaemon`,
   `WorkerManager` (`createSignalRouter` + `createAgentManager` + — REQUIRED —
   `createPageSignalRouter`, whose framework default throws), `AgentManager`,
   `WorkerServer` (`onStart` only; the base already queues
   INITIAL_AGENTS_START), `WebSocketServer` (`onCreateClient` + `onStart`),
   `WebSocketClient` (`onHandshake` + `onActionValidated` — the base default
   rejects EVERY action; validate against `Hilos::getPageActionRoutes()`).
7. **Database**: `Database` (configure/connect from `DB_*` env),
   `<Name>DbContext extends HilosDbContext` (can be empty — the base registers
   the framework settings collection), an `EnvCatalog` overriding the
   `DB_DATABASE` default (the framework stub default is an empty string), and
   migration 001 = a copy of
   `framework/backend/Database/Migration/Stub/create_hilos_setting.sql`
   (+`_down`). The settings table is mandatory because `HilosDbContext`
   registers the collection unconditionally.
8. **Topology registry unit test** (`tests/Unit/`): pins registry/class-constant
   consistency and asserts that the action/signal/table routes the project has not
   opted into yet stay empty. Transport-only is a starting state, not a permanent
   contract — relax these assertions as the project activates a feature (e.g.
   activating the framework settings page registers a table and its action routes).

Beyond this base set, framework-owned admin features are activated — not
re-authored — through the per-feature recipes in
[../agents/architecture/admin-feature-scaffold.md](../agents/architecture/admin-feature-scaffold.md):
settings (the first one, above), and `backup` (a catalog + env + agent/CLI/RT
binding over the framework backup engine).

Load-bearing boot order in `docker.php`: `Database::initialize(initHilos:
false, retryConnection: true)` → `Migration::migrateUp()` → `Hilos::init()`.
Calling `Hilos::init()` before migrations breaks first boot on an empty DB.

## Docker stacks

Two compose files per project; every `container_name` is prefixed with the
app key (`chat-…`, `poll-…`, `tasks-…`) so it stays globally unique
(`container_name` and explicitly named volumes are docker-GLOBAL, not
project-scoped — never reuse another project's).

**Local** (`docker-compose.local.yml`, the developer sandbox): mysql (datadir
bind-mounted at `data/mysql`, `mysql/init.sql`), phpMyAdmin, the daemon, a cli
service (profile `cli`), the prod-parity nginx (profile `full`), and the
frontend dev server (no profile — `composer run daemon-start` brings the whole
stack up). The daemon/cli `env_file` uses the long form with
`required: false`: a fresh checkout boots on the compose environment plus
`.env.example`, and `.env` itself appears only when someone runs
`composer run setup-env`.

**Test** (`docker-compose.test.yml`, the agent/CI lane): mysql (named volume,
healthcheck), the daemon (`env_file: ../tests/.env` — must exist; created by
`composer run setup-env`), a cli service, the prod-parity nginx serving
`frontend/dist` with the `/ws` upgrade proxy (profile `e2e`), the Playwright
runner (its image tag MUST equal the `@playwright/test` version). Teardown
always via `--profile "*" down` or profiled services leak.

**Ports.** In-container daemon ports are FIXED for every project: 8090
(status) / 8091 (worker comm) / 8092 (websocket) — the nginx template proxies
`/ws` to the hardcoded `:8092`. Only host-side publishes shift. Current
registry of taken host ports:

| Stack | mysql | daemon (status/comm/ws) | pma | nginx http/https | FE dev |
|---|---|---|---|---|---|
| chat local | 33060 | 8090/8091/8092 (+8093 legacy) | 8080 | 80/443 | 5173 |
| chat test | 33061 | 8095/8096/8097 | — | 8086/8446 | — |
| framework test | 33062 | — | — | — | — |
| tasks local | 33063 | 8098/8099/8100 | 8081 | 81/444 | 5174 |
| tasks test | 33064 | 8101/8102/8103 | — | 8087/8447 | — |
| simple-poll local | 33065 | 8104/8105/8106 | 8082 | 82/445 | 5175 |
| simple-poll test | 33066 | 8107/8108/8109 | — | 8088/8448 | — |

A new project takes the next free block. Each local network also needs its own
subnet (`172.26/16` chat, `172.27/16` tasks, `172.28/16` poll, …) because the
cli reaches the daemon by a static IP (`HILOS_DAEMON_HOST`).

**Worker pool.** The daemon pre-starts `WORKER_MIN_REGULAR` regular and
`WORKER_MIN_MONOPOLISTIC` monopolistic workers (regular ones scale up to
`WORKER_MAX_REGULAR`). Set these in the daemon's compose `environment`, NOT in
`.env`/`.env.example`: `EnvAccessor` resolves a key as container env (compose) →
`.env` → `.env.example` → catalog default, so the stack that launched the node
has the last word and a value pinned in an env file is only a default. The
framework catalog defaults are 3 / 2 / 10.

The price of that single rule, named here so it is read rather than discovered:
on a running stack, editing `.env` no longer changes anything for a variable the
compose file sets. Change it where the stack sets it, or unset it there.

Monopolistic sizing is load-bearing: each monopolistic agent claims its own
monopolistic worker (one holding zero agents), and there is no on-demand spawn —
a subscription that finds no free monopolistic worker crashes the daemon. So
`WORKER_MIN_MONOPOLISTIC` must cover every monopolistic agent that can be live at
once. A project that mounts the SDK application shell (`HilosLayout`) gains a
second monopolistic agent for free: the shell's gear subscribes the Hilos
dashboard, owned by the monopolistic `hilos_index` agent (a concrete
`AbstractHilosIndexAgent` + an `AbstractHilosDashboardPage`; see
demo/tasks). The app agent plus the dashboard therefore needs
`WORKER_MIN_MONOPOLISTIC` ≥ 2 — which is also the catalog default. The demos pin
it in compose regardless, so the pool is explicit: tasks and simple-poll use 7 on
the local and test stacks and 4 in prod, chat 15 for its larger agent roster.

Every framework feature a project activates can raise that floor, and the logs
feature is one of them: it requires the monopolistic `hilos_log_store` agent
(HIL-753), so activating it costs one more monopolistic worker. The symptom of
forgetting is not a warning but a crash loop — `NoSuitableWorkerException` in the
daemon loop, and every page stuck at `data-state="loading"` because no daemon is
left to answer the subscription.

## Composer script lifecycle

Mirror the tasks demo's `composer.json` scripts: `setup-env` (copies BOTH
`.env.example→.env` and `tests/.env.example→tests/.env`; runs in the node cli
container to avoid the env_file chicken-and-egg), `install-deps`,
`daemon-start[-build]/stop/restart/status`, `cli`, `db:migration:*`,
`db:seed:apply`, `db:schema:status`, `pma`, `frontend:*`, and the test lane:
`test:up/down/down-volumes/db-wait/db-reset/unit/phpunit/install-deps`,
`test:check`, `test:e2e-build/install/check/up/(run)/down/full`.
`test:e2e-up` = mysql up → `db:wait` → `db:test:reset` → daemon + nginx up.
Always `docker compose` (not the legacy `docker-compose`), and
`config.process-timeout: 0` (image pulls outlive composer's 300s default).

## Frontend (common ground)

The frontend is an independent consumer of the Hilos SDK: it pulls
`@hilos/<view>` via a local `file:` dependency into
`framework/frontend/<view>` and follows the committed spec in
`docs/agents/frontend/`. Rules that apply to every framework:

- ALL node tooling runs in project-defined containers — never host npm/node
  (`docs/agents/frontend/build-and-docker.md`).
- Vite-based apps (Vue, React) must widen `server.fs.allow` to the monorepo
  root with a relative path (`allow: ['../../..']`, resolved from the config's
  app root — no `node:url`/`@types/node` needed): the SDK is a `file:`
  dependency symlinked from `framework/frontend`, and Vite's dev server refuses
  to serve assets outside the app root — so the Bootstrap-Icons font the view
  layer ships would 403 in dev (the production build inlines it, so this is
  dev-only and the e2e build never catches it).
- One `HilosConnection` per app; URL = same-origin `/ws` (nginx proxies it in
  test/prod); a `buildMismatch` listener calls `location.reload()`.
- Stable-id selectors: interactive elements carry `data-id`; Playwright uses
  `testIdAttribute: 'data-id'`.
- e2e runs against the BUILT artifact through the prod-parity nginx with a
  booted daemon: two-phase readiness in `global-setup.ts` (static HEAD, then a
  `/ws` upgrade-101 probe), then a `connected` assertion. Copy
  `demo/tasks/tests/e2e/` wholesale.
- The e2e package pins `@playwright/test` to the exact runner image version.

How the DEV page reaches the daemon differs per framework — see
[frontend-vue.md](frontend-vue.md), [frontend-react.md](frontend-react.md),
[frontend-angular.md](frontend-angular.md).

## Verification checklist for a new project

Run everything through the composer scripts (containers only):

1. `composer validate` + `composer run install-deps` (generates the lock —
   commit it).
2. `composer run test:unit` — the topology registry test is green.
3. `composer run test:e2e-full` — the connection spec asserts a live
   `connected` through nginx `/ws`.
4. `composer run test:down` leaves zero containers/networks behind.
5. From the repo root, `composer run test:frontend:all` stays green.

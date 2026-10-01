# Creating a new Hilos project (backend + frontend)

How to stand up a new end project on the Hilos framework: a PHP backend
(daemon + workers + agents) and a browser frontend on the Hilos SDK, both
running in project-owned docker stacks. The reference implementations, from
minimal to full:

| Project | Backend | Frontend | Role |
|---|---|---|---|
| [demo/tasks](../../demo/tasks) | one app agent, every admin section | React | every framework feature activated |
| [demo/polls](../../demo/polls) | one app agent, every admin section | Angular | every framework feature activated, Angular toolchain |
| [demo/binance-btc-tracker](../../demo/binance-btc-tracker) | minimal | Vue | the smallest complete shape on Vue: sign-in and an empty home — copy this; its cluster stand and the operations e2e: [testing.md](../agents/testing.md) |
| [demo/ecommerce-shop](../../demo/ecommerce-shop) | minimal | React | the smallest complete shape on React: sign-in and an empty home — copy this; its cluster stand and the operations e2e: [testing.md](../agents/testing.md) |
| [demo/online-testing](../../demo/online-testing) | minimal | Angular | the smallest complete shape on Angular: sign-in and an empty home — copy this; its cluster stand and the operations e2e: [testing.md](../agents/testing.md) |
| [demo/chat](../../demo/chat) | full | Vue | every subsystem in real use |

The frontend specifics are split per view framework:
[frontend-vue.md](frontend-vue.md) · [frontend-react.md](frontend-react.md) ·
[frontend-angular.md](frontend-angular.md). Everything below applies to all
three.

Before writing backend code, read `agents.md` at the repo root — especially
the **Contract approval gate**: a new project declares pages, agent types, and
router defaults, which are gated contract surfaces.

Install **git-lfs** on every machine holding a framework checkout, run
`git lfs install`, then `git lfs pull` to materialize `framework/data/`.
Package archives must include their Git LFS objects. Daemon, worker, and
docker watchdog startup refuses an unmaterialized pointer with its file name
and the repair command; the CLI stays available for repair.

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
    e2e/               # Playwright package (own package.json; own eslint and prettier configs)
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

Namespace `Demo\<Name>\`, autoload root `backend/`. The binance-btc-tracker
backend is the canonical minimal set — the base below plus sign-in by password,
the empty admin dashboard and the four footer pages, 47 PHP files and one
migration per framework table it needs; mirror it file by file:

1. **Bootstrap** (`backend/Bootstrap/`): `docker.php` (container PID-1:
   env init → DB connect with retry → migrations → `Hilos::init()` → watchdog
   over `daemon.php`), `daemon.php` (servers + `/status` route + main loop),
   `worker.php`, `cli.php`, `phpunit.php`. All five are boilerplate — copy and
   rename the namespace.
2. **Facade** `backend/Hilos.php` extends `\Hilos\Hilos`: `PAGES`, `AGENTS`
   registries, `createDb()` (the only abstract member), optional
   `ENV_CATALOG`, and `DATABASE_GUARANTEES` naming both
   `DatabaseGuarantee::ONE_LOGICAL_DATABASE` and
   `DatabaseGuarantee::READ_AFTER_WRITE` — required: without both the daemon
   does not start (see *Database Guarantees* in
   [app-topology.md](../agents/app-topology.md)).
   `GROUPS`/`TABLES`/`BROWSER_TABLES`/`PAGE_TABLES` default to
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
   registers the collection unconditionally. A project that builds an RT
   context owes a second one: a copy of `create_hilos_verifier_circle.sql`
   (+`_down`) from the same directory — the context mounts the freeze row, a
   node that can freeze admits its verification window by the circle, and the
   topology unit test below refuses the project without it.
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
app key (`chat-…`, `polls-…`, `tasks-…`) so it stays globally unique
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

| Stack | mysql | daemon (status/comm/ws) | pma | mailpit | nginx | FE dev | subnet |
|---|---|---|---|---|---|---|---|
| chat local | 33060 | 8090/8091/8092 (+8093 legacy) | 8080 | 8025 (dev 8028) | https 443 | 5173 | 10.196 |
| chat test | 33061 | 8095/8096/8097 | — | — | http 8086 / https 8446 | — | 10.186 |
| framework test | 33062 | — | — | — | — | — | — |
| tasks local | 33063 | 8098/8099/8100 | 8081 | 8026 (dev 8029) | https 444 | 5174 | 10.197 |
| tasks test | 33064 | 8101/8102/8103 | — | — | http 8087 / https 8447 | — | 10.187 |
| polls local | 33065 | 8104/8105/8106 | 8082 | 8027 (dev 8030) | https 8445 | 5175 | 10.198 |
| polls test | 33066 | 8107/8108/8109 | — | — | http 8088 / https 8448 | — | 10.188 |
| binance-btc-tracker local | 33067 | 8110/8111/8112 | 8119 | 8120 | https 8116 | 5176 | 10.201 |
| binance-btc-tracker test | 33068 | 8113/8114/8115 | — | — | http 8117 / https 8118 | — | 10.211 |
| binance-btc-tracker cluster | — | — | — | — | — | — | 10.221 |
| ecommerce-shop local | 33069 | 8130/8131/8132 | 8139 | 8140 | https 8136 | 5177 | 10.202 |
| ecommerce-shop test | 33070 | 8133/8134/8135 | — | — | http 8137 / https 8138 | — | 10.212 |
| ecommerce-shop cluster (not in the code yet — HIL-1216) | — | — | — | — | — | — | 10.222 |
| online-testing local | 33071 | 8150/8151/8152 | 8159 | 8160 | https 8156 | 5178 | 10.203 |
| online-testing test | 33072 | 8153/8154/8155 | — | — | http 8157 / https 8158 | — | 10.213 |
| online-testing cluster | — | — | — | — | — | — | 10.223 |
| demo/cluster — retires, and 10.185 stays unassigned (not in the code yet — HIL-1218) | — | — | — | — | — | — | 10.185 |

Every number comes from the stack's own compose file — the `${…:-N}` defaults
of `demo/<demo>/docker/docker-compose.{local,dev,test}.yml` and of
`framework/docker/docker-compose.yml` — and the subnet from the same files
(`DOCKER_NETWORK_SUBNET`, `DOCKER_TEST_NETWORK_SUBNET`). Local nginx publishes
https only; Mailpit is published by the local and dev stacks.

A demo from binance-btc-tracker on takes a block of twenty host ports in the
81xx range — 8110–8129, 8130–8149, 8150–8169; the next project takes
8170–8189 — with the same offsets in every block: +0/+1/+2 the local daemon
status/comm/ws, +3/+4/+5 the test daemon, +6 the local nginx https, +7/+8 the
test nginx http/https, +9 phpMyAdmin, +10 the local Mailpit, +11…+19 kept for
the demo's later stacks. mysql and FE dev keep their own rows: the next
project takes 33073/33074 and 5179. A block, because the rows of the first
three demos have no room left: the next test http port would be 8089, and
8090 is already the chat daemon; and the `443+n` scheme for local https is
dead on Windows, where SMB holds 445 — which is why polls sits on 8445.

Each network also needs its own subnet, because the cli reaches the daemon by
a static IP (`HILOS_DAEMON_HOST`), and that subnet must sit **outside Docker's
default address pools** (`172.16.0.0/12` and `192.168.0.0/16`). A subnet
claimed inside a pool Docker also hands out loses the race against whichever
network came up first, and the stand then fails to start with `Pool overlaps
with other one on this address space`. `10.0.0.0/8` is never auto-assigned, so
the ranges live there. The first three demos sit in the `10.18x` row for test
and in the `10.19x` row for local+prod+dev; from the second generation on a
demo takes one column across three rows — `10.20x` local+prod+dev, `10.21x`
test, `10.22x` the cluster stand — and the column is the demo: 1
binance-btc-tracker, 2 ecommerce-shop, 3 online-testing. The next project
takes the next column: `10.204` / `10.214` / `10.224`.

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

The monopolistic minimum is a warm-up, not a ceiling. Each monopolistic agent
claims its own monopolistic worker (one holding zero agents); an agent that finds
none free orders one on the spot and waits for it to register, and the frames
addressed to it are held by the master meanwhile (HIL-998). A wait that runs past
`AgentConstants::START_DEADLINE_SECONDS` is refused the way any start refused on
this node is — a page gets its subscription error, the project one
`AGENT_START` card — and the daemon keeps running (HIL-999). So
`WORKER_MIN_MONOPOLISTIC` only decides how many agents come up without that wait
of a second or two; zero is a working value, and the catalog default of 2 covers
an app agent plus the Hilos dashboard of the SDK application shell. The demos do
not pin it: the warm-up is paid on every node start, one worker a second, and a
number that grows with the agent roster is exactly what a new feature used to
have to remember. The cluster stands pin it by what a node carries, not by its
rank — 1 on a node with placed work, 0 on a node without; on online-testing the
masters carry work and take the 1 — because
there it says what a node is for, not how big a pool is.

The pool grows by one bound: **a monopolistic agent may not be per-instance.** A
start of a monopolistic agent with an index is refused rather than given a worker
of its own, so the pool follows the monopolistic agent TYPES a node hosts and never
the number of entities. The rule sits at the growth site, not in the topology
validator, because monopolistic-ness is declared by the daemon instance, which the
validator never builds. The pool does not shrink either: a worker lives until the
node stops, and a freed one is handed to the next agent that needs one.

Every framework feature a project activates still costs workers — the logs feature
runs two monopolistic agents, `hilos_log_store`, which owns the directory
(HIL-753), and `hilos_log_carrier`, which moves rotated batches into the archive
(HIL-870); settings runs one, `hilos_settings_library` (HIL-946) — but the cost is
paid by the pool growing, not by an env line the project has to remember.

## Composer script lifecycle

Mirror the binance-btc-tracker demo's `composer.json` scripts: `setup-env` (copies BOTH
`.env.example→.env` and `tests/.env.example→tests/.env`; runs in the node cli
container to avoid the env_file chicken-and-egg), `install-deps`,
`daemon-start[-build]/stop/restart/status`, `cli`, `db:migration:*`,
`db:seed:apply`, `db:schema:status`, `pma`, `frontend:*`, and the test lane:
`test:up/down/down-volumes/db-wait/db-reset/unit/phpunit/install-deps`,
`test:check`, `test:e2e-build/install/check/up/(run)/down/full`.
`test:e2e-up` = mysql up → `db:wait` → `test:db:reset` → daemon + nginx up.
Every link of `test:e2e-full` but `@test:e2e` carries ` @no_additional_args` —
that is what lets the cycle be pointed (`composer run test:e2e-full -- <spec>`),
and `E2eFullPointingTest` in the framework unit suite checks the chain of every
demo that declares one. Always `docker compose` (not the legacy `docker-compose`),
Composer ≥ 2.8.0 (the marker is read from that version on), and
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
  `demo/binance-btc-tracker/tests/e2e/` wholesale — the package, the helpers
  and the three specs of the smallest shape (smoke, connection, sign-in).
- The e2e package pins `@playwright/test` to the exact runner image version.

How the DEV page reaches the daemon differs per framework — see
[frontend-vue.md](frontend-vue.md), [frontend-react.md](frontend-react.md),
[frontend-angular.md](frontend-angular.md).

## Verification checklist for a new project

Verify that git-lfs is installed on the checkout's machine and
`framework/data/` contains real files after `git lfs pull` (or that the package
archive includes its LFS objects). Then run everything through the composer
scripts (containers only):

1. `composer validate` + `composer run install-deps` (generates the lock —
   commit it).
2. `composer run test:unit` — the topology registry test is green.
3. `composer run test:e2e-full` — the connection spec asserts a live
   `connected` through nginx `/ws`.
4. `composer run test:down` leaves zero containers/networks behind.
5. From the repo root, `composer run test:frontend:all` stays green.

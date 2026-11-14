# Creating a new Hilos project (backend + frontend)

How to stand up a new end project on the Hilos framework: a PHP backend
(daemon + workers + agents) and a browser frontend on the Hilos SDK, both
running in project-owned docker stacks. The demos, each with what it carries:

| Project | Backend | Frontend | Role |
|---|---|---|---|
| [demo/tasks](../../demo/tasks) | one app agent, every admin section | React | every admin section on React |
| [demo/polls](../../demo/polls) | one app agent, every admin section | Angular | every admin section on Angular, Angular toolchain |
| [demo/binance-btc-tracker](../../demo/binance-btc-tracker) | minimal | Vue | the smallest complete shape on Vue: sign-in and an empty home — copy this; its cluster stand: [testing.md](../agents/testing.md) |
| [demo/pmd](../../demo/pmd) | service database | Vue | active carrier: empty home; sign-in, profile and Security; admin Analytics, Users, Settings, Appearance and Communications |
| [demo/roblox-game-server](../../demo/roblox-game-server) | minimal | Vue | active carrier: empty home; sign-in and profile; admin Analytics, Communications, Change Log, Moderation, Daemon, Backup, Logs and Maintenance |
| [demo/ecommerce-shop](../../demo/ecommerce-shop) | minimal | React | the smallest complete shape on React: sign-in and an empty home — copy this; its cluster stand and the operations e2e: [testing.md](../agents/testing.md) |
| [demo/solar-system-model](../../demo/solar-system-model) | minimal | React | active carrier: empty public home, sign-in at `/hilos`, profile; admin Analytics, Users, Settings |
| [demo/online-testing](../../demo/online-testing) | minimal | Angular | the smallest complete shape on Angular: sign-in and an empty home — copy this; its cluster stand and the operations e2e: [testing.md](../agents/testing.md) |
| [demo/preferans](../../demo/preferans) | minimal | Angular | an active Angular carrier with password sign-in, an empty home, profile, settings, i18n, Appearance and Analytics; local/test stacks and e2e: [testing.md](../agents/testing.md) |
| [demo/concert-tickets](../../demo/concert-tickets) | minimal | Angular | an active Angular carrier with sign-in (password, passkey, SMS, GitHub), an empty home, profile, and admin Daemon, Logs, Change Log and Analytics; local/test stacks and e2e: [testing.md](../agents/testing.md) |
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
  .env.example         # committed; .env is gitignored
  backend/             # the PHP application
  frontend/            # the SDK consumer app (see the per-framework docs)
  tests/
    phpunit.xml        # unit suite; bootstrap = backend/Bootstrap/phpunit.php; registers the process-environment extension
    Unit/              # at minimum: the topology registry test
    .env               # test DB coordinates; tracked, the same on every checkout
    e2e/               # Playwright package (own package.json; own eslint and prettier configs)
  docker/
    Dockerfile         # php:8.5.12-cli + sockets/pcntl/posix/mysqli/pdo + pinned, patched event 3.1.6
    Dockerfile.nginx   # pinned nginx:1.31.8-alpine + envsubst + self-signed TLS entrypoint
    nginx.conf.template
    docker-entrypoint-nginx.sh
    mysql/init.sql     # first-boot DB + grants (project DB name hardcoded)
    docker-compose.local.yml
    docker-compose.test.yml
  data/                # mysql datadir bind, daemon logs (gitignored content)
```

The exact PHP image digest is pinned in the
[canonical minimal demo Dockerfile](../../demo/binance-btc-tracker/docker/Dockerfile).
It loads sockets before the shared event installer, which builds PECL event
3.1.6 with OpenSSL and sockets support and the Hilos peer-certificate and
buffer-event lifetime patch.
The required APIs are `EventBufferEvent::sslSocket()` and
`EventBufferEvent::sslGetPeerCertificate(): ?string`; the version alone does
not establish that the patch is present, and the getter alone does not prove
the lifetime repair. Rebuild consumers after native asset updates. Follow the
[native build guide](../../framework/docker/native/event/README.md) for
installation, patch updates and license notices, and the
[root README](../../README.md) for the environment required outside Docker.

## Minimal backend file set

Namespace `Demo\<Name>\`, autoload root `backend/`. The binance-btc-tracker
backend is the canonical minimal set — the base below plus sign-in by password,
the empty admin dashboard and the four footer pages, 47 PHP files. Mirror its
scaffold code file by file; create framework table migrations with the composite
baseline command in step 7:

1. **Bootstrap** (`backend/Bootstrap/`): `docker.php` (container PID-1:
   env init → DB connect with retry → migrations → `Hilos::init()` → watchdog
   over `daemon.php`), `daemon.php` (servers + `/status` route + main loop),
   `worker.php`, `cli.php`, `phpunit.php`. All five are boilerplate — copy and
   rename the namespace.
2. **Facade** `backend/Hilos.php` extends `\Hilos\Hilos`: declare the framework
   features this project includes in `FEATURES`, then add its `PAGES`, `AGENTS`
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
7. **Database**: `Database` (`openConnections()` configures and connects from
   `DB_*` env and is what `docker.php` calls; `initialize()` adds the schema
   read and `Hilos::init()`),
   `<Name>DbContext extends HilosDbContext` (can be empty — the base registers
   the framework settings collection), and an `EnvCatalog` overriding the
   `DB_DATABASE` default (the framework stub default is an empty string). The
   settings collection is mounted unconditionally, so declare
   `HilosFeature::SETTINGS` in `FEATURES`; the baseline selects its required
   `setting` unit. Create the empty project track and generate its initial
   framework schema from the facade's `FEATURES` declaration:

   ```bash
   mkdir -p backend/Database/Migration/Schema
   php backend/Bootstrap/cli.php db:framework:baseline
   ```

   The database-free command writes one composite up/down pair using the current
   framework stubs for the core units and the units required by the declared
   features. It also selects `verifier_circle` when the facade builds an RT
   context. The origin records exact selected units, change parts, and body
   hashes; bootstrap-owned units are recorded there but their DDL remains with
   migration initialization. Commit the pair, then use the ordinary
   `db:migration:up` command to apply it. The baseline command does not connect
   to a database or execute SQL. Repeating it skips a verified baseline without
   rebuilding it; after an interrupted write it completes only an exact matching
   half-pair. Keep project-owned schema changes in their own migrations. Use
   `db:framework:pull` for later framework changes and newly selected feature
   units; this is not a legacy schema adoption command. The deferred feature
   check in the topology test below still verifies the selected schema units,
   including `verifier_circle` for projects with an RT context.
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

Load-bearing boot order in `docker.php`:
`Database::openConnections(retryConnection: true)` → `Migration::migrateUp()` →
`Hilos::init()`. Calling `Hilos::init()` before migrations breaks first boot on
an empty DB. The connect step does not read the schema: a neighbouring
migration that dropped a table would otherwise crash the start (HIL-2086).

## Docker stacks

Two compose files per project for development (local and test); every
`container_name` in them is prefixed with the app key (`chat-…`, `polls-…`,
`tasks-…`) so it stays globally unique (`container_name` and explicitly named
volumes are docker-GLOBAL, not project-scoped — never reuse another project's).

**Production stack.** A project that goes to production adds a third file,
`docker/docker-compose.prod.yml`, and leaves the installation's identity to
the installation. No service sets `container_name`: `docker compose -p
<environment>` names everything, so two environments of one project can stand
on one host. Keep the file's `name:` line: `-p` overrides it, and a manual
start without `-p` would otherwise take its project name from the `docker`
directory, the same for every project. The subnet and the three fixed
addresses have no default:

```yaml
- subnet: ${DOCKER_NETWORK_SUBNET:?set DOCKER_NETWORK_SUBNET in docker/.env}
ipv4_address: ${DOCKER_DAEMON_IP:?set DOCKER_DAEMON_IP in docker/.env}
```

An installation takes one `/24` per environment on its host from
`10.240.0.0/16` (`10.240.N.0/24`: database `.10`, daemon `.11`, nginx `.12`),
plus its own `HILOS_DATA_ROOT` and published ports. `DemoStackComposeGuardTest`
holds this for every demo.

**PHP build context.** When copying a minimal demo's Dockerfile, preserve
`COPY --from=hilos-event . /tmp/hilos-event/` and the following installer run.
Before the first build, supply the named context `hilos-event` pointing at
`framework/docker/native/event`. With Docker Compose 2.17 or later and a
BuildKit builder supporting additional contexts, declare
`build.additional_contexts` on every PHP build: daemon, cli, monitor and gateway
where present, including test and cluster tooling. Keep each principal build
context as it is; the shared installer and patch stay in the native directory.

For example, these build entries come from
[the minimal demo's local stack](../../demo/binance-btc-tracker/docker/docker-compose.local.yml)
(other service settings omitted):

```yaml
services:
  binance-btc-tracker-daemon-local:
    build:
      context: .
      additional_contexts:
        hilos-event: ../../../framework/docker/native/event
  binance-btc-tracker-cli-local:
    build:
      context: .
      additional_contexts:
        hilos-event: ../../../framework/docker/native/event
```

Those paths are for `demo/binance-btc-tracker/docker/docker-compose.local.yml`.
Resolve paths from the corresponding Compose model when adapting a stack,
including its `include` and `extends` relationships; do not assume a fragment's
directory is always the base. Check the resolved builds with `docker compose
-f <compose-file> config` before building.

For a direct build, pass `--build-context hilos-event=<native directory>`.
This example runs from the repository root and keeps the demo's principal
context at its Docker directory:

```sh
docker build --build-context hilos-event=framework/docker/native/event \
  -f demo/binance-btc-tracker/docker/Dockerfile demo/binance-btc-tracker/docker
```

**Local** (`docker-compose.local.yml`, the developer sandbox): mysql (datadir
bind-mounted at `data/mysql`, `mysql/init.sql`), the daemon, a cli
service (profile `cli`), the prod-parity nginx (profile `full`), and the
frontend dev server (no profile — `composer run daemon-start` brings the whole
stack up). The daemon/cli `env_file` uses the long form with
`required: false`: a fresh checkout boots on the compose environment plus
`.env.example`, and `.env` itself appears only when someone runs
`composer run setup-env`.

**Test** (`docker-compose.test.yml`, the agent/CI lane): mysql (data in tmpfs,
write durability reduced by `framework/docker/mysql/test-stand.cnf`, healthcheck),
the daemon (`env_file: ../tests/.env` — tracked, the same on every checkout, so a
fresh clone runs its tests with no setup step; `LOG_ROTATION_CRON=0 3 29 2 *` in its `environment`, so
the nightly log rotation never runs under a test), a cli service, the prod-parity
nginx serving `frontend/dist` with the `/ws` upgrade proxy (profile `e2e`), the
Playwright runner (its image tag MUST equal the `@playwright/test` version).
Teardown always via `--profile "*" down` or profiled services leak.

A stand never shares the local installation's `data/`: the test stack mounts
`../data/test:/app/data` on its daemon and cli, and its nginx reads exports,
Status and files from `data/test/`; a cluster stand mounts a named data volume of
its own, suffixed with `${HILOS_STAND_SUFFIX:-}`, at `/app/data` on every node and
its CLI, with Status on a named volume of its own over it. A new project that
copies a demo's stand copies its data root too, and
`framework/tests/Unit/StandDataRootGuardTest.php` holds it to that
([Data root of a stand](../agents/testing.md#the-full-run--one-graph-a-bounded-number-of-lanes)).

**Ports.** In-container daemon ports are FIXED for every project: 8090
(status) / 8091 (worker comm) / 8092 (websocket) — the nginx template proxies
`/ws` to the hardcoded `:8092`. Only host-side publishes shift. Current
registry of taken host ports:

| Stack | mysql | daemon (status/comm/ws) | mailpit | nginx | FE dev | subnet |
| --- | --- | --- | --- | --- | --- | --- |
| chat local | 33060 | 8090/8091/8092 (+8093 legacy) | 8025 (dev 8028) | https 443 | 5173 | 10.196 |
| chat test | 33061 | 8095/8096/8097 | — | http 8086 / https 8446 | — | 10.186 |
| framework test | 33062 | — | — | — | — | 10.189 |
| tasks local | 33063 | 8098/8099/8100 | 8026 (dev 8029) | https 444 | 5174 | 10.197 |
| tasks test | 33064 | 8101/8102/8103 | — | http 8087 / https 8447 | — | 10.187 |
| polls local | 33065 | 8104/8105/8106 | 8027 (dev 8030) | https 8445 | 5175 | 10.198 |
| polls test | 33066 | 8107/8108/8109 | — | http 8088 / https 8448 | — | 10.188 |
| binance-btc-tracker local | 33067 | 8110/8111/8112 | 8120 | https 8116 | 5176 | 10.201 |
| binance-btc-tracker test | 33068 | 8113/8114/8115 | — | http 8117 / https 8118 | — | 10.211 |
| binance-btc-tracker cluster | — | — | — | — | — | 10.221 |
| ecommerce-shop local | 33069 | 8130/8131/8132 | 8140 | https 8136 | 5177 | 10.202 |
| ecommerce-shop test | 33070 | 8133/8134/8135 | — | http 8137 / https 8138 | — | 10.212 |
| ecommerce-shop cluster | — | — | — | — | — | 10.222 |
| online-testing local | 33071 | 8150/8151/8152 | 8160 | https 8156 | 5178 | 10.203 |
| online-testing test | 33072 | 8153/8154/8155 | — | http 8157 / https 8158 | — | 10.213 |
| online-testing cluster | — | — | — | — | — | 10.223 |
| pmd local | 33073 | 8170/8171/8172 | 8180 | https 8176 | 5179 | 10.204 |
| pmd test | 33074 | 8173/8174/8175 | — | http 8177 / https 8178 | — | 10.214 |
| roblox-game-server local | 33075 | 8190/8191/8192 | 8200 | https 8196 | 5180 | 10.205 |
| roblox-game-server test | 33076 | 8193/8194/8195 | — | http 8197 / https 8198 | — | 10.215 |
| roblox-game-server cluster | — | — | — | — | — | 10.225 |
| solar-system-model local | 33077 | 8210/8211/8212 | 8220 | https 8216 | 5181 | 10.206 |
| solar-system-model test | 33078 | 8213/8214/8215 | — | http 8217 / https 8218 | — | 10.216 |
| file-gallery-manager local | 33079 | 8230/8231/8232 | 8240 | https 8236 | 5182 | 10.207 |
| file-gallery-manager test | 33080 | 8233/8234/8235 | — | http 8237 / https 8238 | — | 10.217 |
| file-gallery-manager cluster | — | — | — | — | — | 10.227 |
| preferans local | 33081 | 8250/8251/8252 | 8260 | https 8256 | 5183 | 10.208 |
| preferans test | 33082 | 8253/8254/8255 | — | http 8257 / https 8258 | — | 10.218 |
| concert-tickets local | 33083 | 8270/8271/8272 | 8280 | https 8276 | 5184 | 10.209 |
| concert-tickets test | 33084 | 8273/8274/8275 | — | http 8277 / https 8278 | — | 10.219 |
| concert-tickets cluster (not in the code yet — HIL-2033) | — | — | — | — | — | 10.229 |
| retired five-node stand — 10.185 stays unassigned (HIL-1218) | — | — | — | — | — | 10.185 |

Every number comes from the stack's own compose file — the `${…:-N}` defaults
of `demo/<demo>/docker/docker-compose.{local,dev,test}.yml` and of
`framework/docker/docker-compose.yml` — and the subnet from the same files
(`DOCKER_NETWORK_SUBNET`, `DOCKER_TEST_NETWORK_SUBNET`). Local nginx publishes
https only; Mailpit is published by the local and dev stacks. A row whose
Stack cell carries a marker is reserved for that activation; its compose
carries exactly these defaults.

A demo from binance-btc-tracker on takes a block of twenty host ports in the
81xx range — 8110–8129, 8130–8149, 8150–8169 — with the same offsets in every
block: +0/+1/+2 the local daemon status/comm/ws, +3/+4/+5 the test daemon, +6
the local nginx https, +7/+8 the test nginx http/https, +10 the local Mailpit;
+9 and +11…+19 are kept for the demo's later stacks. mysql and FE dev keep
their own rows. Six blocks, 8170–8289, mysql 33073–33084 and FE dev 5179–5184,
are reserved by the rows above; the next project takes 8290–8309, 33085/33086
and 5185. A block, because the rows of the first three demos have no room
left: the next test http port would be 8089, and 8090 is already the chat
daemon; and the `443+n` scheme for local https is dead on Windows, where SMB
holds 445 — which is why polls sits on 8445.

Each network also needs its own subnet, because the cli reaches the daemon by
a static IP (`HILOS_DAEMON_HOST`), and that subnet must sit **outside Docker's
default address pools** (`172.16.0.0/12` and `192.168.0.0/16`). A subnet
claimed inside a pool Docker also hands out loses the race against whichever
network came up first, and the stand then fails to start with `Pool overlaps
with other one on this address space`. `10.0.0.0/8` is never auto-assigned, so
the ranges live there. The first three demos sit in the `10.18x` row for test
and in the `10.19x` row for local+dev; from the second generation on a
demo takes one column across three rows — `10.20x` local+dev, `10.21x`
test, `10.22x` the cluster stand — and the column is the demo: 1
binance-btc-tracker, 2 ecommerce-shop, 3 online-testing, 4 pmd, 5
roblox-game-server, 6 solar-system-model, 7 file-gallery-manager, 8 preferans,
9 concert-tickets. Columns 1–9 are taken (twelve ordinary demos are approved);
the tenth project needs a new rule for the rows. Column 10 (`10.210` /
`10.220` / `10.230`) is not promised: it breaks the `10.20x` / `10.21x` /
`10.22x` notation. A stand instance moves its test network off its default
onto the octet of its own block
([Stand instances](../agents/testing.md#the-full-run--one-graph-a-bounded-number-of-lanes)).
Production installations sit outside these rows, in `10.240.0.0/16` (see
**Production stack** above).

**Project time zone.** Copy the approved
[warning and full consequences](../agents/architecture/calculated-results.md#warning-at-the-configuration-source)
into your project's own `.env.example`, immediately before the commented
`# HILOS_PROJECT_TIME_ZONE=UTC` assignment; the
[Binance example](../../demo/binance-btc-tracker/.env.example) shows the complete
block. Projects do not inherit the root `.env.example`. Keep the OLAP reset
marked **PLANNED** until HIL-1402 implements it. Use the same zone on every
cluster node and restart all nodes after changing it.

History without retained raw data cannot be rebuilt; archived raw data must be
available to the normal rebuild pipeline. A zone change can alter the calendar
buckets used by ordinary backup retention to select what to keep or delete;
it does not itself delete all backups. See the
[time-zone guide](../agents/architecture/time-zones.md) and the full consequences
above for calendar boundaries, cron, server text in project mode or using the
project-zone fallback, and the planned reset of current OLAP cubes and dependent
results until rebuilt.

Existing `.env` files do not receive new comments automatically:
`composer run setup-env` uses `cp -n`, which preserves an existing file instead
of overwriting it. Process environment or Compose can also set the value without
going through the example. This visibility limit is accepted; it does not add a
confirmation gate or a startup stop.

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
10 seconds (`WorkerServer::WORKER_CONNECT_WAIT_SECONDS`) is refused the way any
start refused on this node is — a page gets its subscription error, the project
one `AGENT_START` card — and the daemon keeps running (HIL-999). So
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

Each framework feature a project activates still costs workers — the logs feature
runs two monopolistic agents, `hilos_log_store`, which owns the directory
(HIL-753), and `hilos_log_carrier`, which moves rotated batches into the archive
(HIL-870); settings runs one, `hilos_settings_library` (HIL-946) — but the cost is
paid by the pool growing, not by an env line the project has to remember.

## Composer script lifecycle

Mirror the binance-btc-tracker demo's `composer.json` scripts: `setup-env` (copies
`.env.example→.env`; `tests/.env` is tracked and needs no copy; runs in the node cli
container to avoid the env_file chicken-and-egg), `install-deps`,
`daemon-start[-build]/stop/restart/status`, `cli`, `db:migration:*`,
`db:seed:apply`, `db:schema:status`, `frontend:*`, and the test lane:
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

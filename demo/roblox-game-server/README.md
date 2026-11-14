# Roblox Game Server Demo Project

**Complexity: 5/5**

Demo project demonstrating game server architecture for Roblox with low latency.

Roblox Game Server is an active Vue demo. Today it is the application shell with
the framework's operations sections; the game server described under
[What is this?](#what-is-this) is what it is going to show.

## What runs today

- The Vue application shell has an empty home page and the standard sign-in
  flow; registration and recovery confirm the address with a code sent by mail.
- Signed-in users have the standard profile page, with its photo, and Profile
  notifications.
- The `/hilos` admin area has Analytics, Users, Settings, Appearance,
  Communications, Change Log, Moderation, Daemon, Backup, Logs and Maintenance.
- The Change Log journals `hilos_user`, `hilos_identity`, `hilos_second_factor`,
  `hilos_setting` and `hilos_moderation_rule` into its own database beside the
  primary one, on the same MariaDB server.
- Local development and the test stand each use this project's MariaDB service.
- The cluster stand runs three masters and one slave over a MariaDB Galera
  cluster of two data nodes with an arbitrator (see [Cluster stand](#cluster-stand)).
  There the Change Log journal lives in the same Galera cluster: it is made on
  the first data node and the second receives it by copy.

The game itself — rooms, teams, ratings, leagues and bots — is not built yet.

## What is this?

This is a game server implementation for Roblox with a specific game mode: team PvP arena with point capture.

### Features

- **Game mode**: Team PvP arena with point capture - players divided into teams fighting for control points
- **Scoring system**: Points, respawn, temporary bonuses for capturing points
- **Rating system**: Players earn points for wins, captures, kills - updated in real-time with leaderboard display
- **Leagues**: Different leagues by rating level (bronze, silver, gold, etc.)
- **Agent-based architecture**: Agents manage separate game sessions (rooms), each room is a separate agent for state isolation
- **High-frequency sync**: Synchronization of player positions, health, statuses at 60 FPS
- **AI bots**: AI agents manage bot players to fill rooms, analyze game situation, help with team balancing
- **Low-latency communication**: WebSocket ensures low-latency communication with Roblox clients
- **Admin panel**: Vue 3 + TypeScript frontend showing room statistics, active players, performance metrics, rating tables

### Technical Highlights

- Game logic implementation
- In-memory state management
- Concurrent event processing from multiple players
- Scaling through multiple agents for different game rooms
- Anti-cheat validation
- Respawn system
- Game resource management
- Real-time rating system updates

## Prerequisites and first setup

Use Composer 2.8 or newer, Docker Compose v2 and an available Docker daemon. Use
Composer to invoke the documented scripts; PHP and Node project dependencies,
checks and builds run in project containers.

From `demo/roblox-game-server`:

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
installs this demo's own frontend dependencies in its container. From
`demo/roblox-game-server`, run it after the framework workspace install:

```bash
composer run frontend:install
```

## Local application

From `demo/roblox-game-server`, start the local stack:

```bash
composer run daemon-start
```

This starts MariaDB, Mailpit, the daemon and the Vite development server. A
fresh MariaDB data directory also creates the Change Log journal's database. The
daemon applies pending migrations during startup. Register or sign in through
the app; local verification and recovery messages can be read in Mailpit.

| Local endpoint | Default address |
|---|---|
| Vue dev server with HMR | <http://localhost:5180> |
| Built app behind nginx | <https://localhost:8196> |
| Mailpit inbox | <http://localhost:8200> |
| Daemon status | <http://localhost:8190/status> |
| Daemon WebSocket | <ws://localhost:8192> |
| MariaDB from the host | `localhost:33075` |

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

The host ports below are the defaults in this demo's Compose files. Their
published values and stand identity can follow the shared Compose override and
stand instance configuration; the daemon's in-container ports remain 8090, 8091
and 8092.

## Database migrations

The Schema track starts with the immutable
`backend/Database/Migration/Schema/001_framework_baseline.sql` pair. Keep it
unchanged. Roblox Game Server is registered in the repository's
[`framework-projects.json`](../../framework-projects.json), so
`composer db:framework:pull` from the repository root includes it. For an
update of this demo only, run the normal pull from this project, review and
commit the generated migration pairs, then apply pending migrations:

```bash
composer run cli -- db:framework:pull
composer run db:migration:up
composer run db:migration:status
composer run db:schema:status
```

The baseline command is for a new, empty Schema track; it does not regenerate
this demo's existing history. See the
[migration guide](../../docs/agents/orm/migrations.md) for the framework pull
and migration rules.

The Change Log triggers of the journaled tables are files under
`backend/Database/Migration/Triggers`. To regenerate them on the test stand
after migrations, use `composer run test:change-log:generate`; repeating it
leaves matching files untouched. Commit the complete generated set before
starting a daemon. A local MariaDB volume made before the journal existed gets
its database, safely and repeatably, with:

```bash
docker compose -f docker/docker-compose.local.yml exec roblox-game-server-mysql-local \
  /usr/local/bin/provision-change-log.sh
```

See the [Change Log guide](../../docs/agents/architecture/change-log.md) for the
journal's database and its triggers.

## Tests

PHP commands run in the test CLI container:

| Command | What it does |
|---|---|
| `composer run test:install-deps` | Install the locked PHP dependencies in the test CLI container |
| `composer run test:unit` | Run the unit suite and topology registry guard |
| `composer run test:integration` | Run the integration suite against the test database |
| `composer run test:phpunit` | Run all PHPUnit suites |

For an integration run, prepare and clean up the test database from
`demo/roblox-game-server`:

```bash
composer run test:up
composer run test:db-reset
composer run test:integration
composer run test:down
```

`test:db-reset` recreates the primary test database and the Change Log journal's
database beside it.

Frontend and end-to-end commands:

| Command | What it does |
|---|---|
| `composer run test:check` | Install if needed, type-check/lint/format-check the app and lint/format-check e2e |
| `composer run test:e2e-build` | Build the app for the test stack |
| `composer run test:e2e-install` | Install the Playwright dependencies |
| `composer run test:e2e-check` | Type-check the e2e test code |
| `composer run test:e2e-up` | Raise the test stack: a prepared database, the daemon and nginx |
| `composer run test:e2e` | Run the suite against an already raised test stack |
| `composer run test:e2e-full` | Run the full clean e2e lifecycle |

The clean lifecycle tears down the prior test stack, prepares a fresh test
database, builds the frontend, installs and checks Playwright, starts the daemon
and nginx, runs the tests and tears the stack down. To point the same clean
cycle at one real spec, run from `demo/roblox-game-server`:

```bash
composer run test:e2e-full -- tests/activation.spec.ts
```

The test browser reaches nginx inside Compose as
`https://roblox-game-server-nginx-test`. For manual inspection from the host,
use `https://localhost:8198`. The test nginx also publishes HTTP on port `8197`,
which redirects to the default HTTPS port. Test MariaDB is published at
`localhost:33076`; its data directory is in memory and is discarded with the
container. The test daemon publishes status on `8193` and WebSocket on `8195`.
The test Mailpit service has no host port: the e2e runner reads its mailbox at
`http://roblox-game-server-mailpit-test:8025` inside the Compose network.
`test:e2e` runs the runner in the test daemon's PID namespace through
`docker/docker-compose.e2e-restart.yml`, so the Daemon spec can pause the
stand's own test worker by its process during a real agent restart; install,
check and build keep their own namespace. The test daemon keeps
its backups, exports and files under `data/test/` and its logs under
`data/logs-test/`, apart from the local installation's `data/`.

If a run stops before its final cleanup, use `composer run test:down`. To also
remove Compose's named volumes, use `composer run test:down-volumes` when their
data should be discarded.

For test selection and stand lifecycle details, see
[`docs/agents/testing.md`](../../docs/agents/testing.md) and the
[frontend testing strategy](../../docs/agents/frontend/testing-strategy.md).

## Cluster stand

`docker/docker-compose.cluster.yml` raises this demo as a Hilos cluster on one
Galera database: three masters, `m1`–`m3`, and one slave, `s1`. `m1` declares no
capacity, so it votes and hosts no placed work; `m2` and `m3` are
`worker,ram=10,slots=64`; `s1` is `worker,ram=4,slots=64`. The database is one
MariaDB Galera cluster of two data nodes, `galera1` and `galera2`, and the
`garbd` arbitrator: a third vote that stores no data and answers no SQL, so the
cluster keeps its majority when one data node is lost. One HAProxy is the only
database address the nodes and the CLI know, and the application writes to both
data nodes. The stand is a compose project of its own,
`hilos-roblox-game-server-cluster`, on the default subnet `10.225.0.0/16`, and
publishes nothing on the host: the e2e steps take their whole project down when
they start, and a fleet inside it would fall in the middle of its matrix.

HAProxy gives each node a data node of its own: `m1` and `m3` go to `galera1`,
`m2` and `s1` to `galera2`. With two data nodes and connections laid out by
load alone, every node could end up on the same one, and a row one node writes
would never be read from another data node. A node whose data node is down is
led to the living one. HAProxy marks a data node up while it is Synced or
donating a full copy (the agent check on port 9200), because the survivor is at
once the donor and the only SQL backend left. A read waits for what the cluster
committed (`wsrep_sync_wait=1`, the READ bit) in every application session,
reconnects included, and `wsrep_dirty_reads` is off.

The one nginx entry is `10.225.0.30` inside the stand network, unpublished on
the host. `hilos_stand_master=m1|m2|m3` selects the master behind it; without
the cookie nginx rotates over the masters, and an unknown name returns 421. The
nodes' `data_export` and the published files are volumes of this stand shared
by every node and the entry, over the stand's own data volume at `/app/data`;
the entry serves a published file from `/__hilos_files/` after a node's files
library has checked the reader.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 26, the database is one cluster of two data nodes and
the arbitrator, and 49, a row written by one node is read by another through
another data node, past the process copy and after that connection is lost. The
framework's database probe is in this demo's `AGENTS` and starts only here; it
keeps its values in the framework table `hilos_probe_value`, which this demo's
schema carries as the framework unit `probe_value` (a migration pair from
`db:framework:pull`, see [Database migrations](#database-migrations)). The full
run has a step of its own, `roblox-game-server-cluster`.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build/recreate the database, arbitrator, proxy, four nodes, entry and CLI, retaining data |
| `composer run test:cluster:restart` | ordinary down/up with current code, retaining the database |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:inspect -- m1` | inspect one node as JSON |
| `composer run test:cluster:logs -- m1` | follow one node's container logs; Ctrl-C stops following |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run an existing CLI command through the selected node |
| `composer run test:cluster:scenarios` | scenarios 26 and 49 on a fresh stand; `-- 49` runs only the one named |
| `composer run test:cluster:e2e` | fresh browser suite: Daemon, one person's tabs, theme and protected mode on live masters, the daemon phase is parked (F-023); build first with `test:e2e-build`; `-- --grep 'cluster theme'` points it |
| `composer run test:cluster:e2e-time-zone` | draw an explicit browser zone for a new cluster spec |
| `composer run test:cluster:down-volumes` | take the stand down, database included |
| `composer run test:cluster:down` | stop the stand, retaining the database data |

The e2e profile adds Mailpit and a Playwright runner. The browser enters through
one stand entry; every context selects a master with `hilos_stand_master` before
its first navigation. Browsers start on a follower master.
In `tests/e2e/cluster/live`, protected-mode checks
the stub, the verifier circle and admission across masters, and sessions checks
sign-in, sign-out and access re-decisions across masters. Manual maintenance closes
through the UI on follower F; another administrator enters by code and opens it on
master T. Visitors on every master see the closure, while the operator and circle
member keep their documents. All six pages reload themselves on opening. Actual
WebSocket upgrades prove each master's address, and inspect snapshots plus marked
daemon log tails prove there was no freeze. Point that case with
`composer run test:cluster:e2e -- --grep 'cluster manual maintenance:'`.
The three tests in `cluster/live/theme.spec.ts` check live theme settings on
every master, choices and reset between independent account sessions, new tabs
with personal Dark and after reset, and stale Dark reconnect without adoption.
They inspect complete theme frames and actual WebSocket upstreams; live updates
keep documents and sockets, and the leader and term stay unchanged. Point them
with `composer run test:cluster:e2e -- --grep 'cluster theme'`.

The harness commands — `kill`, `inspect` and the rest — use the shell entry
point from this demo directory; see the
[shared runner details](../../docs/agents/testing.md#cluster-harness-runner).

### Raising and raising again

The data nodes start in order: `galera1` raises the cluster or joins `galera2`,
`galera2` joins once `galera1` is Synced, `garbd` joins once `galera2` is, the
proxy waits for both data nodes, and the nodes and the CLI wait for the proxy.
The four nodes roll the schema out themselves as they start together.

Only `galera1` ever raises a cluster, and only when `galera2` does not answer on
the replication port 4567; otherwise it joins, so a restarted `galera1` beside a
living `galera2` never splits the database in two. A node that stopped before
the rest leaves `safe_to_bootstrap: 0` in its `grastate.dat` and refuses to
raise a cluster; `galera1` rewrites it to `1` when `galera2` does not answer,
because a stand's database is disposable and a stand that cannot come up again
is not. So `composer run test:cluster:down` (without `--volumes`) followed by
`composer run test:cluster:up` brings the cluster up again on `galera1`'s own
data, with `galera2` joining it. `test:cluster:down-volumes`,
and every `test:cluster:scenarios` run, which starts with it, begin on empty
data. The data nodes keep their data under
`/dev/shm/hilos-roblox-game-server-cluster-galera1-data` and
`/dev/shm/hilos-roblox-game-server-cluster-galera2-data`, so it survives a
container replacement only until the host reboots.

The pinned MariaDB image carries no `garbd`; `framework/docker/galera/Dockerfile.garbd`
installs `galera-arbitrator-4` from the repository the image already has
configured, in exactly the version of its `galera-4`. The full copy to a joining
node is made by `mariabackup`, signing in as `mysql@localhost` through
`unix_socket`, so no password is written anywhere. The scripts the data nodes
mount (`galera.cnf`, `bootstrap.sh`, `member.sh`, `agent.sh`) are shared with
every Galera stand in `framework/docker/galera/`.

### Change Log provisioning

The journal's database and its grant are made once, through the replicating
path. On a fresh stand `galera1` creates them in the image's initialization of
its empty directory (`docker/mysql/init-change-log.sh`), and `galera2` receives
them, with the users, by the full copy. The application installs the journal's
triggers itself; the cluster's `galera.cnf` sets
`log_bin_trust_function_creators=1`, because a Galera node counts as a server
with binary logging and would otherwise refuse `CREATE TRIGGER` from a user
without SUPER. A database directory that already exists gets the journal on
ONE data node — Galera replicates it — before the nodes start:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --wait \
  roblox-game-server-cluster-galera1 roblox-game-server-cluster-galera2
docker compose -f docker/docker-compose.cluster.yml exec roblox-game-server-cluster-galera1 \
  /usr/local/bin/provision-change-log.sh
composer run test:cluster:up
```

Provisioning uses the existing `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_ROOT_PASSWORD`. It is repeatable: it creates a missing journal and
refreshes its grant without DROP or reset.

### Standalone parts

Each component is also a compose file of its own in `docker/cluster/` — `database.yml`
(one data node), `garbd.yml`, `haproxy.yml`, `node.yml` and `entry.yml` — for a
deployment with one component on one server. The header of
`docker/cluster/standalone.env.example` lists the `docker compose config`
commands and every value the parts read: the data nodes' addresses and
`HILOS_GALERA_DATA_PEERS`, the arbitrator's data nodes, the proxy's two data
nodes and the node addresses each is given, the node's runtime file and the
shared export and published-files storage. It does not install remote servers.
Start the data nodes one at a time; the replication ports 4567, 4568 and 4444
and the agent port 9200 must be reachable between the servers by your own means.

### TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand
fixtures only**: the authority behind them signs nothing else, its key is not
in the repository, and it is not the authority of any other stand on the same
machine. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of the four nodes |
| `m1.pem` … `s1.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |

Reissuing means a new set in full: the old authority's key is gone, so no
single file can be replaced alone. The commands run in the image of the
stand's cli container, which exists after the first
`composer -d demo/roblox-game-server run test:cluster:up`. The framework's own
commands print PEM to stdout and the host shell writes the files, so they come
out owned by you rather than root. Keep the authority file (`cluster-ca.pem`)
outside the repository and delete it when done — from the repository root:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/roblox-game-server":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-roblox-game-server-cluster-roblox-game-server-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/roblox-game-server/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 m2 m3 s1; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a
node warns in its log from 30 days before the end.

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.

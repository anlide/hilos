# Hilos Concert Tickets Demo Project

An Angular demo project for selling concert tickets. The application shell and
framework sections are active; the product itself - the hall, its seats and a
booking - has not been built.

## What is in the project

The main page at `/` is intentionally empty on screen. It has a visually hidden
`Concert Tickets` heading for assistive technology. The signed-in person's name
is shown in the profile control in the header.

Sign-in uses a password, a passkey, a code sent by SMS or a GitHub account.
Registration and account recovery confirm the email address with a code sent by
email. The project also includes:

- Profile details, a photo checked by moderation, notification preferences,
  sign-in methods, sessions, two-step verification, agreements and a copy of
  the person's data.
- An admin area at `/hilos` with Users, Settings, Appearance, Analytics, Daemon,
  Logs and Change Log.
- Email delivery for account codes and notifications.
- Public About, Terms, Privacy and License pages, plus the license dependency
  list and Terms/Privacy history routes.

The demo has no tables of its own. Its Analytics section uses the normal Hilos
journal-to-database flow, and its Change Log journals the framework tables it
carries into a database of its own beside the primary one.

## Run locally

From the repository root, prepare the local environment and dependencies, build
the Angular frontend, then start the local stack:

```bash
composer -d demo/concert-tickets run setup-env
composer -d demo/concert-tickets run install-deps
composer -d demo/concert-tickets run frontend:install
composer -d demo/concert-tickets run frontend:build
composer -d demo/concert-tickets run daemon-start-build
```

The local nginx stack publishes HTTPS on port `8276`; Mailpit's web interface
is on port `8280`. Stop the local services with:

```bash
composer -d demo/concert-tickets run daemon-stop
```

The project provides ordinary local and test Compose stacks. Unit tests and
frontend checks run with:

```bash
composer -d demo/concert-tickets run test:unit
composer -d demo/concert-tickets run test:check
```

The full browser cycle is:

```bash
composer -d demo/concert-tickets run test:e2e-full
```

`test:e2e-full` runs the full clean browser cycle: the activation scenarios
(a guest's opening of the empty home and a real sign-in reaching Analytics) and
the specs of the Daemon, Change Log, profile and account deletion areas. If a
failed run leaves its stand up for diagnosis, clean it with:

```bash
composer -d demo/concert-tickets run test:down
```

For integration tests, start the test database and prepare its schema first:

```bash
composer -d demo/concert-tickets run test:up
composer -d demo/concert-tickets run test:db-prepare
composer -d demo/concert-tickets run test:integration
composer -d demo/concert-tickets run test:down
```

The project's Composer scripts in `composer.json` are the complete command
interface for its local and test stacks.

## Cluster stand

`docker/docker-compose.cluster.yml` raises this demo as a Hilos cluster on one
Galera database: five equal masters, `m1`–`m5`, each `worker,ram=10,slots=64`,
and no slaves. The masters run on the framework's production election,
heartbeat, link and grace timers. The database is one MariaDB Galera cluster of
three data nodes, `galera1`–`galera3`, with no arbitrator: three data nodes keep
their majority when one is lost. One HAProxy is the only database address the
nodes and the CLI know; it lays each next connection on the least busy data
node, so the application writes to all three. HAProxy marks a data node up
while it is Synced or donating a full copy (the agent check on port 9200); a
node that is still joining takes no new connections. A read waits for what the
cluster committed (`wsrep_sync_wait=1`, the READ bit) in every application
session, reconnects included, and `wsrep_dirty_reads` is off. The stand is a
compose project of its own, `hilos-concert-tickets-cluster`, on the default
subnet `10.229.0.0/16`, and publishes nothing on the host: the e2e steps take
their whole project down when they start, and a fleet inside it would fall in
the middle of its matrix.

The one nginx entry is `10.229.0.30` inside the stand network, unpublished on
the host. `hilos_stand_master=m1|m2|m3|m4|m5` selects the master behind it;
without the cookie nginx rotates over the masters, and an unknown name returns
421. The nodes' `data_export` and the published files are volumes of this stand
shared by every node and the entry, over the stand's own data volume at
`/app/data`; the entry serves a published file from `/__hilos_files/` after a
node's files library has checked the reader.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 21, the five nodes started together on an empty
database roll the schema out once, and 26, the database is one cluster of three
synced data nodes, each holding a connection of the application. The full run
has a step of its own, `concert-tickets-cluster`.

The browser suite uses the same five masters through the entry, with Mailpit
and Playwright from the `e2e` profile. The entry serves the Angular build's
`frontend/dist/index.csr.html`; a missing build fails before the harness resets
the stand. Five rotations put a person, the administrator who writes a
Moderation rule and the administrator who watches it on three distinct masters;
only the live phase runs, with no fault request or after-loss phase. The full
graph's `concert-tickets-cluster-e2e` follows `concert-tickets-e2e` and shares a
group with the scenario matrix; its snapshot keeps every node's logs and
Playwright evidence before teardown. A direct browser run has no snapshot
collector: save `data/logs-cluster/` and `tests/e2e/playwright-report/cluster-live/`
before repeating it.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build/recreate the database, proxy, five nodes, entry and CLI, retaining data |
| `composer run test:cluster:restart` | ordinary down/up with current code, retaining the database |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:inspect -- m1` | inspect one node as JSON |
| `composer run test:cluster:logs -- m1` | follow one node's container logs; Ctrl-C stops following |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run an existing CLI command through the selected node |
| `composer run test:cluster:scenarios` | scenarios 21 and 26 on a fresh stand; `-- 26` runs only the one named |
| `composer run test:cluster:e2e` | Moderation through five person/author/observer master rotations on a fresh stand; build first with `test:e2e-build` |
| `composer run test:cluster:e2e-time-zone` | draw an explicit browser zone for a new cluster spec |
| `composer run test:cluster:down-volumes` | take the stand down, database included |
| `composer run test:cluster:down` | stop the stand, retaining the database data |

The harness commands — `kill`, `inspect` and the rest — use the shell entry
point from this demo directory; see the
[shared runner details](../../docs/agents/testing.md#cluster-harness-runner).

### Raising and raising again

The data nodes start in order: `galera1` raises the cluster or joins one of the
others, `galera2` joins once `galera1` is Synced, `galera3` once `galera2` is,
the proxy waits for all three, and the five nodes and the CLI wait for the
proxy. The five nodes roll the schema out themselves as they start together,
under the rollout claim in the database; no schema step of the stand runs before
them.

Only `galera1` ever raises a cluster, and only when neither `galera2` nor
`galera3` answers on the replication port 4567; otherwise it joins, so a
restarted `galera1` beside a living member never splits the database in two. A
node that stopped before the rest leaves `safe_to_bootstrap: 0` in its
`grastate.dat` and refuses to raise a cluster; `galera1` rewrites it to `1` when
no other member answers, because a stand's database is disposable and a stand
that cannot come up again is not. So `composer run test:cluster:down` (without
`--volumes`) followed by `composer run test:cluster:up` brings the cluster up
again on `galera1`'s own data, with `galera2` and `galera3` joining it.
`test:cluster:down-volumes`, and every `test:cluster:scenarios` run, which
starts with it, begin on empty data. The data nodes keep their data under
`/dev/shm/hilos-concert-tickets-cluster-galera1-data` and its `galera2` and
`galera3` siblings, so it survives a container replacement only until the host
reboots.

The full copy to a joining node is made by `mariabackup`, signing in as
`mysql@localhost` through `unix_socket`, so no password is written anywhere. The
scripts the data nodes mount (`galera.cnf`, `bootstrap.sh`, `member.sh`,
`agent.sh`) are shared with every Galera stand in `framework/docker/galera/`;
this demo's own part of the database is `docker/haproxy/haproxy.cfg`.

### Change Log provisioning

The journal's database and its grant are made once, through the replicating
path. On a fresh stand `galera1` creates them in the image's initialization of
its empty directory (`docker/mysql/init-change-log.sh`), and `galera2` and
`galera3` receive them, with the users, by the full copy. The application
installs the journal's triggers itself, once, and Galera replicates them; the
shared `galera.cnf` sets `log_bin_trust_function_creators=1`, because a Galera
node counts as a server with binary logging and would otherwise refuse
`CREATE TRIGGER` from a user without SUPER. A database directory that already
exists gets the journal on `galera1` alone — Galera replicates it — before the
nodes start:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --wait \
  concert-tickets-cluster-galera1 concert-tickets-cluster-galera2 concert-tickets-cluster-galera3
docker compose -f docker/docker-compose.cluster.yml exec concert-tickets-cluster-galera1 \
  /usr/local/bin/provision-change-log.sh
composer run test:cluster:up
```

Provisioning uses the existing `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_ROOT_PASSWORD`. It is repeatable: it creates a missing journal and
refreshes its grant without DROP or reset.

### Standalone parts

Each component is also a compose file of its own in `docker/cluster/` —
`database.yml` (one data node), `proxy.yml`, `node.yml` and `entry.yml` — for a
deployment with one component on one server. The header of
`docker/cluster/standalone.env.example` lists the `docker compose config`
commands and every value the parts read: the data node's Galera name, address
list and `HILOS_GALERA_DATA_PEERS`, the proxy's three backends, the node's
runtime file, the ten entry endpoints and the shared export and published-files
storage. It does not install remote servers. Start the data nodes one at a time;
the replication ports 4567, 4568 and 4444 and the agent port 9200 must be
reachable between the servers by your own means.

### TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand
fixtures only**: the authority behind them signs nothing else, its key is not
in the repository, and it is not the authority of any other stand on the same
machine. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of the five nodes |
| `m1.pem` … `m5.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |

Reissuing means a new set in full: the old authority's key is gone, so no
single file can be replaced alone. The commands run in the image of the
stand's cli container, which exists after the first
`composer -d demo/concert-tickets run test:cluster:up`. The framework's own
commands print PEM to stdout and the host shell writes the files, so they come
out owned by you rather than root. Keep the authority file (`cluster-ca.pem`)
outside the repository and delete it when done — from the repository root:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/concert-tickets":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-concert-tickets-cluster-concert-tickets-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/concert-tickets/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 m2 m3 m4 m5; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a
node warns in its log from 30 days before the end.

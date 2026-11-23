# Testing — Cluster Compose Model and Harness Runner

Read this when changing a cluster stand's compose files, or running, pointing or
reading the cluster harness: its commands, scenario and browser steps, phases
and artifacts.

### Cluster Compose model

Each demo's root `docker/docker-compose.cluster.yml` keeps the project name and
`x-hilos-cluster`. One `include` path-list merges `cluster/local.yml` with
`cluster/tooling.yml`. Every service of `local.yml` that extends a file in that
directory — a part — inherits its mounts and ports and resets the ports with
`ports: !reset []`; today's parts are `database.yml`, `node.yml` and, where
present, `entry.yml`; roblox-game-server adds `garbd.yml` and `haproxy.yml`,
concert-tickets adds `proxy.yml`, and the Galera scripts both mount live in
`framework/docker/galera/`. The services
inside a part do not join the aggregate themselves; only the services that
extend them do. Compose 5.6.1, pinned in the
harness, resolves this model directly, including `!reset` and `!override`.

Every local node replaces `env_file` with the tracked demo `.env.example`,
`common.env` and its fixed `nodes/<id>.env`, in that order. A global
`HILOS_CLUSTER_NODE_ENV` therefore cannot replace local node identities. Bind
paths and build contexts resolve from `docker/cluster`; mounts merge by their
container target, retaining per-node logs and the stand's export storage.
Every cluster stand mounts its own named data volume at `/app/data` on its nodes
and its CLI ([Data root of a stand](stand-compose.md)).
Binance and online-testing each share one named export volume of the stand among
its nodes and entry; online-testing also serves Angular's `frontend/dist` at
`/usr/share/nginx/html`. Roblox-game-server shares two named volumes among its
nodes and entry: the exports, and the published files the entry serves from
`/published` through an internal `/__hilos_files/` location once a node's files
library has checked the reader (its nodes carry `HILOS_FILES_XACCEL_LOCATION`
for it). Concert-tickets shares the same two, `data-export` and
`files-published`, among its five nodes and entry, in the same way; its entry
serves Angular's `frontend/dist` at `/usr/share/nginx/html`. Its first data node
alone mounts the change-log initialization and provisioning scripts; the other
two receive the databases and grant with their full copy. Ecommerce-shop's
browser configuration adds an nginx entry serving React's `frontend/dist`
and routing WebSockets to either master. Its scenario configuration has only m1 as master.
File-gallery-manager shares its named `data-export` volume among all five nodes
and its entry; the entry serves React's `frontend/dist` at
`/usr/share/nginx/html`. Its primary alone mounts the change-log initialization
and provisioning scripts; the read-only replica receives the databases and
grant through replication.

Each cluster stand whose demo declares Status gives it a separate named
volume over its root, suffixed with `${HILOS_STAND_SUFFIX:-}` and shared by all
its nodes and the entry where one exists.

The CLI carrier is default-on. It uses `APP_ENV=test`, explicit cluster database
credentials and command port, and mounts tracked `tests/.env` read-only
at `/app/tests/.env` (selectable through `HILOS_CLUSTER_TEST_ENV_FILE`). Its
`/app/tests` is tmpfs: Docker would otherwise create a missing nested file-mount
target on the host project bind. This carrier runs backend commands; the test
suites keep their existing separate services. Nodes use `APP_ENV=dev` and
tracked defaults. Cluster startup neither copies nor overwrites host `.env`
files. Only Mailpit and Playwright need the `e2e` profile; Binance's foreign
node uses `stranger`.

From a demo directory, one direct command starts its ordinary local aggregate:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --build --force-recreate
```

The canonical components can also be resolved and started separately with
Compose on their own server. Each demo's `docker/cluster/standalone.env.example`
and README show `--env-file`, a distinct project per component, published
database/peer/WebSocket/HTTP ports and, where present, entry ports.
`--env-file` supplies Compose interpolation; `HILOS_CLUSTER_NODE_ENV` selects
the separate runtime file loaded after `common.env`. The operator supplies
routable database, advertise and seed endpoints, matching mutual-TLS files,
trusted proxy ranges, built dependencies/frontend and any shared export storage
visible to every node and entry. Entry upstreams are complete `host:port` pairs
in `HILOS_CLUSTER_WS_M1/M2/M3` and `HILOS_CLUSTER_HTTP_M1/M2/M3`.
The nginx entrypoints substitute only those endpoints and
`FRONTEND_HTML_UPSTREAM`, preserving nginx's own variables and noncluster
templates. Standalone config has been checked; deployment across real hosts has
not been tested. The harness remains a local single-host runner and provisions
no remote machines or shared storage.

### Cluster harness runner

From a demo directory, run
`../../framework/docker/cluster/run docker/docker-compose.cluster.yml <command> [args]`.
For example, `up`, `status`, `inspect m1` and `down` operate the selected stand.
The existing `composer run test:cluster:*` names remain convenient entry points.
From the repository root, `composer run test:cluster:unit` runs the harness's
stdlib Python unit tests in the same disposable runtime. The `unit` command
runs before loading the compose stand, checking its registry or dispatching a
stand action; it starts no cluster and makes no SQL or network calls in its tests.
The independent `cluster-unit` graph step includes this check in the full run.

`composer run test:cluster:down` keeps using the existing
`scripts/down-stands.php`; calling the shell runner's `down` is a standalone
path. Use `down --volumes` only when intentionally deleting the stand's
database data.

`up` uses the single root Compose command above. It builds and recreates default
services with current code; it does not build every optional profile. The first
optional `e2e` or `stranger` startup builds its own services. `restart` performs
ordinary `down` followed by `up`. Ordinary `up`, `down` and `restart` retain both
databases and protected-mode stores, including a saved manual maintenance window.
They do not clear logs or open a freeze. The demo aliases include
`test:cluster:restart`, `test:cluster:inspect -- m1`,
`test:cluster:logs -- m1` (follow; Ctrl-C stops it) and
`test:cluster:client -- m1 test:cluster:inspect`.

Every new `scenarios` or `e2e` invocation, pointed or unpointed, checks its
prerequisites before one `down --volumes` and a fresh `up`. There is no further
global database reset between scenarios, retries or browser phases, and no
`--keep-db` mode. This test boundary deliberately discards accumulated fixtures;
it is separate from the ordinary lifecycle that preserves them.

Before fleet/browser readiness, test startup inspects every master's protected
mode and opens a recognized saved operation through its recorded initiator:
`maintenance:disable` for `manual_maintenance` owned by `hilos_index`,
`test:protected-mode:open` for that owner's test freeze,
`protected-mode:open` for `restore` owned by `hilos_backup`, and
`test:backup:freeze-open` for `cluster-settle` owned by `hilos_backup` (the
scenario 25 test driver). It waits for all
masters to become inactive within one bounded deadline. An unknown identity,
malformed store/reply, refused command or timeout fails startup. No freeze file
is deleted to make the test pass. For ordinary operation, use
`test:cluster:client -- <node> protected-mode:inspect` to inspect the saved
identity and the corresponding owner's command to open it.

The shell launcher needs ordinary Linux shell utilities, the Docker CLI and an
accessible local Unix Docker socket. It requires Linux and a selected local
Unix endpoint: `DOCKER_CONTEXT` and `DOCKER_HOST` are honored, while a remote
TCP/SSH endpoint or an absent or inaccessible socket is refused with exit 2
before the stand is affected. The supported target is a local Linux Docker
Engine; Docker Desktop, rootless, SSH and multi-machine setups are outside this
runner's supported contract. It needs no host Python, PHP, Composer or Compose
plugin, and it does not need root, privileged mode or host networking.

Every invocation builds `framework/docker/Dockerfile.cluster-harness` with
Docker's normal cache and without `--pull`, then runs that invocation's exact
image ID in a disposable `--rm --init` container. The first build happens
automatically; unchanged inputs use cached layers on later invocations. The
image pins Python 3.12.16 on Alpine 3.24.3, Docker CLI 29.9.2, Compose 5.6.1
and Buildx 0.38.1. It reads the current Python sources from the repository mount.
The launcher preserves arguments, standard streams, exit codes and signals;
build output goes to stderr, leaving `inspect` stdout as JSON. The container
has temporary `HOME` and `DOCKER_CONFIG`, and Python bytecode is not written
into the repository.

The repository is mounted read/write at its physical absolute path, and the
container working directory is the physical demo directory. It mounts the
selected Docker socket and runs as the caller's UID:GID with the socket's
numeric GID added. Host `/dev/shm` is mounted read-only so a separate Docker
helper can find existing database bind directories before cleanup. New files
belong to the caller; owners and permissions of existing journals are left
intact. When defined, the launcher passes the existing
`CLUSTER_E2E_TIMEOUT_SCALE`, `CLUSTER_E2E_RETRIES` and
`HILOS_E2E_TIMEOUT_SCALE` variables, plus the existing
`HILOS_STAND_SUFFIX`, `HILOS_STAND_CPU_SHARES`,
`HILOS_STAND_NET_BINANCE_BTC_TRACKER_CLUSTER`,
`HILOS_STAND_NET_ECOMMERCE_SHOP_CLUSTER`,
`HILOS_STAND_NET_ONLINE_TESTING_CLUSTER`,
`HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER`,
`HILOS_STAND_NET_FILE_GALLERY_MANAGER_CLUSTER` and
`HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER` variables. Its explicit deployment
allowlist also carries the `HILOS_CLUSTER_*` runtime-file/storage selections,
bind address, published ports, ten entry upstream endpoints and the three
Galera backends of a proxy, defined in the standalone examples. It preserves
absent versus empty values and does not forward arbitrary host environment
variables. A mistaken direct call to `cluster.py`
under Python earlier than 3.10 exits before imports with a shell-runner hint;
Python 3.10+ is not a host prerequisite.

The harness carries multi-server database capabilities, in use on
roblox-game-server, file-gallery-manager and concert-tickets.
Such a stand labels every member `hilos.database.member: "true"` and exactly one
`hilos.role: database` — the one `db-sql` and the artifact collector reach.
The nodes use one address leading to the writable database. A replica is
declared by `MARIADB_MASTER_HOST` and has no `MYSQL_DATABASE`, `MYSQL_USER` or
`MYSQL_PASSWORD` of its own: its schema and users arrive from the primary.
The harness uses the database service's application credentials. Scenario 26
asks every member of a multi-primary; scenario 31 asks every replica. Scenario 31
first waits for each replica to answer as the application user, which a fresh
replica does only once replication has brought that user, and only then judges
its replication threads. A proxy is labeled `hilos.database.proxy: "true"`;
scenario 27 reads its server states.
A Galera arbitrator (`garbd`) votes and stores no data: it is declared apart
from the data members, labeled `hilos.database.voter: "true"`, never labeled
`hilos.database.member` and never asked SQL. Scenario 26 asks for the READ bit of
`@@SESSION.wsrep_sync_wait`, not a value of at least 1, and requires
`@@SESSION.wsrep_dirty_reads` to be off on every data member.
Scenario 49 checks the READ bit and disabled dirty reads in every application
session when the stand has multiple writable backends; it reads a write from
another node through the application's own connection (and, on multiple
backends, from another backend), distinguishes that fresh query from the process
copy, and verifies the next query after the reader connection is killed.
Scenario 49 has one multi-primary carrier, roblox-game-server; other
multi-primary stands do not repeat it.
binance-btc-tracker, ecommerce-shop and online-testing declare no database
member or proxy label; each labels its single MariaDB `hilos.role: database`.
Roblox-game-server labels both data nodes `hilos.database.member`, the first of
them also `hilos.role: database`, `garbd` `hilos.database.voter` and its
HAProxy `hilos.database.proxy`. Concert-tickets labels its three data nodes
`hilos.database.member`, the first of them also `hilos.role: database`, and its
HAProxy `hilos.database.proxy`; it has no voter.

Every server of a fleet's database, whether one server or a member of a cluster,
keeps its data in a host directory under `/dev/shm` and mounts
`framework/docker/mysql/test-stand.cnf`. A new fleet database server does the
same. The harness wipes these directories on `down --volumes`; a member killed
and started again keeps its data, as does ordinary `down/up` or `restart`.
This is host-memory persistence only: a host reboot clears `/dev/shm`.
Stand suffixes keep those directories separate, alongside namespaced projects,
containers, networks and named volumes. The binance-btc-tracker, ecommerce-shop
and online-testing stands each mount their existing
`mysql/init-change-log.sh` at `/docker-entrypoint-initdb.d/init-change-log.sh`
and `mysql/provision-change-log.sh` at `/usr/local/bin/provision-change-log.sh`.
Fresh initialization creates and grants the derived journal before Hilos
starts; an existing ordinary MariaDB directory is provisioned with the root
script before the new Hilos code starts, without DROP or reset. Repeating that
script and restarting on the same directory preserve primary and journal data.
For Galera and primary/replica, journal preparation runs once through the
replicating path, as specified in [On A Cluster](../architecture/change-log.md#on-a-cluster).

Before changing an older compose, stop it through its old file without
`--volumes`, so removed replica/proxy/member containers do not become orphans.
Online-testing uses the new `/dev/shm/hilos-online-testing-cluster-mysql-data`;
its old `hilos-online-testing-cluster-galera{1,2,3}-data` directories remain
untouched and must not be mounted as ordinary MariaDB data. No automatic
transfer from Galera is provided. Ecommerce's former replica data stays too.
Local/test volumes are outside this transition. The per-demo README gives the
exact provisioning commands.

A demo's cluster stand is `docker/docker-compose.cluster.yml` beside its other
stacks, and a compose project of its own: the demo's e2e steps take their whole
project down when they start, and a fleet inside it would fall in the middle of
its matrix. Its nodes log to `data/logs-cluster/<node>`, and it has a TLS
authority of its own, so two stands on one machine never trust each other. It
has a step of its own in the full run, `<demo>-cluster`, which runs the demo's
`composer run test:cluster:scenarios` — binance-btc-tracker's,
ecommerce-shop's, online-testing's, roblox-game-server's,
file-gallery-manager's and concert-tickets'.

From `demo/ecommerce-shop`, run `composer run test:cluster:e2e` for the shop's
cross-master inventory checks. It selects `docker/docker-compose.cluster-browser.yml`
with `e2e --require-after-loss`. A one-shot seed prepares the database before
the four nodes start. Live records the stock change and requests the loss of
the owning slave. The stand declares `x-hilos-cluster.e2e.required-loss-agent: stock_library`.
With `--require-after-loss` the host checks the requested agent against this
declaration and the leader's placement before stopping the container. It then
requires a worker start logged strictly after Docker's finish time on the
slave that now hosts the agent. After-loss checks the surviving placement and another stock
change against the other master's warehouse view. Acceptance requires both
main phases and a proof from the current run.

In the full graph, `ecommerce-shop-cluster-e2e` depends on `ecommerce-shop-e2e`
for the finished frontend build, shared dependencies and reports. Both cluster
configurations use the same Compose project and network, so the
`ecommerce-shop-cluster` group runs one at a time. Each step's stand ID selects
its own Compose file for the snapshot and teardown. After a direct browser run,
use `../../framework/docker/cluster/run docker/docker-compose.cluster-browser.yml down --volumes`
to remove the stand and clear its test database.

A new scenario is written on the stand whose shape it needs. When two shapes
would do, the reasons in [cluster-stands.md](cluster-stands.md) decide. A scenario that needs a shape none of the
six has is a question for the owner, not a seventh stand grown on the way:
the six shapes are one decision of the owner, 2026-11-04 (HIL-1985). A scenario
that needs a shape not built yet lands with its carrier or after it.

Nodes that start together on one database roll the schema out once, under
the rollout claim in the database itself (HIL-1228,
[orm/migrations.md](../orm/migrations.md)): one node applies the migrations, the
rest wait for it and find the level already there. A stand therefore has no
one-shot rollout service in front of its nodes any more, and starts them all
at once on an empty database — which is what scenario 21 reads.

The `binance-btc-tracker-cluster-e2e` step drives the cluster browser suite in
`demo/binance-btc-tracker/tests/e2e/cluster/`, beside the demo's single-node e2e
rather than after it (HOTFIX 2026-10-25): it waits for `binance-btc-tracker-build`,
whose `frontend/dist` its entry serves, and `binance-btc-tracker-check`, which
installs `tests/e2e/node_modules`. The demo's main Playwright run keeps its output
in `test-results/main` and `playwright-report/main`, so it clears neither the
`cluster-*` folders nor `quarantine/*`. The browser enters through the stand's one nginx entry, where each context's
`hilos_stand_master` cookie selects a master (HIL-1304). By default browsers
sit on a follower master, away from the page holder
on the leader and the policy-placed agents on slaves. The live phase checks
backup, logs, Change Log, delivery windows and i18n across masters.
`cluster/live/change-log.spec.ts` first observes a known
leader Workers roster and its started monopolistic `hilos_change_log` worker
before opening Change Log. If the first roster was unknown, it makes one fresh
Workers navigation after the known node report, then applies the full roster
checks; this independent read does not warm the journal. It checks the person's
`started` `hilos_user:<id>` owner on a slave before and after the block, then
pins the receipt and `rowKey`. Fresh socket, filter and receipt reads through
every master must return that receipt and its single entry with exact actor,
action, writer, the complete When/Source header, and `block` values `0` → `1`;
the leader and term stay unchanged. The shared
`expectFreshChangeLogReceiptWriter` helper reopens the pinned receipt within the
normal assertion budget until the actual writer appears, and lets refusals or
errors fail. Attached evidence includes bootstrap, placement and term, the
pinned receipt and master reads (HIL-1621).

The cold acceptance run passed on the test box on 2026-10-26 with no retry.
F-016 remains quarantined: its tag, 2026-10-18 date and original failure
observation stay in the spec, and this pass does not settle the earlier flake.
Point the scenario with `-- --grep 'cluster change log:'` (HIL-1465).
This pointed run stays in the live phase, with no fault request.

`cluster/live/i18n.spec.ts` adds five live tests (HIL-1505):

- language `nativeName` edits in both directions;
- country `currencySymbol` edited from the list and delivered to the other card;
- locale off/on delivered between masters;
- language base and locale-correction names created and then edited across masters;
- country base and locale-correction names edited in both directions.

Each test uses real forms in two independent contexts fixed to the two follower
masters, with `signUpAdmin` in both. CDP checks their WebSocket upgrades against
those masters. After the subscriptions are ready, `watchI18nLivePages` checks
delivery on the existing documents and sockets, with no observer reload,
navigation or Apply and no new WebSocket on either page; `expectI18nName` checks
the saved names in place without Apply. The leader and term stay unchanged.
Cleanup removes the language, locale and names fixtures; the country edit
restores its original currency symbol, currency code, default locale and card
text. From the repository root, the pointed live run is:

```bash
composer -d demo/binance-btc-tracker run test:cluster:e2e -- --grep i18n
```

The pointed i18n run stays in the live phase, with no fault request. In the full
browser run, the harness then stops the slave the backup spec names, waits for
placement to move, and runs the after-loss phase: the archive is out of reach
and the log node offline.
`composer -d demo/binance-btc-tracker run test:cluster:e2e` runs that suite
alone on a fresh stand; `-- --grep circle` points the existing live/after-loss
phases. The harness collects a static plan before destructive host actions.
Every selected main project finishes before any quarantine project starts.
A nonempty main `daemon` project retains its original place before live and
strict recovery checks; with the entire daemon scenario tagged, main daemon is
empty and starts no controller or readiness wait. A main failure may skip its
dependent after-loss phase, while the quarantine tail still runs and preserves
main's native rc.

The tail runs selected quarantine after-loss tests first, only with a valid
original fault request and a stopped slave. Without that state they are
`notRun`, never substituted with live. Before quarantine live, the host starts
the stopped slave, waits for all members to converge and reads leader/follower
and term again. It opens a recognized saved freeze through the existing test
bootstrap and refreshes roles before live and daemon. Quarantine daemon runs
last. There is no database reset or stand teardown between main and the tail.
`-- --daemon` selects daemon only, including forwarded Playwright filters; a
pointed live run does not add daemon. Each selected quarantine project runs
at most once.

A phase listed in `FLAKY_SKIP` of `framework/docker/cluster/browser.py` is
switched off whole, the host-side twin of a static `test.fixme`: the host prints
`cluster e2e: phase <project> SKIP (<reason>)` and prepares it in neither main
nor quarantine. `-- --daemon` with daemon switched off prints
`browser suite skipped: phase daemon` and succeeds without collecting.

The roblox-game-server daemon scenario checks foreign Workers/Agents/Cron/HTTP/Env,
a remote UI restart proved by fresh node journals, mismatch privacy, and loss of
the original leader. Two original follower contexts retain scheme, placement,
mismatch and dashboard tabs; the source that remains follower proves addressed
`page_resubscribe`, ordinary subscribe and live updates on its original
sockets/documents. Both LEADER rows (`hilos_index`, `hilos_daemon`) move; the
dead column remains unknown. The destructive spec has no retries.

The host alone changes the remote environment; it kills the leader only after
the browser's run-id-bound readiness request. On every exit it joins its
controller, restores the master, clears that environment override, waits for
all members to converge, checks every restart for startup refusal and
removes only that run's control files. Live delivery control likewise stops
and joins before restoring its captured faults and checking controller errors.
A main cleanup failure stays red, and any later main phase requires restored
roles. For quarantined daemon, ordinary phases have already finished: a host
failure becomes one final failed/not-run observation, without changing main rc.
The original failure reason is retained alongside any recovery failure.
Inspect before/after, fresh restart journal tails, Cron and mismatch page-response/table-window
frames and socket/document recovery proof are Playwright attachments under
`demo/roblox-game-server/tests/e2e/test-results/cluster-daemon`, or
`demo/roblox-game-server/tests/e2e/test-results/quarantine/daemon` for the
tagged scenario; the harness prints restoration/convergence too.
The scenario and browser
steps share a group because both reset the same compose project (HIL-1232).
Before the browser stand starts, a fresh CLI image clears its stopped nodes'
log directories, including rotation archives, while retaining `.gitkeep` and
every `protected-mode.state.json*` store, including unfinished `.tmp` files.
Ordinary `up` does not run this cleanup. Save a failed run's logs before
starting another. The Binance cluster step copies `data/logs-cluster/<node>/` to
`artifacts/binance-btc-tracker-cluster-e2e/nodes/<node>/`, and the Roblox-game-server
cluster step copies it to `artifacts/roblox-game-server-cluster-e2e/nodes/<node>/`
at every verdict, before teardown. Live logs and this run's archive and staging
trees keep their relative paths. A missing root or unreadable source is listed in `SNAPSHOT.txt`
under `missing`, without changing the test verdict; `data/logs-test` is not a
substitute for these node journals. A direct composer run has no step snapshot
collector, so preserve its journals before starting the next fresh stand. After
node loss the wait checks only placements that host an agent (`placing` or
`started`); a refused record is no running work.

The `online-testing-cluster-e2e` step proves Analytics on the existing
three-master stand (HIL-1423). After
`composer -d demo/online-testing run test:e2e-build`,
`composer -d demo/online-testing run test:cluster:e2e` runs three sequential
source/viewer pairs, each master followed by the next in the harness's list.
The entry serves Angular's `dist/index.csr.html`; Mailpit and the browser runner
belong to the `e2e` profile. This stand has only a live phase: no fault request,
no after-loss phase and no slaves. Online-testing declares no `e2e.capabilities`;
binance-btc-tracker declares `delivery`; roblox-game-server declares `daemon`
and `env-mismatch`.

Each pair registers a person and an administrator in separate contexts, proves
every actual WebSocket upgrade's master through CDP, waits for both Analytics
snapshots, and checks navigation, session isolation, action order, sorting and
absence of action bodies in the DOM and Analytics frames. Before and after the
browser work, the live reader is on the harness leader, the writer matches its
single started POLICY placement, and every member has one journal agent.
Fresh node logs prove the LEADER/NODE agents, accounting for stops, worker death
and daemon restarts; inspect's POLICY placements are not their registry.
The leader and term must stay unchanged throughout each pair.

The graph runs this step after `online-testing-e2e`, and both it and
`online-testing-cluster` share the `online-testing-cluster` group. A directed graph
run is `composer run test:suite -- online-testing-cluster-e2e --lanes=1` from
the repository root; it includes the step's dependencies. The collector keeps
`artifacts/online-testing-cluster-e2e/nodes/<node>/`, including archive and staging
paths, plus Playwright evidence and `SNAPSHOT.txt`, before teardown, under the same
missing-source rules as binance. It never substitutes `data/logs-test` for these
journals. Save direct-run logs before another fresh stand empties them.

The `roblox-game-server-cluster-e2e` step carries Daemon clock, environment
mismatch and orphan cases plus section navigation, one person's tabs and rights,
theme delivery and protected mode with manual maintenance across masters. Its
existing sessions journey also checks raw numeric session/connection moments on
two physical masters and a new session arriving on the subscribed socket without
reload, followed by the original cross-master sign-out; point it with
`-- sessions.spec.ts`.
The theme spec checks settings on all three masters, choices and reset between
independent account sessions, new tabs, and stale Dark reconnect without adoption;
`-- --grep 'cluster theme'` runs its three tests. Its quarantine tail runs
protected-mode (F-016) after the main verdict. The step
runs after `roblox-game-server-e2e`, sharing `frontend/dist`, `tests/e2e/node_modules` and
reports. The cluster-browser step shares the `roblox-game-server-cluster` group
and stand with the cluster scenarios because both reset the same compose project;
the ordinary e2e step uses its own stand and group. After
`composer -d demo/roblox-game-server run test:e2e-build`, run the focused suite
with `composer -d demo/roblox-game-server run test:cluster:e2e`. Passing
`-- --daemon` prints `SKIP F-023` while that phase remains parked. The step
runner copies node logs to
`artifacts/roblox-game-server-cluster-e2e/nodes/<node>/`.

The `concert-tickets-cluster` step runs scenarios 21, 26 and 27 on a fresh stand in
the `concert-tickets-cluster` group; from the repository root,
`composer -d demo/concert-tickets run test:cluster:scenarios` runs the same
matrix. Scenario 27 includes the loss, return and journal verification on all
three data members.

The `concert-tickets-cluster-e2e` step proves Moderation across the five
masters of the same stand (HIL-1639, HIL-2033). After
`composer -d demo/concert-tickets run test:e2e-build`,
`composer -d demo/concert-tickets run test:cluster:e2e` runs
`cluster/live/moderation.spec.ts` in five sequential rotations over the
harness's list of masters. Rotation i puts a person (S) on master i, the
rule-writing administrator (A) on master i+1 and the observing administrator
(O) on master i+2, modulo five, so the three are always distinct, and checks
every actual socket upgrade against its assigned master. A saves a Profile name
rule with a `modelKey`; O sees the exact rule without reload. S changes their
own name without supplying a key and sees it published, with exactly one model
request using the key from A's rule. O deletes the rule; A receives a pending
removal, uses the standard Apply action, and then sees the row disappear
without reload. Before and after the browser work, the spec checks both POLICY
singleton placements and the current journal agents on all nodes. The leader
and term remain unchanged. The removal is not expected to disappear from A's
view before A applies it.

Like online-testing, this stand has only a live phase: no fault request, no
after-loss phase, no `e2e.capabilities` and no slaves. The entry serves
Angular's `dist/index.csr.html`; global setup waits for the shell and for every
master's WebSocket through the entry, then clears Mailpit.

The graph runs this step after `concert-tickets-e2e`, which builds the
`frontend/dist` the entry serves; it shares the `concert-tickets-cluster` group
with `concert-tickets-cluster` and takes the stand down at any outcome. A
directed graph run is `composer run test:suite -- concert-tickets-cluster-e2e --lanes=1`
from the repository root. The collector keeps
`artifacts/concert-tickets-cluster-e2e/nodes/<node>/`, including archive and
staging paths, plus Playwright evidence and `SNAPSHOT.txt`, before teardown,
under the same missing-source rules as binance.

Cluster browser steps with more than one SQL backend that does not follow a primary
(concert-tickets and roblox-game-server) run a Galera wait observer through main and
the quarantine tail. An executing query lasting at least three seconds, an
INNODB_LOCK_WAITS row, or current flow control or an apply queue present for three
consecutive polls opens an episode. All members are captured every two seconds and
once after release, up to 30 snapshots per episode and five episodes per step.
Evidence goes to the demo's `data/logs-cluster/galera/`, collected as
`artifacts/<step>/nodes/galera/`; `run.json` identifies the current run, and earlier
evidence moves to its `archive/`. No episode text files on a quiet step is normal.
Observer errors are reported without changing the browser verdict.

The full run carries the fleets binance-btc-tracker, ecommerce-shop,
online-testing, roblox-game-server, file-gallery-manager and concert-tickets.
An e2e step may stand beside any fleet in the lane plan: nothing keeps them
apart, and each fleet leaves with its own step (HIL-1227).

The ports and the subnet of every stand are in the registry of
[../new-project/README.md](../../new-project/README.md); which demo carries an
e2e spec, and whose a spec is, is in
[frontend/testing-strategy.md](../frontend/testing-strategy.md), "Which demo
carries a spec".

---


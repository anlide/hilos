# Testing — The Cluster Stands

Read this when a scenario needs a cluster: which of the six cluster stands
carries it, on what shape, and which scenarios each stand already proves.

## The cluster stands — six demos, six shapes

The six cluster carriers are separate from the twelve ordinary-demo target:
all six stands work today — the three phase-one stands, roblox-game-server,
file-gallery-manager and concert-tickets, the last two approved under the
owner's decision of 2026-11-04 (HIL-1985). The marked rows below name the
leaves that will build what is still missing.
See the [ordinary/cluster phase boundary](ordinary-carriers.md#ordinary-and-cluster-phases) for
HIL-1984 and HIL-1985; it does not retire or unpark a scenario below.

The phase-one stands — binance-btc-tracker, ecommerce-shop and online-testing —
each use one ordinary MariaDB 11.4 server, holding the primary database and its
derived `<primary>-change-log` journal (the owner's decision, 2026-10-14,
HIL-1453). Every carrier uses MariaDB. `pmd`, `solar-system-model` and `preferans`
receive no cluster stands of their own.
All of it runs in docker on one machine: a multi-machine stand does not exist,
and HIL-348 closed without one. These stands test process and network failures
on one host, not physical-server or data-center failures. A single HAProxy or
entry provides no high-availability promise of its own. The one stand that
carried every scenario on a single shape is retired (HIL-1218).

| Demo | View | Hilos cluster | MariaDB | Scenarios |
|---|---|---|---|---|
| binance-btc-tracker | Vue | three masters, two slaves and `x1`, a node of a foreign authority | one server, primary + derived journal | 1 master-slave mesh, 2 master-master, 5 leader-kill re-election, 7 quorum-loss, 8 split-brain prevention, 10 cross-node browser, 13 rt partition converges (skipped as flaky, P-169), 17 foreign certificate refused, 20 rt set width across nodes, 23 verifier circle on every master, 25 freeze settles on every master, 29 a node with a cluster directory of its own refused on both ends, 30 a ready data export copy outlives the node its agent lived on, 32 a node with another admin view mode refused on both ends, 33 every master takes browsers, 34 a tab is the same on every master under protected mode, 35 rt row deleted while cut off is swept, 36 a slave cut off with its leader stops its work before it runs elsewhere, 38 manual maintenance from any node, 41 i18n catalog taken once and skipped after holder restart, 42 a node with another default language refused on both ends, 44 replicated waiting fleet survives leader death and quorum loss, then starts when slots return without another ask, 45 upload pool across nodes: reservations, worker receipts, crossing waits and peer generations, 46 browser liveness across masters, 47 a node with another project time zone refused on both ends, 57 millisecond moments survive a real hot FULL restore, 58 erasure frames and owner recovery, 59 erasure waits and restrict, 60 partial erasure survives FULL restore, 61 merge recovery after sender worker death and FULL restore |
| ecommerce-shop | React | one master and two slaves of unequal room, `ram=10` and `ram=4`, each with `slots=64` | one server, primary + derived journal | 3 placement, 4 slave-kill failover, 6 hot-join, 9 daemon-crash self-heal, 12 rt replication, 14 rt claim refused, 16 recreated node leaves no phantom fleet, 18 capacity is consumed, 19 worker death on a live node, 37 costless agents consume slots, 39 person agents across nodes, 43 waiting ballast retries after a slave returns without another ask (cleanup checked with `composer -d demo/ecommerce-shop run test:cluster:scenarios -- 43 37 18`) |
| online-testing | Angular | three equal masters that host work themselves | one server, primary + derived journal | 11 cross-node db fact, 15 db interest addressing, 21 schema rolled out once by nodes that start together, 22 a node reading another database refused on both ends, 24 a cut-off leader stops its work before it runs elsewhere, 40 journal triggers installed once and unchanged on ordinary restart, 48 daemon hang self-heal |
| roblox-game-server | Vue | three masters and one slave: `m1` without capacity — it votes and hosts no placed work; `m2`, `m3` workers with `ram=10`, `slots=64`; slave `s1` worker with `ram=4`, `slots=64` | Galera of two data nodes and the `garbd` arbitrator, which votes and stores no data, behind HAProxy; the application uses both data nodes: HAProxy gives each node a data node of its own — `m1` and `m3` the first, `m2` and `s1` the second — so a row written by one node is always read by a node on the other data node, and a node whose data node is down is led to the living one. HAProxy closes the sessions of a data node it marks down: a data node cut off from the majority stays reachable and refuses every statement, and Hilos does not reconnect from a member outside the primary component, so a proxy in front of Galera must close them | 26 database is one cluster, 49 a row written by one node is read by another through another data node, past the process copy, and after that connection is lost, 50 journal is one logical set on every server; 51 a working master that does not lead is cut off and stops its work before it runs elsewhere; 27 the data node under the settings library dies under load: every node writes on through the survivor and the arbitrator, and it comes back, catches up from the survivor that donates while it serves, and takes connections only when Synced, with the journal oracle on both; 53 the arbitrator lost and back; 54 a data node cut off from the other and the arbitrator is fenced while the majority writes on |
| file-gallery-manager | React | two masters without capacity and three slave workers with `ram=10` / `6` / `4`, each with `slots=64` | primary and one read-only replica behind one address that always leads to the primary, no automatic promotion | 31 replica keeps up with the primary, 50 journal is one logical set on every server, 52 losing the leader of the two masters leaves no heir: every slave carrying work stops it, nobody leads, until the leader returns, 55 the primary and then the replica are lost: while the primary is down no write is confirmed and no application connection reaches the replica; both return without a Hilos restart and hold one journal |
| concert-tickets | Angular | five equal master workers with `ram=10`, `slots=64`, no slaves | Galera of three data nodes behind HAProxy; the application uses all three: HAProxy lays each next connection on the least busy data node | 21 schema rolled out once by five nodes that start together on an empty Galera database; 26 database is one cluster of three synced data nodes, each holding an application connection; 27 a data node is killed under load while every node writes on through the two survivors, then comes back, catches up and takes connections only when Synced; the same cycle writes journal batches through the oracle and holds the three servers to one journal |

Scenarios **58–60** on binance-btc-tracker exercise live erasure recovery.
Scenario 58 suppresses a start ask and recovers it through ordinary cron,
routes one live duplicate ask and answer, then kills the root's host after
personal commit while mail debt remains open. The original holder survives
on another node; the root starts on the surviving slave and completes without
another purge. A stale ask has an observed participant answer and unchanged
results/effects. Registered disabled email completes its erasure duty.
Scenario 59 keeps an unregistered delivery owner as an open wait after one
ordinary recovery pass. A late identity produces a safe `RESTRICT` refusal,
rolls back the personal final transaction and leaves the operation unfinished.
Scenario 60 verifies a stable partial FULL archive, proves the same root's real
idle stop with personal/final results absent, then restarts the holder once and
observes original completion. A real hot import removes a post-archive marker,
preserves exact archived NON-CAPTURE results and assignments, and rebuilds the
missing personal/final results through ordinary startup/resume. Finalized
captures are absent, the folded final is unchanged and the neighbour is intact.
Fresh pointed runs `58 59` and, separately, `60` passed with their owned cleanup:
fixtures, settings, the one faulted node, exact archive/sidecar, own restore
window and ordinary mesh/fleet restart, as applicable. Their measured scenario
times, including cleanup, are recorded in the Binance README.

Scenario 61 opens a merge and verifies a FULL archive after give and holder
sessions commit, with the hand-over still held and the receipt absent. The
survivor stops idle and the sender worker dies. With the holder pass paused,
an ordinary placement request starts a replacement sender, which replays the
saved give at startup. Its hand-over wakes the survivor, whose receipt commits
while the ordinary receive path is gated. The stand observes the receipt,
clears the gate and then fires the ordinary pass to finish any open duties.
After hot FULL import, the same merge completes with the archived results,
assignments, transferred row IDs and fence revisions. Recovery rebuilds the
receipt, survivor sessions and cleanup, and each backup code retains its spend
status by ID.

The original ordinary receive ask was held and discarded. After FULL recovery,
the stand confirms the restored sender stopped, allowing a later ordinary
start on the current node if the sender has already been placed again. It
records the current nodes and worker incarnations of both agents, arms the
survivor's answer probe once and delivers one old hand-over. The survivor's
single `go`/`done` receipt reply matches the original operation, receive
assignment and journal receipt. The completed SQL snapshot and the neighbour
stay unchanged, with the copy absent and fence revisions still one. The
pointed run observed both agents on one worker on `s1`. A cross-node proof
requires the two agents to be on different nodes before sending.

On success, product erasure removes the survivor's circle and the neighbour,
and scenario 60 runs next on the same stand without a restart in between. A
failed 61 uses the targeted fixture SQL cleanup and stand restart from
scenarios 58–60. PHP integration checks two separate deliveries of the old
hand-over after completion, both through the same survivor handler. It also
covers give replay on an already-running holder's scheduled pass, a lost
receipt answer, a subsequent merge and old `DB_SYNC`. Scenario 58 covers
owner relocation after node failure. See the restore window checks and PHP
integration details in
[Account operation recovery](../architecture/account-operations.md#recovery).
The measured time is in the [Binance README](../../../demo/binance-btc-tracker/README.md).

On the dedicated, disposable test stand, scenarios 58–61 admit fixture writes
and backup mutations using the current roster's daemon, BackupAgent, and
LogStore journals. For each required stream, a pruned batch is covered by a
retained, confirmed segment or a complete batch listing that shows the stream
was absent. The reader confirms the listing separately for each stream. If part
of a batch is deleted between reads, a `BackupAgent` or `error` file seen while
reading another stream can later be counted as absent. This can let the harness
stop the stand during an unrelated `CREATE`. The reader preserves observed file
sizes across listing and open, and checks unfinished copies within the history
needed to establish the boot or match the stopped writers' last segments. For an
addressed backup mutation, the final incarnation check compares the expected
publisher with the last confirmed journal read on the original holder's node.
A BackupAgent restart on the same worker in the same millisecond as the previous
daemon acknowledgment can be mistaken for the previous incarnation until the
new acknowledgment is read. After a move to another node, the new holder can
already be visible in that node's journal while the final check still uses the
original holder's journal. An addressed mutation can then write on the former
holder's node. The last confirmed reads bound what admission can establish.
When admission detects an unsafe state, the harness stops the run with `FATAL`
and preserves any primary `FAIL`.

`ram` means abstract units of declared placement capacity, not gigabytes of RAM
or a container memory limit.

**What each new shape must keep — continue or stop safely**

| Carrier | Layer | Fault or condition | Expected outcome | Proof owner |
|---|---|---|---|---|
| roblox-game-server | Hilos | `m1` alive without capacity | It votes and never hosts placed work; work runs on `m2`/`m3`/`s1`. | scenario 51 |
| roblox-game-server | Hilos | A working master that does not lead is cut off | Its work moves to the remaining capacity — the other working master or the slave — never two executors at once; `m1` takes none; no rebalance is owed on return. | scenario 51 |
| roblox-game-server | DB | `garbd` is lost | The two data nodes keep quorum and writes continue. | scenario 53 |
| roblox-game-server | DB | One data node is lost, `garbd` alive | Survivor and arbitrator keep quorum and writes continue; the returned node takes connections only when Synced. | scenario 27 |
| roblox-game-server | DB | Data node + `garbd` are cut off from the other data node | The majority continues; the minority takes no operations, its sessions are closed by the proxy and its nodes write through the majority. | scenario 54 |
| file-gallery-manager | Hilos | Either of the two masters is lost | Quorum 2 of 2 is gone: leadership and placed work stop safely, never two executors; the three slaves add no votes; the returned pair elects one leader and work resumes. | 52 on file-gallery-manager (the leader is lost); losing the other master is the quorum condition of 7 and the stop of 36 on binance-btc-tracker (36 parked, F-012) |
| file-gallery-manager | Hilos | One slave is lost, masters healthy | Its work moves to the remaining capacity. | 4 slave-kill failover on ecommerce-shop: the same failover; the probe fleet costs a slot and no ram, so 10/6/4 change nothing (owner's decision 2026-11-14) |
| file-gallery-manager | DB | Replica is lost | The primary is unaffected. | scenario 55 |
| file-gallery-manager | DB | Primary is lost | The database is unavailable until the primary is safely restored; no promotion and no read from the replica. | scenario 55 |
| concert-tickets | Hilos | Two of five masters are lost | Quorum 3 of 5 holds and work continues. | `ClusterConsensusConfigTest` (a set of five takes a quorum of three); 5 leader-kill re-election on binance-btc-tracker |
| concert-tickets | Hilos | Three masters are lost | Work stops. | `ClusterConsensusConfigTest`; 7 quorum-loss on binance-btc-tracker; 51 working master cut off stops its work on roblox-game-server; with no leader possible the stand's placed work stops — 52 the leader of two lost stops the work on file-gallery-manager |
| concert-tickets | Hilos | 3:2 partition, with the old leader and its working agents in the minority | The majority continues; the minority leader stops its work; one executor after recovery. | 24 cut-off leader stops its work on online-testing (parked, F-013); 51 working master cut off stops its work on roblox-game-server |
| concert-tickets | DB | One of three data nodes is lost and returned | Writes continue through the survivors without restarting Hilos; the returned member catches up and takes new connections only when Synced. | scenario 27 |

The matrices above prove Hilos and database failures separately, keeping the other
layer healthy. New fleets enter after phase-one
acceptance HIL-2023 (the ordinary activations HIL-2017, HIL-2019 and HIL-2021 have
landed), with isolation in every instance extending HIL-2015: their octets are the third block of
`scripts/stand-instance.php`, the variables `HILOS_STAND_NET_ROBLOX_GAME_SERVER_CLUSTER`,
`HILOS_STAND_NET_FILE_GALLERY_MANAGER_CLUSTER` and
`HILOS_STAND_NET_CONCERT_TICKETS_CLUSTER` with default octets 225, 227 and 229, and the
cluster runner forwards them.
Full run 2550 on the test box (2026-11-16, seven lanes, n=1) ran all six carriers:
the eleven cluster steps had green main verdicts, with existing scenario skips
and browser quarantine reported separately. Its snapshot durations supply the
cluster scheduling hints; they do not measure container resource peaks or prove
the subsequent scenario-51 oracle fix. Do not add lanes or instances: HIL-2043
records the final full-run acceptance and sampled resource budget under the
existing comparison conditions. That resource acceptance remains pending until
its run, observations and explicit cleanup have finished.

Every carrier owes [the database guarantees](../app-topology.md#database-guarantees-hil-1206)
`ONE_LOGICAL_DATABASE` and `READ_AFTER_WRITE` for both primary and
`<primary>-change-log`. Roblox sets `wsrep_sync_wait=1` (the READ bit) in every
application session, reconnects included, with `wsrep_dirty_reads` off.
Concert-tickets uses that same session setup.
Hilos does not read the replica; losing acknowledged writes by promoting a
lagging replica is not an accepted outcome.

Scenario **4** on ecommerce-shop moves the real fleet and one person's owner
through the same slave kill. Independent database reads require exact numeric
person and second-factor moments before and after failover. The ordinary
`second-factor:unlock` command then clears the lock through the new owner,
preserves the reset-wait sentinel and places `updated_at` within its observed
command interval. Its owned rows are removed and the original stand restarted
without deleting volumes in `finally`.

Scenario **40** on online-testing keeps the complete database-marker row,
including `written_at`, byte-for-byte across its existing non-leader restart.
The same run checks the exact trigger catalog, unchanged global DDL counters,
an empty rollout claim, and the restored mesh and fleet. It adds no second
restart or schema rollout catalog.

The ordinary fresh pointed runs observed for HIL-1934 took 82.416 seconds for
scenario 4 (110.230 seconds for the whole invocation) and 7.212 seconds for
scenario 40 (34.708 seconds for the invocation), including scenario cleanup.
These are totals with no comparable baseline, not measured additions or full
matrix costs.

Scenario **45** on binance-btc-tracker proves the
[upload raw-byte pool](../architecture/uploads.md#the-raw-byte-pool) on real
nodes (`scenario_45_upload_pool_across_nodes` in
[`scenarios.py`](../../../framework/docker/cluster/scenarios.py)). Its actual
shape guard is `Need(masters=2, slaves=2)`: the WORKER fleet probes run on
both slaves of this carrier. From the repository root, use the pointed run
while changing that path and the carrier's whole matrix before merge:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 45
composer -d demo/binance-btc-tracker run test:cluster:scenarios
```

The test-only `test:cluster:upload-pool` driver uses `inspect`, `limit` and
`enqueue`, behind the ordinary CLI and daemon production guards. Its oracle
has exactly four fields: `limitBytes`, `usedBytes`, `waitingUploads` and
`pendingPeerReservations`: the installed node limit, charged raw bytes,
unfinished credit demands plus driver metadata, and incoming/outgoing reserve waits.
`pendingPeerReservations` does not count every received worker receipt.
The driver's zero limit suspends grants for the proof; the production setting
remains positive. These live counters do not prove RSS or payload contents.

With 131,072-byte node pools and 65,536-byte portions, the scenario proves
reservation before data, local plus remote debt under one cap, overlapping
crossing demands and fresh control roundtrips while capacity waits. It captures
an exact worker incarnation before STOP, checks its stopped state, and restores
that incarnation with CONT in `finally`. Real worker death, peer EOF and a new
peer generation prove final cleanup without releasing accepted receiver debt
early. The original stand is restored and checked before the matrix proceeds.
Actual browser credit/binary bytes, files larger than the pool and exact
downloaded contents are covered separately by chat's
[`attachment.spec.ts`](../../../demo/chat/tests/e2e/tests/attachment.spec.ts),
under `the shared browser upload pool`.

Scenarios **43/44** prove waiting-placement recovery (HIL-446). Ecommerce-shop's
43 returns a slave to waiting ballast; binance-btc-tracker's 44 first gives both
slaves 16 slots, then fills them with fleet indices 2000..2032 until one waits
`Unplaced` with a null host and exact slot refusals. Every surviving master must
hold a complete snapshot with that record before the leader is killed, naming
the same leader/term and at least the observed revision. A new leader's snapshot
must name its new term after rebuild, preserve the queue, and remain available
when that master loses quorum and leadership. Returning the masters must recover
the queue again; restoring the first slave's 64 slots then starts the unique
target and advances its live RT row without another ask. All live placements
count against the ceiling. The supervisor only declares 0..9, so it cannot mask
lost extra intent. Revisions are compared only within one leader/term. These
are delivered memory replicas, with no durable or quorum-commit guarantee.
Both scenarios clean up by restarting their whole original stand without
deleting volumes, restoring the ordinary fleet and removing extra demand and
environment overrides before the next scenario. Scenario 44 runs after 5/7/8:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 5 7 8 44
```

Scenarios **41/42** on binance-btc-tracker prove the i18n startup and default
language agreement (HIL-1505); its compose gives every node
`HILOS_DEFAULT_LANGUAGE=en`. Scenario 41 runs first in that stand's matrix,
before any restart or kill can rotate the original startup logs. It requires
one `hilos_i18n_library` startup and one started POLICY placement, one
`I18n catalog reflow committed: <fingerprint>` INFO on its holder, and the same
64-character lowercase hex fingerprint in `hilos_i18n_reflow`. The database has
51 countries, 51 English base country names, and only the `en` language and
countryless locale. Killing and restarting the holder produces a new library
startup and one started placement, with the catalog snapshot unchanged and no
new reflow commit line.

Scenario 42 temporarily recreates a slave with `HILOS_DEFAULT_LANGUAGE=ru`.
It requires the exact `ru` versus `en` refusal on both ends, the node offline
in every survivor's view, the singleton placed outside it, and no `ru` language
row. Its `finally` restores the original env, full mesh and singleton; scenario
41 also restores the holder, mesh and singleton in `finally`. Both scenarios
belong only to binance-btc-tracker. From the repository root, the pointed run
and that carrier's full matrix are:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 41 42
composer -d demo/binance-btc-tracker run test:cluster:scenarios
```

Scenario **47** on binance-btc-tracker recreates a node with another
`HILOS_PROJECT_TIME_ZONE`. It requires the exact project-time-zone refusal on
both ends and the node offline in the remaining cluster. The scenario restores
the original environment and mesh in `finally`. From the repository root, run:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 47
```

Scenario **46** on binance-btc-tracker proves
[browser socket liveness](../architecture/event-loop.md#browser-socket-liveness) across
masters (HIL-1566; `scenario_46_browser_liveness_across_masters` in
[`scenarios.py`](../../../framework/docker/cluster/scenarios.py)). A raw socket is held
through the entry on a master that does not lead (the `liveness-hold` command of
[`cluster.py`](../../../framework/docker/cluster/cluster.py)), the owner of `connections`
is the singleton on the leader, and the row is read on the third master. The whole
stand, the socket's master included, runs the framework's 40/50/120/600 s, and no
moment is waited out: the raw socket publishes the session token its master set on the
101, and the scenario brings the doubt, the close and the check of the copy to now on
that master with `test:connection:liveness` (HIL-1860). The scenario requires that a
socket whose doubt is brought to now is doubted in the observer's row, and cleared once it
answers; that a transition made while the owner's worker is stopped is missed — the
transition and the check brought to now after it go to the stopped worker, and the
observer's row stays as it was (for a missed `false` the check is brought twice, because
the tick of the first may itself clear the doubt and skip its check); that, after the
worker is killed and the leader starts the owner again, the socket's master's own check
repairs the row within `CONVERGE_TIMEOUT`, the check being brought to now again on every
turn of the wait, for a missed `true` and a missed `false` alike; that the master closes a socket whose close is brought
to now and drops one that floods pings and reads no pong past the 8 MiB cap, the cap's log
line being in that master's log and no other's; and that another socket of the same
master, and every node, live on. How long each span is, the scenario does not measure:
the unit test
[`WebSocketLivenessTest.php`](../../../framework/tests/Unit/Socket/WebSocket/WebSocketLivenessTest.php)
holds the production values. The scenario also resumes a stopped worker in `finally`. It
needs three masters and the entry, and runs before the freeze pair 23/25:

```bash
composer -d demo/binance-btc-tracker run test:cluster:scenarios -- 46
```

The single-node half is `presence-liveness.spec.ts` in each demo's `tests/e2e/tests/`
(Vue, React, Angular). Each demo's `docker/docker-compose.test.yml` sets none of the
`WEBSOCKET_*` liveness spans, so the master runs the production values of the catalog,
and the spec waits none of them out: `bringLivenessMomentToNow()` from
[`rawWebSocket.ts`](../../../framework/frontend/e2e/rawWebSocket.ts) sends
`test:connection:liveness` with one browser's session token
([Time-based features](../cli/commands.md#time-based-features-no-universal-clock)) and
requires the master to answer that exactly one socket took the moment. Raw sockets are
needed because no browser keeps silent: Chromium answers a ping in its network service
whatever its page is doing. The spec therefore opens raw sockets with the cookies of a
browser context (`openRawWebSocket()`) that can keep their pong back. The command takes
every socket of the browser it names, so the person signs in on two browsers, the first by
`createTestUser` and the second by `bindTestUser`; each reads its session token on a fresh
document, closes its tab and leaves one raw socket, so the token is the cookie that socket
presents and the one the master finds it by. An administrator watches the person
on the people list and the card: online while one socket answers, probably offline while
every socket is doubted, offline when none is left. The spec keeps no clock of its own and
no `test.slow()`; each step brings one browser's doubt or close to now, waits for what the
master did to its socket, the ping it sent into the silence or the close, and then for
what the observer was told.

Scenario **48** on online-testing proves
[container watchdog hang recovery](../architecture/daemon-lifecycle.md)
for a live follower master (`scenario_48_daemon_hang_selfheal` in
[`scenarios.py`](../../../framework/docker/cluster/scenarios.py)). Its `Need(masters=3)`
selects a confirmed follower while the leader and surviving majority remain healthy. The
scenario observes the first accepted heartbeat and rechecks the follower and majority before
the fault. It pins the watchdog PID 1, its start tick, the daemon PID and its start tick, and
the container identity, and requires the same watchdog process to remain alive. It freezes
the daemon with SIGSTOP, uses the harness's `watchdog-expire-heartbeat` request to make its
active 60-second silence deadline due, and checks TERM; it then uses
`watchdog-expire-stop` to make the active five-second stop grace due and checks SIGKILL plus
the watchdog's observation of the old process's actual exit. No command-port request is sent
to the stopped master. Only after a new master is alive in the same container does the
scenario inspect the victim again and require every node's fresh roster to agree that it
rejoined.

Those two harness requests send SIGUSR1 and SIGUSR2 to the addressed watchdog; they make only
the active hang deadlines due now and do not wait out the production 60 seconds or five-second
grace. The watchdog admits them only in a non-production environment, and the harness verifies
the target watchdog PID before signaling it. On this stand the normal 20-second
`DAEMON_MIN_RESTART_INTERVAL` backoff remains real. Polling for TERM, KILL, observed exit, the
replacement process and fresh roster is bounded by the existing `CRASH_RECOVERY_TIMEOUT`.
Its `finally` path captures evidence and ordinarily recreates the victim node so the next
scenario starts with the stand restored.

Measured on the test box on 2026-11-08, scenario 48 took 36.288 seconds when pointed and
38.505 seconds in the matrix, including initial readiness, evidence collection and cleanup.
The recovered-node inspection portion took 26.867 seconds pointed and 27.632 seconds in the
matrix. These are the scenario's observed added times; the 76-second pointed invocation and
128-second complete matrix are command totals, not scenario cost. The timing is an
observation, not a promise for a future graph. The separate 24-hour no-browser observation
has not been completed and is not established by these scenario runs. From the repository
root, run the pointed scenario and, before merge, the online-testing matrix:

```bash
composer -d demo/online-testing run test:cluster:scenarios -- 48
composer -d demo/online-testing run test:cluster:scenarios
```

The Hilos shapes keep their original purpose: quorum, network partitions and
TLS on binance-btc-tracker; placement and failover with a stable leader on
ecommerce-shop; shared database facts and simultaneous schema rollout on
online-testing. Roblox adds roles with and without capacity and a Galera
arbitrator. Gallery adds the even quorum of two masters that must stop safely,
and primary/replica. Tickets adds a quorum of five masters and
three-node Galera. The owner's decision of
2026-11-04 (HIL-1985) chooses different shapes for meaningful tests.
The former primary/replica and Galera database topologies were replaced by
one MariaDB on binance-btc-tracker, ecommerce-shop and online-testing by the
owner's decision of 2026-10-14.
Scenario **26** runs on roblox-game-server, whose database is one Galera
cluster of two data nodes and the `garbd` arbitrator, and on concert-tickets,
whose database is one Galera cluster of three data nodes; scenario **49** runs
on roblox-game-server alone. Concert-tickets also carries scenario **21** on its
Galera: five nodes started together on an empty database roll the schema out
once. Scenarios **31** and **52** are carried by file-gallery-manager; 52 needs
exactly two masters, so no other stand can carry it.

Scenario **50** runs on file-gallery-manager, a primary and its read-only
replica, and on roblox-game-server, two Galera data nodes and `garbd`. It
writes the settings library's example settings through `test:settings:write`
in five batches, one refused between confirmed ones, waits until every replica
has taken them, and holds every database server to one catalog — the project's
trigger files, the columns of both schemas, the journal's partitions and the
application user's grants — and one journal: one receipt with one write per
pair for each confirmed batch, none for the refused one, the same rows on every
server and no entry of a copy's own triggers. It runs after 49 and before 31,
so on file-gallery-manager scenario 31 then finds these writes on the replica
too; `Need(database_members=2)` refuses a stand of one server. Concert-tickets
does not carry it: its Galera replicates by the same mechanism as
roblox-game-server's, and the same oracle holds its three servers once a member
is lost and returned in scenario 27. The pointed runs:

```bash
composer -d demo/file-gallery-manager run test:cluster:scenarios -- 50
composer -d demo/roblox-game-server run test:cluster:scenarios -- 50
```

Scenario **27** is carried by roblox-game-server, on two data nodes and `garbd`:
one cycle kills a data node under load and brings it back, the return checks
once split off as 28 included, and the journal oracle holds both data nodes
after it; **53** and **54** take the arbitrator away and cut a data node off.
Concert-tickets also carries scenario 27, on three data nodes and no arbitrator:
one cycle covers loss and return, writes journal batches through the oracle and
holds the three servers to one journal once a member is back. Its PASS records
application connections before and after the fault and the nodes writing through
each backend. The component model and preconditions count votes separately from
data members. Scenario **28** was merged into **27** (HIL-2041); its number is retired and must not be reused.

```bash
composer -d demo/roblox-game-server run test:cluster:scenarios -- 27 53 54
composer -d demo/concert-tickets run test:cluster:scenarios -- 27
```

Scenario **55** runs on file-gallery-manager, the only stand carrying a
replica (`Need(database_replicas=1)`). It loses the primary and then the
replica and returns both, with the JournalOracle writing one synchronous batch
at a time. Its final confirmed batch, replica settling and journal verification
use the same `finish_journal()` as 27. While the primary is down, a write is not
confirmed and no application connection reaches the replica; returned, the nodes
write again without restarting
any node. The replica is then lost while the primary writes on. Primary first,
because restarting the replica after the primary's return replaces waiting for its
reconnect timer; the write made while the primary is down waits out the CLI's
15-second answer window; the reconnect layer is proven by integration tests
(`framework/tests/Integration/DatabaseReconnectIntegrationTest.php` and
`demo/chat/tests/Integration/JournalReceiptIntegrationTest.php`). The pointed run:

```bash
composer -d demo/file-gallery-manager run test:cluster:scenarios -- 55
```

The one-time schema rollout is needed on one server too (HIL-1228). Nodes that
start together also read one marker of their database — the first write is
decided by the insert, like the rollout claim — and a node naming another
marker is refused by every other (HIL-1206).

A stand takes rather than holds. The harness is one and shared,
`framework/docker/cluster/`: it reads the nodes, their addresses, their room
and the set of scenarios from the stand it is run against. The probe agents the
scenarios drive are the framework's (`framework/backend/Cluster/Probe/`): a demo
lists in its `AGENTS` the ones its stand's scenarios need, one row each, keyed by
the probe's type with the record taken from `ClusterProbe::AGENTS`:

```php
HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
```

A probe starts only on a node that is in a cluster and whose `APP_ENV` is not
production-like, so the same demo on one node, on its own Playwright stand and
in production carries the rows and runs none of them. Which demo takes which:
binance-btc-tracker the fleet, runtime set and database probes; ecommerce-shop the
fleet, the claimer and the ballast; file-gallery-manager the fleet;
online-testing the fleet and the database probe; roblox-game-server the fleet
and the database probe; concert-tickets the database probe for scenario 27. The
database probe stores its values in the framework install unit `probe_value`,
so a demo that takes it carries that unit's
migration pair, pulled with `db:framework:pull` like any other framework unit.
Binance's generated install is migration pair `113`, creating
`hilos_probe_value`; its schema/origin, adoption and topology checks cover the
activation. Scenario **57**, `composer -d demo/binance-btc-tracker run
test:cluster:scenarios -- 57`, passed on a fresh stand: a slave-held archive
went through hot FULL restore, the same warmed remote probe returned
`v1 -> v2 -> restored v1`, exact numeric DB moments survived, and a new
post-import UPDATE produced a nonempty receipt/log/change chain with numeric
moments in its command window. The ordinary invocation, including startup and
owned cleanup, took about 2m42s; no isolated scenario duration or baseline
delta was measured.

The stand's resolved Compose model is the one place its nodes are written, and
there is no second copy: a node is a service with `CLUSTER_ENABLED=true` and a
`CLUSTER_NODE_ID`, and its container, address, role, `ram` and log directory
are read off that service — what the node itself reads. What compose cannot
say stands in a top-level `x-hilos-cluster` block of the same file: `cli`, the
service commands to the nodes go through; `entry`, the one browser-facing service
shared by the masters (optional); `stranger`, the node under a profile
that a scenario raises on its own (optional); `cluster-directory`, the name and
in-container path of a cluster directory of the stand's `$fs`, which scenario 29
gives one node a copy of its own (optional); `scenarios`, the numbers the
stand carries — never their order, which is the harness's; `e2e`, the optional
browser declaration with `runner` (a service under profile `e2e`, requiring
`entry`) and `capabilities` (an optional list, empty when absent). Its only
capability names are `daemon` (leader loss; at least three masters and one
slave), `delivery` (live fault control; at least three masters), and
`env-mismatch` (a foreign-environment fixture; at least one slave). The host
checks every declared capability against the stand's shape before starting
containers, regardless of browser filters. After collecting the native plan
and before filtering projects or starting a phase, it refuses any planned
daemon, delivery or env-mismatch test whose capability is undeclared.
Delivery control serves every live phase on a declaring stand because its
spec loads before native execution filters; collection receives its directory
on every stand without starting a controller. The env-mismatch fixture follows
the native plan separately for main and quarantine.

Scripts that call the harness enter through `framework/docker/cluster/run` with that demo's
compose file. A scenario the stand names but cannot carry by its shape — too
few masters or slaves, no stranger, no cluster directory — is refused before
the stand is raised, and so is a stand that leaves out what the harness reads.


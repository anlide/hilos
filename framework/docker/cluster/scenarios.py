"""
scenarios.py - the assertion matrix of the daemon-cluster e2e harness (HIL-185), one for
every cluster stand.

It assumes the stand is already up (cluster.py raises it fresh before the matrix) and drives
it: for each scenario it perturbs the cluster through the controller (control.py: docker kill
-9 for node-down, docker network disconnect for partition, a SIGKILL of the daemon or one
worker inside a live container, and a SIGSTOP of one worker), polls each node's
`test:cluster:inspect` reply until the topology converges (bounded by a hard cap), and
asserts the expected invariants against the machine-readable reply. Destructive scenarios
restore the cluster and re-converge before the next.

The stand is not written down here. bind() takes it once, before the matrix, and the nodes a
scenario perturbs are named by their role on it - the first master, the second slave - never
by id. Each scenario says what shape it needs (Need), and a stand that names a scenario it
cannot carry is refused before it is raised.

Timing on a loaded host (HIL-367): the convergence caps below are sized for an
adequately-provisioned stand (HIL-348). On a resource-constrained host
the grace-driven detection windows (keepalive-timeout + failover-grace, HIL-183)
run longer than the fixed caps and the matrix flakes on pure "timed out after Ns"
without the cluster logic being wrong. Two guards keep the run honest without
falsely passing:
  * the caps are multiplied by a TIMEOUT_SCALE (>= 1.0) derived from the host's
    load-per-cpu and free memory, the same inside the full run and outside it;
    the runner no longer exports a factor from its lane count (HIL-1227).
    CLUSTER_E2E_TIMEOUT_SCALE is a pin, made by hand or by the one graph step that
    runs the cluster browser beside the demo e2e steps, and a FLOOR which little
    free memory may raise further. A provisioned host stays at 1.0.
  * a scenario that fails PURELY on a convergence timeout is retried a bounded
    number of times (CLUSTER_E2E_RETRIES, default 1) after re-converging; a hard
    invariant assertion never retries and fails immediately.

Covers the full spike-HIL-176 matrix:
  1 master-slave mesh          exactly one leader, slaves follow
  2 master-master              one leader among masters, slaves never lead
  3 placement                  the no-op agent is placed on a data-plane node
  4 slave-kill failover        leader re-places the agent onto another slave
  5 leader-kill re-election     survivors elect a new leader, and the fleet it inherits keeps
                               running past its slaves' fence window (HIL-440)
  6 hot-join                   a returning node is admitted; full roster
  7 quorum-loss                a minority stops leading; no new leader
  8 split-brain prevention     the majority keeps one leader; the minority steps down

Plus scenarios beyond that matrix:
  9 daemon-crash self-heal     a node whose daemon is SIGKILLed with one worker frozen: the sweep
                               kills the frozen one, and the node rebinds and rejoins inside the
                               same container (HIL-450, HIL-1310)
 10 cross-node browser         a browser attached to one node is answered from another (HIL-668)
 11 cross-node db fact         a row written on one node is read back on another (HIL-670,
                               HIL-712)
 12 rt replication             a fleet row reaches the nodes that read it, and only them
 13 rt partition converges     a cut-off node serves its replica frozen and catches up (HIL-589)
 14 rt claim refused           a second owner of one collection is named and kept down (HIL-696)
 15 db interest addressing     a db fact hops only to the nodes that read its collection (HIL-750)
 16 recreated node leaves      a data-plane container replaced faster than the failover grace
    no phantom fleet           leaves the leader naming no node that runs nothing (HIL-719)
 17 foreign certificate        a node certified by an authority the cluster does not trust is
    refused                    refused on both ends of every link and admitted by nobody (HIL-1034)
 18 capacity is consumed       ballast fills the slaves in proportion to their declared ram,
                               never lands on a master, and a full cluster places no more, saying
                               why node by node (HIL-448, HIL-447)
 19 worker death on a live     one worker of a slave is SIGKILLed: the node names the loss,
    node                       the leader re-places exactly those members, and the rest of the
                               node runs on (HIL-440)
 20 rt set width across nodes  every node owns its set of one collection: a node writes its own
                               set, is refused another's, and a node cut off while a set was
                               written gets the row by the hand-over of that set (HIL-1116)
 21 schema rolled out once     every node of the stand starting together on an empty database:
                               one applies, the rest wait (HIL-1228)
 22 other database refused    a node reading another database marker is admitted by nobody
                               (HIL-1206)
 23 verifier circle on every   the circle photographed at a freeze is on every master's row
    master                     through the window and gone once the system opens (HIL-1125)
 24 cut-off leader stops its   a leader carrying work and cut off fences it before the majority
    work                       starts any of it again (HIL-1217)
 25 freeze settles on every    every master reads active once the freeze holds, and the close
    master                     back from the window answers only once every master has stopped
                               again what the entry stopped (HIL-1128)
 26 database is one cluster    every member of a clustered database is in one synced primary
                               cluster, reads wait for the cluster's writes, and the application
                               is connected to each (HIL-1230)
 27 database member dies       a member is killed while every node writes; each writes on through
                               the others (HIL-1231)
  28 database member comes      the member comes back, catches up, and is handed connections again
                               (HIL-1231)
 32 another admin view mode     a node whose HILOS_ADMIN_VIEW_MODE_ENABLED differs from its
                               neighbours' is refused on both ends (HIL-1274)
 35 rt row deleted while cut    a cut-off neighbour sweeps rows deleted by each set owner on the
    off is swept               hand-over and keeps rows written just after it (HIL-1178)
 36 slave cut off with its     a slave cut off together with its leader fences its work once that
    leader stops its work      leader stops leading, before the majority starts any of it (HIL-1287)
 37 costless agents consume    agents that declare no cost still take a slot each: the slaves hold
    slots                      no more than the slots they declare, and the next is refused for
                               want of a slot on every slave (HIL-447)
 38 manual maintenance from    enable and disable on different follower masters, mint on a slave:
    any node                   every master holds the window without stopping agents (HIL-1367)
 39 person agents across nodes a person's row and child set survive idle, a wake on another node
                               and the death of that node, through real commands (HIL-1412)
 40 journal triggers installed  concurrent startup creates the filed triggers once; an ordinary
    once                       node restart leaves their catalog and DDL counters intact (HIL-1465)
 41 i18n catalog taken once     one library starts and commits the catalog on a fresh cluster;
                               restarting its holder keeps the fingerprint and catalog (HIL-1505)
 42 another default language    a node naming another HILOS_DEFAULT_LANGUAGE is refused on both
    refused                    ends, stays outside the mesh and creates no language (HIL-1505)
 43 waiting work retries when   an Unplaced ballast with no host starts after a slave returns,
    a node returns             without another placement ask, and keeps one record (HIL-446)
 44 waiting work survives       a replicated Unplaced fleet member survives a leader's death and
    leadership changes         quorum loss, then starts when slots return without an ask (HIL-446)
 45 upload pool across nodes    small real node pools, stopped workers, crossing requests, control
                               progress, worker death and peer replacement retain exact raw debt
                               until receipts/drop; original stand restored (HIL-1527)
 46 browser liveness across     a silent socket on one master is doubted and cleared in the row
    masters                    the owner on the leader writes and a third master reads; a missed
                               true and false are repaired once the owner is back; the close by
                               silence and the 8 MiB cap are the socket's master's (HIL-1566)
 31 replica keeps up with       a replica took every write the preceding scenarios made, stays
    the primary                read-only and is reached by nobody (HIL-1229)

run_matrix() answers 0 when every scenario passes, 1 otherwise.
"""

import json
import os
import re
import sys
import threading
import time
import uuid
from collections import namedtuple
from datetime import datetime, timezone

import control

# The stand the matrix drives, bound once by bind() before it runs: one process, one stand. The
# names below are read off it there - its nodes by role, its stranger, the room its slaves
# declare and the grace a slave keeps its work for - and stay empty until then.
STAND = None
MASTERS = []
SLAVES = []
ALL_NODES = []

# The stranger of scenario 17: the node the stand names in x-hilos-cluster.stranger, up only
# under its compose profile, whose certificate is signed by an authority the cluster does not
# trust (HIL-1034).
STRANGER = None
STRANGER_IP = None
# What either end of a refused TLS handshake writes, through the containment of a failing client.
TLS_REFUSAL_LINE = "Socket TLS handshake failed"

# The constants below that say "mirrors" copy the code of the framework's cluster probe agents
# the scenarios drive (framework/backend/Cluster/Probe/, HIL-1211), and each names the file it
# copies.

# Mirrors HilosAgentType::HILOS_PROBE_FLEET (framework/backend/Constants/HilosAgentType.php).
WORKER_AGENT_TYPE = "hilos_probe_fleet"
# Fleet size the leader keeps placed; mirrors ClusterProbe::FLEET_SIZE
# (framework/backend/Cluster/Probe/ClusterProbe.php).
WORKER_FLEET_SIZE = 10
# RT collection every fleet member owns one row of; mirrors HilosProbeFleetStatus::RT_COLLECTION
# (framework/backend/Runtime/State/Item/HilosProbeFleetStatus.php).
WORKER_STATUSES = "hilosProbeFleetStatuses"
# Seconds a fleet member waits between reports; mirrors FleetProbeAgent::REPORT_INTERVAL_SEC
# (framework/backend/Cluster/Probe/FleetProbeAgent.php).
WORKER_REPORT_INTERVAL_SEC = 5.0
# Seconds a slave keeps its work after losing the leader it answers to: the stand's
# CLUSTER_SLAVE_WORK_GRACE_MS, read by bind().
SLAVE_WORK_GRACE_SEC = None
AGENT_STARTED_ON_WORKER = re.compile(r"Agent '([^']+)' started on worker #(\d+)")
WORKER_DIED_HOSTING = re.compile(r"Worker #(\d+) died hosting \d+ agent\(s\): (.*)")
# How many lines of a victim's log a missing worker-death report prints (HIL-1162).
EVIDENCE_LINES = 40

# The settings row the per-node probe writes and reads. Non-catalog by construction - no
# settings catalog declares it - so it is a true orphan row and nothing else in the stand is
# about it.
DB_PROBE_KEY = "cluster_probe_value"
# What the read command prints in place of a value when the node holds no row for the key;
# mirrors ClusterTestDbReadCommand::NO_ROW (framework/backend/Core/CLI/Commands/
# ClusterTestDbReadCommand.php). Said in a word so that "no row" and "an empty row" stay
# different answers.
DB_PROBE_NO_ROW = "(none)"

# The RT collection the per-node set probe writes, cut into sets by the node a note belongs to;
# mirrors HilosProbeNote::RT_COLLECTION (framework/backend/Runtime/State/Item/HilosProbeNote.php).
# Each node's probe owns the set named by its own node id.
PROBE_NOTES = "hilosProbeNotes"
# The notes scenario 20 writes. Prefixes, not ids: a retried attempt suffixes them afresh, because
# the node the first attempt cut off is recreated holding every note written by then, and a note
# it already holds could not show what the hand-over brings.
NOTE_OWN = "set-note-own"
NOTE_FOREIGN = "set-note-foreign"
NOTE_PEER = "set-note-peer"
NOTE_LATE = "set-note-late"
NOTE_SWEPT_OWN = "set-note-swept-own"
NOTE_SWEPT_PEER = "set-note-swept-peer"
NOTE_KEPT = "set-note-kept"
NOTE_BURST = "set-note-burst"

# The probe that claims the WHOLE of the collection the fleet owns row by row, so the
# cluster-wide guard has two whole rights to judge; mirrors HilosAgentType::HILOS_PROBE_CLAIMER
# (framework/backend/Constants/HilosAgentType.php).
CLAIMER_AGENT_TYPE = "hilos_probe_claimer"
CLAIMER_INDEX = "0"
CLAIMER_AGENT_ID = f"{CLAIMER_AGENT_TYPE}:{CLAIMER_INDEX}"
# Seconds the leader waits between attempts at a policy placement that has not taken; mirrors
# DaemonManager::POLICY_PLACEMENT_RETRY_SEC (framework/backend/Core/Daemon/DaemonManager.php). A
# refusal outliving it is what "terminal" means here.
POLICY_PLACEMENT_RETRY_SEC = 5.0

# The probe that does nothing but hold capacity (HIL-448); mirrors
# HilosAgentType::HILOS_PROBE_BALLAST (framework/backend/Constants/HilosAgentType.php).
BALLAST_AGENT_TYPE = "hilos_probe_ballast"
# Ram one ballast reserves; mirrors BallastProbeAgentDaemon::RAM_COST
# (framework/backend/Cluster/Probe/BallastProbeAgentDaemon.php).
BALLAST_RAM_COST = 2
# Ram each slave declares in its CLUSTER_NODE_CAPABILITIES, read by bind(). The masters declare
# none, so by rule they take no placed work at all.
SLAVE_RAM = {}
# How many ballasts scenario 18 asks for: one more than the slaves have room for, set by bind().
BALLAST_ASKED = 0

# The resources the stand's nodes declare, by name. Ram mirrors ClusterProbe::RESOURCE_RAM
# (framework/backend/Cluster/Probe/ClusterProbe.php); slots mirrors ResourceProfile::RESOURCE_SLOTS
# (framework/backend/Cluster/Placement/ResourceProfile.php), the stock every placed agent takes
# one of (HIL-447).
RESOURCE_RAM = "ram"
RESOURCE_SLOTS = "slots"
# The tag the fleet asks of its host; mirrors ClusterProbe::CAPABILITY_WORKER
# (framework/backend/Cluster/Probe/ClusterProbe.php).
CAPABILITY_WORKER = "worker"
# Placement states that hold a node's capacity: what the leader counts against what it declares.
# Mirror PlacementState::Placing and PlacementState::Started
# (framework/backend/Cluster/Placement/PlacementState.php).
LIVE_PLACEMENT_STATES = ("placing", "started")
# Placement states a converged cluster no longer holds on its leader: a start still under way and
# a start a node tried and could not make. Mirror PlacementState::Placing and PlacementState::Failed
# (framework/backend/Cluster/Placement/PlacementState.php). Unplaced is not one of them: it is the
# wait for resources scenarios 43/44 prove (HIL-446), not a start that has yet to land.
UNSETTLED_PLACEMENT_STATES = ("placing", "failed")
# What a node logs when it starts a regular agent with no regular worker to seat it on; mirrors
# NoSuitableWorkerException (framework/backend/Core/Agent/Exception/NoSuitableWorkerException.php).
# A start that finds a regular worker its master launched but has not yet registered waits for it
# (HIL-1671), so in a node's startup window this line is that defect back.
REGULAR_STARTUP_REFUSAL = "No suitable regular worker available."
# The gate's reasons for refusing a node; mirror PlacementRefusalCode
# (framework/backend/Cluster/Placement/PlacementRefusalCode.php).
REFUSAL_MISSING_CAPABILITIES = "missing_capabilities"
REFUSAL_NO_DECLARED_CAPACITY = "no_declared_capacity"
REFUSAL_INSUFFICIENT_CAPACITY = "insufficient_capacity"
# What the leader logs when answering the nodes that asked for a placement; mirrors
# ClusterPlacement::answerPlacementAskers() (framework/backend/Cluster/Placement/ClusterPlacement.php).
ANSWERING_ASK_LINE = "Answering the placement ask of '{}': {}"
# What a node logs when a peer frame does not parse, as it drops the link; mirrors PeerLink
# (framework/backend/Cluster/Peer/PeerLink.php).
PEER_LINK_DROPPED_LINE = "Peer link dropped"

# The slots scenarios 37/44 give each slave in place of the stand's 64: few enough that the fleet,
# the demo's own placed agents and a handful more fill them.
COSTLESS_SLOTS = 16
# The fleet indexes scenario 37 asks for beyond the fleet's own 0..9: enough to fill two empty
# slaves of COSTLESS_SLOTS and one more.
EXTRA_FLEET_INDEXES = range(1000, 1033)
# Scenario 44 uses its own range, also outside the supervisor's 0..9 declarations.
WAITING_FLEET_INDEXES = range(2000, 2033)


# ------------------------------------------------------- adaptive timing (HIL-367)

def _load_per_cpu():
    """Host 1-minute load average normalised per CPU, or None if unavailable."""
    try:
        la1 = os.getloadavg()[0]
    except (OSError, AttributeError):
        return None
    return la1 / (os.cpu_count() or 1)


def _free_gib():
    """Host available memory in GiB from /proc/meminfo, or None if unavailable."""
    try:
        with open("/proc/meminfo", encoding="ascii") as fh:
            for line in fh:
                if line.startswith("MemAvailable:"):
                    return float(line.split()[1]) / (1024 * 1024)
    except (OSError, ValueError):
        pass
    return None


def resolve_timeout_scale():
    """Factor (>= 1.0) that stretches every convergence cap for a loaded/slow host.

    CLUSTER_E2E_TIMEOUT_SCALE is a pin made by hand — someone debugging a scenario,
    a CI that knows its own box — and one graph step that runs beside the demo e2e
    steps, binance-btc-tracker-cluster-e2e (scripts/test-suite.php). The runner
    used to derive it from its lane count and no longer does (HIL-1227): on the
    box of the line a factor above 1 rescued no scenario, and one that hung cost
    four of its caps.

    A pinned value is a FLOOR rather than the finished factor: a box short enough
    on memory to swap may still raise it further. What it does silence is the load
    term, which runs only when nothing is pinned (HIL-853). Capped at 4.0 so a
    runaway host still fails in bounded time. A well-provisioned host with no
    override resolves to 1.0 (no change).
    """
    scale = 1.0
    overridden = False
    override = os.environ.get("CLUSTER_E2E_TIMEOUT_SCALE")
    if override:
        try:
            scale = max(1.0, float(override))
            overridden = True
        except ValueError:
            print(f"  (ignoring non-numeric CLUSTER_E2E_TIMEOUT_SCALE={override!r})")

    if not overridden:
        lpc = _load_per_cpu()
        if lpc and lpc > 0.75:
            scale = max(scale, 1.0 + (lpc - 0.75))
    free = _free_gib()
    if free is not None:
        floor = 1.0
        if free < 1.0:
            floor = 3.0
        elif free < 2.0:
            floor = 2.0
        if floor > scale:
            if overridden:
                print(
                    f"  (CLUSTER_E2E_TIMEOUT_SCALE={override} raised to {floor}"
                    f" by {round(free, 2)} GiB free)"
                )
            scale = floor
    return round(min(scale, 4.0), 2)


TIMEOUT_SCALE = resolve_timeout_scale()

# Number of extra attempts for a scenario that fails PURELY on a convergence
# timeout (transient, env-driven); hard invariant assertions never retry.
SCENARIO_RETRIES = max(0, int(os.environ.get("CLUSTER_E2E_RETRIES", "1")))

POLL_INTERVAL = 1.0
CONVERGE_TIMEOUT = 60.0 * TIMEOUT_SCALE
FAILOVER_TIMEOUT = 40.0 * TIMEOUT_SCALE
ELECTION_TIMEOUT = 30.0 * TIMEOUT_SCALE
QUORUM_TIMEOUT = 30.0 * TIMEOUT_SCALE
# Recovering from a daemon crash is deliberately slower than any of the above: the
# watchdog rate-limits an error restart to DAEMON_MIN_RESTART_INTERVAL (20s), and only
# then does the new daemon sweep the orphans — waiting out a hung orphan's 5 s grace
# before the bind — and gossip its way back in.
CRASH_RECOVERY_TIMEOUT = 90.0 * TIMEOUT_SCALE
DB_MEMBER_DEATH_TIMEOUT = 60.0 * TIMEOUT_SCALE
DB_MEMBER_RETURN_TIMEOUT = 120.0 * TIMEOUT_SCALE
# A replica must catch up to the primary's position after every preceding scenario wrote.
REPLICA_CATCH_UP_TIMEOUT = 30.0 * TIMEOUT_SCALE
PRIMARY_POSITION_SQL = "SELECT @@GLOBAL.gtid_binlog_pos"
REPLICA_POSITION_SQL = "SELECT @@GLOBAL.gtid_slave_pos"
REPLICA_RUNNING_SQL = "SHOW GLOBAL STATUS LIKE 'Slaves_running'"
READ_ONLY_SQL = "SELECT @@GLOBAL.read_only"
REPLICATION_ERROR_MARKS = ("Error_code:", "slave SQL thread aborted", "[ERROR]")
DB_LOAD_STEADY = 3
# Each probe's worker also drains the other nodes' DB syncs. An unbounded writer can
# queue more work than that worker processes and time out its own command before the fault.
DB_LOAD_INTERVAL_SECONDS = 0.5

# The liveness spans scenario 46 gives the master holding its sockets, in milliseconds (HIL-1566):
# short enough that a silent socket is doubted and closed within the scenario, and in the order the
# handshake demands, idle below probably offline below the close (WebSocketLivenessConfig). The
# close leaves room for a silence that outlasts a stopped, killed and restarted owner and the check
# that repairs what it missed. Only that master runs on them, for as long as the scenario runs; the
# stand keeps the framework's 40/50/120/600 s (EnvCatalogStub).
LIVENESS_PING_IDLE_MS = 2000
LIVENESS_PING_INTERVAL_MS = 3000
LIVENESS_PROBABLY_OFFLINE_MS = 6000
LIVENESS_CLOSE_TIMEOUT_MS = 60000
# How long a missed liveness transition may take to be repaired once its owner is back. The socket's
# master checks its copy of the row once a ping interval, whatever the browser sends
# (WebSocketLiveness::isReconcileDue()), so one interval finds the disagreement; the second interval
# and the ten seconds are for the copy to be back on that master and for the row the owner writes to
# reach the observer.
LIVENESS_REPAIR_TIMEOUT = (2 * LIVENESS_PING_INTERVAL_MS / 1000 + 10.0) * TIMEOUT_SCALE
# How far a socket's own stamp of its last frame may run behind the master's reading of it: the
# frame is on the wire before the write that sends it returns and is stamped. Both read one host
# clock, so this covers the stamp, not a drift.
LIVENESS_CLOCK_SLACK_SEC = 0.5
# The RT collection of the browser sockets, a copy of which every process holds; mirrors
# BinanceBtcTrackerRtContext::connections (demo/binance-btc-tracker/backend/Runtime/View/Context/
# BinanceBtcTrackerRtContext.php), the stand that carries scenarios 33 and 46.
CONNECTIONS = "connections"
# The agent that owns `connections` on that stand and answers its sockets' lifecycle, a singleton of
# the leader on a monopolistic worker of its own; mirrors AgentType::BINANCE_BTC_TRACKER
# (demo/binance-btc-tracker/backend/Constants/AgentType.php), the type
# BinanceBtcTrackerSignalRouter::getDefaultWebSocketLifecycleAgentType() names.
CONNECTIONS_OWNER_AGENT_TYPE = "binance_btc_tracker"
# The outbound bytes past which a master drops a browser socket, and the line it logs as it does;
# mirror WebSocketClient::MAX_WRITE_BUFFER_BYTES and AbstractClient::enforceOutboundCap()
# (framework/backend/Socket/Client/).
BROWSER_OUTBOUND_CAP_BYTES = 8 * 1024 * 1024
OUTBOUND_CAP_LINE = (f"Outbound buffer exceeded {BROWSER_OUTBOUND_CAP_BYTES} bytes; "
                     "dropping the link to shed backpressure")


# --------------------------------------------------------------------------- io

def ctl(*args):
    """Run a controller command (kill/start/recreate/partition/heal/stranger/own-env), its answer dropped."""
    control.execute(STAND, *args)


def ctl_out(*args):
    """Run the controller for a value: its stdout stripped, or '' when it failed."""
    outcome = control.execute(STAND, *args)
    return outcome.out.strip() if outcome.code == 0 else ""


def client(node, *args):
    """Run a test-only client command on one node. True when the CLI reported success."""
    return control.client(STAND, node, *args).code == 0


def client_out(node, *args):
    """Run a test-only client command on one node for its OUTPUT: stdout stripped, or None
    when the CLI reported failure."""
    outcome = control.client(STAND, node, *args)
    return outcome.out.strip() if outcome.code == 0 else None


def client_refusal(node, *args):
    """Run a test-only client command on one node for its REFUSAL: stderr stripped when the CLI
    reported failure, or None when it succeeded. A refusal is printed on stderr and a result on
    stdout (CommandChannelClientTrait::printRefusal()), so client_out() has nothing to show here."""
    outcome = control.client(STAND, node, *args)
    return outcome.err.strip() if outcome.code != 0 else None


def db_read(node, key):
    """What one node answers it holds for a settings key: the value, or None for no row.

    The answer comes out of that node's own copy of the collection rather than out of a fresh
    query, which is the whole reason this is worth asking - see ClusterTestDbReadCommand. The
    reply line is `<key>=<value>`, with DB_PROBE_NO_ROW standing in for "this node holds none".
    """
    out = client_out(node, "test:cluster:db:read", key)
    if out is None:
        return None
    marker = f"{key}="
    for line in out.splitlines():
        if line.startswith(marker):
            value = line[len(marker):]
            return None if value == DB_PROBE_NO_ROW else value
    return None


# A node's daemon log, read from the host by node id (control.py says how and why).

def node_log_path(node):
    return control.node_log_path(STAND.node(node))


def node_log(node, offset=0):
    return control.node_log(STAND.node(node), offset)


def node_log_size(node):
    return control.node_log_size(STAND.node(node))


def node_log_mark(node):
    return control.node_log_mark(STAND.node(node))


def node_log_since(node, mark):
    return control.node_log_since(STAND.node(node), mark)


def assert_no_startup_refusal(node, mark, window):
    """A node that started after `mark` refused no start for want of a regular worker (HIL-1671).

    The mark is taken before the action that starts the node and the log is read once the cluster
    has converged after it. Convergence alone does not tell the fix from the defect: a start the
    node refused is retried by the leader five seconds later and converges all the same, and only
    this line in the node's own log tells the two apart. node_log_since() reads the fresh file of a
    node that started after the mark, so a line an earlier start left is never counted.
    """
    refusals = [line for line in node_log_since(node, mark).splitlines() if REGULAR_STARTUP_REFUSAL in line]
    assert not refusals, (f"{node} refused a start for want of a regular worker {window} "
                          f"(read {node_log_path(node)}):\n" + "\n".join(refusals[:EVIDENCE_LINES]))


def db_sql(statement, member=None):
    """Run one SQL statement in the stand's database, or on the member of a clustered one that
    `member` names by service: its rows as printed, or '' when it failed."""
    return ctl_out("db-sql", statement, *([member] if member else []))


def db_log(member):
    """One database member's container log so far, or '' when there is none."""
    return ctl_out("db-log", member)


def db_kill(member):
    """Kill one member by the service name the stand declares."""
    outcome = control.execute(STAND, "db-kill", member)
    assert outcome.code == 0, f"could not kill {member}: {outcome.err or outcome.out}"


def db_start(member):
    """Start the same member container again, retaining its data."""
    outcome = control.execute(STAND, "db-start", member)
    assert outcome.code == 0, f"could not start {member}: {outcome.err or outcome.out}"


def proxy_states():
    """HAProxy backend server name -> state, as the agent check last reported it."""
    lines = ctl_out("db-proxy").splitlines()
    return dict(line.split("\t", 1) for line in lines if "\t" in line)


def container_id(node):
    """Docker id of a node's container, or '' when there is none."""
    return ctl_out("container-id", node)


def container_log(node):
    """A node's container log so far (docker logs, not followed), or '' when there is none."""
    return ctl_out("container-log", node)


def _inspect(subcmd, node):
    out = control.execute(STAND, subcmd, node).out
    brace = out.find("{")
    if brace < 0:
        return None
    try:
        obj, _ = json.JSONDecoder().raw_decode(out[brace:])
        return obj
    except json.JSONDecodeError:
        return None


def inspect(node):
    """Return a node's test:cluster:inspect reply as a dict, or None if unreachable."""
    return _inspect("inspect", node)


def inspect_local(node):
    """Inspect a node from inside its own container (works while it is partitioned off the network)."""
    return _inspect("inspect-local", node)


# Every `nodes=None` below means the stand's ALL_NODES, looked up at the call: a default written
# as `nodes=ALL_NODES` would be read when the function is defined, before bind() names a node.

def inspect_all(nodes=None):
    return {n: inspect(n) for n in (ALL_NODES if nodes is None else nodes)}


# ------------------------------------------------------------------- verdicts

def is_leader(view):
    return bool(view) and view.get("consensusRole") == "leader" and view.get("lifecycleState") == "MasterLeader"


def leaders(views):
    """Node ids that currently claim leadership, from a {node: view} map."""
    return [n for n, v in views.items() if is_leader(v)]


def leader_placements(views):
    """Placement rows from whichever node is the leader (empty if no single leader)."""
    ls = leaders(views)
    if len(ls) != 1:
        return []
    return views[ls[0]].get("placements", []) or []


def worker_placements(views):
    """The worker fleet's placement rows on the leader, keyed by agent id."""
    prefix = WORKER_AGENT_TYPE + ":"
    return {row["agentId"]: row for row in leader_placements(views)
            if str(row.get("agentId", "")).startswith(prefix)}


def node_online(views, node):
    """Predicate: a single leader is in charge and its roster lists a node as online."""
    ls = leaders(views)
    if len(ls) != 1:
        return False
    return any(n["nodeId"] == node and n.get("online") for n in views[ls[0]].get("nodes", []))


def hosted_by(views, node):
    """Agent ids of the fleet members the leader reports started on one node."""
    return {i for i, row in worker_placements(views).items()
            if row.get("nodeId") == node and row.get("state") == "started"}


def rt_collection(views, node, key=WORKER_STATUSES):
    """One node's report about an RT collection: {} when the node is unreachable."""
    view = views.get(node)
    if not view:
        return {}
    return (view.get("rtCollections") or {}).get(key) or {}


def rt_rows(views, node, key=WORKER_STATUSES):
    """The rows one node holds of an RT collection, keyed by row id.

    The reply carries them either way round, and that is not a choice anyone made: a PHP array
    whose keys are exactly 0..n-1 IN ORDER serializes as a JSON array rather than an object,
    and these row ids are fleet indices - so the same collection comes back as a map or as a
    list depending on the order the node happened to receive its members in. The list form
    carries each id in its own index, so it is turned back into the map every caller reads.
    """
    rows = rt_collection(views, node, key).get("rows") or {}
    if isinstance(rows, list):
        return {str(index): row for index, row in enumerate(rows)}

    return rows


def rt_stale_rows(views, node, key=WORKER_STATUSES):
    """The rows one node reports frozen, keyed by row id, with the moment each froze.

    Turned back into a map for the reason rt_rows() is: an object keyed 0..n-1 in order comes
    off PHP as a JSON array, and these row ids are fleet indices.
    """
    rows = rt_collection(views, node, key).get("staleRows") or {}
    if isinstance(rows, list):
        return {str(index): row for index, row in enumerate(rows) if row is not None}

    return {str(rid): row for rid, row in rows.items()}


def rt_refused(views, node):
    """Remote RT frames a node refused as a two-owner split, or -1 when unreachable."""
    view = views.get(node)
    return -1 if view is None else int(view.get("rtRefused", 0))


def rt_read_by(views, node, key=WORKER_STATUSES):
    """Whether a worker of one node reads an RT collection, as that node reports it."""
    return bool(rt_collection(views, node, key).get("read"))


def reading_nodes(views, nodes=None, key=WORKER_STATUSES):
    """The nodes that say a worker of theirs reads an RT collection."""
    return [n for n in (ALL_NODES if nodes is None else nodes) if rt_read_by(views, n, key)]


def fleet_workers_on(node, members):
    """Fleet members last reported on each worker of one node."""
    latest = {}
    for agent_id, worker_index in AGENT_STARTED_ON_WORKER.findall(node_log(node)):
        if agent_id in members:
            latest[agent_id] = int(worker_index)

    by_worker = {}
    for agent_id, worker_index in latest.items():
        by_worker.setdefault(worker_index, set()).add(agent_id)
    return by_worker


def worker_death_evidence(node, offset, worker_index):
    """Log lines after `offset` about one worker's process, link, and agents.

    Printed when the node never reports the worker's lost agents: the next run starts
    on a fresh stand, so its node log would otherwise disappear (HIL-1162).
    """
    about_worker = re.compile(rf"\b[Ww]orker #{worker_index}\b")
    return [line for line in node_log(node, offset).splitlines()
            if about_worker.search(line) or "Error in client" in line or "Suppressed " in line]


def newest_row_updates(views):
    """Newest update time reported for every runtime row across the nodes reading it."""
    newest = {}
    for node in reading_nodes(views, nodes=views):
        for row_id, row in rt_rows(views, node).items():
            newest[row_id] = max(newest.get(row_id, 0), row.get("updatedAt", 0))
    return newest


def assert_table_names_running_nodes(views):
    """Every node named by the placement table holds the live fleet's full runtime view."""
    for node in sorted({row["nodeId"] for row in worker_placements(views).values()}):
        assert rt_read_by(views, node), \
            f"the leader places fleet members on {node}, which reports reading no '{WORKER_STATUSES}' at all"
        held = len(rt_rows(views, node))
        assert held == WORKER_FLEET_SIZE, \
            (f"{node} hosts fleet members by the leader's table but holds {held} of "
             f"{WORKER_FLEET_SIZE} rows: the table is naming a node that runs nothing")


def fleet_rows_where_read(views, nodes=None):
    """Predicate: the nodes reading the collection hold every fleet member's row, and the
    nodes reading none hold nothing (HIL-717).

    Both halves, because together they ARE the addressing. A frame about a collection is
    delivered only to the nodes that said they read it - the deltas and the hand-over
    alike - so a node running nothing that reads holds no copy at all, and a node hosting
    fleet members holds the whole of it. The old form of this predicate asked every node
    for all ten rows, which was the right question while every frame went everywhere.

    Nobody reading it is not a pass. The fleet is what reads this collection, and a run
    where no node claims to read it is one where the fleet is not up.
    """
    nodes = ALL_NODES if nodes is None else nodes
    readers = reading_nodes(views, nodes)
    if not readers:
        return False

    return (all(len(rt_rows(views, n)) == WORKER_FLEET_SIZE for n in readers)
            and all(len(rt_rows(views, n)) == 0 for n in nodes if n not in readers))


def rt_claim_conflicts(views, node):
    """RT ownership clashes a node has named while leading, or -1 when unreachable."""
    view = views.get(node)
    return -1 if view is None else int(view.get("rtClaimConflicts", 0))


def rt_claim_refusals(views, node):
    """Claims of a node's own agents that the leader refused, or -1 when unreachable."""
    view = views.get(node)
    return -1 if view is None else int(view.get("rtClaimRefusals", 0))


def placement_row(views, agent_id):
    """The leader's placement row for one agent id, or {} when it tracks none."""
    for row in leader_placements(views):
        if row.get("agentId") == agent_id:
            return row
    return {}


def placement_refusal(views, agent_id):
    """The leader's last failed attempt to place one agent id, or None when it keeps none.

    The leader's diagnosis rather than a record (HIL-447): a new agent that no node takes gets no
    placement row at all, so this is the one place its refusal shows up. Empty on every other node.

    A refusal's empty `shortfalls` comes back as an empty list, and is turned back into the map it
    is. The daemon writes an object there, as the peer wire does, but the reply reaches us printed
    by the inspect CLI, which decoded it into PHP arrays first - and an empty PHP array prints as
    a list.
    """
    ls = leaders(views)
    if len(ls) != 1:
        return None
    for snapshot in views[ls[0]].get("placementRefusals") or []:
        if snapshot.get("agentId") == agent_id:
            refusals = [dict(refusal, shortfalls=refusal.get("shortfalls") or {})
                        for refusal in snapshot.get("refusals") or []]
            return dict(snapshot, refusals=refusals)
    return None


def node_refusal(node, reason_code, missing=(), shortfalls=None):
    """One node's refusal the way the leader's reply writes it; mirrors PlacementRefusal::toArray()
    (framework/backend/Cluster/Placement/PlacementRefusal.php). `shortfalls` maps a resource to the
    agent's cost of it and what the node has free."""
    return {
        "nodeId": node,
        "reasonCode": reason_code,
        "missingCapabilities": list(missing),
        "shortfalls": {resource: {"cost": cost, "free": free}
                       for resource, (cost, free) in (shortfalls or {}).items()},
    }


def live_on(views, node):
    """How many placements the leader holds live on one node: one slot taken each."""
    return sum(1 for row in leader_placements(views)
               if row.get("nodeId") == node and row.get("state") in LIVE_PLACEMENT_STATES)


def declared_capabilities(views, node):
    """The capability tags the leader's roster lists for one node, empty without a single leader."""
    ls = leaders(views)
    if len(ls) != 1:
        return []
    return next((row.get("capabilities") or [] for row in views[ls[0]].get("nodes", [])
                 if row.get("nodeId") == node), [])


def client_deliveries(views, node):
    """Cross-node client deliveries a node reports having accepted, or -1 when unreachable."""
    view = views.get(node)
    return -1 if view is None else int(view.get("clientDeliveries", 0))


def client_test_deliveries(views, node):
    """The existing CLI probe only; background fan-outs cannot satisfy its assertions."""
    view = views.get(node)
    return -1 if view is None else int(view["clientTestDeliveries"])


def admin_view_mode(views, node):
    """Whether a node reports serving admin view mode, or None while it is unreachable."""
    view = views.get(node)
    return None if view is None else view.get("adminViewMode")


def db_replicas(views, node, collection=None):
    """Accepted DB facts, optionally for one collection, or -1 when unreachable."""
    view = views.get(node)
    if view is None:
        return -1
    if collection is not None:
        return int((view["dbReplicasByCollection"] or {}).get(collection, 0))
    return int(view.get("dbReplicas", 0))


def db_collections_read(views, node):
    """The database collections one node reports its workers read, empty when unreachable.

    A flat list rather than a per-collection row, unlike the runtime side: the rows of a database
    collection are in the shared database rather than in a replica of this node's, so there is
    nothing per collection to report beside the fact that somebody here reads it - which is
    exactly what decides whether a fact about it is worth a hop.
    """
    view = views.get(node)
    return list((view or {}).get("dbCollectionsRead") or [])


def indexed_for(views, watcher, holder):
    """How many browser connections one node's index holds for another node."""
    view = views.get(watcher)
    return 0 if view is None else int((view.get("clientIndex") or {}).get(holder, 0))


def fleet_started(views):
    """Predicate: every fleet member is placed and started somewhere."""
    rows = worker_placements(views)
    return len(rows) == WORKER_FLEET_SIZE and all(r.get("state") == "started" for r in rows.values())


# ------------------------------------------------------------------- polling

class ScenarioTimeout(AssertionError):
    """A convergence poll hit its cap. A subclass of AssertionError so existing
    handlers still catch it, but distinct so the runner can retry a pure timeout
    (transient, env-driven) while failing hard invariant assertions immediately."""


class ScenarioPreconditionLost(ScenarioTimeout):
    """The stand moved away from the shape a scenario arranged before the scenario could act on
    it - a re-election of the stand's own, its links flapping once as late seed dials land
    (P-459), moved the work the scenario had laid out. That says nothing about the behaviour under
    test, so it is retried like a timeout and printed with the retries rather than failed."""


def wait_until(predicate, timeout, desc, nodes=None, local=False):
    """Poll inspect(nodes) until predicate(views) is truthy; return the final views.

    `local` asks each node from inside its own container instead of over the network, which is
    the only way to ask one that is partitioned off it - and a partitioned node is exactly where
    some answers only become true after a delay (a link takes a keepalive to be noticed dead).
    """
    nodes = ALL_NODES if nodes is None else nodes
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        views = {n: inspect_local(n) for n in nodes} if local else inspect_all(nodes)
        last = views
        if predicate(views):
            return views
        time.sleep(POLL_INTERVAL)
    raise ScenarioTimeout(f"timed out after {timeout:.0f}s waiting for: {desc}\n"
                          f"last view: {summarize(last)}")


def wait_db_value(node, key, expected, timeout, desc):
    """Poll one node's copy of a settings row until it answers `expected`.

    The value twin of wait_until, and it raises the same ScenarioTimeout so that a pure
    convergence timeout stays retryable while a wrong value fails hard.
    """
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        last = db_read(node, key)
        if last == expected:
            return
        time.sleep(POLL_INTERVAL)
    raise ScenarioTimeout(f"timed out after {timeout:.0f}s waiting for: {desc}\n"
                          f"last value {node} answered for {key}: {last!r}")


def converged(expected_nodes):
    """Predicate: exactly one leader, every expected node is online in its roster, and none of that
    leader's placements is still placing or failed.

    The placements read are the leader's own: a follower's hosted set or a retained snapshot says
    what one node holds or what an earlier term handed over, not whether the leader's starts have
    landed. A placing or failed row is work that runs nowhere yet, and a cluster carrying one has
    not recovered (HIL-1671); an unplaced row may stay, as the wait for resources it is (HIL-446).
    A step that means to watch a placing or failed row waits on a predicate of its own.
    """
    expected = set(expected_nodes)

    def check(views):
        ls = leaders(views)
        if len(ls) != 1:
            return False
        roster = views[ls[0]].get("nodes", [])
        online = {n["nodeId"] for n in roster if n.get("online")}
        return expected.issubset(online) and not any(
            row.get("state") in UNSETTLED_PLACEMENT_STATES for row in leader_placements(views))

    return check


def wait_converge(expected_nodes=None, timeout=CONVERGE_TIMEOUT):
    expected_nodes = ALL_NODES if expected_nodes is None else expected_nodes
    return wait_until(converged(expected_nodes), timeout,
                      f"single leader + online roster {sorted(expected_nodes)}"
                      f" + no {'/'.join(UNSETTLED_PLACEMENT_STATES)} placement on the leader")


def summarize(views):
    if not views:
        return "(none)"
    parts = []
    for n, v in views.items():
        if not v:
            parts.append(f"{n}=down")
            continue
        p = ",".join(f"{r['agentId']}@{r['nodeId']}:{r['state']}" for r in v.get("placements", []))
        parts.append(f"{n}[{v.get('lifecycleState')},leader={v.get('leaderId')},"
                     f"quorum={v.get('hasQuorum')}{(',pl=' + p) if p else ''}]")
    return " ".join(parts)


def wait_fleet_rows():
    """Wait until every node holds a status row for every fleet member.

    Rows rather than the leader's placement table, and that distinction outlives the defect
    that taught it: a row is written by a live member, so waiting for rows waits for the fleet
    itself, which is what the RT scenarios are about, while the table says only where the
    leader has written an agent down. The table used to be able to lie outright - a data-plane
    container recreated faster than the failover grace came back WITHOUT its agents and nothing
    re-placed them (P-152) - and since HIL-719 it cannot: the returning node reports the empty
    set, the fleet is put back to work, and the table stops naming a node that runs none of it,
    which scenario 16 holds it to.

    That defect is also why the RT scenarios run where they do (see SCENARIOS), and their order
    is kept now that it no longer has to be: once the fleet was dead the collection had no owner
    at all and nothing could repair it, because the nodes still holding a copy may not hand it
    over - passing on somebody else's rows is precisely what makes a second source of them.
    """
    try:
        return wait_until(fleet_rows_where_read, CONVERGE_TIMEOUT,
                          "every node reading the collection holds a row for every fleet member")
    except ScenarioTimeout as timeout:
        # The topology summary a timeout prints says nothing about RT, and the question here is
        # always the same one: which node is short of rows, does the node writing them know it
        # owns them, and - since HIL-717 - does the node short of them ask for them at all. All
        # three come from the same reply, so the answer costs one more poll.
        views = inspect_all()
        held = {node: len(rt_rows(views, node)) for node in ALL_NODES}
        owned = {node: rt_collection(views, node).get("owned") for node in ALL_NODES}
        read = {node: rt_read_by(views, node) for node in ALL_NODES}
        raise ScenarioTimeout(f"{timeout}\nrows per node: {held}\nowns the collection: {owned}"
                              f"\nreads the collection: {read}") from timeout


_NUMBER_WORDS = ("zero", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten")


def in_words(count):
    """A small count as a word, the way a scenario's report names how many nodes it saw."""
    return _NUMBER_WORDS[count] if 0 <= count < len(_NUMBER_WORDS) else str(count)


# ------------------------------------------------------------------ scenarios

def scenario_1_master_slave_mesh():
    views = wait_converge(ALL_NODES)
    ls = leaders(views)
    assert len(ls) == 1, f"expected exactly one leader, got {ls}"
    for s in SLAVES:
        assert views[s]["lifecycleState"] == "Slave", f"{s} not in Slave phase: {views[s]['lifecycleState']}"
        assert not is_leader(views[s]), f"slave {s} claims leadership"
    return f"leader={ls[0]}, slaves follow"


def scenario_2_master_master():
    # Converge first (like the other scenarios) so every node view is present: a
    # transiently-unreachable node under load returns None, and reading its phase
    # would crash the scenario instead of retrying as a timeout (HIL-367).
    views = wait_converge(ALL_NODES)
    master_leaders = [m for m in MASTERS if is_leader(views.get(m))]
    assert len(master_leaders) == 1, f"expected one leader among masters, got {master_leaders}"
    for m in MASTERS:
        if m == master_leaders[0]:
            continue
        assert views[m]["lifecycleState"] == "MasterFollowerOrCandidate", \
            f"non-leader master {m} phase {views[m]['lifecycleState']}"
    for s in SLAVES:
        assert views[s].get("term") is None, f"slave {s} has a consensus term {views[s].get('term')}"
    return f"one leader among masters ({master_leaders[0]}), slaves never lead"


def scenario_3_placement():
    views = wait_until(fleet_started, CONVERGE_TIMEOUT,
                       f"all {WORKER_FLEET_SIZE} worker agents placed and started")
    rows = worker_placements(views)
    stray = {row["nodeId"] for row in rows.values()} - set(SLAVES)
    assert not stray, f"worker fleet placed on non-slave nodes {sorted(stray)}"
    spread = ", ".join(f"{s}={len(hosted_by(views, s))}" for s in SLAVES)
    return f"{len(rows)} workers placed and started on the data plane ({spread})"


def scenario_4_slave_kill_failover():
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before failover")
    host = max(SLAVES, key=lambda s: len(hosted_by(views, s)))
    other = next(s for s in SLAVES if s != host)
    moving = len(hosted_by(views, host))
    print(f"    killing worker host slave {host} carrying {moving} agent(s); "
          f"expecting re-placement onto {other}")
    ctl("kill", host)
    try:
        # The survivor is the only capable node left, so the whole fleet must land on it.
        wait_until(lambda v: len(hosted_by(v, other)) == WORKER_FLEET_SIZE,
                   FAILOVER_TIMEOUT, f"the whole fleet re-placed onto {other}")
        return f"{moving} worker(s) failed over {host} -> {other}; all {WORKER_FLEET_SIZE} started there"
    finally:
        ctl("start", host)
        wait_converge(ALL_NODES)


def scenario_5_leader_kill_reelection():
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before the leader dies")
    carriers = sorted({row["nodeId"] for row in worker_placements(views).values()} & set(SLAVES))
    old_leader = leaders(views)[0]
    surviving_masters = [n for n in MASTERS if n != old_leader]
    offsets = {n: node_log_size(n) for n in surviving_masters}
    print(f"    killing leader {old_leader}; expecting a new leader among the survivors")
    ctl("kill", old_leader)
    survivors = [n for n in ALL_NODES if n != old_leader]
    try:
        def new_leader_elected(v):
            ls = [n for n in MASTERS if n != old_leader and is_leader(v.get(n))]
            return len(ls) == 1 and v[ls[0]].get("hasQuorum") is True
        views = wait_until(new_leader_elected, ELECTION_TIMEOUT,
                           "a new leader with quorum", nodes=survivors)
        new_leader = [n for n in MASTERS if n != old_leader and is_leader(views.get(n))][0]
        assert_reelection_logged(new_leader, views[new_leader]["term"], surviving_masters, offsets)

        def carriers_saw_old_leader_offline(v):
            for carrier in carriers:
                old = next((node for node in (v.get(carrier) or {}).get("nodes", [])
                            if node.get("nodeId") == old_leader), None)
                if old is None or old.get("online") is not False:
                    return False
            return True

        wait_until(carriers_saw_old_leader_offline, CONVERGE_TIMEOUT,
                   f"every fleet carrier sees {old_leader} offline", nodes=carriers)
        seen_at = time.time()
        fenced_by = int(seen_at + SLAVE_WORK_GRACE_SEC)

        def fleet_wrote_past_fence(v):
            updates = newest_row_updates(v)
            return all(updates.get(str(index), 0) > fenced_by for index in range(WORKER_FLEET_SIZE))

        try:
            views = wait_until(
                fleet_wrote_past_fence,
                SLAVE_WORK_GRACE_SEC + 3 * WORKER_REPORT_INTERVAL_SEC * TIMEOUT_SCALE,
                "the inherited fleet writes past its slaves' fence window",
                nodes=survivors,
            )
        except ScenarioTimeout as error:
            updates = newest_row_updates(inspect_all(survivors))
            silent = [f"{WORKER_AGENT_TYPE}:{index}" for index in range(WORKER_FLEET_SIZE)
                      if updates.get(str(index), 0) <= fenced_by]
            raise AssertionError(
                f"fleet member(s) {silent} stopped writing after {new_leader} took them over: "
                "fenced by its slave after the new leader took it over"
            ) from error

        assert fleet_started(views), "the inherited fleet no longer has every member started"
        assert_table_names_running_nodes(views)
        return (f"re-elected {new_leader} after {old_leader} died; "
                f"its log carries the candidacy, the term and the winning vote, and the fleet "
                f"it inherited kept running past its slaves' fence window")
    finally:
        ctl("start", old_leader)
        wait_converge(ALL_NODES)


def assert_reelection_logged(new_leader, term, surviving_masters, offsets):
    """The survivors' own daemon logs tell the re-election, with no inspect call (HIL-442).

    The lines are on disk by the time the harness sees the new leader: the daemon appends each
    one at the transition itself with no buffer, and the harness only learns of the leader by
    polling afterwards. The voter is every other surviving master - with one master dead, the
    winner's majority cannot be reached without it."""
    tail = node_log(new_leader, offsets[new_leader])
    where = node_log_path(new_leader)
    assert tail, f"{new_leader}'s daemon log gained nothing since the kill (read {where})"
    assert "Consensus: becoming candidate in term " in tail, \
        f"{new_leader}'s log has no candidacy line since the kill (read {where})"
    won = [line for line in tail.splitlines() if "Consensus: won term " in line]
    assert won, f"{new_leader}'s log has no won-term line since the kill (read {where})"
    assert f"Consensus: won term {term} with " in won[-1], \
        f"{new_leader} leads term {term}, but its last won-term line reads: {won[-1]}"
    for voter in surviving_masters:
        if voter == new_leader:
            continue
        voter_tail = node_log(voter, offsets[voter])
        assert f"Consensus: granted the vote to node '{new_leader}'" in voter_tail, \
            f"{voter}'s log has no vote granted to {new_leader} since the kill (read {node_log_path(voter)})"


def scenario_6_hot_join():
    wait_converge(ALL_NODES)
    joiner = SLAVES[1]
    print(f"    taking {joiner} down, then hot-joining it back")
    ctl("kill", joiner)
    wait_until(lambda v: not node_online(v, joiner),
               CONVERGE_TIMEOUT, f"{joiner} seen offline in the roster",
               nodes=[n for n in ALL_NODES if n != joiner])
    ctl("start", joiner)
    wait_until(converged(ALL_NODES), CONVERGE_TIMEOUT,
               f"{joiner} re-admitted; full roster of {len(ALL_NODES)}")
    return f"{joiner} hot-joined; gossip shows the full roster"


def scenario_7_quorum_loss():
    wait_converge(ALL_NODES)
    # Leave the first master alone as the isolated minority (1 of 3 < quorum 2).
    lone = MASTERS[0]
    victims = [MASTERS[1], MASTERS[2]]
    print(f"    killing masters {victims}; the lone survivor {lone} must stop leading")
    for v in victims:
        ctl("kill", v)
    try:
        def minority_no_quorum(views):
            survivor = views.get(lone)
            if not survivor or survivor.get("hasQuorum") is not False:
                return False
            # No node anywhere may still claim leadership.
            return len(leaders(inspect_all(ALL_NODES))) == 0
        wait_until(lambda v: minority_no_quorum(v), QUORUM_TIMEOUT,
                   f"{lone} without quorum and no leader cluster-wide", nodes=[lone, SLAVES[0], SLAVES[1]])
        return f"minority ({lone}) lost quorum and stopped leading; no new leader"
    finally:
        for v in victims:
            ctl("start", v)
        wait_converge(ALL_NODES)


def scenario_8_split_brain():
    views = wait_converge(ALL_NODES)
    isolated = MASTERS[2]
    print(f"    partitioning {isolated} off the network (1 | 2 split of the master set)")
    ctl("partition", isolated)
    try:
        def split_ok(v):
            majority = {n: v.get(n) for n in [MASTERS[0], MASTERS[1]]}
            maj_leaders = [n for n, view in majority.items() if is_leader(view)]
            if len(maj_leaders) != 1:
                return False
            if v[maj_leaders[0]].get("hasQuorum") is not True:
                return False
            # The isolated master is off the network now, so inspect it from inside its own
            # container.
            minority = inspect_local(isolated)
            if minority is None:
                return False
            return minority.get("hasQuorum") is False and not is_leader(minority)
        wait_until(split_ok, CONVERGE_TIMEOUT,
                   f"majority {{{MASTERS[0]},{MASTERS[1]}}} keeps one leader; minority {isolated} steps down",
                   nodes=[MASTERS[0], MASTERS[1], SLAVES[0], SLAVES[1]])
        return f"majority kept a single leader; isolated {isolated} has no quorum and does not lead"
    finally:
        # Rejoin the isolated node as a fresh container rather than a raw `docker network
        # connect`: reconnecting the interface leaves half-open TCP sockets on both sides
        # (no RST), which is not how a real partition heals — a recovering node comes back
        # clean. A recreate gives the isolated master a fresh socket stack, and the survivors
        # reset their stale links to it on the next write and re-dial, so the mesh reconverges.
        # This is the one place that asks for a pristine container, hence `recreate` and
        # not `start` — `start` deliberately reuses the container (see scenario 9).
        ctl("recreate", isolated)
        wait_converge(ALL_NODES)


def scenario_44_waiting_work_survives_leadership_changes():
    """Delivered waiting intent survives two rebuilds without another ask (HIL-446).

    Fill both slaves' 16 slots with real fleet members, beyond the supervisor's 0..9.
    Before killing the leader, require every surviving master to hold the complete snapshot
    containing the first Unplaced extra. Its new leader must rebuild that queue, retain it
    while alone without quorum, and recover it after the masters return. Only then restore
    the first slave's stock of 64 slots: the target must start and write without a new ask.
    Every slave recreated on the way logs no start refused for want of a regular worker
    (HIL-1671). Finally restart the entire stand without wiping volumes, removing all extra
    intent and environment overrides even when an assertion fails.
    """
    views = wait_converge(ALL_NODES)
    original = {slave: declared_capabilities(views, slave) for slave in SLAVES}
    for slave in SLAVES:
        expected = {CAPABILITY_WORKER, f"{RESOURCE_RAM}={SLAVE_RAM[slave]}", f"{RESOURCE_SLOTS}=64"}
        assert set(original[slave]) == expected, f"{slave} must start on its stock capabilities: {original[slave]}"
    extras = []
    target = None
    marks = {}

    def ordinary_started(v):
        return all(placement_row(v, f"{WORKER_AGENT_TYPE}:{index}").get("state") == "started"
                   for index in range(WORKER_FLEET_SIZE))

    def runs(v, agent_id):
        row = placement_row(v, agent_id)
        return (row.get("state") == "started" and row.get("nodeId") in SLAVES
                and agent_id.split(":", 1)[1] in rt_rows(v, row.get("nodeId")))

    def ceiling(v, expanded=False):
        held = {slave: live_on(v, slave) for slave in SLAVES}
        limits = {slave: 64 if expanded and slave == SLAVES[0] else COSTLESS_SLOTS for slave in SLAVES}
        assert all(held[slave] <= limits[slave] for slave in SLAVES), \
            f"live placements exceed declared slots: held={held}, limits={limits}"
        assert all(live_on(v, master) == 0 for master in MASTERS), "a master with no capacity hosts live work"
        return held

    def refusals_for(masters):
        return [node_refusal(node, REFUSAL_MISSING_CAPABILITIES, missing=[CAPABILITY_WORKER]) if node in MASTERS
                else node_refusal(node, REFUSAL_INSUFFICIENT_CAPACITY, shortfalls={RESOURCE_SLOTS: (1, 0)})
                for node in sorted(list(masters) + SLAVES)]

    def waiting_record(row, expected):
        # Snapshot records have agentType/agentIndex, whereas working placements also carry agentId.
        refusals = [dict(refusal, shortfalls=refusal.get("shortfalls") or {})
                    for refusal in row.get("refusals") or []]
        return (row.get("state") == "unplaced" and row.get("nodeId") is None
                and row.get("reason") == "no capable and online node fits the agent" and refusals == expected)

    def snapshot_waits(snapshot, expected):
        rows = [row for row in snapshot.get("records", [])
                if row.get("agentType") == WORKER_AGENT_TYPE
                and str(row.get("agentIndex")) == target.split(":", 1)[1]]
        assert len(rows) <= 1, f"{target} duplicated in complete snapshot: {snapshot}"
        return len(rows) == 1 and waiting_record(rows[0], expected)

    def queue_ready(v, masters, after_term):
        ls = leaders(v)
        if len(ls) != 1:
            return False
        leader = ls[0]
        view = v[leader]
        snapshot = view.get("placementSnapshot") or {}
        # While rebuilding, inspect serves the retained donor, stamped by its older leader/term.
        if not (view.get("hasQuorum") is True and view.get("term", -1) > after_term
                and snapshot.get("leaderNodeId") == leader and snapshot.get("term") == view.get("term")):
            return False
        ceiling(v)
        rows = [row for row in leader_placements(v) if row.get("agentId") == target]
        assert len(rows) <= 1, f"{target} duplicated in the rebuilt queue: {rows}"
        if rows:
            assert rows[0].get("state") == "unplaced" and rows[0].get("nodeId") is None, \
                f"{target} stopped waiting before any capacity was restored: {rows}"
        expected = refusals_for(masters)
        refusal = placement_refusal(v, target)
        return (len(rows) == 1 and waiting_record(rows[0], expected) and snapshot_waits(snapshot, expected)
                and refusal is not None and refusal.get("reason") == rows[0].get("reason")
                and refusal.get("refusals") == expected
                and ordinary_started(v) and all(runs(v, agent_id) for agent_id in extras))

    try:
        recreated = {}
        for slave in SLAVES:
            declared = [CAPABILITY_WORKER, f"{RESOURCE_RAM}={SLAVE_RAM[slave]}", f"{RESOURCE_SLOTS}={COSTLESS_SLOTS}"]
            assignment = f"CLUSTER_NODE_CAPABILITIES={','.join(declared)}"
            print(f"    recreating {slave} with {assignment}")
            recreated[slave] = node_log_mark(slave)
            outcome = control.execute(STAND, "own-env", slave, assignment)
            assert outcome.code == 0, f"could not limit {slave}'s slots: {outcome.err or outcome.out}"
            wait_until(lambda v, slave=slave, declared=declared: converged(ALL_NODES)(v)
                       and set(declared_capabilities(v, slave)) == set(declared),
                       CONVERGE_TIMEOUT, f"{slave} online with its tag, original ram and {COSTLESS_SLOTS} slots")

        steady = {"rows": None, "since": 0.0}

        def settled(v):
            rows = {row["agentId"]: (row.get("nodeId"), row.get("state")) for row in leader_placements(v)
                    if row.get("state") in LIVE_PLACEMENT_STATES}
            ceiling(v)
            if rows != steady["rows"]:
                steady["rows"], steady["since"] = rows, time.time()
                return False
            return (ordinary_started(v) and all(state == "started" for _node, state in rows.values())
                    and time.time() - steady["since"] >= POLICY_PLACEMENT_RETRY_SEC * 2)

        views = wait_until(settled, CONVERGE_TIMEOUT, "ordinary fleet and stable live placements at 16 slots")
        for slave, mark in recreated.items():
            assert_no_startup_refusal(slave, mark, f"after its recreation with {COSTLESS_SLOTS} slots")
        old_leader = leaders(views)[0]
        marks = {master: node_log_mark(master) for master in MASTERS}
        for slave in SLAVES:
            assert set(declared_capabilities(views, slave)) == {
                CAPABILITY_WORKER, f"{RESOURCE_RAM}={SLAVE_RAM[slave]}", f"{RESOURCE_SLOTS}={COSTLESS_SLOTS}"}, \
                f"{slave} lost its temporary capabilities"

        for index in WAITING_FLEET_INDEXES:
            agent_id = f"{WORKER_AGENT_TYPE}:{index}"
            assert client(old_leader, "test:cluster:agent:place", WORKER_AGENT_TYPE, str(index)), \
                f"could not ask {old_leader} to place {agent_id}"

            def answered(v, agent_id=agent_id):
                ceiling(v)
                return runs(v, agent_id) or placement_row(v, agent_id).get("state") == "unplaced"

            views = wait_until(answered, CONVERGE_TIMEOUT, f"{agent_id} really runs or waits Unplaced/null")
            if runs(views, agent_id):
                extras.append(agent_id)
                continue
            target = agent_id
            break
        assert target is not None, f"all {len(WAITING_FLEET_INDEXES)} extras fit; the slot ceiling capped nothing"
        assert extras, "the very first extra waited, so no extra live instance proved the ceiling"
        assert ceiling(views) == {slave: COSTLESS_SLOTS for slave in SLAVES}, "waiting despite free slots"
        assert queue_ready(views, MASTERS, -1), f"{target} has no unique Unplaced/null record and exact diagnosis"
        original_snapshot = views[old_leader]["placementSnapshot"]
        old_term, floor = original_snapshot["term"], original_snapshot["revision"]
        surviving_masters = [master for master in MASTERS if master != old_leader]

        def delivered(v):
            if leaders(v) != [old_leader] or not queue_ready(v, MASTERS, -1):
                return False
            for master in surviving_masters:
                view = v.get(master) or {}
                snapshot = view.get("placementSnapshot") or {}
                if not (view.get("leaderId") == old_leader and view.get("term") == old_term
                        and snapshot.get("leaderNodeId") == old_leader and snapshot.get("term") == old_term
                        and snapshot.get("revision", -1) >= floor
                        and snapshot_waits(snapshot, refusals_for(MASTERS))):
                    return False
            return True

        views = wait_until(delivered, CONVERGE_TIMEOUT, f"{target} delivered in a complete snapshot to EVERY surviving master")
        versions = {master: {key: views[master]["placementSnapshot"][key]
                             for key in ("leaderNodeId", "term", "revision")} for master in surviving_masters}
        print(f"    {target} replicated before killing {old_leader}: {versions}")
        outcome = control.execute(STAND, "kill", old_leader)
        assert outcome.code == 0, f"could not kill {old_leader}: {outcome.err or outcome.out}"
        survivors = [node for node in ALL_NODES if node != old_leader]
        views = wait_until(lambda v: queue_ready(v, surviving_masters, old_term),
                           CONVERGE_TIMEOUT, f"new leader rebuilt {target} without another ask", nodes=survivors)
        new_leader = leaders(views)[0]
        inherited = views[new_leader]["placementSnapshot"]
        assert "Placement rebuild settled:" in node_log_since(new_leader, marks[new_leader]), \
            f"{new_leader} never settled its rebuild"
        assert "Placement queue recovered:" in node_log_since(new_leader, marks[new_leader]), \
            f"{new_leader} never logged recovering the waiting queue"
        print(f"    {new_leader} rebuilt {target} in term {inherited['term']}; taking away its quorum")
        other_master = next(master for master in surviving_masters if master != new_leader)
        outcome = control.execute(STAND, "kill", other_master)
        assert outcome.code == 0, f"could not kill {other_master}: {outcome.err or outcome.out}"
        lone_nodes = [new_leader] + SLAVES

        def retained_without_quorum(v):
            view = v.get(new_leader) or {}
            snapshot = view.get("placementSnapshot") or {}
            return (view.get("hasQuorum") is False and not leaders(v) and view.get("leaderId") is None
                    and snapshot.get("leaderNodeId") == new_leader and snapshot.get("term") == inherited["term"]
                    and snapshot.get("revision", -1) >= inherited["revision"]
                    and snapshot_waits(snapshot, refusals_for(surviving_masters)))

        wait_until(retained_without_quorum, QUORUM_TIMEOUT,
                   f"{new_leader} has no quorum/leader and retains {target}'s complete snapshot", nodes=lone_nodes)
        for master in MASTERS:
            if master != new_leader:
                outcome = control.execute(STAND, "start", master)
                assert outcome.code == 0, f"could not return {master}: {outcome.err or outcome.out}"
        wait_converge(ALL_NODES)
        views = wait_until(lambda v: queue_ready(v, MASTERS, inherited["term"]),
                           CONVERGE_TIMEOUT, f"all masters returned and {target}'s queue rebuilt again without an ask")
        recovered_leader = leaders(views)[0]
        recovered_term = views[recovered_leader]["placementSnapshot"]["term"]
        print(f"    {recovered_leader} recovered {target} in term {recovered_term}; restoring {SLAVES[0]}'s slots")
        restored_at = node_log_mark(SLAVES[0])
        outcome = control.execute(STAND, "own-env", SLAVES[0],
                                  f"CLUSTER_NODE_CAPABILITIES={','.join(original[SLAVES[0]])}")
        assert outcome.code == 0, f"could not restore {SLAVES[0]}'s stock: {outcome.err or outcome.out}"

        def resumed(v):
            ceiling(v, expanded=True)
            return (converged(ALL_NODES)(v) and set(declared_capabilities(v, SLAVES[0])) == set(original[SLAVES[0]])
                    and ordinary_started(v) and all(runs(v, agent_id) for agent_id in extras + [target]))

        views = wait_until(resumed, CONVERGE_TIMEOUT, f"{target} and all other fleet members really run without new asks")
        target_rows = [row for row in leader_placements(views) if row.get("agentId") == target]
        assert len(target_rows) == 1, f"{target} must resume exactly once: {target_rows}"
        host = target_rows[0]["nodeId"]
        assert host == SLAVES[0], f"{target} started on {host}, although only {SLAVES[0]} gained room"
        assert placement_refusal(views, target) is None, f"{target} kept a refusal after starting"
        index = target.split(":", 1)[1]
        before = rt_rows(views, host)[index]

        def writes(v):
            if not resumed(v) or placement_row(v, target).get("nodeId") != host:
                return False
            row = rt_rows(v, host).get(index) or {}
            return row.get("updatedAt", 0) > before["updatedAt"] and row.get("jobsDone", 0) > before["jobsDone"]

        views = wait_until(writes, CONVERGE_TIMEOUT, f"the unique resumed {target} advances its host's live RT row")
        assert_no_startup_refusal(SLAVES[0], restored_at, "after its stock of 64 slots was restored")
        held = ceiling(views, expanded=True)
    finally:
        if target is not None:
            for master, mark in marks.items():
                tail = node_log_since(master, mark)
                evidence = [line for line in tail.splitlines()
                            if target in line or "Placement queue recovered:" in line or "Placement rebuild" in line]
                print(f"    {master} inheritance evidence:\n" + "\n".join(evidence))
        print("    restarting the stand on its own compose file without deleting volumes")
        code = control.restart(STAND, f"{sys.argv[0]} {STAND.compose}")
        assert code == 0, f"the stand did not come back up after scenario 44 (exit {code})"
        wait_converge(ALL_NODES)

        def restored(v):
            ls = leaders(v)
            if len(ls) != 1:
                return False
            snapshot = v[ls[0]].get("placementSnapshot") or {}
            return (fleet_started(v) and fleet_rows_where_read(v)
                    and snapshot.get("leaderNodeId") == ls[0] and snapshot.get("term") == v[ls[0]].get("term")
                    and all(set(declared_capabilities(v, slave)) == set(original[slave]) for slave in SLAVES)
                    and not any(row.get("agentType") == WORKER_AGENT_TYPE
                                and str(row.get("agentIndex")) in {str(index) for index in WAITING_FLEET_INDEXES}
                                for row in snapshot.get("records", []))
                    and all(placement_refusal(v, agent_id) is None for agent_id in extras + ([target] if target else [])))

        wait_until(restored, CONVERGE_TIMEOUT, "stock tag/ram/slots64, ordinary fleet and no extra agents or queued records")

    return (f"{target} replicated to every surviving master, survived leader death and quorum loss/rebuild "
            f"(terms {old_term}->{inherited['term']}->{recovered_term}), then started and wrote on {host} without "
            f"another ask; {len(extras)} extras ran, live slots {held}; full stand restored")


def scenario_45_upload_pool_across_nodes():
    """Real node pools retain worker debt through contention, exit and peer replacement.

    The oracle is the four driver counters plus actual process/link/receipt facts,
    not RSS, JSON size or payload contents. Every STOP is captured before signaling
    and has a finally CONT of that exact incarnation. Pool liveness/invariant failures
    are hard assertions: the matrix's convergence retry cannot hide a stuck delivery.
    """
    pool_bytes, chunk_bytes = 131072, 65536
    # Mirrors UploadsSettingsCatalog::MEMORY_POOL_BYTES default, not a test policy.
    default_bytes = 2147483648
    route = "test:cluster:upload-pool"
    frozen, attached, floors = [], [], {}
    marks = {node: node_log_mark(node) for node in ALL_NODES}
    phase = "prepare"

    def trace(event, **data):
        print("    upload-pool " + json.dumps(dict(event=event, phase=phase,
                                                  time=time.monotonic(), **data), sort_keys=True), flush=True)

    def command(node, operation, *args):
        result = control.client(STAND, node, route, operation, *args)
        trace("command", node=node, operation=operation, args=args,
              rc=result.code, stdout=result.out, stderr=result.err)
        assert result.code == 0, f"{phase}: {node} {operation}: {result}"
        row = json.loads(result.out)
        assert set(row) == {"limitBytes", "usedBytes", "waitingUploads", "pendingPeerReservations"}, row
        assert all(type(value) is int and value >= 0 for value in row.values()), row
        assert row["usedBytes"] <= row["limitBytes"], f"{node} exceeded its raw byte budget: {row}"
        assert row["usedBytes"] >= floors.get(node, 0), f"{node} freed a stopped worker's debt early: {row}"
        return row

    def limit(node, size):
        return command(node, "limit", f"--memory-pool-bytes={size}")

    def snapshot(nodes):
        return {node: command(node, "inspect") for node in nodes}

    def await_pool(nodes, predicate, description):
        last = {}

        def ready(_views):
            last.update(snapshot(nodes))
            return predicate(last)

        try:
            wait_until(ready, CONVERGE_TIMEOUT, description, nodes=[])
        except ScenarioTimeout as error:
            raise AssertionError(f"{phase}: delivery failed to progress: {description}; {last}") from error
        trace("condition", description=description, pools=last)
        return last

    def idle(nodes):
        return await_pool(nodes, lambda rows: all(all(row[key] == 0 for key in
                          ("usedBytes", "waitingUploads", "pendingPeerReservations")) for row in rows.values()),
                          "all receipts, demands and peer waits drained without another enqueue")

    def target(node, views):
        members = hosted_by(views, node)
        workers = fleet_workers_on(node, members)
        assert workers, f"no current fleet worker on {node}: {members}"
        index, agents = max(workers.items(), key=lambda item: len(item[1]))
        agent = sorted(agents)[0]
        process = control.worker_process(STAND, node, index)
        assert placement_row(inspect_all(), agent).get("nodeId") == node, f"{agent} moved during process capture"
        trace("target", agent=agent, process=process._asdict())
        return agent, process

    def signal(process, number):
        outcome = control.signal_worker_process(STAND, process, number)
        trace("process", process=process._asdict(), signal=number,
              rc=outcome.code, stdout=outcome.out, stderr=outcome.err)
        assert outcome.code == 0, f"could not signal captured process: {outcome}"
        return json.loads(outcome.out)

    def stop(process):
        # Register before STOP, including a failure between signal and confirmation.
        frozen.append(process)
        assert signal(process, 19) is not None, f"worker vanished before STOP: {process}"
        try:
            wait_until(lambda _v: (signal(process, None) or {}).get("state") == "T",
                       CONVERGE_TIMEOUT, "captured worker is stopped", nodes=[])
        except ScenarioTimeout as error:
            raise AssertionError(f"worker did not stop: {process}") from error

    def resume(process):
        floors.pop(process.node, None)
        signal(process, 18)  # null means gone, never signal its replacement.

    def enqueue(source, destination, agent):
        return command(source, "enqueue", f"--target-node-id={destination}",
                       f"--agent-type={WORKER_AGENT_TYPE}", f"--agent-index={agent.split(':', 1)[1]}",
                       f"--bytes={chunk_bytes}")

    def control_progress(source, receiver):
        # A NEW index fact and addressed reply, on the actual pair while pools wait.
        key = f"pool45-{uuid.uuid4().hex}"
        before = inspect_all([source, receiver])
        count = indexed_for(before, source, receiver)
        deliveries = client_deliveries(before, receiver)
        attached.append((receiver, key))
        assert client(receiver, "test:cluster:client:attach", key), "could not attach fresh control fixture"
        wait_until(lambda v: indexed_for(v, source, receiver) > count, CONVERGE_TIMEOUT,
                   "fresh connection fact crosses the contended peer", nodes=[source, receiver])
        assert client(source, "test:cluster:client:send", key, key), "could not send fresh control reply"
        wait_until(lambda v: client_deliveries(v, receiver) > deliveries
                   and (v.get(receiver) or {}).get("lastClientAcceptKey") == key,
                   CONVERGE_TIMEOUT, "fresh addressed control reply delivered", nodes=[source, receiver])
        for node in ALL_NODES:
            assert inspect(node) is not None, f"{node} inspect stopped during pool contention"
            result = control.client(STAND, node, "daemon:ping")
            trace("ping", node=node, rc=result.code, stdout=result.out, stderr=result.err)
            assert result.code == 0, f"{node} daemon control stopped: {result}"
        assert client(receiver, "test:cluster:client:detach", key), "could not detach control fixture"
        attached.remove((receiver, key))
        wait_until(lambda v: indexed_for(v, source, receiver) == count, CONVERGE_TIMEOUT,
                   "fresh control fixture removed from peer index", nodes=[source])
        trace("control-roundtrip", source=source, receiver=receiver, key=key)

    try:
        wait_converge(ALL_NODES)
        views = wait_until(fleet_started, CONVERGE_TIMEOUT, "fleet started before pool perturbation")
        pair = SLAVES[:2]
        views = spread_fleet_over(pair, views)
        a, b = sorted(pair)
        agents, processes = {}, {}
        for node in pair:
            agents[node], processes[node] = target(node, views)
        trace("participants", nodes=pair, agents=agents, need=dict(masters=2, slaves=2))
        for node in ALL_NODES:
            limit(node, pool_bytes)
        idle(ALL_NODES)

        phase = "reservation-before-data"
        stop(processes[b])
        limit(b, 0)
        enqueue(a, b, agents[b])
        await_pool(pair, lambda r: r[b]["usedBytes"] == 0 and r[a]["waitingUploads"] == 1
                   and r[b]["pendingPeerReservations"] > 0, "receiver has metadata wait and no unreserved bytes")
        control_progress(a, b)
        assert command(b, "inspect")["usedBytes"] == 0, "unreserved input reached worker"
        limit(b, pool_bytes)
        await_pool(pair, lambda r: r[b]["usedBytes"] == chunk_bytes and r[a]["usedBytes"] == 0
                   and r[a]["waitingUploads"] == 0 and r[b]["pendingPeerReservations"] == 0,
                   "remote accepted: source transport settled, receiver worker still owes one portion")
        floors[b] = chunk_bytes

        phase = "local-plus-remote-full"
        enqueue(b, b, agents[b])
        await_pool(pair, lambda r: r[b]["usedBytes"] == pool_bytes, "one node holds LOCAL + REMOTE raw portions")
        floors[b] = pool_bytes
        enqueue(a, b, agents[b])
        await_pool(pair, lambda r: r[b]["usedBytes"] == pool_bytes and r[a]["waitingUploads"] == 1
                   and r[b]["pendingPeerReservations"] > 0, "third portion waits behind the shared node cap")
        control_progress(a, b)
        snapshot(pair)
        resume(processes[b])
        idle(pair)

        phase = "crossing-intents"
        for node in pair:
            stop(processes[node])
            limit(node, chunk_bytes)
            enqueue(node, node, agents[node])
            await_pool([node], lambda r, node=node: r[node]["usedBytes"] == chunk_bytes,
                       "local accepted portion fills stopped worker pool")
            floors[node] = chunk_bytes
        barrier = threading.Barrier(2)
        errors = []

        def cross(source, receiver):
            try:
                barrier.wait(timeout=CONVERGE_TIMEOUT)
                enqueue(source, receiver, agents[receiver])
            except BaseException as error:
                errors.append(error)

        threads = [threading.Thread(target=cross, args=direction) for direction in ((a, b), (b, a))]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(CONVERGE_TIMEOUT)
        assert all(not thread.is_alive() for thread in threads), "crossing CLI callers never returned"
        assert not errors, f"crossing caller failed: {errors}"
        await_pool(pair, lambda r: all(r[n]["waitingUploads"] == 1 and r[n]["usedBytes"] == chunk_bytes for n in pair),
                   "BOTH crossing intents overlap while each node is full")
        control_progress(a, b)
        for node in pair:
            limit(node, pool_bytes)
        await_pool(pair, lambda r: any(r[n]["waitingUploads"] == 0 for n in pair),
                   "ordered acquisition advances a direction after capacity increase")
        for node in pair:
            resume(processes[node])
        idle(pair)

        phase = "worker-death"
        stop(processes[b])
        enqueue(a, b, agents[b])
        await_pool(pair, lambda r: r[b]["usedBytes"] == chunk_bytes and r[a]["usedBytes"] == 0,
                   "receiver ALREADY ACCEPTED debt before worker death")
        floors[b] = chunk_bytes
        death_mark = node_log_size(b)
        floors.pop(b)
        assert signal(processes[b], 9) is not None, "worker died before deliberate SIGKILL"
        idle(pair)
        wait_until(lambda _v: any(int(m.group(1)) == processes[b].index for m in
                   WORKER_DIED_HOSTING.finditer(node_log(b, death_mark))),
                   CONVERGE_TIMEOUT, "master reports captured worker death", nodes=[b])
        views = wait_until(fleet_started, FAILOVER_TIMEOUT, "fleet started after worker exit")
        # Re-resolve holder and process from CURRENT placement; it may have moved.
        host = placement_row(views, agents[b])["nodeId"]
        new_agent, replacement = target(host, views)
        assert replacement != processes[b], "worker incarnation did not change"
        assert signal(processes[b], None) is None, "killed incarnation is still present"
        stop(replacement)
        enqueue(host, host, new_agent)
        await_pool([host], lambda r: r[host]["usedBytes"] == chunk_bytes,
                   "replacement worker accounts a new accepted delivery")
        floors[host] = chunk_bytes
        # Late cleanup of old process must not release replacement bytes.
        signal(processes[b], 18)
        assert command(host, "inspect")["usedBytes"] == chunk_bytes
        resume(replacement)
        idle(pair)

        phase = "peer-loss"
        views = wait_until(fleet_started, CONVERGE_TIMEOUT, "fleet ready before peer loss")
        views = spread_fleet_over(pair, views)
        agents[b], receiver = target(b, views)
        stop(receiver)
        enqueue(a, b, agents[b])
        await_pool(pair, lambda r: r[b]["usedBytes"] == chunk_bytes and r[a]["usedBytes"] == 0,
                   "receiver ALREADY ACCEPTED debt before peer EOF")
        floors[b] = chunk_bytes
        # No shrink of issued windows: the sole retained debt equals this smaller cap.
        limit(b, chunk_bytes)
        enqueue(a, b, agents[b])
        await_pool(pair, lambda r: r[a]["waitingUploads"] == 1 and r[b]["pendingPeerReservations"] > 0,
                   "unused peer request waits beside accepted receiver debt")
        outcome = control.execute(STAND, "kill", a)
        trace("peer-kill", node=a, rc=outcome.code, stdout=outcome.out, stderr=outcome.err)
        assert outcome.code == 0, outcome
        await_pool([b], lambda r: r[b]["pendingPeerReservations"] == 0 and r[b]["usedBytes"] == chunk_bytes,
                   "peer EOF deletes unused waits, preserves live receiver debt")
        outcome = control.execute(STAND, "start", a)
        assert outcome.code == 0, outcome
        wait_converge(ALL_NODES)
        limit(a, pool_bytes)
        limit(b, pool_bytes)
        enqueue(a, b, agents[b])
        await_pool(pair, lambda r: r[a]["usedBytes"] == 0 and r[a]["waitingUploads"] == 0
                   and r[b]["usedBytes"] == pool_bytes, "new peer generation adds debt beside the old accepted portion")
        floors[b] = pool_bytes
        control_progress(a, b)
        snapshot(pair)
        resume(receiver)
        idle(pair)
        trace("proof-complete")
    finally:
        # Evidence is emitted BEFORE restart rotates/clears logs, even on a hard failure.
        failed = sys.exc_info()[0] is not None
        errors = []
        for process in frozen:
            try:
                resume(process)
            except Exception as error:
                errors.append(str(error))
        for node, key in attached:
            try:
                assert client(node, "test:cluster:client:detach", key), f"detach failed: {node}/{key}"
            except Exception as error:
                errors.append(str(error))
        if failed:
            for node in ALL_NODES:
                trace("failure-node", node=node, inspect=inspect(node),
                      activeLog=node_log_since(node, marks[node]), containerLog=container_log(node))
        for node in ALL_NODES:
            try:
                restored = limit(node, default_bytes)
                if not failed:
                    assert restored == dict(limitBytes=default_bytes, usedBytes=0,
                                            waitingUploads=0, pendingPeerReservations=0), restored
            except Exception as error:
                # A killed node may be unreachable; the full restart removes its test state too.
                trace("restore-limit", node=node, error=str(error))
                if not failed:
                    errors.append(str(error))
        code = control.restart(STAND, f"{sys.argv[0]} {STAND.compose}")
        assert code == 0, f"scenario 45 full original stand restart failed: {code}; {errors}"
        wait_converge(ALL_NODES)
        wait_until(lambda v: fleet_started(v) and fleet_rows_where_read(v), CONVERGE_TIMEOUT,
                   "original stand mesh and ordinary fleet restored")
        phase = "restored"
        for node in ALL_NODES:
            row = command(node, "inspect")
            # This carrier has no product UPLOADS. A fresh driver-only master starts
            # suspended at zero (UploadMemoryMaster::__construct); its test overrides
            # must disappear on restart. The catalog default was restored above.
            assert row == dict(limitBytes=0, usedBytes=0, waitingUploads=0, pendingPeerReservations=0), row
        trace("cleanup", restored=True, errors=errors)
        assert not errors, f"scenario 45 process/fixture cleanup failed: {errors}"

    return ("131072-byte node pools: reservation-before-data, local+remote cap, receipts, "
            "overlapping crossing intents, control roundtrips, worker exit and peer replacement; "
            "all stopped incarnations resumed/gone, full original stand/defaults restored")


def scenario_9_daemon_crash_selfheal():
    """A crashed daemon's workers leave on their own, except the one that cannot.

    The workers of a crashed daemon leave by themselves (HIL-520), and the one that
    cannot — a worker frozen with SIGSTOP before the crash, holding the daemon's
    listening sockets — is finished off by the watchdog's sweep before the next
    start (HIL-450): the sweep finds exactly that one live child, it ignores
    SIGTERM, it is SIGKILLed, and the node comes back in the same container.
    Without the sweep every restart fails to bind forever, and this scenario is
    what goes red.
    """
    victim = SLAVES[0]
    wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before the crash")
    before = container_id(victim)
    assert before, f"could not read the container id of {victim}"

    frozen = ctl_out("freeze-worker", victim)
    assert "SIGSTOPped" in frozen, \
        f"could not freeze a worker inside {victim}: {frozen or '(no output)'}"
    pid = int(re.search(r"pid (\d+)", frozen).group(1))
    print(f"    {frozen.removeprefix('cluster: ')}")

    killed = ctl_out("crash-daemon", victim)
    assert "SIGKILLed" in killed, f"could not kill the daemon inside {victim}: {killed or '(no output)'}"
    print(f"    {killed.removeprefix('cluster: ')}; the other workers leave, the frozen one stays")

    survivors = [n for n in ALL_NODES if n != victim]
    try:
        wait_until(lambda v: not node_online(v, victim), CONVERGE_TIMEOUT,
                   f"{victim} seen offline after its daemon died", nodes=survivors)
        wait_until(converged(ALL_NODES), CRASH_RECOVERY_TIMEOUT,
                   f"{victim} rebound its ports and rejoined the roster on its own")
        after = container_id(victim)
        assert after == before, \
            f"{victim} came back as a NEW container ({after[:12]} != {before[:12]}); " \
            "it was recreated instead of self-healing"
        sweep_finished_off(victim, pid)

        # Back in the roster is not the same as fit for work: leave the recovered node
        # as the only capable target and require the whole fleet to land on it.
        other = next(s for s in SLAVES if s != victim)
        print(f"    killing {other} so the recovered {victim} is the only placement target")
        ctl("kill", other)
        try:
            wait_until(lambda v: len(hosted_by(v, victim)) == WORKER_FLEET_SIZE,
                       FAILOVER_TIMEOUT, f"the whole fleet placed onto the recovered {victim}",
                       nodes=[n for n in ALL_NODES if n != other])
        finally:
            ctl("start", other)
        return (f"{victim} self-healed in the same container ({before[:12]}): "
                f"the sweep found 1 orphan and killed frozen worker pid {pid} "
                f"after it ignored SIGTERM, and then took all {WORKER_FLEET_SIZE} workers")
    finally:
        # Recreate rather than start: a node that did NOT self-heal is still running, so
        # `start` would be a no-op on it and every later attempt would inherit the wedge.
        # Replacing the container is the only way back, and after a pass it is a cheap reset.
        ctl("recreate", victim)
        wait_converge(ALL_NODES)


def sweep_finished_off(node, pid):
    """The scan nearest the frozen pid, not the last scan in the log.

    A start that lost the bind race to the dying worker is followed by a second
    start whose scan says found 0. The proof is the scan that named this pid:
    found exactly one orphan, then that pid terminated, then killed after it
    ignored SIGTERM.
    """
    lines = container_log(node).splitlines()
    quoted = "\n".join(line for line in lines if "OrphanReaper:" in line)
    terminating = f"OrphanReaper: terminating orphan pid={pid} cmd="
    killing = f"OrphanReaper: orphan pid={pid} ignored SIGTERM, killing it"

    def miss(reason):
        raise AssertionError(f"{node}: {reason}\n{quoted}")

    term_at = next((index for index, line in enumerate(lines) if terminating in line), None)
    if term_at is None:
        miss(f"no line terminating frozen worker pid {pid}")
    kill_at = next((index for index, line in enumerate(lines)
                    if index > term_at and killing in line), None)
    if kill_at is None:
        miss(f"frozen worker pid {pid} was not killed after ignoring SIGTERM")
    scan_at = None
    for index in range(term_at):
        if "OrphanReaper: orphan scan complete, found " in lines[index]:
            scan_at = index
    if scan_at is None:
        miss(f"no orphan scan before terminating frozen worker pid {pid}")
    if "found 1 orphan(s)" not in lines[scan_at]:
        miss(f"the scan nearest pid {pid} did not find exactly 1 orphan: {lines[scan_at]}")


def scenario_39_person_agents_across_nodes():
    """Real row/set writes follow one person's canonical owner through idle and failover.

    SQL belongs to this external harness only: unique fixtures precede their first lazy
    read, and the oracle reads durable rows independently of the agents' caches. All edits
    go through the existing admin and second-factor commands. A final restart discards
    the process-local caches and claims that deleting the fixtures cannot announce.
    """
    # Mirrors AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC; the product window is never shortened.
    idle_seconds = 240
    library_types = {"hilos_users_library", "hilos_sessions_library"}
    tables = {"hilos_user": "id", "hilos_second_factor_setting": "user_id"}
    entry, host_a, host_b = MASTERS[0], SLAVES[0], SLAVES[1]
    survivors = [node for node in ALL_NODES if node != host_b]
    fixture_ids = []
    phase = "preparation"
    marks = {node: node_log_mark(node) for node in ALL_NODES}
    worker_marks = {}
    failed = False

    def begin_phase(name):
        nonlocal phase, marks, worker_marks
        phase = name
        marks = {node: node_log_mark(node) for node in ALL_NODES}
        worker_marks = {}
        for node in ALL_NODES:
            worker_marks[node] = {}
            for path in STAND.node(node).log_dir.glob("worker-*.log"):
                stat = path.stat()
                worker_marks[node][path] = control.LogMark(stat.st_ino, stat.st_size)
        print(f"    person agents: {phase}", flush=True)

    def worker_log(node):
        # WorkerManager's lifecycle reasons and start-hook failures go to worker streams,
        # not daemon.log (WorkerServer::saveWorkerOutput). Only live files, since this phase.
        parts = []
        for path in sorted(STAND.node(node).log_dir.glob("worker-*.log")):
            mark = worker_marks.get(node, {}).get(path)
            try:
                with path.open("rb") as stream:
                    if mark is not None and os.fstat(stream.fileno()).st_ino == mark.inode:
                        stream.seek(mark.size)
                    parts.append(stream.read().decode("utf-8", errors="replace"))
            except FileNotFoundError:
                # A returning node rotates its previous process's streams before starting.
                continue
        return "\n".join(parts)

    def libraries_on_a(views):
        rows = [row for row in leader_placements(views)
                if row.get("agentId", "").split(":", 1)[0] in library_types]
        return (len(rows) == len(library_types)
                and {row["agentId"].split(":", 1)[0] for row in rows} == library_types
                and all(row.get("nodeId") == host_a and row.get("state") == "started" for row in rows))

    def command(name, expected_reply):
        outcome = control.client(STAND, entry, name, str(user_x))
        assert outcome.code == 0, \
            f"{phase}: {name} failed ({outcome.code}): {outcome.out}\n{outcome.err}"
        assert expected_reply in outcome.out.splitlines(), \
            f"{phase}: {name} gave no expected reply {expected_reply!r}: {outcome.out}\n{outcome.err}"
        print(f"      {expected_reply}", flush=True)

    def snapshot():
        result = {}
        for table, key in tables.items():
            answer = db_sql(f"SELECT * FROM {table} WHERE {key} IN ({ids_sql}) ORDER BY {key}")
            rows = [line.split("\t") for line in answer.splitlines()]
            assert len(rows) == 2 and all(len(row) == len(columns[table]) for row in rows), \
                f"{phase}: missing or malformed {table} fixtures: {answer!r}"
            mapped = [dict(zip(columns[table], row)) for row in rows]
            result[table] = {int(row[key]): row for row in mapped}
            assert set(result[table]) == set(fixture_ids), f"{phase}: wrong fixture ids in {table}"
        return result

    def assert_facts(admin=None, unlocked=False):
        actual = snapshot()
        if admin is not None:
            expected["hilos_user"][user_x]["admin"] = str(admin)
        if unlocked:
            setting = expected["hilos_second_factor_setting"][user_x]
            updated_at = actual["hilos_second_factor_setting"][user_x]["updated_at"]
            assert datetime.fromisoformat(updated_at) > datetime.fromisoformat(setting["updated_at"]), \
                f"{phase}: unlock did not advance updated_at: {setting['updated_at']} -> {updated_at}"
            setting.update(app_code_misses="0", app_code_misses_from="NULL", app_code_lock_step="0",
                           app_code_locked_until="NULL", updated_at=updated_at)
            print(f"      set Update persisted at {updated_at}", flush=True)
        assert actual == expected, f"{phase}: durable facts changed:\nexpected {expected}\nactual {actual}"
        for node in ALL_NODES:
            log = node_log_since(node, marks[node]) + worker_log(node)
            refused = [line for line in log.splitlines()
                       if f"Agent '{agent_id}' start hook failed" in line
                       or f"Agent '{agent_id}' did not start" in line
                       or ("Agent start refused:" in line and f"-> agent: {agent_id} -" in line)]
            assert not refused, f"{phase}: {agent_id} failed on {node}: {refused}"

    def started_on(host, nodes, timeout=CONVERGE_TIMEOUT):
        views = wait_until(lambda current: placement_row(current, agent_id).get("state") == "started",
                           timeout, f"{phase}: {agent_id} started", nodes=nodes)
        row = placement_row(views, agent_id)
        assert row.get("nodeId") == host, f"{phase}: {agent_id} started on the wrong node: {row}"
        assert libraries_on_a(views), f"{phase}: the libraries left {host_a}: {summarize(views)}"
        log = node_log_since(host, marks[host])
        assert any(match.group(1) == agent_id for match in AGENT_STARTED_ON_WORKER.finditer(log)), \
            f"{phase}: {host} never logged the start of {agent_id}"
        assert f"Agent '{agent_id}' start hook failed" not in worker_log(host), \
            f"{phase}: {agent_id}'s start hook failed on {host}"
        print(f"      {agent_id} started on {host}; both libraries on {host_a}", flush=True)
        return views

    try:
        wait_converge(ALL_NODES)
        views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet started before person fixtures")
        # Scenario 14 leaves a refused claim in the leader's view; it hosts no agent.
        placed_before = {row["agentId"] for row in leader_placements(views)
                         if row.get("state") in LIVE_PLACEMENT_STATES}
        outcome = control.execute(STAND, "kill", host_b)
        assert outcome.code == 0, f"could not stop {host_b}: {outcome.err or outcome.out}"

        def gathered_on_a(current):
            rows = [row for row in leader_placements(current)
                    if row.get("state") in LIVE_PLACEMENT_STATES]
            return (len(leaders(current)) == 1 and not node_online(current, host_b)
                    and fleet_started(current) and libraries_on_a(current)
                    and placed_before <= {row["agentId"] for row in rows}
                    and all(row.get("nodeId") == host_a and row.get("state") == "started" for row in rows))

        wait_until(gathered_on_a, FAILOVER_TIMEOUT,
                   f"{host_b} offline and every live placement started on {host_a}", nodes=survivors)

        # New ids before the first frame: users and secondFactorSettings both use LAZY_STRATEGY_KEY.
        prefix = f"cluster-person-39-{uuid.uuid4().hex}"
        for suffix, admin in (("x", 0), ("y", 1)):
            answer = db_sql(f"INSERT INTO hilos_user (name, admin) VALUES ('{prefix}-{suffix}', {admin}); "
                            "SELECT LAST_INSERT_ID()")
            assert answer.isdigit() and int(answer) > 0, f"could not seed person {suffix}: {answer!r}"
            fixture_ids.append(int(answer))
        user_x, user_y = fixture_ids
        ids_sql = ",".join(str(user_id) for user_id in fixture_ids)
        agent_id = f"hilos_user:{user_x}"
        for user_id, wait_days, misses, step in ((user_x, 7, 5, 2), (user_y, 14, 9, 3)):
            outcome = control.execute(STAND, "db-sql",
                "INSERT INTO hilos_second_factor_setting "
                "(user_id, reset_wait_days, pending_reset_wait_days, pending_reset_wait_from, "
                "app_code_misses, app_code_misses_from, app_code_lock_step, app_code_locked_until, updated_at) "
                f"VALUES ({user_id}, {wait_days}, {wait_days + 1}, UTC_TIMESTAMP() + INTERVAL 2 DAY, "
                f"{misses}, UTC_TIMESTAMP(), {step}, UTC_TIMESTAMP() + INTERVAL 1 DAY, "
                "UTC_TIMESTAMP() - INTERVAL 1 DAY)")
            assert outcome.code == 0, f"could not seed second-factor settings: {outcome.err or outcome.out}"
        columns = {table: [line.split("\t")[0] for line in db_sql(f"SHOW COLUMNS FROM {table}").splitlines()]
                   for table in tables}
        assert all(key in columns[table] for table, key in tables.items()), "could not read fixture columns"
        expected = snapshot()
        assert expected["hilos_user"][user_x]["admin"] == "0", "X must begin without admin rights"
        assert expected["hilos_user"][user_y]["admin"] == "1", "Y must reserve another administrator"
        assert not placement_row(inspect_all(survivors), agent_id), f"{agent_id} was already placed before its first frame"

        begin_phase("cold start and row/set writes")
        command("admin:grant", f"Reply (ok): user #{user_x} is now admin, no open session to tell")
        started_on(host_a, survivors)
        assert_facts(admin=1)
        locked_until = expected["hilos_second_factor_setting"][user_x]["app_code_locked_until"]
        command("second-factor:unlock",
                f"Reply (ok): user #{user_x} was locked until {locked_until} +00:00 (UTC); app codes are accepted again")
        assert_facts(unlocked=True)

        begin_phase("ordinary idle with no frames addressed to X")
        outcome = control.execute(STAND, "start", host_b)
        assert outcome.code == 0, f"could not return {host_b}: {outcome.err or outcome.out}"
        views = wait_converge(ALL_NODES)
        assert libraries_on_a(views), f"returning {host_b} moved the libraries: {summarize(views)}"
        idle_line = f"Agent {agent_id} stopped (idle for {idle_seconds}s)"

        def idled(current):
            return (len(leaders(current)) == 1 and not placement_row(current, agent_id)
                    and idle_line in worker_log(host_a))

        views = wait_until(idled, idle_seconds + CONVERGE_TIMEOUT,
                           f"{agent_id}'s placement gone and its ordinary {idle_seconds}s idle stop logged")
        assert libraries_on_a(views), f"{phase}: the libraries left {host_a}: {summarize(views)}"
        assert_facts()
        print(f"      {idle_line}; placement absent and durable facts intact", flush=True)
        assert live_on(views, host_b) < live_on(views, host_a), \
            f"{host_b} is not the less occupied node after idle: {summarize(views)}"

        begin_phase("wake on the other node and cross-node replies")
        no_lock_reply = f"Reply (ok): user #{user_x} had no lock; the miss count is cleared"
        command("second-factor:unlock", no_lock_reply)
        started_on(host_b, ALL_NODES)
        assert_facts(unlocked=True)
        command("admin:revoke", f"Reply (ok): user #{user_x} is now not admin, no open session to tell")
        assert_facts(admin=0)
        views = inspect_all()
        assert placement_row(views, agent_id).get("nodeId") == host_b \
            and placement_row(views, agent_id).get("state") == "started" and libraries_on_a(views), \
            f"the live cross-node owner disappeared before the kill: {summarize(views)}"

        begin_phase("active owner host dies and its claims are rebuilt")
        outcome = control.execute(STAND, "kill", host_b)
        assert outcome.code == 0, f"could not kill {host_b}: {outcome.err or outcome.out}"
        views = wait_until(lambda current: len(leaders(current)) == 1 and not node_online(current, host_b)
                           and placement_row(current, agent_id).get("state") == "started"
                           and placement_row(current, agent_id).get("nodeId") == host_a,
                           FAILOVER_TIMEOUT, f"{host_b} offline and the same {agent_id} started on {host_a}",
                           nodes=survivors)
        started_on(host_a, survivors)
        assert_facts()
        command("admin:grant", f"Reply (ok): user #{user_x} is now admin, no open session to tell")
        assert_facts(admin=1)
        command("second-factor:unlock", no_lock_reply)
        assert_facts(unlocked=True)
    except BaseException:
        failed = True
        # Save evidence before restart rotates the files, including on a retried timeout.
        try:
            print(f"    person agents FAILED in {phase}: {summarize(inspect_all(survivors))}", flush=True)
            for node in ALL_NODES:
                print(f"    {phase}: {node} log since phase mark ({node_log_path(node)}):\n"
                      f"{node_log_since(node, marks[node])}", flush=True)
                print(f"    {phase}: {node} worker logs since phase mark:\n{worker_log(node)}", flush=True)
        except Exception as evidence_error:
            print(f"    could not collect phase evidence: {evidence_error}", flush=True)
        raise
    finally:
        recovery_errors = []
        if fixture_ids:
            # Try each cleanup and the restart independently; recovery never masks the original error.
            ids = ",".join(str(user_id) for user_id in fixture_ids)
            for table, key in reversed(list(tables.items())):
                try:
                    outcome = control.execute(STAND, "db-sql", f"DELETE FROM {table} WHERE {key} IN ({ids})")
                    assert outcome.code == 0, f"could not clean {table}: {outcome.err or outcome.out}"
                    assert db_sql(f"SELECT COUNT(*) FROM {table} WHERE {key} IN ({ids})") == "0", \
                        f"fixture rows remain in {table}"
                except Exception as cleanup_error:
                    recovery_errors.append(str(cleanup_error))
        try:
            outcome = control.execute(STAND, "start", host_b)
            assert outcome.code == 0, f"could not return {host_b}: {outcome.err or outcome.out}"
        except Exception as start_error:
            recovery_errors.append(str(start_error))
        try:
            print("    person agents: restarting the same stand after fixture cleanup", flush=True)
            code = control.restart(STAND, f"{sys.argv[0]} {STAND.compose}")
            assert code == 0, f"the stand did not come back after scenario 39 (exit {code})"
            wait_converge(ALL_NODES)
            wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet started after person fixture cleanup")
            print("    person agents: full mesh and fleet restored", flush=True)
        except Exception as restart_error:
            recovery_errors.append(str(restart_error))
        if recovery_errors:
            message = "scenario 39 recovery failed: " + "; ".join(recovery_errors)
            print(f"    {message}", flush=True)
            if not failed:
                raise AssertionError(message)

    return (f"{agent_id}: cold on {host_a}, ordinary {idle_seconds}s idle, woke on {host_b} beside libraries "
            f"on {host_a}, failed over to {host_a}; row/set writes and updated_at proved by DB, "
            f"Y={user_y} unchanged; fixtures removed, full mesh and fleet restored")


def scenario_10_cross_node_browser():
    """A browser attached to one node must be answerable from any other (HIL-668).

    The defect this closes is silent: a browser hangs on exactly one node, an agent runs on
    whichever node the leader placed it on, and until now the second could not reach the first.
    The answer went out locally, to a socket table that never held that connection, and nothing
    anywhere reported a failure.

    Both directions of the fix are asserted, and they are different mechanisms. An ADDRESSED
    signal is looked up in the connection index and forwarded to the one node holding the key -
    so the delivery lands on that node and on no other. A FAN-OUT has no address at all, because
    which browsers it reaches is answered by each node's own subscriptions, so it is carried to
    every node instead. The sender is the exception on purpose: it expands its own fan-out
    locally, and the probe counter here is of client-test frames that came off the mesh.
    The total counter keeps counting unrelated application traffic, including Status hints.

    The scenario opens no browser, so one is attached through the CLI and delivery is read
    from the inspect reply rather than from a socket. Everything between those two ends - the
    per-tick announcement, the index, the routing pass, the peer frame - is the production path.
    """
    key = "ak-cluster-e2e"
    holder, sender = SLAVES[0], MASTERS[1]
    wait_converge(ALL_NODES)
    assert client(holder, "test:cluster:client:attach", key), \
        f"could not attach a test browser on {holder}"
    try:
        wait_until(lambda v: indexed_for(v, sender, holder) >= 1, CONVERGE_TIMEOUT,
                   f"{sender} learns that {holder} holds a browser")

        views = inspect_all()
        totals_before = {n: client_deliveries(views, n) for n in ALL_NODES}
        before = {n: client_test_deliveries(views, n) for n in ALL_NODES}
        assert client(sender, "test:cluster:client:send", key, "hello"), \
            f"could not raise an addressed signal on {sender}"

        def addressed_arrived(views):
            view = views.get(holder)
            return (bool(view)
                    and client_test_deliveries(views, holder) == before[holder] + 1
                    and view.get("lastClientAcceptKey") == key)

        wait_until(addressed_arrived, CONVERGE_TIMEOUT,
                   f"{holder} takes in the signal {sender} addressed to its browser")

        others = [n for n in ALL_NODES if n != holder]
        # Where Status is active, cross a real background delivery before checking the
        # negative. This reproduces the old total-counter collision without a fake frame,
        # changing cadence, or making an application broadcast into probe evidence.
        if placement_row(views, "hilos_status"):
            quiet = wait_until(
                lambda current: any(client_deliveries(current, n) > totals_before[n] for n in others),
                CONVERGE_TIMEOUT, "background traffic crosses the addressed probe", nodes=others)
            print(f"    background totals: before={totals_before}, "
                  f"after={ {n: client_deliveries(quiet, n) for n in others} }; "
                  f"probe before={before}, after={ {n: client_test_deliveries(quiet, n) for n in others} }",
                  flush=True)
        else:
            quiet = inspect_all(others)
        for node in others:
            assert client_test_deliveries(quiet, node) == before[node], \
                f"an addressed signal reached {node}, which holds no such browser"

        views = inspect_all()
        before = {n: client_test_deliveries(views, n) for n in ALL_NODES}
        assert client(sender, "test:cluster:client:fanout", "everyone"), \
            f"could not raise a fan-out on {sender}"

        receivers = [n for n in ALL_NODES if n != sender]
        last_deliveries = {}

        def fanout_arrived(views):
            last_deliveries.update({n: client_test_deliveries(views, n) for n in receivers})
            return all(last_deliveries[n] == before[n] + 1 for n in receivers)

        try:
            wait_until(fanout_arrived, CONVERGE_TIMEOUT,
                       "every node but the sender takes in the fan-out", nodes=receivers)
        except ScenarioTimeout as exc:
            missing = {n: {"before": before[n], "last": last_deliveries.get(n, -1)}
                       for n in receivers if last_deliveries.get(n, -1) != before[n] + 1}
            raise ScenarioTimeout(f"{exc}\nfan-out receivers still missing deliveries: {missing}") from exc
        assert client_test_deliveries(inspect_all([sender]), sender) == before[sender], \
            "the sender received its own fan-out from the mesh instead of expanding it locally"
        return (f"{sender} answered a browser attached to {holder} and fanned out to all "
                f"{len(receivers)} other nodes")
    finally:
        client(holder, "test:cluster:client:detach", key)


def scenario_12_rt_replication():
    """A runtime row written on one node reaches every node that READS it, and its workers.

    Every fleet member owns exactly ONE row of `hilosProbeFleetStatuses`, by its own index, and
    the fleet is spread over the data-plane nodes - so this collection has a truth source on
    several nodes at once, each for its own rows. Before the row axis of ownership existed, a node holding any
    of it claimed the whole collection: every neighbour's frame read as "two truth sources" and
    was dropped, and the collection never converged anywhere (HIL-589).

    Who a frame goes to is the second half, and it is what makes the shape above visible from
    outside (HIL-717). A node is sent a collection only while a worker of its own reads one - the
    deltas and the hand-over alike - so the fleet hosts hold the whole of it and the masters, who
    run nothing that reads it, hold none of it. That is asserted rather than assumed, because the
    failure it guards against is silent in both directions: a node kept in the fan-out costs a hop
    per write forever, and a node wrongly dropped from it stops converging with no error anywhere.

    Two more things are asserted, and the first is the load-bearing one. Each row's `rowsSeen`
    equal to the fleet size says the frames went on to the WORKERS: that number is what a member
    counted in its own process, so a member on s1 reporting ten has seen the rows s2's members
    wrote. The inspect reply alone could never say that - it is read from the master.

    `rtRefused` is zero because nothing here is a split, and that is also how the absence of an
    echo shows: a node passing replicas on would be refusing its own writes back within seconds.
    """
    wait_converge(ALL_NODES)
    views = wait_fleet_rows()
    readers = reading_nodes(views)

    def every_member_sees_the_fleet(v):
        return all(row.get("rowsSeen") == WORKER_FLEET_SIZE
                   for node in readers for row in rt_rows(v, node).values())

    wait_until(every_member_sees_the_fleet,
               CONVERGE_TIMEOUT + WORKER_REPORT_INTERVAL_SEC,
               "every fleet member reports seeing the whole fleet",
               nodes=readers)

    # The whole mesh again, not just the readers the poll above narrowed to: the placement table
    # the assertion below reads is the LEADER's, and the leader is a master, which reads none of
    # this collection and so is not among them.
    views = inspect_all()
    readers = reading_nodes(views)

    # Reading it and hosting a writer of it are the same set here, and that is the point: the
    # only thing in this demo that reads the collection is a fleet member, and a member reads
    # what it claims. So the leader's placement table is an independent answer to the same
    # question the `read` flag answers, and the two agreeing is what says the flag is real.
    hosts = sorted(n for n in ALL_NODES if hosted_by(views, n))
    assert sorted(readers) == hosts, \
        f"the nodes reading the collection are {sorted(readers)}, the nodes hosting writers {hosts}"

    for node in ALL_NODES:
        assert rt_refused(views, node) == 0, \
            f"{node} refused an RT frame as a two-owner split: {rt_refused(views, node)}"
        if node not in readers:
            continue
        owned = rt_collection(views, node).get("fullyOwned")
        assert owned is False, \
            f"{node} claims the whole collection ({owned}); each node owns only its members' rows"

    quiet = [n for n in ALL_NODES if n not in readers]
    return (f"all {WORKER_FLEET_SIZE} rows on {', '.join(sorted(readers))}, every member seeing "
            f"the whole fleet, nothing sent to {', '.join(quiet)} which read none of it")


def scenario_13_rt_partition_converges():
    """A node cut off from the mesh serves what it has, marked as frozen, and catches up (HIL-711).

    The reader's side of a dead link: the replica is served AS IS - nothing is swept when the
    owner goes away (HIL-589 D1/D2) - but it is no longer indistinguishable from fresh. Each row
    of the unreachable owner now carries the moment this node stopped hearing about it, and the
    heal takes the mark off again. The first half asserts both directions positively, because
    each of them is a decision that a later change must fail a test to reverse: serving the rows
    is one, and being able to tell how old they are is the other.

    The second half is the hole this ticket had to close. Delivery is best-effort with no retries
    (HIL-183), so everything written while the link was down is lost for good; catching up is the
    hand-over's job, and until now a node owning rows rather than collections handed over NOTHING.
    The rows would have stayed frozen forever.

    The victim is a MASTER on purpose, though it is a data-plane node that hosts the writers. A
    partitioned slave has its fleet members re-placed onto its neighbours by the leader, and a
    re-placed member is a second writer of the same rows whose counter starts at zero - the
    scenario would then be measuring placement, not replication. A master owns no row of this
    collection, so what it holds is a pure replica, which is exactly what the reader's side is
    about.
    """
    victim = MASTERS[2]
    others = [n for n in ALL_NODES if n != victim]
    wait_converge(ALL_NODES)
    wait_fleet_rows()

    print(f"    partitioning {victim} off the network while the fleet keeps writing")
    ctl("partition", victim)
    try:
        # Frozen, not gone: the rows of an unreachable owner stay exactly as they were.
        frozen = rt_rows({victim: inspect_local(victim)}, victim)
        assert len(frozen) == WORKER_FLEET_SIZE, \
            f"{victim} lost rows the moment it was cut off: {sorted(frozen)}"
        time.sleep(WORKER_REPORT_INTERVAL_SEC * 2)
        still = rt_rows({victim: inspect_local(victim)}, victim)
        assert still == frozen, \
            f"{victim} kept changing rows nobody could have sent it"

        # And said to be frozen, which is the half this ticket adds: the rows are still served,
        # and every one of them now names the moment this node stopped hearing about it. Polled
        # rather than read once - the mark is raised when the LINK closes, and a partitioned
        # interface takes a keepalive to notice, so the rows are frozen for real some seconds
        # before either side has said so.
        def every_row_marked_frozen(v):
            marked = rt_stale_rows(v, victim)
            return len(marked) == WORKER_FLEET_SIZE and all(
                isinstance(since, (int, float)) and since > 0 for since in marked.values())

        wait_until(every_row_marked_frozen, CONVERGE_TIMEOUT,
                   f"{victim} marks the replicas of the owners it can no longer reach",
                   nodes=[victim], local=True)

        # And the connected side keeps moving, so the two really are apart. Any node still on
        # the network answers this: whichever of them hosts the fleet, they all hold its rows.
        # Measured by the report clock rather than by the job counter, for the reason the
        # catch-up below is: a member that gets re-placed starts counting jobs from zero, and
        # that is a restart rather than a report going backwards.
        def majority_moved(v):
            return any(row.get("updatedAt", 0) > frozen[rid].get("updatedAt", 0)
                       for node in others
                       for rid, row in rt_rows(v, node).items() if rid in frozen)

        wait_until(majority_moved, CONVERGE_TIMEOUT,
                   "the connected side goes on writing while the split holds", nodes=others)

        print(f"    healing {victim} back into the mesh")
        ctl("heal", victim)
        # Twice the usual cap, because a mesh healed by reconnecting the interface comes back
        # slower than one that converges from a clean start: both sides still hold half-open TCP
        # to the node that was cut off (the reason scenario 8 recreates instead), so the links
        # have to time out on the keepalive before anyone re-dials and handshakes.
        wait_converge(ALL_NODES, CONVERGE_TIMEOUT * 2)

        # Catching up is what the hand-over owes, and no sample may go backwards on the way:
        # a snapshot arriving behind a delta would show up here as a row whose report is older
        # than the one this node already had.
        #
        # The report CLOCK is what says that, not the job counter. A fleet member that is
        # re-placed - which a partition can cause on its own - comes up as a fresh instance and
        # starts counting jobs from zero, so a counter going down is a member restarting and not
        # a state rolled back. Its clock still only moves forward, whoever writes the row.
        seen = dict(frozen)

        def caught_up(v):
            rows = rt_rows(v, victim)
            if len(rows) != WORKER_FLEET_SIZE:
                return False
            for rid, row in rows.items():
                before = seen.get(rid, {}).get("updatedAt", 0)
                now = row.get("updatedAt", 0)
                assert now >= before, \
                    f"{victim} row {rid} was rolled back after the heal: reported at {before}, then {now}"
                seen[rid] = row
            return all(row.get("updatedAt", 0) > frozen[rid].get("updatedAt", 0)
                       for rid, row in rows.items() if rid in frozen)

        wait_until(caught_up, CONVERGE_TIMEOUT + WORKER_REPORT_INTERVAL_SEC,
                   f"{victim} catches up with what was written while it was cut off")

        # And the mark comes off with the catch-up. It needs no expiry of its own: the handshake
        # says the deltas flow again, and the hand-over that follows repairs what the break cost,
        # so the two events that raise it are the two that clear it (Design D8).
        def nothing_marked_frozen(v):
            return rt_stale_rows(v, victim) == {}

        wait_until(nothing_marked_frozen, CONVERGE_TIMEOUT,
                   f"{victim} takes the frozen mark off once its owners are reachable again",
                   nodes=[victim])

        return (f"{victim} served its replica through the split with every row marked frozen, "
                f"caught up after the heal without going backwards, and cleared the mark")
    finally:
        # The reconnect is what this scenario is about, and it is also why the node cannot be
        # left as it is: `heal` puts the interface back with half-open TCP on both sides, and a
        # node in that state stops coming back from the next kill - which is what scenario 8
        # recreates for. So the assertions are made on the healed node, and then it is replaced
        # by a pristine one. A recreate also covers the path where an assertion above failed
        # with the partition still on.
        #
        # Twice the usual cap here as well, and for a longer wait than the heal above: a brand-new
        # container has no links at all, so all four peers have to notice the old ones die, re-dial
        # and handshake again. Under the plain cap this is what a loaded box fails on, and it fails
        # the scenarios AFTER this one rather than this one - they open on a mesh still settling.
        ctl("recreate", victim)
        wait_converge(ALL_NODES, CONVERGE_TIMEOUT * 2)


def scenario_14_rt_claim_refused():
    """A second owner of one runtime collection is named at the claim, and kept down (HIL-696).

    Everything before this ticket could only see a split once BOTH owners had written: a replica
    arrives, the receiver finds it writes those rows itself, drops the frame and says so — to
    nobody but itself, with no outcome and no name for either agent. A right, though, exists from
    the moment it is declared, and the leader is the only place two declarations ever meet.

    So the drill declares one. The claimer claims the WHOLE of the collection the fleet owns row
    by row, which is what makes it overlap: two whole rights over rows that intersect are the one
    shape the guard calls a conflict, while a co-owner short of an operation (HIL-688) and two
    agents naming different rows (HIL-589) are the arrangement working and must stay legal. It is
    also why the claimer writes nothing — the assertion at the end is that the fleet's rows came
    through untouched, and a second writer would have wrecked them on its way to being caught.

    Four things are asserted, and the last is the one with no other cover. That the leader named
    the clash, and that the node whose agent lost was told which of its agents it was — the two
    ends of the same verdict, counted separately because an administrator reads one journal or the
    other, never both. That the loser actually came down, which is read off the node's own claim
    rather than off the leader's table. And that the refusal is TERMINAL: the record survives the
    node confirming the stop it was given, and a second ask puts the agent on no node at all.
    Nothing else in the harness would notice if it came back — a re-placed loser simply moves the
    split to another node, quietly, and the collection goes on having two owners.

    Placed here, right after the fleet has been shown converging, because that is when the fleet
    holds its rows and there is a right to clash with. What it leaves behind is inert: a refused
    record the leader never re-places, and an agent nothing starts.
    """
    wait_fleet_rows()
    # Converged LAST, so the view the leader is read from is one a single leader is in charge of:
    # the row poll above says nothing about leadership, and the counters below are the leader's.
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    before_conflicts = rt_claim_conflicts(views, leader)
    before_refusals = {n: rt_claim_refusals(views, n) for n in ALL_NODES}

    print(f"    asking for {CLAIMER_AGENT_ID}, which claims all of {WORKER_STATUSES} the fleet owns by rows")
    assert client(leader, "test:cluster:agent:place", CLAIMER_AGENT_TYPE, CLAIMER_INDEX), \
        f"could not ask {leader} to place {CLAIMER_AGENT_ID}"

    def claim_refused(v):
        return placement_row(v, CLAIMER_AGENT_ID).get("state") == "refused"

    views = wait_until(claim_refused, CONVERGE_TIMEOUT,
                       f"the leader refuses the claim {CLAIMER_AGENT_ID} made")
    host = placement_row(views, CLAIMER_AGENT_ID).get("nodeId")
    assert host in SLAVES, f"{CLAIMER_AGENT_ID} was placed on {host}, which is not a data-plane node"
    assert rt_claim_conflicts(views, leader) > before_conflicts, \
        f"{leader} refused the claim without counting the clash it named"

    # The other end of the same verdict, and it travels its own frame: the node hosting the loser
    # has to be told, or the only account of why an agent stopped is in a journal on another host.
    wait_until(lambda v: rt_claim_refusals(v, host) > before_refusals[host], CONVERGE_TIMEOUT,
               f"{host} is told which of its agents lost the claim", nodes=[host])
    quiet = [n for n in ALL_NODES if n != host]
    views = inspect_all()
    for node in quiet:
        assert rt_claim_refusals(views, node) == before_refusals[node], \
            f"{node} was told about a claim of somebody else's agent"

    # And the loser is gone, read off the node rather than off the leader's table. While it ran,
    # its host claimed the WHOLE collection - the fleet's own members each claim a single row, so
    # nothing else on this stand makes that flag true - and a claim lives exactly as long as the
    # agent holding it. So the flag going back down is the stop having happened, not merely
    # having been ordered.
    wait_until(lambda v: rt_collection(v, host).get("fullyOwned") is False, CONVERGE_TIMEOUT,
               f"{host} stops claiming the whole of {WORKER_STATUSES}, so the loser is down",
               nodes=[host])

    # Terminal, and both halves of that are asserted because they fail apart. The record has to
    # survive the node confirming the stop it was just given - that report arrives seconds later
    # and would otherwise clear the placement as an ordinary revoke - and it has to survive being
    # asked for again, which is how any addressed frame would ask.
    time.sleep(POLICY_PLACEMENT_RETRY_SEC * 2)
    assert client(leader, "test:cluster:agent:place", CLAIMER_AGENT_TYPE, CLAIMER_INDEX), \
        f"could not ask {leader} for {CLAIMER_AGENT_ID} a second time"
    time.sleep(POLICY_PLACEMENT_RETRY_SEC)
    views = inspect_all()
    row = placement_row(views, CLAIMER_AGENT_ID)
    assert row.get("state") == "refused", \
        f"{CLAIMER_AGENT_ID} came back as {row.get('state')!r} on {row.get('nodeId')!r}: the refusal did not hold"

    # And the fleet is where it was, with every row it wrote. The split cost the collection
    # nothing, which is the whole promise: the owner working correctly is never disturbed.
    assert fleet_started(views), f"the fleet did not survive the refused claim: {summarize(views)}"
    views = wait_until(fleet_rows_where_read, CONVERGE_TIMEOUT + WORKER_REPORT_INTERVAL_SEC,
                       "every node reading the collection holds a row for every fleet member again")

    # Frames refused as a two-owner split are expected WHILE the second right stands - the claim
    # goes out on the same pass that offers a snapshot, and the verdict comes back after it. What
    # says the split is over rather than merely noticed is that the count stops moving.
    settled = {n: rt_refused(views, n) for n in ALL_NODES}
    time.sleep(WORKER_REPORT_INTERVAL_SEC * 2)
    still = inspect_all()
    for node in ALL_NODES:
        assert rt_refused(still, node) == settled[node], \
            f"{node} is still refusing RT frames after the second owner was stopped"

    return (f"{leader} named the clash {CLAIMER_AGENT_ID} made on {host}, kept it down across a "
            f"second ask, and the fleet kept all {WORKER_FLEET_SIZE} rows")


def scenario_11_cross_node_db_fact():
    """A row one node changed must be seen changed by every other (HIL-670, HIL-712).

    The defect this closes is the quietest of the set. The nodes share a database but not the
    rows they have read out of it: each keeps its own copy in memory, so a row one node changed
    stayed as another node first read it, for the life of that process. Nothing fails, nothing is
    logged, and a person sees a rename that "did not happen".

    Until HIL-712 it could only be drilled at one remove. Every node of this stand carried a
    schema of its own, so no row was ever a row two nodes were both about, and what was asserted
    was that the FACT crossed - announced against a row id that exists nowhere, which is what
    test:cluster:db:announce and scenario 15 still do. The stand shares one schema now, and this
    scenario asserts the thing itself: one node writes a value and another answers with it.

    The second tact is the proof; the first is only its setup. A first read is allowed to be
    right for the boring reason - the watcher held no row, so it went to the database for one.
    What cannot happen by accident is the second: the watcher HAS a copy now, that copy says v1,
    and it has to answer v2. That is the announcement crossing the mesh and a copy being dropped
    on purpose.

    The third tact is the old second half of this scenario, and the case the re-read on link
    exists for. Delivery is best-effort, so whatever was announced while a node was away is
    simply lost; the watcher is recreated, allowed to take a fresh copy, and then has to follow
    one more write - which says the channel carries facts again rather than merely that the
    database can still be read.

    One assertion is not about the watcher at all: the sender must not take in its own writes as
    replicas. A node that did would apply its own frame back over the row it had just written,
    which is a loop rather than a sync, and no other scenario asks it - scenario 15 counts every
    node before its announcements but judges only the receivers.

    The sender is the leader, where the automatically started journal reads receipts. Each read
    command on the watcher creates and deletes an empty CLI receipt: lawful remote facts that
    increase the sender's total. Count settings separately, with exact receiver deltas too, so
    receipt traffic cannot impersonate a loop or hide an extra settings fact (HIL-1621).
    """
    views = wait_converge(ALL_NODES)
    sender = leaders(views)[0]
    watcher = next(node for node in MASTERS if node != sender)
    views = wait_until(lambda current: "changeLogReceipts" in db_collections_read(current, sender),
                       CONVERGE_TIMEOUT, f"{sender}'s journal reads receipts before the DB probe")

    sender_replicas_before = db_replicas(views, sender, "settings")
    watcher_replicas_before = db_replicas(views, watcher, "settings")
    sender_total_before = db_replicas(views, sender)

    # Tact one. The watcher answers because it went and read the row, and by answering it now
    # holds a copy of it - which is the only thing this tact establishes.
    assert client(sender, "test:cluster:db:write", DB_PROBE_KEY, "v1"), \
        f"could not write the probe row on {sender}"
    wait_db_value(watcher, DB_PROBE_KEY, "v1", CONVERGE_TIMEOUT,
                  f"{watcher} answers with the value {sender} wrote")

    # Tact two, and the whole scenario: a copy that says v1 has to come back saying v2.
    assert client(sender, "test:cluster:db:write", DB_PROBE_KEY, "v2"), \
        f"could not rewrite the probe row on {sender}"
    wait_db_value(watcher, DB_PROBE_KEY, "v2", CONVERGE_TIMEOUT,
                  f"the copy {watcher} already held goes stale and comes back as v2")

    # Both writes have reached the watcher by now, so whatever the sender was going to count for
    # them it has counted. Receipt facts from the watcher's reads belong to another collection.
    views = inspect_all()
    assert db_replicas(views, watcher, "settings") == watcher_replicas_before + 2, \
        f"{watcher} did not accept exactly the two settings facts {sender} wrote"
    assert db_replicas(views, sender, "settings") == sender_replicas_before, \
        f"{sender} counted its own writes as replicas; a fact must not come back to its raiser"
    print(f"    {sender} accepted {db_replicas(views, sender) - sender_total_before} other DB facts; "
          "settings replicas stayed unchanged", flush=True)

    # Tact three: a link that went away and came back.
    print(f"    recreating {watcher} to break and re-establish its links")
    ctl("recreate", watcher)
    wait_converge(ALL_NODES)

    # The container is new, so its copy is taken here rather than inherited - and only once it
    # holds one is the write below asking anything of the mesh.
    wait_db_value(watcher, DB_PROBE_KEY, "v2", CONVERGE_TIMEOUT,
                  f"the recreated {watcher} takes a copy of the row")
    watcher_replicas_before = db_replicas(inspect_all([watcher]), watcher, "settings")
    assert client(sender, "test:cluster:db:write", DB_PROBE_KEY, "v3"), \
        f"could not write the probe row on {sender} after the reconnect"
    wait_db_value(watcher, DB_PROBE_KEY, "v3", CONVERGE_TIMEOUT,
                  f"{watcher} follows a write made after it re-linked")

    views = inspect_all()
    assert db_replicas(views, watcher, "settings") == watcher_replicas_before + 1, \
        f"{watcher} did not accept exactly one settings fact after re-linking"
    assert db_replicas(views, sender, "settings") == sender_replicas_before, \
        f"{sender} counted its own write after the reconnect as a replica"

    return (f"{sender} wrote the row and {watcher} answered with it, answered the rewrite out of "
            f"a copy it already held, and followed one more write after re-linking")


def scenario_15_db_interest_addressing():
    """A database fact takes a hop only to the nodes that read the collection it names (HIL-750).

    Every DB fact used to go to everybody, and the reasoning behind that was sound as far as it
    went: the rows live in the one database all the nodes share, so no node is owed a copy of a
    row and there is nobody in particular to address. What it missed is the other side of the
    same fact - a node holding none of a collection has nothing to apply the announcement into,
    so the hop was work no receiver could use. The reader map that already addressed the runtime
    frames now addresses these too, off the interest each node announces for itself.

    This is the only place that map's database half can be watched from outside, which is why the
    node's own list of read collections is asserted first: without it a green run below would be
    just as consistent with a mesh that has stopped carrying database facts altogether.

    Both halves ride on one pair of announcements, and their ORDER is what makes the negative
    honest. The unread collection is announced first and the read one second, from the same node,
    over the same links; so by the time the second fact has landed everywhere, the first has had
    at least that long to land too. Each receiver's counter moving by exactly one is then the
    assertion that it never came - a "nothing happened" with a bound on it rather than a sleep.

    Nothing is written here, and unlike scenario 11 that is the point rather than a limitation:
    the row id names a row that exists nowhere, so no node's copy of either collection is
    disturbed and the counters below move for the announcement alone.
    """
    # The unread half is the image variants: online-testing, the stand that carries this, reads
    # 'verifications' since it signs people in, and no node of it runs the images agent.
    read_key, unread_key = "settings", "fileVariants"
    sender, row_id = MASTERS[0], "999999"
    receivers = [n for n in ALL_NODES if n != sender]
    wait_converge(ALL_NODES)

    views = inspect_all()
    for node in ALL_NODES:
        reads = db_collections_read(views, node)
        assert read_key in reads, \
            f"{node} does not report reading '{read_key}', which the framework reads in every process: {reads}"
        assert unread_key not in reads, \
            f"{node} reports reading '{unread_key}', so it is no longer the unread half of this drill: {reads}"

    before = {n: db_replicas(views, n) for n in ALL_NODES}
    assert client(sender, "test:cluster:db:announce", unread_key, row_id), \
        f"could not announce the unread collection on {sender}"
    assert client(sender, "test:cluster:db:announce", read_key, row_id), \
        f"could not announce the read collection on {sender}"

    views = wait_until(lambda v: all(db_replicas(v, n) > before[n] for n in receivers),
                       CONVERGE_TIMEOUT,
                       f"every node but {sender} takes in the fact about '{read_key}'",
                       nodes=receivers)

    for node in receivers:
        moved = db_replicas(views, node) - before[node]
        assert moved == 1, \
            (f"{node} took in {moved} facts where one was addressed to it: the announcement about "
             f"'{unread_key}', which no node reads, was carried across the mesh anyway")
        last = (views.get(node) or {}).get("lastDbReplicaCollection")
        assert last == read_key, \
            f"the last fact {node} took in was about '{last}' rather than '{read_key}'"

    return (f"'{read_key}' reached all {len(receivers)} other nodes and '{unread_key}', "
            f"which none of them reads, reached none of them")


def scenario_16_recreated_node_leaves_no_phantom_fleet():
    """A data-plane container replaced faster than the failover grace leaves no phantom fleet (HIL-719).

    The state this closes was stable and silent. A recreated container comes back inside the
    grace, so no failover ever fires for it - and it used to come back saying nothing at all,
    because a node with an empty hosted set skipped its placement report. The leader therefore
    kept every agent of that node written down as `started` while not one of them ran anywhere:
    a dashboard showing the record of work instead of the work.

    So the assertion is deliberately not about the leader's table alone - the table is what
    lied. Every node the table NAMES has to be holding the collection its fleet members WRITE,
    which only a live member fills. Before the fix the victim came back with a placement table
    that read exactly right and a collection with no rows in it at all.

    What it deliberately does NOT assert is that the agents land back on the victim, because
    which node ends up with them is not this leaf's business and is not even the same answer
    twice. A recreate shuts the node down gracefully when it gets the chance, and then its
    agents report Stopped before the container goes: the leader forgets those records, and the
    framework's fleet supervisor places the members it no longer tracks onto the survivor while
    the victim is still down. When instead the node dies without a word, the records live on and
    the rejoin report is what clears them - and there the emptied node is the least loaded
    candidate, so its own share does come home. Both endings are healthy, and the one thing
    that must be true of either is what this scenario asks for: the fleet is running, and the
    leader is not naming a node that runs none of it.
    """
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before the recreate")
    # The victim is picked rather than named, so that a rerun after an earlier attempt moved the
    # fleet does not fail on the premise instead of on the assertion.
    carriers = sorted(n for n in SLAVES if hosted_by(views, n))
    assert carriers, "no data-plane node carries the fleet, so replacing one would prove nothing"
    victim = carriers[0]
    before_id = container_id(victim)
    assert before_id, f"could not read the container id of {victim}"
    lost = hosted_by(views, victim)

    print(f"    recreating {victim}, which carries {len(lost)} agent(s) the leader calls started")
    ctl("recreate", victim)
    wait_converge(ALL_NODES)

    after_id = container_id(victim)
    assert after_id and after_id != before_id, \
        f"{victim} was not replaced ({after_id[:12]} == {before_id[:12]}); the recreate did not take"

    wait_until(fleet_started, FAILOVER_TIMEOUT,
               f"the whole fleet started again after {victim} was replaced")

    # And the table has to be about running agents rather than about records of them: every node
    # it names is a node whose fleet members are writing their rows again.
    views = wait_fleet_rows()
    assert_table_names_running_nodes(views)

    hosts = ", ".join(f"{n}={len(hosted_by(views, n))}" for n in sorted(SLAVES))
    return (f"{victim} came back as {after_id[:12]} without the {len(lost)} agent(s) it had; the "
            f"whole fleet runs again ({hosts}) and every node the leader names writes its rows")


def scenario_17_foreign_certificate_refused():
    """A node certified by an authority the cluster does not trust is admitted by nobody (HIL-1034).

    Every link between nodes is mutual TLS against the cluster's own authority. The stranger
    passes its own start-up check - it trusts the authority that signed it - and dials every
    node it is seeded with. What is asserted is the fact, not a timer: the refusal is named in
    the log on BOTH ends. The stranger names it because it does not trust the certificate each
    node presents; the node it dialed names it with the stranger's address, because in TLS 1.3
    the accepting side is the one that learns a dialer was refused. Then nobody may list the
    stranger, and the five still form one cluster under one leader.

    Both ends are read by a file mark (node_log_mark), not an offset. The stranger starts
    inside this scenario, and starting its container moves its log to staging before the
    daemon's first line, so the daemon writes a fresh file. An offset of the old file read the
    new one past the refusal the stranger writes in its first moments, and the scenario went
    red on every run after the first one that left a log behind (HIL-1068). m1 does not
    restart; it is marked the same way so both ends are read alike.
    """
    wait_converge(ALL_NODES)
    dialed = MASTERS[0]
    marks = {n: node_log_mark(n) for n in (dialed, STRANGER)}
    print(f"    starting {STRANGER}, certified by an authority the cluster does not trust")
    ctl("stranger", "up")
    try:
        def refused_on_both_ends(_views):
            return (f"{TLS_REFUSAL_LINE}: {STRANGER_IP}" in node_log_since(dialed, marks[dialed])
                    and TLS_REFUSAL_LINE in node_log_since(STRANGER, marks[STRANGER]))

        views = wait_until(refused_on_both_ends, CONVERGE_TIMEOUT,
                           f"a refused TLS handshake named in the logs of {dialed} and {STRANGER}")

        for node in ALL_NODES:
            listed = [n for n in (views.get(node) or {}).get("nodes", []) if n.get("nodeId") == STRANGER]
            assert listed == [], f"{node} lists the stranger {STRANGER}: {listed}"
        assert not node_online(views, STRANGER), f"the leader lists {STRANGER} online"
        assert converged(ALL_NODES)(views), \
            f"the {in_words(len(ALL_NODES))} no longer form one cluster under one leader: {summarize(views)}"
    finally:
        ctl("stranger", "down")

    previous = (f"over a previous log of {marks[STRANGER].size} bytes"
                if marks[STRANGER].inode is not None else "with no previous log")
    return (f"{STRANGER} started {previous} and was refused on both ends of the link; nobody "
            f"lists it; the {in_words(len(ALL_NODES))} still converge")


# Numbered by when they were written, ORDERED by what they need. The RT scenarios and
# scenario 19 run right after placement, while the fleet the leader just placed is still alive
# and spread over both slaves: they need running agents rather than a converged topology. That order was once
# forced on them - a recreated data-plane container came back without its agents and the leader
# went on calling them started (P-152), so everything after scenario 9 met a dead fleet. Since
# HIL-719 it does not: the returning node reports its empty hosted set, the fleet goes back to
# work, and the table names no node that runs none of it - which is what scenario 16 asserts.
# The order is left exactly as it was all the same, because moving a scenario moves its timing
# with it and none of them is owed a different place. Everything from 4 on perturbs the
# topology and cares nothing about who is writing.
#
# 14 also has to come after 12 rather than before it: it deliberately makes a second owner of the
# collection, and while that stands the nodes refuse each other's frames - which is exactly the
# count 12 asserts is zero. 20 stands between 12 and 14 for the same reason: it asserts that no
# counter of refusals moved.
def scenario_18_capacity_is_consumed():
    """Placement spends what a node declares, and stops when it is spent (HIL-448).

    The slaves declare different stocks of ram (s1 ram=10, s2 ram=4), the masters declare none,
    and each ballast costs ram=2 and requires no tag. Eight are asked for, one at a time, from the
    leader. What each assertion proves:

    - s1 holds 5 and s2 holds 2: the spread follows the declared stock (10:4), not the head count.
      A head count would have put the eight on every node;
    - no master holds any: a ballast requires no tag, so the only thing keeping it off a master is
      the rule that a node declaring no capacity accepts no placed work;
    - hilos_probe_ballast:7 has exactly one Unplaced record with nodeId=null, still visible
      across two retry intervals and a second ask: both slaves are full, so it waits for room;
    - the leader says why all the same (HIL-447): its last refusal of hilos_probe_ballast:7 names
      every node, each by the first gate it failed - a master for declaring no capacity at all,
      a slave for being short of ram, cost 2 against what its stock has left;
    - periodic attempts are logged across two intervals, with one waiting entry. A second ask
      shares that interval and keeps one record and the same structural refusal.

    The split does not depend on the order either: seven fit and seven are placed, and a slave
    never takes more than its stock - on a stand of a whole demo the head count includes the
    demo's own agents, so the order in which the slaves fill is the stand's, the 5/2 at the end is
    not. Placed LAST because the ballasts stay up and hold capacity, and every earlier scenario is
    written against a stand without them.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    ids = [f"{BALLAST_AGENT_TYPE}:{i}" for i in range(BALLAST_ASKED)]

    print(f"    asking {leader} for {BALLAST_ASKED} ballasts of ram={BALLAST_RAM_COST}, one at a time")
    for i in range(BALLAST_ASKED):
        assert client(leader, "test:cluster:agent:place", BALLAST_AGENT_TYPE, str(i)), \
            f"could not ask {leader} to place {ids[i]}"

    fit = sum(ram // BALLAST_RAM_COST for ram in SLAVE_RAM.values())

    def ballasts_started(v):
        rows = [placement_row(v, agent_id) for agent_id in ids]
        return sum(1 for row in rows if row.get("state") == "started") == fit

    views = wait_until(ballasts_started, CONVERGE_TIMEOUT, f"{fit} ballasts started")

    def layout(v):
        placed = {}
        for agent_id in ids:
            row = placement_row(v, agent_id)
            if row.get("state") in LIVE_PLACEMENT_STATES:
                placed[row.get("nodeId")] = placed.get(row.get("nodeId"), 0) + 1
        return placed

    placed = layout(views)
    for slave, ram in SLAVE_RAM.items():
        assert placed.get(slave, 0) == ram // BALLAST_RAM_COST, \
            f"{slave} (ram={ram}) holds {placed.get(slave, 0)} ballasts, not {ram // BALLAST_RAM_COST}: {placed}"
    for master in MASTERS:
        assert master not in placed, f"{master} declares no capacity yet holds {placed[master]} ballasts"
    last = ids[BALLAST_ASKED - 1]
    def waiting_row(v, agent_id):
        rows = [row for row in leader_placements(v) if row.get("agentId") == agent_id]
        assert len(rows) == 1, f"{agent_id} has {len(rows)} records, not one: {rows}"
        row = rows[0]
        assert row.get("state") == "unplaced" and row.get("nodeId") is None, \
            f"{agent_id} must wait without a host while both slaves are full: {row}"
        # The inspect CLI prints an empty shortfalls map as []; match placement_refusal().
        refusals = [dict(refusal, shortfalls=refusal.get("shortfalls") or {})
                    for refusal in row.get("refusals") or []]
        return dict(row, refusals=refusals)

    waiting_row(views, last)

    # Why, node by node, in the order of their ids: a master declares nothing, so it fails the
    # declaration before its room is ever asked about; a slave declares ram and has left only what
    # no ballast fits in. The slots the slaves declare are far from spent, so ram is all they lack.
    expected = [node_refusal(node, REFUSAL_NO_DECLARED_CAPACITY) if node in MASTERS
                else node_refusal(node, REFUSAL_INSUFFICIENT_CAPACITY, shortfalls={
                    RESOURCE_RAM: (BALLAST_RAM_COST, SLAVE_RAM.get(node, 0) % BALLAST_RAM_COST)})
                for node in sorted(ALL_NODES)]
    refusal = placement_refusal(views, last)
    assert refusal is not None, \
        f"{leader} keeps no refusal of {last}"
    assert refusal.get("refusals") == expected, \
        f"{leader} refused {last} for {refusal.get('refusals')}, not {expected}"

    row = waiting_row(views, last)
    assert row.get("reason") == refusal.get("reason") and row.get("refusals") == expected, \
        f"{last} waiting record and refusal disagree: {row}, {refusal}"

    # Full stays full and the queue remains visible at both retry intervals. An explicit ask
    # shares the leader's interval; it need not cause another attempt at this instant.
    mark = node_log_mark(leader)
    retry_line = f"Placement retry: '{last}' trigger=periodic"
    for interval in range(2):
        time.sleep(POLICY_PLACEMENT_RETRY_SEC)
        views = inspect_all()
        row = waiting_row(views, last)
        assert row.get("reason") == refusal.get("reason") and row.get("refusals") == expected, \
            f"{last} lost its diagnosis after retry interval {interval + 1}: {row}"
        assert layout(views) == placed, f"the ballast layout moved from {placed} to {layout(views)}"

    def retried_twice(v):
        waiting_row(v, last)
        return node_log_since(leader, mark).count(retry_line) >= 2

    views = wait_until(retried_twice, CONVERGE_TIMEOUT, f"two periodic attempts of waiting {last}")
    assert client(leader, "test:cluster:agent:place", BALLAST_AGENT_TYPE, str(BALLAST_ASKED - 1)), \
        f"could not ask {leader} for {last} a second time"
    views = inspect_all()
    waiting_row(views, last)
    assert layout(views) == placed, f"the ballast layout moved from {placed} to {layout(views)}"
    assert placement_refusal(views, last) == refusal, \
        f"the second ask left {placement_refusal(views, last)} as the refusal of {last}, not {refusal}"
    assert f"Placement waiting: '{last}'" not in node_log_since(leader, mark), \
        f"{leader} logged the same waiting entry again on retry"

    return (f"{leader} placed {fit} ballasts as {placed} in proportion to {SLAVE_RAM}, none on a "
            f"master; {last} kept one Unplaced/null record and its diagnosis across two retries "
            "and a repeated ask")


def scenario_43_waiting_work_retries_when_a_node_returns():
    """Waiting demand resumes when a capable node returns, with no new ask (HIL-446).

    Both slaves leave after the ordinary fleet starts. The lone master declares no capacity,
    so one uniquely indexed ballast must wait Unplaced with a null host and the gate's diagnosis.
    A repeated ask must keep one record. After one slave returns only inspect and logs observe
    the target: neither polling nor the fleet supervisor asks for this ballast. A full restart
    retains the database, restores both slaves and removes this extra demand before 37 and 18.
    """
    wait_converge(ALL_NODES)
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the ordinary fleet started before both slaves leave")
    leader = leaders(views)[0]
    assert MASTERS == [leader], f"scenario 43 needs one stable master, got {MASTERS}"
    assert not declared_capabilities(views, leader), \
        f"{leader} must declare no capacity: {declared_capabilities(views, leader)}"
    index = f"waiting-{uuid.uuid4().hex}"
    agent_id = f"{BALLAST_AGENT_TYPE}:{index}"
    returning = SLAVES[0]
    expected = [node_refusal(leader, REFUSAL_NO_DECLARED_CAPACITY)]
    reason = "no capable and online node fits the agent"
    mark = node_log_mark(leader)

    def assert_waiting(v):
        rows = [row for row in leader_placements(v) if row.get("agentId") == agent_id]
        assert len(rows) == 1, f"{agent_id} must have exactly one waiting record: {rows}"
        row = rows[0]
        assert row.get("state") == "unplaced" and row.get("nodeId") is None, \
            f"{agent_id} must wait without a host while both slaves are offline: {row}"
        # The inspect CLI prints an empty shortfalls map as []; match placement_refusal().
        refusals = [dict(refusal, shortfalls=refusal.get("shortfalls") or {})
                    for refusal in row.get("refusals") or []]
        assert row.get("reason") == reason and refusals == expected, \
            f"{agent_id} lost the no-capacity master's diagnosis: {row}"
        refusal = placement_refusal(v, agent_id)
        assert refusal is not None and refusal.get("reason") == reason and refusal.get("refusals") == expected, \
            f"{agent_id} waiting record and last refusal disagree: {row}, {refusal}"
        assert live_on(v, leader) == 0, f"{leader} declares no capacity yet holds live placements"
        return refusal

    try:
        print(f"    stopping both slaves {SLAVES}; {leader} has no declared capacity")
        for slave in SLAVES:
            ctl("kill", slave)
        wait_until(lambda v: leaders(v) == [leader] and all(not node_online(v, slave) for slave in SLAVES),
                   CONVERGE_TIMEOUT, "both slaves offline in the lone master's roster", nodes=[leader])

        print(f"    asking {leader} for {agent_id}, then repeating once while it waits")
        assert client(leader, "test:cluster:agent:place", BALLAST_AGENT_TYPE, index), \
            f"could not ask {leader} to place {agent_id}"
        views = wait_until(lambda v: placement_row(v, agent_id).get("state") == "unplaced",
                           CONVERGE_TIMEOUT, f"{agent_id} Unplaced with both slaves offline", nodes=[leader])
        refusal = assert_waiting(views)
        assert client(leader, "test:cluster:agent:place", BALLAST_AGENT_TYPE, index), \
            f"could not repeat the ask for {agent_id}"
        views = inspect_all([leader])
        assert assert_waiting(views) == refusal, f"the repeated ask changed the diagnosis of {agent_id}"
        waiting_line = f"Placement waiting: '{agent_id}'"
        assert node_log_since(leader, mark).count(waiting_line) == 1, \
            f"{leader} must log one waiting entry for {agent_id}"

        print(f"    returning {returning}; observing {agent_id} without asking for it again")
        ctl("start", returning)
        views = wait_until(lambda v: node_online(v, returning)
                           and placement_row(v, agent_id).get("state") == "started",
                           CONVERGE_TIMEOUT, f"waiting {agent_id} starts after {returning} returns",
                           nodes=[leader, returning])
        rows = [row for row in leader_placements(views) if row.get("agentId") == agent_id]
        assert len(rows) == 1 and rows[0].get("nodeId") == returning, \
            f"{agent_id} must have one Started record on the returning slave {returning}: {rows}"
        assert placement_refusal(views, agent_id) is None, f"{agent_id} kept its refusal after starting"
        tail = node_log_since(leader, mark)
        assert tail.count(waiting_line) == 1, f"{leader} logged duplicate waiting entries for {agent_id}"
        resumed_line = f"Placement resumed: '{agent_id}' on '{returning}'"
        assert tail.count(resumed_line) == 1, f"{leader} never logged one resumed entry: {resumed_line}"
    finally:
        evidence = [line for line in node_log_since(leader, mark).splitlines() if agent_id in line]
        print(f"    {agent_id} leader log since marker:\n" + "\n".join(evidence))
        print("    restarting the stand on its own compose file")
        code = control.restart(STAND, f"{sys.argv[0]} {STAND.compose}")
        assert code == 0, f"the stand did not come back up after scenario 43 (exit {code})"
        wait_converge(ALL_NODES)
        wait_until(lambda v: fleet_started(v)
                   and not any(str(row.get("agentId", "")).startswith(BALLAST_AGENT_TYPE + ":")
                               for row in leader_placements(v))
                   and placement_refusal(v, agent_id) is None,
                   CONVERGE_TIMEOUT, "both slaves restored, ordinary fleet started and extra ballast demand gone")

    return (f"{agent_id} kept one Unplaced/null record and its diagnosis across two asks, then started "
            f"on returning {returning} without new demand; waiting/resumed logged once; full stand restored")


def scenario_37_costless_agents_consume_slots():
    """Agents that declare no cost still take a slot each, and the slots run out (HIL-447).

    A fleet member declares no cost of its own, so before HIL-447 a node took any number of them.
    Now placement charges every placed agent one of the `slots` its node declares. Both slaves are
    recreated declaring COSTLESS_SLOTS slots beside their own tag and ram, and once the stand has
    settled more fleet members are asked for, one at a time, under indexes far from the fleet's own
    0..9, each through a slave: a node that does not lead forwards the ask, so every answer is a
    verdict sent back over the mesh. What each assertion proves:

    - no slave ever holds more live placements than its slots, whatever the leader is asked: the
      demo's own placed agents, the fleet and the extra members each take one;
    - the extra members asked while slots were free really run: started, and writing their rows;
    - the first one asked once both slaves are full is refused for want of a slot on each - cost 1,
      free 0 - and the master for lacking the tag the fleet requires; it keeps exactly one
      Unplaced record with nodeId=null and the same structural diagnosis;
    - the slave that asked took the verdict carrying those refusals without dropping its link.

    Nothing is said about ram: the fleet costs none. Freeing a slot and asking again is the unit
    tests' to prove; this proves the ceiling and why it refuses. The stand is put back by a full
    restart on its own compose file, which drops the overrides and the extra members together:
    recreating the slaves alone would have the leader place the extra members again.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    asker = SLAVES[0]
    extras = []
    refused = None
    try:
        for slave in SLAVES:
            declared = [CAPABILITY_WORKER, f"{RESOURCE_SLOTS}={COSTLESS_SLOTS}"]
            if slave in SLAVE_RAM:
                declared.append(f"{RESOURCE_RAM}={SLAVE_RAM[slave]}")
            assignment = f"CLUSTER_NODE_CAPABILITIES={','.join(declared)}"
            print(f"    recreating {slave} with {assignment}")
            outcome = control.execute(STAND, "own-env", slave, assignment)
            assert outcome.code == 0, f"could not give {slave} slots of its own: {outcome.err}"

            def ready(v, node=slave):
                return (converged(ALL_NODES)(v)
                        and f"{RESOURCE_SLOTS}={COSTLESS_SLOTS}" in declared_capabilities(v, node))

            wait_until(ready, CONVERGE_TIMEOUT, f"{slave} back online declaring {COSTLESS_SLOTS} slots")

        # The demo's own placed agents come back on the policy's sweep rather than all at once, so
        # the placements are read only once they have held still for two of its intervals.
        steady = {"rows": None, "since": 0.0}

        def settled(v):
            rows = {row["agentId"]: (row.get("nodeId"), row.get("state")) for row in leader_placements(v)
                    if row.get("state") in LIVE_PLACEMENT_STATES}
            if rows != steady["rows"]:
                steady["rows"], steady["since"] = rows, time.time()
                return False
            return (fleet_started(v) and all(state == "started" for _node, state in rows.values())
                    and time.time() - steady["since"] >= POLICY_PLACEMENT_RETRY_SEC * 2)

        views = wait_until(settled, CONVERGE_TIMEOUT, "the fleet started and every live placement still")
        free = {slave: COSTLESS_SLOTS - live_on(views, slave) for slave in SLAVES}
        assert all(slots >= 0 for slots in free.values()), \
            f"a slave holds more placements than its {COSTLESS_SLOTS} slots before anything was asked: {free}"
        assert sum(free.values()) > 0, \
            f"the slaves have no free slot to fill before anything was asked ({free}), so the ceiling would prove nothing"

        def runs(v, agent_id):
            """Started by the leader's table, and writing its row on the node the table names."""
            row = placement_row(v, agent_id)
            return row.get("state") == "started" and agent_id.split(":", 1)[1] in rt_rows(v, row.get("nodeId"))

        mark = {node: node_log_mark(node) for node in (leader, asker)}
        print(f"    asking {asker} for more of {WORKER_AGENT_TYPE} than the {sum(free.values())} free slots of {free}")
        for index in EXTRA_FLEET_INDEXES:
            agent_id = f"{WORKER_AGENT_TYPE}:{index}"
            assert client(asker, "test:cluster:agent:place", WORKER_AGENT_TYPE, str(index)), \
                f"could not ask {asker} to place {agent_id}"

            def answered(v, agent_id=agent_id):
                return runs(v, agent_id) or placement_refusal(v, agent_id) is not None

            views = wait_until(answered, CONVERGE_TIMEOUT, f"{agent_id} running, or the leader's refusal of it")
            held = {slave: live_on(views, slave) for slave in SLAVES}
            assert all(count <= COSTLESS_SLOTS for count in held.values()), \
                f"a slave holds more live placements than its {COSTLESS_SLOTS} slots after {agent_id}: {held}"
            if runs(views, agent_id):
                extras.append(agent_id)
                continue
            refused = agent_id
            break
        assert refused is not None, \
            f"all {len(EXTRA_FLEET_INDEXES)} extra fleet members were placed: the slots capped nothing"
        assert extras, f"{refused}, the first one asked, was refused although {free} slots were free"

        # Refused where the slots ran out, and for that alone: each slave short of one slot, the
        # master turned down by the first gate, for the tag the fleet requires.
        expected = [node_refusal(node, REFUSAL_MISSING_CAPABILITIES, missing=[CAPABILITY_WORKER]) if node in MASTERS
                    else node_refusal(node, REFUSAL_INSUFFICIENT_CAPACITY, shortfalls={RESOURCE_SLOTS: (1, 0)})
                    for node in sorted(ALL_NODES)]
        refusal = placement_refusal(views, refused)
        assert refusal.get("refusals") == expected, \
            f"{leader} refused {refused} for {refusal.get('refusals')}, not {expected}"
        waiting = [row for row in leader_placements(views) if row.get("agentId") == refused]
        assert len(waiting) == 1, f"{refused} must have exactly one waiting record: {waiting}"
        row = waiting[0]
        assert row.get("state") == "unplaced" and row.get("nodeId") is None, \
            f"{refused} has no room and must wait without a host: {row}"
        # The inspect CLI prints an empty shortfalls map as []; match placement_refusal().
        refusals = [dict(node, shortfalls=node.get("shortfalls") or {})
                    for node in row.get("refusals") or []]
        assert row.get("reason") == refusal.get("reason") and refusals == expected, \
            f"{refused} waiting record and refusal disagree: {row}, {refusal}"
        held = {slave: live_on(views, slave) for slave in SLAVES}
        assert all(count == COSTLESS_SLOTS for count in held.values()), \
            f"{refused} was refused for want of a slot while the slaves hold {held} of {COSTLESS_SLOTS}"
        stopped = [agent_id for agent_id in extras if not runs(views, agent_id)]
        assert not stopped, f"extra fleet members stopped running once the slots ran out: {stopped}"

        assert ANSWERING_ASK_LINE.format(refused, "unplaced") in node_log_since(leader, mark[leader]), \
            f"{leader} never answered {asker}'s ask for {refused}"
        dropped = [line for line in node_log_since(asker, mark[asker]).splitlines() if PEER_LINK_DROPPED_LINE in line]
        assert not dropped, f"{asker} dropped a peer link while it was answered: {dropped}"
        assert node_online(views, asker), f"{asker} fell out of the cluster while it was answered"
    finally:
        print("    restarting the stand on its own compose file")
        code = control.restart(STAND, f"{sys.argv[0]} {STAND.compose}")
        assert code == 0, f"the stand did not come back up after scenario 37 (exit {code})"
        wait_converge(ALL_NODES)

        def restored(v):
            return (fleet_started(v)
                    and not any(f"{RESOURCE_SLOTS}={COSTLESS_SLOTS}" in declared_capabilities(v, slave)
                                for slave in SLAVES))

        wait_until(restored, CONVERGE_TIMEOUT, "the stand back on its own room with only its own fleet")

    return (f"{asker} asked for {len(extras)} more fleet member(s) and all ran ({held} live of "
            f"{COSTLESS_SLOTS} slots); {refused} was refused for want of a slot on {', '.join(SLAVES)}, "
            "with one Unplaced/null record")


def scenario_19_worker_death_on_live_node():
    """One dead worker is reported and only its fleet members are placed again (HIL-440)."""
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before a worker dies")
    victim = max(SLAVES, key=lambda slave: len(hosted_by(views, slave)))
    on_victim = hosted_by(views, victim)
    by_worker = fleet_workers_on(victim, on_victim)
    assert by_worker, f"{victim} carries {sorted(on_victim)} but its log names none of their workers"
    worker_index, lost = max(by_worker.items(), key=lambda item: len(item[1]))
    untouched = on_victim - lost
    victim_rows = rt_rows(views, victim)
    jobs_before = {
        agent_id: victim_rows[agent_id.split(":", 1)[1]].get("jobsDone", 0)
        for agent_id in untouched
    }
    offset = node_log_size(victim)
    killed_at = time.time()

    out = ctl_out("kill-worker", victim, str(worker_index))
    assert "SIGKILLed" in out, f"the worker kill did not report success: {out}"

    def worker_death_reported(_views):
        return any(int(match.group(1)) == worker_index
                   for match in WORKER_DIED_HOSTING.finditer(node_log(victim, offset)))

    try:
        wait_until(worker_death_reported, CONVERGE_TIMEOUT,
                   f"{victim} names the agents lost with worker #{worker_index}", nodes=[victim])
    except ScenarioTimeout:
        print(f"    {out.removeprefix('cluster: ')}")
        for line in worker_death_evidence(victim, offset, worker_index)[-EVIDENCE_LINES:]:
            print(f"    {victim}: {line}")
        raise
    reports = [match for match in WORKER_DIED_HOSTING.finditer(node_log(victim, offset))
               if int(match.group(1)) == worker_index]
    named = {agent_id.strip() for agent_id in reports[-1].group(2).split(",")
             if agent_id.strip().startswith(f"{WORKER_AGENT_TYPE}:")}
    assert named == lost, \
        f"the node named {sorted(named)} instead of {sorted(lost)} for worker #{worker_index}"

    def lost_members_run_again(v):
        updates = newest_row_updates(v)
        return (fleet_started(v)
                and all(updates.get(agent_id.split(":", 1)[1], 0) > int(killed_at)
                        for agent_id in lost))

    views = wait_until(lost_members_run_again, FAILOVER_TIMEOUT,
                       f"the members lost with worker #{worker_index} run again")
    assert node_online(views, victim), f"{victim} fell out of the cluster after one worker died"

    victim_rows = rt_rows(views, victim)
    for agent_id in untouched:
        assert placement_row(views, agent_id).get("nodeId") == victim, \
            f"{agent_id} moved off {victim} although its worker survived"
        row_id = agent_id.split(":", 1)[1]
        assert victim_rows[row_id].get("jobsDone", 0) >= jobs_before[agent_id], \
            f"{agent_id} reset its jobsDone although its worker survived"

    assert_table_names_running_nodes(views)
    spread = ", ".join(f"{node}={len(hosted_by(views, node))}" for node in SLAVES)
    return (f"worker #{worker_index} of {victim} died with {sorted(lost)}; the node named them, "
            f"the leader placed them again ({spread}), and {len(untouched)} member(s) on its "
            "other workers ran on untouched")


def scenario_20_rt_set_width_across_nodes():
    """Every node owns its own set of one RT collection, and the width holds across nodes (HIL-1116).

    The claim over a set is laid in the worker running the agent, and a write past the owner is
    refused by construction inside that one process. Here the owners of the sets of one collection
    sit on five nodes: each node's set probe owns the probe notes of its own node, and every write
    is driven through the ordinary runtime actions, so the door that judges it is the one every
    application write passes.

    (1) All five come up claiming a set of the same collection - owning it, and not whole - so no
        node's start is refused for its neighbours' declarations.
    (2) A note written by s1 into its own set reaches every node.
    (3) The same writes from s2 - an edit of that note, and a note created in set s1 - are refused
        in the words of the door, and neither goes anywhere. Proven by a fact, not by a pause:
        s2 then writes its own set, and since one node's frames arrive in order, a refused write
        that had gone out would be visible by the time that one is.
    (4) A master cut off from the network while s1 writes its set gets the new note after it is
        back - and only the hand-over of the set can bring it. The victim holds notes of the
        collection, so it asks nobody for what it is missing (HIL-823), and nobody but s1 hands
        set s1 over. Before this leaf that step was red: a set was handed over by no one.
    (5) No counter of refused frames or claims moved on any node.

    The victim is a master that does not lead, for the reason scenario 13 gives: a master holds no
    fleet, so cutting it off moves no placement. It is recreated afterwards, as scenarios 8 and 13
    do, because a healed interface leaves half-open links behind.
    """
    run = format(int(time.time() * 1000), "x")
    own, foreign, peer, late = (f"{prefix}-{run}" for prefix in (NOTE_OWN, NOTE_FOREIGN, NOTE_PEER, NOTE_LATE))

    # (0) The counters are read off a converged mesh, before anything is written.
    views = wait_converge(ALL_NODES)
    refused_before = {n: rt_refused(views, n) for n in ALL_NODES}
    claim_refusals_before = {n: rt_claim_refusals(views, n) for n in ALL_NODES}
    leader = leaders(views)[0]
    conflicts_before = rt_claim_conflicts(views, leader)
    victim = sorted(m for m in MASTERS if m not in leaders(views))[-1]

    # (1)
    def every_node_claims_a_set(v):
        return all(rt_collection(v, n, PROBE_NOTES).get("owned") is True
                   and rt_collection(v, n, PROBE_NOTES).get("fullyOwned") is False
                   for n in ALL_NODES)

    wait_until(every_node_claims_a_set, CONVERGE_TIMEOUT,
               f"every node's set probe owns its set of '{PROBE_NOTES}', and no node owns it whole")

    def note_on(note_id, node_id, text, nodes=ALL_NODES):
        expected = {"noteId": note_id, "nodeId": node_id, "text": text}
        return lambda v: all(rt_rows(v, n, PROBE_NOTES).get(note_id) == expected for n in nodes)

    # The two slaves write, each its own set: a set is named by the node id it belongs to.
    writer, peer_writer = SLAVES[0], SLAVES[1]

    # (2)
    refusal = client_refusal(writer, "test:cluster:rt:write", writer, own, "v1")
    assert refusal is None, f"{writer} was refused a write into its own set: {refusal}"
    wait_until(note_on(own, writer, "v1"), CONVERGE_TIMEOUT,
               f"the note {writer} wrote into its set reaches every node")

    # (3)
    for args, what in (((writer, own, "v2"), f"an edit of a note of set {writer}"),
                       ((writer, foreign, "x"), f"a note created in set {writer}")):
        refusal = client_refusal(peer_writer, "test:cluster:rt:write", *args)
        assert refusal is not None, f"{peer_writer} was let make {what}"
        assert f"it holds set '{peer_writer}'" in refusal and f"[{writer}]" in refusal, \
            f"{peer_writer} was refused {what}, but not in the words of the set door: {refusal}"

    refusal = client_refusal(peer_writer, "test:cluster:rt:write", peer_writer, peer, "p1")
    assert refusal is None, f"{peer_writer} was refused a write into its own set: {refusal}"
    views = wait_until(note_on(peer, peer_writer, "p1"), CONVERGE_TIMEOUT,
                       f"the note {peer_writer} wrote into its own set reaches every node")
    for n in ALL_NODES:
        rows = rt_rows(views, n, PROBE_NOTES)
        assert foreign not in rows, f"{n} holds the note {peer_writer} was refused to create in set {writer}"
        assert rows.get(own, {}).get("text") == "v1", \
            f"{n} holds the note of set {writer} as {rows.get(own)}, after {peer_writer} was refused its edit"

    # (4)
    others = [n for n in ALL_NODES if n != victim]
    print(f"    partitioning {victim} off the network while {writer} writes its set")
    ctl("partition", victim)
    try:
        # The mark is raised when the link closes, and a partitioned interface takes a keepalive
        # to notice - so the write below waits for the victim to say it is cut off.
        wait_until(lambda v: own in rt_stale_rows(v, victim, PROBE_NOTES), CONVERGE_TIMEOUT,
                   f"{victim} marks the note of set {writer} frozen once it can no longer reach {writer}",
                   nodes=[victim], local=True)

        refusal = client_refusal(writer, "test:cluster:rt:write", writer, late, "late")
        assert refusal is None, f"{writer} was refused a write into its own set: {refusal}"
        wait_until(note_on(late, writer, "late", others), CONVERGE_TIMEOUT,
                   f"the note {writer} wrote during the split reaches every node but {victim}", nodes=others)
        cut_off = rt_rows({victim: inspect_local(victim)}, victim, PROBE_NOTES)
        assert late not in cut_off, f"{victim} got the note written while it was cut off, before the heal"

        print(f"    healing {victim} back into the mesh")
        ctl("heal", victim)
        # Twice the usual cap, for the reason scenario 13 gives: both sides hold half-open TCP to
        # the node that was cut off, and the links time out on the keepalive before anyone re-dials.
        wait_converge(ALL_NODES, CONVERGE_TIMEOUT * 2)

        def caught_up(v):
            return (note_on(late, writer, "late", [victim])(v)
                    and note_on(own, writer, "v1", [victim])(v)
                    and note_on(peer, peer_writer, "p1", [victim])(v)
                    and rt_stale_rows(v, victim, PROBE_NOTES) == {})

        wait_until(caught_up, CONVERGE_TIMEOUT,
                   f"{victim} gets the note written while it was cut off, and nothing stays frozen",
                   nodes=[victim])

        # (5) Read on the healed victim, before the recreate below resets its counters.
        views = inspect_all()
        for n in ALL_NODES:
            refused, claims_refused = rt_refused(views, n), rt_claim_refusals(views, n)
            assert refused == refused_before[n], \
                f"{n} refused RT frames as a split: {refused_before[n]} before, {refused} after"
            assert claims_refused == claim_refusals_before[n], \
                f"the leader refused claims of {n}: {claim_refusals_before[n]} before, {claims_refused} after"
        assert rt_claim_conflicts(views, leader) == conflicts_before, \
            f"the leader {leader} named an RT ownership clash over the sets"

        return (f"{in_words(len(ALL_NODES))} nodes own their sets of '{PROBE_NOTES}'; {writer} wrote its "
                f"set, {peer_writer} was refused it in the door's words, and {victim}, cut off, got the "
                f"new note by the hand-over of set {writer}")
    finally:
        ctl("recreate", victim)
        wait_converge(ALL_NODES, CONVERGE_TIMEOUT * 2)


def scenario_35_rt_row_deleted_while_cut_off_is_swept():
    """A set owner removes a row while a neighbour is cut off; its next hand-over sweeps the
    neighbour's copy, even when the set is now empty (HIL-1178).

    The victim is a non-leading master, so cutting it off does not move the set probes on the
    slaves. After healing, a burst from the second set owner overlaps link convergence and
    continues for three writes after it. Those later deltas must survive the snapshot sweep.
    Before HIL-1178, the deleted rows remain on the victim. Recreate the master afterwards:
    a healed interface may leave half-open links that would color the next scenario.
    """
    run = format(int(time.time() * 1000), "x")
    swept_own = f"{NOTE_SWEPT_OWN}-{run}"
    swept_peer = f"{NOTE_SWEPT_PEER}-{run}"
    kept = f"{NOTE_KEPT}-{run}"

    views = wait_converge(ALL_NODES)
    refused_before = {n: rt_refused(views, n) for n in ALL_NODES}
    victim = sorted(m for m in MASTERS if m not in leaders(views))[-1]
    writer, peer_writer = SLAVES[0], SLAVES[1]

    def every_node_claims_a_set(v):
        return all(rt_collection(v, n, PROBE_NOTES).get("owned") is True
                   and rt_collection(v, n, PROBE_NOTES).get("fullyOwned") is False
                   for n in ALL_NODES)

    wait_until(every_node_claims_a_set, CONVERGE_TIMEOUT,
               f"every node owns its own set of '{PROBE_NOTES}', none owns the collection")

    for node, note_id in ((writer, swept_own), (peer_writer, swept_peer), (peer_writer, kept)):
        refusal = client_refusal(node, "test:cluster:rt:write", node, note_id, "before")
        assert refusal is None, f"{node} was refused a write into its own set: {refusal}"

    def starting_notes_arrived(v):
        return all(all(note_id in rt_rows(v, n, PROBE_NOTES)
                       for note_id in (swept_own, swept_peer, kept)) for n in ALL_NODES)

    wait_until(starting_notes_arrived, CONVERGE_TIMEOUT, "the three starting notes reach every node")

    print(f"    partitioning {victim} while {writer} and {peer_writer} remove notes")
    ctl("partition", victim)
    try:
        wait_until(lambda v: all(note_id in rt_stale_rows(v, victim, PROBE_NOTES)
                                 for note_id in (swept_own, swept_peer, kept)),
                   CONVERGE_TIMEOUT, f"{victim} marks the three notes frozen", nodes=[victim], local=True)

        for node, note_id in ((writer, swept_own), (peer_writer, swept_peer)):
            refusal = client_refusal(node, "test:cluster:rt:delete", note_id)
            assert refusal is None, f"{node} was refused removal of its own note {note_id}: {refusal}"

        others = [n for n in ALL_NODES if n != victim]
        wait_until(lambda v: all(swept_own not in rt_rows(v, n, PROBE_NOTES)
                                 and swept_peer not in rt_rows(v, n, PROBE_NOTES)
                                 and kept in rt_rows(v, n, PROBE_NOTES) for n in others),
                   CONVERGE_TIMEOUT, "the linked nodes drop both deleted notes", nodes=others)
        cut_off = rt_rows({victim: inspect_local(victim)}, victim, PROBE_NOTES)
        assert swept_own in cut_off and swept_peer in cut_off, \
            f"{victim} did not keep both deleted rows while cut off"

        print(f"    healing {victim} while {peer_writer} writes a burst")
        ctl("heal", victim)
        deadline = time.time() + CONVERGE_TIMEOUT * 2
        converged_at = None
        burst_ids = []
        while time.time() < deadline:
            note_id = f"{NOTE_BURST}-{run}-{len(burst_ids)}"
            refusal = client_refusal(peer_writer, "test:cluster:rt:write", peer_writer, note_id, "burst")
            assert refusal is None, f"{peer_writer} was refused burst note {note_id}: {refusal}"
            burst_ids.append(note_id)
            views = inspect_all()
            if converged_at is None and converged(ALL_NODES)(views):
                converged_at = len(burst_ids)
            elif converged_at is not None and len(burst_ids) >= converged_at + 3:
                break
        else:
            raise ScenarioTimeout(f"{victim} did not converge while {peer_writer} wrote burst notes")

        def caught_up(v):
            victim_rows = rt_rows(v, victim, PROBE_NOTES)
            peer_rows = rt_rows(v, peer_writer, PROBE_NOTES)
            victim_peer_set = {key: row for key, row in victim_rows.items() if row.get("nodeId") == peer_writer}
            owner_peer_set = {key: row for key, row in peer_rows.items() if row.get("nodeId") == peer_writer}
            return (swept_own not in victim_rows and swept_peer not in victim_rows
                    and kept in victim_rows and all(note_id in owner_peer_set for note_id in burst_ids)
                    and victim_peer_set == owner_peer_set
                    and rt_stale_rows(v, victim, PROBE_NOTES) == {})

        wait_until(caught_up, CONVERGE_TIMEOUT,
                   f"{victim} drops deleted notes, keeps every burst note, and thaws remaining notes",
                   nodes=[victim, peer_writer])
        views = inspect_all()
        for n in ALL_NODES:
            refused = rt_refused(views, n)
            assert refused == refused_before[n], \
                f"{n} refused RT frames as a split: {refused_before[n]} before, {refused} after"

        return (f"{victim} swept notes deleted by {writer} and {peer_writer}, kept the other set "
                f"and all {len(burst_ids)} burst notes, with no refused RT frames")
    finally:
        ctl("recreate", victim)
        wait_converge(ALL_NODES, CONVERGE_TIMEOUT * 2)


# The watchdog's line after it applied migrations on startup (DockerApplication), and the one a
# node writes while another holds the rollout claim (MigrationClaim).
APPLIED_ON_STARTUP = re.compile(r"Applied \d+ migration\(s\) on startup")
WAITING_FOR_CLAIM = "Waiting for the schema rollout claim"


def scenario_21_schema_rolled_out_once():
    """Every node of the stand, started together on an empty database, rolls the schema out once
    (HIL-1228).

    `cluster scenarios` gives this its setting: it wipes the database volume and starts every
    node of the stand at once, with no schema step of the stand in front of them. Each node's
    watchdog runs the migrations under the rollout claim in the database, so exactly one applies
    them and the rest find the level already there - and all of them then converge.

    What this proves is the outcome, not the race: whether the starts overlap is up to timing,
    and with this demo's few quick migrations they often do not - a node arriving after the
    rollout finds nothing to apply with or without the claim. Nodes racing on one claim are
    covered by MigrationClaimIntegrationTest, where the wait is driven rather than hoped for. So
    the lines of the nodes that waited are printed, not asserted.
    """
    wait_converge(ALL_NODES)
    logs = {node: container_log(node) for node in ALL_NODES}
    assert all(logs.values()), f"no container log for {[n for n, log in logs.items() if not log]}"

    applied = [node for node, log in logs.items() if APPLIED_ON_STARTUP.search(log)]
    for node, log in logs.items():
        waited = log.count(WAITING_FOR_CLAIM)
        if waited:
            print(f"  {node} waited for the claim ({waited} line(s))")
    assert len(applied) == 1, f"expected exactly one node to apply the schema on startup, got {applied}"
    return (f"{applied[0]} applied the schema; the other {in_words(len(ALL_NODES) - 1)} found it applied, "
            f"all {in_words(len(ALL_NODES))} in the cluster")


def journal_trigger_sources():
    """Read trigger names and coordinates from the project's active CREATE TRIGGER files.

    The production preflight owns canonical body validation; this independent oracle only
    reads the declaration, rather than duplicating JournalTriggerFile's body comparison.
    """
    directory = STAND.demo_dir / "backend/Database/Migration/Triggers"
    assert directory.is_dir(), f"journal trigger directory is missing: {directory}"
    expected = {}
    for path in sorted(directory.glob("*.sql")):
        declaration = re.search(r"^CREATE TRIGGER `([^`]+)` AFTER (INSERT|UPDATE|DELETE) ON `([^`]+)`\n"
                                r"FOR EACH ROW\b", path.read_text(encoding="utf-8"), re.MULTILINE)
        assert declaration is not None, f"no active journal trigger declaration in {path}"
        name, event, table = declaration.groups()
        assert name == path.stem, f"trigger {name} differs from filename {path.name}"
        assert name not in expected, f"duplicate journal trigger {name} in {directory}"
        expected[name] = (table, event, "AFTER")
    assert expected, f"no active journal trigger files in {directory}"
    return expected


def journal_trigger_catalog():
    """Snapshot every primary-schema trigger, including its complete server-reported body.

    db_sql uses MariaDB's batch output: embedded newlines and tabs in a body are escaped,
    so each trigger occupies one tab-separated line and can be kept verbatim across starts.
    """
    output = db_sql("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT "
                    "FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")
    assert output, "the primary trigger catalog is empty or could not be read"
    catalog = {}
    for line in output.splitlines():
        fields = line.split("\t", 4)
        assert len(fields) == 5, f"invalid trigger catalog row: {line!r}"
        name, table, event, timing, body = fields
        assert name not in catalog, f"duplicate live trigger {name}"
        catalog[name] = (table, event, timing, body)
    return catalog


def journal_trigger_ddl_status():
    """Global DDL counters of this fresh isolated MariaDB, read as its application user."""
    output = db_sql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_create_trigger','Com_drop_trigger')")
    counters = {}
    for line in output.splitlines():
        fields = line.split("\t")
        assert len(fields) == 2 and fields[1].isdigit(), f"invalid trigger DDL status row: {line!r}"
        name, value = fields
        assert name not in counters, f"duplicate trigger DDL counter {name}"
        counters[name] = int(value)
    assert set(counters) == {"Com_create_trigger", "Com_drop_trigger"}, \
        f"trigger DDL status is incomplete: {output!r}"
    return counters


def scenario_40_journal_triggers_are_installed_once():
    """Fresh concurrent startup installs the filed journal triggers once (HIL-1465).

    The catalog proves the exact set and its journal address; global DDL counters prove
    one CREATE per active source file and no DROP, including after an ordinary kill/start.
    This proves the outcome, not a forced scheduler race or a replicated database rollout.
    Claim-through-install is covered by MigrationClaimIntegrationTest together with
    JournalTriggerStartupIntegrationTest. Snapshots belong to this invocation alone.
    """
    wait_converge(ALL_NODES)
    wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet started before journal trigger checks")
    expected = journal_trigger_sources()
    before = journal_trigger_catalog()
    assert set(before) == set(expected), \
        f"live triggers differ from source files: missing {sorted(set(expected) - set(before))}, " \
        f"extra {sorted(set(before) - set(expected))}"
    journal = "`" + (STAND.database.name + "-change-log").replace("`", "``") + "`"
    for name, coordinates in expected.items():
        assert before[name][:3] == coordinates, f"wrong coordinates for {name}: {before[name][:3]}"
        assert f"{journal}.`hilos_change_log`" in before[name][3], \
            f"{name} does not address the derived journal {journal}: {before[name][3]}"
    counters = journal_trigger_ddl_status()
    assert counters == {"Com_create_trigger": len(expected), "Com_drop_trigger": 0}, \
        f"fresh startup did not install each trigger exactly once: {counters}, {len(expected)} source files"
    assert db_sql("SELECT COUNT(*) FROM hilos_migration_claim") == "0", "startup left a schema rollout claim"

    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    victim = next(node for node in ALL_NODES if node != leader)
    survivors = [node for node in ALL_NODES if node != victim]
    failed = False
    try:
        print(f"    restarting non-leader {victim}; {len(expected)} triggers, DDL counters {counters}", flush=True)
        ctl("kill", victim)

        def offline(current):
            return (converged(survivors)(current)
                    and all(current.get(node) and not any(row.get("nodeId") == victim and row.get("online")
                                                         for row in current[node].get("nodes", []))
                            for node in survivors))

        wait_until(offline, CONVERGE_TIMEOUT, f"{victim} offline among surviving nodes", nodes=survivors)
        ctl("start", victim)
        wait_converge(ALL_NODES)
        wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet started after journal trigger restart")
        after = journal_trigger_catalog()
        assert after == before, f"ordinary start changed the trigger catalog: before {before!r}, after {after!r}"
        after_counters = journal_trigger_ddl_status()
        assert after_counters == counters, f"ordinary start ran trigger DDL: before {counters}, after {after_counters}"
        assert db_sql("SELECT COUNT(*) FROM hilos_migration_claim") == "0", "restart left a schema rollout claim"
    except BaseException:
        failed = True
        raise
    finally:
        try:
            ctl("start", victim)
            wait_converge(ALL_NODES)
            wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet restored after journal trigger checks")
        except Exception as cleanup_error:
            print(f"    scenario 40 recovery failed: {cleanup_error}", flush=True)
            if not failed:
                raise

    return (f"{len(expected)} journal triggers installed once, no DROP; {victim} kill/start kept the full "
            "catalog and global DDL counters unchanged, rollout claim empty, full mesh and fleet restored")


# The row that names the database every node of the stand reads (HIL-1206); a marker is 32 hex.
DATABASE_MARKER_SQL = "SELECT marker FROM hilos_database_marker WHERE id = 1"
DATABASE_MARKER = re.compile(r"^[0-9a-f]{32}$")
# Mirrors ClusterDirectoryMarker::FILE_NAME and HilosAgentType::HILOS_DATA_EXPORT.
CLUSTER_DIRECTORY_MARKER_FILE = ".hilos-cluster-directory.json"
DATA_EXPORT_AGENT_TYPE = "hilos_data_export"


# Mirrors HilosAgentType::HILOS_I18N_LIBRARY and I18nLibraryAgent's post-commit INFO line.
I18N_LIBRARY_AGENT_TYPE = "hilos_i18n_library"
I18N_REFLOW_COMMITTED = "I18n catalog reflow committed: "
# The initial language declared by the i18n scenarios' carrier, not a framework default.
I18N_DEFAULT_LANGUAGE = "en"


def i18n_library_placements(views):
    """All placements of the i18n library on the current leader, including pending ones."""
    return [row for row in leader_placements(views)
            if str(row.get("agentId", "")).split(":", 1)[0] == I18N_LIBRARY_AGENT_TYPE]


def i18n_library_started(views):
    """One library placement, acknowledged as started on a member of the stand."""
    rows = i18n_library_placements(views)
    return len(rows) == 1 and rows[0].get("state") == "started" and rows[0].get("nodeId") in ALL_NODES


def scenario_41_i18n_catalog_taken_once():
    """A fresh cluster takes the catalog once; a holder restart skips the same fingerprint.

    This must run before any other scenario can restart a node and rotate its startup log.
    SQL is an independent observation from the external harness, not application data access.
    The framework's mounted i18n collections remain owned by its one policy-placed library.
    """
    worker_marks = {}

    def worker_log(node):
        # The reflow INFO is worker output (WorkerServer::saveWorkerOutput), not daemon.log.
        # Like scenario 39, follow each live worker stream by inode across holder rotation.
        parts = []
        for path in sorted(STAND.node(node).log_dir.glob("worker-*.log")):
            mark = worker_marks.get(node, {}).get(path)
            try:
                with path.open("rb") as stream:
                    if mark is not None and os.fstat(stream.fileno()).st_ino == mark.inode:
                        stream.seek(mark.size)
                    parts.append(stream.read().decode("utf-8", errors="replace"))
            except FileNotFoundError:
                continue
        return "\n".join(parts)

    wait_converge(ALL_NODES)
    views = wait_until(lambda current: i18n_library_started(current)
                       and any(I18N_REFLOW_COMMITTED in worker_log(node) for node in ALL_NODES),
                       CONVERGE_TIMEOUT, "one i18n library started and its catalog reflow committed")
    placement = i18n_library_placements(views)[0]
    holder, agent_id = placement["nodeId"], placement["agentId"]
    logs = {node: node_log(node) for node in ALL_NODES}
    starts = [(node, agent) for node, log in logs.items()
              for agent, _worker in AGENT_STARTED_ON_WORKER.findall(log)
              if agent.split(":", 1)[0] == I18N_LIBRARY_AGENT_TYPE]
    assert starts == [(holder, agent_id)], f"expected exactly one i18n library startup, got {starts}"
    commits = [(node, line.split(I18N_REFLOW_COMMITTED, 1)[1])
               for node in ALL_NODES for line in worker_log(node).splitlines() if I18N_REFLOW_COMMITTED in line]
    assert len(commits) == 1 and commits[0][0] == holder, f"expected one reflow on {holder}, got {commits}"
    fingerprint = commits[0][1]
    assert re.fullmatch(r"[0-9a-f]{64}", fingerprint), f"invalid committed fingerprint: {fingerprint!r}"

    def catalog_snapshot():
        return (db_sql("SELECT fingerprint FROM hilos_i18n_reflow WHERE id = 1"),
                db_sql("SELECT COUNT(*) FROM hilos_country"),
                db_sql("SELECT COUNT(*) FROM hilos_country_name n JOIN hilos_language l ON l.id = n.language_id "
                       "WHERE l.code = 'en' AND n.locale_id IS NULL"),
                db_sql("SELECT code FROM hilos_language"),
                db_sql("SELECT code FROM hilos_locale"))

    expected = (fingerprint, "51", "51", I18N_DEFAULT_LANGUAGE, I18N_DEFAULT_LANGUAGE)
    before = catalog_snapshot()
    assert before == expected, f"unexpected initial i18n catalog: {before!r}, expected {expected!r}"
    marks = {node: node_log_mark(node) for node in ALL_NODES}
    for node in ALL_NODES:
        worker_marks[node] = {}
        for path in STAND.node(node).log_dir.glob("worker-*.log"):
            stat = path.stat()
            worker_marks[node][path] = control.LogMark(stat.st_ino, stat.st_size)
    survivors = [node for node in ALL_NODES if node != holder]
    failed = False
    try:
        print(f"    restarting i18n holder {holder}; committed fingerprint {fingerprint}", flush=True)
        outcome = control.execute(STAND, "kill", holder)
        assert outcome.code == 0, f"could not kill {holder}: {outcome.err or outcome.out}"
        wait_until(lambda current: converged(survivors)(current) and not node_online(current, holder),
                   CONVERGE_TIMEOUT, f"i18n holder {holder} offline", nodes=survivors)
        outcome = control.execute(STAND, "start", holder)
        assert outcome.code == 0, f"could not start {holder}: {outcome.err or outcome.out}"
        wait_converge(ALL_NODES)

        def restarted(current):
            if not i18n_library_started(current):
                return False
            row = i18n_library_placements(current)[0]
            return bool(started_on_worker_at(node_log_since(row["nodeId"], marks[row["nodeId"]]), row["agentId"]))

        views = wait_until(restarted, CONVERGE_TIMEOUT, "a new i18n library startup and one started placement")
        after = catalog_snapshot()
        assert after == before, f"holder restart changed the i18n catalog: before {before!r}, after {after!r}"
        commits_after = [node for node in ALL_NODES if I18N_REFLOW_COMMITTED in worker_log(node)]
        assert not commits_after, f"holder restart committed the catalog again on {commits_after}"
        new_holder = i18n_library_placements(views)[0]["nodeId"]
    except BaseException:
        failed = True
        raise
    finally:
        try:
            outcome = control.execute(STAND, "start", holder)
            assert outcome.code == 0, f"could not restore {holder}: {outcome.err or outcome.out}"
            wait_converge(ALL_NODES)
            wait_until(i18n_library_started, CONVERGE_TIMEOUT, "the i18n singleton restored after restart checks")
        except Exception as cleanup_error:
            print(f"    scenario 41 recovery failed: {cleanup_error}", flush=True)
            if not failed:
                raise

    return (f"one library startup and one committed reflow {fingerprint}; 51 countries and en base names, "
            f"only en language/locale; {holder} kill/start left holder {new_holder}, unchanged catalog, no new reflow")


def scenario_22_other_database_refused():
    """A node that reads another database marker is admitted by nobody (HIL-1206).

    Every node reads the marker of its database once, at the start of its daemon, and names it on
    every handshake; both ends of a link refuse a peer that names another. A second database is
    not raised for this: to a node, "another database" and "a database under another name" are
    the same thing - the marker IS the name - so a living node is stopped, the marker in the one
    database is replaced, and the node is started again. It reads the foreign name while every
    other node still holds the original in memory.

    What is asserted is the fact, not a timer: the refusal is named in the log on BOTH ends - by
    a node that stayed, about the foreign marker the victim names, and by the victim, about the
    original its neighbours name. Then nobody lists the victim online, and the rest converge
    under one leader without it. The marker goes back and the victim rejoins.

    The victim is the first slave, or, on a stand without slaves, a master that does not lead:
    either way the masters left behind keep their quorum. Both logs are read by a file mark
    (node_log_mark): the victim restarts inside the scenario, and its start moves the old log
    to staging (see scenario 17).
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    victim = SLAVES[0] if SLAVES else next(n for n in MASTERS if n != leader)
    observer = next(n for n in MASTERS if n != victim)
    rest = [n for n in ALL_NODES if n != victim]

    original = db_sql(DATABASE_MARKER_SQL)
    assert DATABASE_MARKER.match(original), f"the stand's database carries no marker: {original!r}"
    foreign = "f" * 32 if original != "f" * 32 else "e" * 32

    ctl("kill", victim)
    try:
        db_sql(f"UPDATE hilos_database_marker SET marker = '{foreign}' WHERE id = 1")
        assert db_sql(DATABASE_MARKER_SQL) == foreign, "the marker in the database was not replaced"
        marks = {n: node_log_mark(n) for n in (observer, victim)}
        print(f"    starting {victim} over the marker {foreign[:8]}… instead of {original[:8]}…")
        ctl("start", victim)

        def refused_on_both_ends(_views):
            return (f"names database marker '{foreign}'" in node_log_since(observer, marks[observer])
                    and f"names database marker '{original}'" in node_log_since(victim, marks[victim]))

        wait_until(refused_on_both_ends, CONVERGE_TIMEOUT,
                   f"the refused handshake named in the logs of {observer} and {victim}", nodes=rest)

        def left_out(views):
            return not node_online(views, victim) and converged(rest)(views)

        views = wait_until(left_out, CONVERGE_TIMEOUT,
                           f"{victim} offline to the leader, the rest under one leader", nodes=rest)
        for node in rest:
            listed = [n for n in (views.get(node) or {}).get("nodes", [])
                      if n.get("nodeId") == victim and n.get("online")]
            assert listed == [], f"{node} lists {victim} online: {listed}"
    finally:
        ctl("kill", victim)
        db_sql(f"UPDATE hilos_database_marker SET marker = '{original}' WHERE id = 1")
        ctl("start", victim)
        wait_converge(ALL_NODES)

    return (f"{victim} read marker {foreign[:8]}… instead of {original[:8]}…, was refused on both ends; "
            f"the rest converged; with the marker back it rejoined")


def scenario_29_cluster_directory_of_its_own_refused():
    """One node with its own empty directory writes a marker and is refused on both ends.

    The first writer creates its marker with O_EXCL, and the node has one marker of its own.
    The shared marker stays intact and the node rejoins when its shared volume is restored.
    We recreate a member, rather than add a node under a profile: the stand has no authority
    key for a new certificate and one CLUSTER_NODE_ID must identify just one service.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    victim = SLAVES[0] if SLAVES else next(n for n in MASTERS if n != leader)
    observer = next(n for n in MASTERS if n != victim)
    rest = [n for n in ALL_NODES if n != victim]
    name = STAND.cluster_directory.name

    def shared_marker():
        outcome = control.cluster_directory_exec(STAND, observer, "cat", CLUSTER_DIRECTORY_MARKER_FILE)
        assert outcome.code == 0, f"{observer} cannot read the shared {name} marker: {outcome.err}"
        return json.loads(outcome.out)["marker"]

    original = shared_marker()
    assert DATABASE_MARKER.fullmatch(original), f"the shared {name} marker is invalid: {original!r}"
    marks = {n: node_log_mark(n) for n in (observer, victim)}
    print(f"    recreating {victim} with an empty {name} directory of its own")
    try:
        outcome = control.execute(STAND, "own-directory", victim, "on")
        assert outcome.code == 0, f"could not give {victim} its own {name} directory: {outcome.err}"
        marker_line = re.compile(rf"Cluster directory {re.escape(name)} marker ([0-9a-f]{{32}}) "
                                 rf"written by {re.escape(victim)} at ")
        own = None

        def own_marker_written(_views):
            nonlocal own
            match = marker_line.search(node_log_since(victim, marks[victim]))
            if match:
                own = match.group(1)
            return own is not None

        wait_until(own_marker_written, CONVERGE_TIMEOUT,
                   f"{victim} wrote its own {name} marker", nodes=rest)
        assert own != original, f"{victim} still reads the shared {name} marker {original}"

        def refused_on_both_ends(_views):
            return (f"names directory:{name} marker '{own}'" in node_log_since(observer, marks[observer])
                    and f"names directory:{name} marker '{original}'" in node_log_since(victim, marks[victim]))

        wait_until(refused_on_both_ends, CONVERGE_TIMEOUT,
                   f"the refused {name} handshake named by {observer} and {victim}", nodes=rest)

        def left_out(current):
            return not node_online(current, victim) and converged(rest)(current)

        views = wait_until(left_out, CONVERGE_TIMEOUT,
                           f"{victim} offline, the rest under one leader", nodes=rest)
        for node in rest:
            listed = [row for row in (views.get(node) or {}).get("nodes", [])
                      if row.get("nodeId") == victim and row.get("online")]
            assert listed == [], f"{node} lists {victim} online: {listed}"
        assert shared_marker() == original, f"the shared {name} marker changed while {victim} was apart"
    finally:
        outcome = control.execute(STAND, "own-directory", victim, "off")
        assert outcome.code == 0, f"could not restore {victim} to the shared {name} directory: {outcome.err}"
        wait_converge(ALL_NODES)

    return (f"{victim} wrote its own marker {own[:8]}… beside the shared {original[:8]}…, "
            "was refused on both ends; the rest converged; back on the shared directory it rejoined")


def scenario_32_another_admin_view_mode_refused():
    """A node with another admin view mode variable is refused by both ends (HIL-1274).

    The marker names the variable; both ends refuse the mismatch. The test lever cannot change
    one clustered node silently, and inspect names the mode from each node's RT row. This stand
    runs APP_ENV=dev, so it has no production latch; the latch is covered by
    AdminViewModeStartupIntegrationTest (HIL-1249).
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    victim = SLAVES[0] if SLAVES else next(n for n in MASTERS if n != leader)
    observer = next(n for n in MASTERS if n != victim)
    rest = [n for n in ALL_NODES if n != victim]

    assert all(admin_view_mode(views, n) is False for n in ALL_NODES), \
        f"the stand did not start with admin view mode off everywhere: {views}"
    refusal = client_refusal(observer, "test:admin-view-mode", "on")
    assert refusal is not None and \
        "On a cluster the admin view mode is what HILOS_ADMIN_VIEW_MODE_ENABLED says on every node" in refusal, \
        f"{observer} did not refuse the admin view mode lever: {refusal!r}"
    assert admin_view_mode({observer: inspect(observer)}, observer) is False, \
        f"the refused lever changed {observer}'s mode"

    marks = {n: node_log_mark(n) for n in (observer, victim)}
    print(f"    recreating {victim} with HILOS_ADMIN_VIEW_MODE_ENABLED=true")
    try:
        outcome = control.execute(STAND, "own-env", victim, "HILOS_ADMIN_VIEW_MODE_ENABLED=true")
        assert outcome.code == 0, f"could not give {victim} an admin view mode of its own: {outcome.err}"

        def refused_on_both_ends(_views):
            return ("names admin-view-mode marker 'on'" in node_log_since(observer, marks[observer])
                    and "names admin-view-mode marker 'off'" in node_log_since(victim, marks[victim]))

        wait_until(refused_on_both_ends, CONVERGE_TIMEOUT,
                   f"the admin view mode refusal named by {observer} and {victim}", nodes=rest)

        def left_out(current):
            return not node_online(current, victim) and converged(rest)(current)

        views = wait_until(left_out, CONVERGE_TIMEOUT,
                           f"{victim} offline, the rest under one leader", nodes=rest)
        for node in rest:
            listed = [row for row in (views.get(node) or {}).get("nodes", [])
                      if row.get("nodeId") == victim and row.get("online")]
            assert listed == [], f"{node} lists {victim} online: {listed}"
            assert admin_view_mode(views, node) is False, f"{node} changed admin view mode"

        wait_until(lambda current: admin_view_mode(current, victim) is True, CONVERGE_TIMEOUT,
                   f"{victim} reports admin view mode on from its RT row", nodes=[victim])
    finally:
        outcome = control.execute(STAND, "own-env", victim, "off")
        assert outcome.code == 0, f"could not restore {victim} to the stand's environment: {outcome.err}"
        views = wait_converge(ALL_NODES)
        assert all(admin_view_mode(views, n) is False for n in ALL_NODES), \
            f"the stand did not return to admin view mode off everywhere: {views}"

    return (f"{victim} named admin view mode on, was refused on both ends; the rest agreed on off; "
            "back on the stand's environment it rejoined")


def scenario_42_another_default_language_refused():
    """A slave naming ru instead of the stand's en stays outside the mesh and writes no ru row."""
    wait_converge(ALL_NODES)
    wait_until(i18n_library_started, CONVERGE_TIMEOUT, "the i18n singleton started before the env mismatch")
    victim = SLAVES[0]
    observer = MASTERS[0]
    rest = [node for node in ALL_NODES if node != victim]
    marks = {node: node_log_mark(node) for node in (observer, victim)}

    def refusal(peer, theirs, ours):
        return (f"Peer handshake from node '{peer}' names default-language marker '{theirs}', "
                f"but this node reads '{ours}' from the variable HILOS_DEFAULT_LANGUAGE: "
                "the two nodes do not read one default-language")

    failed = False
    try:
        print(f"    recreating {victim} with HILOS_DEFAULT_LANGUAGE=ru", flush=True)
        outcome = control.execute(STAND, "own-env", victim, "HILOS_DEFAULT_LANGUAGE=ru")
        assert outcome.code == 0, f"could not give {victim} a default language of its own: {outcome.err or outcome.out}"

        def refused_on_both_ends(_views):
            return (refusal(victim, "ru", I18N_DEFAULT_LANGUAGE) in node_log_since(observer, marks[observer])
                    and refusal(observer, I18N_DEFAULT_LANGUAGE, "ru") in node_log_since(victim, marks[victim]))

        wait_until(refused_on_both_ends, CONVERGE_TIMEOUT,
                   f"the exact default language refusals named by {observer} and {victim}", nodes=rest)

        def left_out(current):
            return (converged(rest)(current) and i18n_library_started(current)
                    and i18n_library_placements(current)[0].get("nodeId") != victim
                    and all(current.get(node) and not any(row.get("nodeId") == victim and row.get("online")
                                                         for row in current[node].get("nodes", []))
                            for node in rest))

        views = wait_until(left_out, CONVERGE_TIMEOUT,
                           f"{victim} offline everywhere, the rest converged with one i18n library", nodes=rest)
        assert not node_online(views, victim), f"the leader admitted {victim} with another default language"
        assert db_sql("SELECT COUNT(*) FROM hilos_language WHERE code = 'ru'") == "0", \
            f"the refused {victim} provisioned a Russian language"
    except BaseException:
        failed = True
        raise
    finally:
        try:
            outcome = control.execute(STAND, "own-env", victim, "off")
            assert outcome.code == 0, f"could not restore {victim}'s environment: {outcome.err or outcome.out}"
            wait_converge(ALL_NODES)
            wait_until(i18n_library_started, CONVERGE_TIMEOUT, "the i18n singleton restored after the env mismatch")
        except Exception as cleanup_error:
            print(f"    scenario 42 recovery failed: {cleanup_error}", flush=True)
            if not failed:
                raise

    return (f"{victim} named ru, {observer} named en: exact refusals on both ends, nobody admitted {victim}, "
            "one library outside it, no ru language; the stand's env, full mesh and singleton restored")


def scenario_30_ready_copy_outlives_its_node():
    """The export agent builds a copy on one slave and finds it after moving to another.

    A preparing database row asks the real agent to build it. The agent reads that queue when it
    starts, not on its ticks - in production only its own order() writes one - so the row written
    here past its owner is followed by a restart of the agent. On start the replacement agent
    removes a ready row if its file is missing, so the unchanged ready row after failover proves
    that the new host could see the shared file before it started work.

    The old host returns without its export agent. It must still address the leader's index
    agent: a pass request outside maintenance is answered with the index's refusal, rather than
    lost because the idle slave never learned who leads (HIL-1367).
    """
    views = wait_converge(ALL_NODES)

    def export_placement(current):
        return next((row for row in leader_placements(current)
                     if str(row.get("agentId", "")).split(":", 1)[0] == DATA_EXPORT_AGENT_TYPE
                     and row.get("state") == "started"), None)

    placement = export_placement(views)
    assert placement is not None, "the stand places no data export agent"
    assert placement["nodeId"] in SLAVES, f"the export agent is not on a slave: {placement}"
    agent_id = placement["agentId"]
    user_name = f"cluster-export-30-{int(time.time())}"
    db_sql(f"INSERT INTO hilos_user (name) VALUES ('{user_name}')")
    answer = db_sql(f"SELECT id FROM hilos_user WHERE name = '{user_name}'")
    assert answer.isdigit(), f"the export user was not seeded: {answer!r}"
    user_id = int(answer)
    stored_name = None
    holder = None
    killed = False
    try:
        db_sql(f"INSERT INTO hilos_data_export (user_id, state, requested_at) "
               f"VALUES ({user_id}, 'preparing', UTC_TIMESTAMP())")
        assert client(placement["nodeId"], "agent:restart", agent_id), \
            f"could not restart {agent_id} to pick up the preparing row"

        def ready(_views):
            nonlocal stored_name
            row = db_sql(f"SELECT state, stored_name FROM hilos_data_export WHERE user_id = {user_id}")
            match = re.fullmatch(r"ready\t([0-9a-f]{32}\.zip)", row)
            if match:
                stored_name = match.group(1)
            return stored_name is not None

        views = wait_until(ready, CONVERGE_TIMEOUT, f"the data export of user {user_id} is ready")
        placement = export_placement(views)
        assert placement is not None, "the stand places no data export agent after building the copy"
        holder = placement["nodeId"]
        assert holder in SLAVES, f"the export agent moved off a slave: {placement}"
        assert control.cluster_directory_exec(STAND, holder, "test", "-f", stored_name).code == 0, \
            f"{holder} cannot see ready copy {stored_name}"

        marks = {node: node_log_mark(node) for node in SLAVES}
        print(f"    killing export agent host {holder} after it built {stored_name}")
        ctl("kill", holder)
        killed = True

        def replacement(current):
            row = export_placement(current)
            return row is not None and row.get("nodeId") != holder

        views = wait_until(replacement, FAILOVER_TIMEOUT,
                           f"data export agent {agent_id} started off {holder}")
        new_holder = export_placement(views)["nodeId"]
        assert new_holder in SLAVES, f"the export agent failed over outside the slaves: {new_holder}"
        assert f"Agent '{agent_id}' start hook failed" not in node_log_since(new_holder, marks[new_holder]), \
            f"the export agent start hook failed on {new_holder}"
        row = db_sql(f"SELECT state, stored_name FROM hilos_data_export WHERE user_id = {user_id}")
        assert row == f"ready\t{stored_name}", f"the ready copy changed after failover: {row!r}"
        assert control.cluster_directory_exec(STAND, new_holder, "test", "-f", stored_name).code == 0, \
            f"{new_holder} cannot see ready copy {stored_name}"
    finally:
        db_sql(f"DELETE FROM hilos_data_export WHERE user_id = {user_id}")
        if stored_name is not None:
            live = next(node for node in ALL_NODES if node != holder) if killed else (holder or MASTERS[0])
            control.cluster_directory_exec(STAND, live, "rm", "-f", stored_name)
        if killed:
            ctl("start", holder)
            wait_converge(ALL_NODES)

    refusal = client_refusal(holder, "maintenance:pass")
    assert refusal is not None and "the mode is 'inactive', not verifying" in refusal, \
        f"returning idle slave {holder} did not reach the leader's index agent: {refusal!r}"

    return (f"{holder} built {stored_name[:8]}….zip; after {holder} died the agent started "
            f"on {new_holder} and the copy was still ready there; the returning host reached the index agent")


# The address scenario 23 names to the verifier circle. No user holds it, on purpose: the
# photograph counts a named member whether or not anybody is signed in under the address
# (VerifierCircleSnapshot::capture()), and the worker sends it once at least one is named
# (WorkerManager::photographVerifierCircle()) - so a count of one reaches every master with no
# session to seed.
CIRCLE_ADDRESS = "circle-23@example.test"
# The operation the scenario freezes the stand for; any name does, the test drive protects none.
CIRCLE_OPERATION = "cluster-circle"
# The operation scenario 25 freezes the stand for, apart from 23's so the two runs read apart in the logs.
SETTLE_OPERATION = "cluster-settle"


def protected_mode(node):
    """One node's protected-mode:inspect reply as a dict, or None when it did not answer.

    Answered by the node's master itself rather than by an agent, so it can be asked of every
    master: under the freeze every agent but the initiator is stopped.
    """
    out = client_out(node, "protected-mode:inspect")
    if out is None:
        return None
    brace = out.find("{")
    if brace < 0:
        return None
    try:
        obj, _ = json.JSONDecoder().raw_decode(out[brace:])
        return obj
    except json.JSONDecodeError:
        return None


def wait_protected_mode(predicate, desc, nodes=None):
    """Poll protected-mode:inspect on every master until predicate(reply) holds for each of them.

    The masters only, because the freeze frames reach masters only: a slave holds no freeze row
    and stays inactive through the whole freeze. Raises ScenarioTimeout, so a pure convergence
    timeout stays retryable, naming the nodes whose reply was still wrong.
    """
    nodes = MASTERS if nodes is None else nodes
    deadline = time.time() + CONVERGE_TIMEOUT
    replies = {}
    while time.time() < deadline:
        replies = {n: protected_mode(n) for n in nodes}
        if all(reply is not None and predicate(reply) for reply in replies.values()):
            return replies
        time.sleep(POLL_INTERVAL)
    wrong = {n: (None if r is None else {k: r.get(k) for k in
                                       ("phase", "circleSize", "circleAdmitted", "passCount",
                                        "stoppedAgents", "agentStartGateClosed")})
             for n, r in replies.items() if r is None or not predicate(r)}
    raise ScenarioTimeout(f"timed out after {CONVERGE_TIMEOUT:.0f}s waiting for: {desc}\n"
                          f"masters still not there: {wrong}")


def scenario_38_manual_maintenance_from_any_node():
    """Manual maintenance closes every master while its agents keep running (HIL-1367).

    Three command entrances exercise the live command channel and peer mesh: enable from a
    follower master, mint from a slave, disable from the other follower master. Each step reads
    every master's own protected-mode row. No browser is driven here.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    followers = [node for node in MASTERS if node != leader]
    enable_node, disable_node = followers[:2]
    pass_node = SLAVES[0]

    try:
        assert client(enable_node, "maintenance:enable"), \
            f"maintenance:enable from follower {enable_node} refused or never answered"
        opened = wait_protected_mode(lambda r: r.get("phase") == "verifying",
                                     "every master in the manual maintenance window")
        for node, reply in opened.items():
            assert reply.get("stoppedAgents") == [], f"{node} stopped agents under manual maintenance: {reply}"
            assert reply.get("agentStartGateClosed") is False, f"{node} closed its agent-start gate: {reply}"

        assert client(pass_node, "maintenance:pass"), \
            f"maintenance:pass from slave {pass_node} refused or never answered"
        wait_protected_mode(lambda r: r.get("phase") == "verifying" and r.get("passCount") == 1,
                            "every master holding one manual maintenance pass")

        current = wait_converge(ALL_NODES)
        assert disable_node not in leaders(current), f"{disable_node} became leader before maintenance:disable"
        assert client(disable_node, "maintenance:disable"), \
            f"maintenance:disable from other follower {disable_node} refused or never answered"
        wait_protected_mode(lambda r: r.get("phase") == "inactive" and r.get("passCount") == 0,
                            "every master open again, all manual maintenance passes cleared")
    finally:
        replies = {node: protected_mode(node) for node in MASTERS}
        if any(reply is None or reply.get("phase") != "inactive" for reply in replies.values()):
            assert client(enable_node, "maintenance:disable"), "could not clean up the manual maintenance window"
            wait_protected_mode(lambda r: r.get("phase") == "inactive" and r.get("passCount") == 0,
                                "manual maintenance cleaned up on every master")

    return (f"enabled from follower {enable_node}, minted from slave {pass_node}, disabled from follower {disable_node}; "
            f"all {in_words(len(MASTERS))} masters verified without stopped agents or a closed start gate, "
            "held one pass and cleared it on lift")


def scenario_23_verifier_circle_on_every_master():
    """The verifier circle photographed at a freeze lands on every master's row (HIL-1125).

    A member of the circle is let into the verification window by the session its tab already
    carries, and a tab connects to whichever node the balancer hands it - so each node decides
    that admission against its own copy of the freeze row, and the photograph has to be on all of
    them. One address is named to the circle, the stand is frozen through the test drive of the
    index agent, and every master is asked for its circle: one named, nobody online. The window
    opens and every master still holds it; the system opens and every master has dropped it.

    Headless, so what is proved is the photograph on the rows, not a browser walking in: a
    signed-in tab let in through a node that does not host the initiator is checked by
    demo/binance-btc-tracker/tests/e2e/cluster/live/protected-mode.spec.ts. Slaves
    are not asked: the freeze frames reach masters only.

    The drive commands go to the leader because that is where the index agent runs and the
    scenario initiator is the leader. A command answered by an agent on another node now
    returns to its asking node (HIL-1232). A follower or a slave initiating is covered by the
    unit tests of ClusterProtectedMode.

    Leadership must not move while the freeze holds. The right to the freeze is the agent's, and
    the followers accept a lift from a new leader (HIL-1297), but these assertions describe one
    leadership term and explicitly refuse a re-election. Cross-node command replies return
    to the asking node (HIL-1232). A stand raised a moment ago can re-elect
    on its own - its links flap once as late seed dials land (P-459) - which is why this runs last
    in the matrix rather than first. When it happens anyway, the failure says so instead of leaving
    only a timeout to read.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    term = views[leader].get("term")
    db_sql(f"INSERT INTO hilos_verifier_circle (identity_type, identifier) VALUES ('password', '{CIRCLE_ADDRESS}')")
    named = db_sql(f"SELECT COUNT(*) FROM hilos_verifier_circle WHERE identifier = '{CIRCLE_ADDRESS}'")
    assert named == "1", f"the circle row was not written: {named!r}"
    try:
        entered = client_out(leader, "test:protected-mode:enter", CIRCLE_OPERATION)
        assert entered is not None, f"the index agent on {leader} refused or never answered the enter"

        frozen = wait_protected_mode(lambda r: r.get("circleSize") == 1 and r.get("circleAdmitted") == 0,
                                     "every master holding a circle of one named, nobody online")
        initiator = frozen[leader].get("initiatorNodeId")

        assert client(leader, "test:protected-mode:leave"), f"the index agent on {leader} did not open the window"
        wait_protected_mode(lambda r: r.get("phase") == "verifying" and r.get("circleSize") == 1,
                            "every master in the verification window, the circle still held")

        assert client(leader, "test:protected-mode:open"), f"the index agent on {leader} did not open the system"
        wait_protected_mode(lambda r: r.get("phase") == "inactive" and r.get("circleSize") == 0,
                            "every master open again, the circle dropped")
    finally:
        now = inspect_all(MASTERS)
        moved = [n for n in leaders(now) if n != leader or now[n].get("term") != term]
        if moved:
            print(f"  leadership moved from {leader} (term {term}) to {moved[0]} "
                  f"(term {now[moved[0]].get('term')}) under the freeze; "
                  "these assertions describe one leadership term (P-459)")
        replies = {n: protected_mode(n) for n in MASTERS}
        if any(r is None or r.get("phase") != "inactive" for r in replies.values()):
            client(leader, "test:protected-mode:open")
        db_sql(f"DELETE FROM hilos_verifier_circle WHERE identifier = '{CIRCLE_ADDRESS}'")
        wait_converge(ALL_NODES)

    return (f"frozen from {initiator}; the circle was on all {in_words(len(MASTERS))} masters "
            f"through the window and gone from each once the system opened")


def scenario_25_freeze_settles_on_every_master():
    """Active on a master's freeze row means every agent of the cluster has stopped (HIL-1128).

    The leader writes active once every node of the quiesce round has reported its roster stopped,
    and tells every follower so; a follower writes active on that word and on nothing else. The
    close back from the verification window runs the same round. So once the stand is frozen every
    master reads active with its agent-start gate shut - before HIL-1128 a follower stood on
    activating for the whole freeze - and the close answers only once every master has stopped
    again everything the entry had stopped: before HIL-1128 the close wrote active first and
    answered while the walks were still under way. Read at the moment the close answers, with no
    wait, because the answer is what is on trial.

    Headless, and on the masters only: the freeze frames reach masters only, and a slave holds no
    freeze row. A close asked for on a follower is covered by the unit tests of ClusterProtectedMode.

    The drive commands go to the leader because that is where the index agent runs and the
    scenario initiator is the leader. A command answered by an agent on another node now
    returns to its asking node (HIL-1232). A follower or a slave initiating is covered by the
    unit tests of ClusterProtectedMode.

    Leadership must not move while the freeze holds. The right to the freeze is the agent's, and
    the followers accept a lift from a new leader (HIL-1297), but these assertions describe one
    leadership term and explicitly refuse a re-election. Cross-node command replies return
    to the asking node (HIL-1232). A stand raised a moment ago can re-elect
    on its own - its links flap once as late seed dials land (P-459) - which is why this runs last
    in the matrix rather than first. When it happens anyway, the failure says so instead of leaving
    only a timeout to read.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    term = views[leader].get("term")
    try:
        entered = client_out(leader, "test:protected-mode:enter", SETTLE_OPERATION)
        assert entered is not None, f"the index agent on {leader} refused or never answered the enter"

        frozen = wait_protected_mode(lambda r: r.get("phase") == "active" and r.get("agentStartGateClosed") is True,
                                     "every master active, its start gate closed")

        assert client(leader, "test:protected-mode:leave"), f"the index agent on {leader} did not open the window"
        wait_protected_mode(lambda r: r.get("phase") == "verifying", "every master in the verification window")

        closed = client_out(leader, "test:protected-mode:close")
        assert closed is not None, f"the index agent on {leader} refused or never answered the close"
        for node in MASTERS:
            reply = protected_mode(node)
            assert reply is not None, f"{node} did not answer protected-mode:inspect once the close answered"
            assert reply.get("agentStartGateClosed") is True and reply.get("phase") in ("activating", "active"), \
                f"{node} stood on {reply.get('phase')!r} with its start gate open once the close answered"
            running = set(frozen[node].get("stoppedAgents") or []) - set(reply.get("stoppedAgents") or [])
            assert not running, (f"the close answered while {node} had not stopped {sorted(running)} again, "
                                 f"which the entry had stopped")
        wait_protected_mode(lambda r: r.get("phase") == "active", "every master active again after the close")

        assert client(leader, "test:protected-mode:open"), f"the index agent on {leader} did not open the system"
        wait_protected_mode(lambda r: r.get("phase") == "inactive", "every master open again")
    finally:
        now = inspect_all(MASTERS)
        moved = [n for n in leaders(now) if n != leader or now[n].get("term") != term]
        if moved:
            print(f"  leadership moved from {leader} (term {term}) to {moved[0]} "
                  f"(term {now[moved[0]].get('term')}) under the freeze; "
                  "these assertions describe one leadership term (P-459)")
        replies = {n: protected_mode(n) for n in MASTERS}
        if any(r is None or r.get("phase") != "inactive" for r in replies.values()):
            client(leader, "test:protected-mode:open")
        wait_converge(ALL_NODES)

    return (f"frozen and closed back from {leader}; all {in_words(len(MASTERS))} masters read active, "
            f"and the close answered only once each had stopped again what the entry stopped")


def scenario_33_every_master_takes_browsers():
    """Every master admits browsers, and an agent restart keeps a remote socket's row (HIL-1304)."""
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    follower = next(node for node in MASTERS if node != leader)
    term = views[leader].get("term")
    held_key = None
    cookie_responders = []
    round_robin_responders = set()

    try:
        for master in MASTERS:
            answer = ctl_out("entry-upgrade", master)
            assert answer == f"101 {master}", f"entry cookie {master} reached {answer!r}, expected 101 {master}"
            cookie_responders.append(master)

        for _ in range(6):
            answer = ctl_out("entry-upgrade")
            code, _, responder = answer.partition(" ")
            assert code == "101" and responder in MASTERS, f"entry without cookie reached {answer!r}"
            round_robin_responders.add(responder)
        assert len(round_robin_responders) >= 2, \
            f"six cookie-free upgrades reached only {sorted(round_robin_responders)}"

        for slave in SLAVES:
            answer = ctl_out("direct-upgrade", slave)
            assert answer == "refused", f"slave {slave} accepted or mishandled a WebSocket: {answer!r}"

        def probes_closed(current):
            return not rt_rows(current, leader, "connections") and \
                all(indexed_for(current, leader, master) == 0 for master in MASTERS)

        views = wait_until(probes_closed, CONVERGE_TIMEOUT, "one-shot browser sockets close")
        before = set(rt_rows(views, leader, "connections"))
        assert control.execute(STAND, "entry-hold", follower, "up").code == 0, \
            f"could not hold a browser socket on {follower}"

        def held_socket_indexed(current):
            added = set(rt_rows(current, leader, "connections")) - before
            return len(added) == 1 and indexed_for(current, leader, follower) >= 1

        views = wait_until(held_socket_indexed, CONVERGE_TIMEOUT,
                           f"one browser on {follower} appears in {leader}'s rows and index")
        held_key = next(iter(set(rt_rows(views, leader, "connections")) - before))

        entered = client_out(leader, "test:protected-mode:enter", SETTLE_OPERATION)
        assert entered is not None, f"the index agent on {leader} refused the freeze"
        wait_protected_mode(lambda row: row.get("phase") == "active", "every master frozen")
        wait_until(lambda current: rt_collection(current, leader, "connections").get("owned") is False,
                   CONVERGE_TIMEOUT, f"the connection owner on {leader} stopped under the freeze")

        # The test driver uses the same three-step contract as production: enter freezes,
        # leave reaches the verification window, and open lifts it.
        assert client(leader, "test:protected-mode:leave"), f"the index agent on {leader} did not leave the freeze"
        wait_protected_mode(lambda row: row.get("phase") == "verifying", "every master verifying")
        assert client(leader, "test:protected-mode:open"), f"the index agent on {leader} did not lift the freeze"
        wait_protected_mode(lambda row: row.get("phase") == "inactive", "every master open again")

        def owner_restarted_with_row(current):
            return rt_collection(current, leader, "connections").get("owned") is True and \
                held_key in rt_rows(current, leader, "connections")

        wait_until(owner_restarted_with_row, CONVERGE_TIMEOUT,
                   f"connection {held_key} survives its owning agent's restart on {leader}")

        assert control.execute(STAND, "entry-hold", follower, "down").code == 0, \
            f"could not close the held socket on {follower}"
        wait_until(lambda current: held_key not in rt_rows(current, leader, "connections"),
                   CONVERGE_TIMEOUT, f"connection {held_key} closes after its socket leaves")
    finally:
        control.execute(STAND, "entry-hold", follower, "down")
        now = inspect_all(MASTERS)
        current_leaders = leaders(now)
        moved = current_leaders != [leader] or (now.get(leader) or {}).get("term") != term
        if moved:
            print(f"  leadership moved from {leader} (term {term}) to {current_leaders} "
                  f"(terms {[now[node].get('term') for node in current_leaders]}) under the freeze (P-459)")
        replies = {node: protected_mode(node) for node in MASTERS}
        if any(row is None or row.get("phase") != "inactive" for row in replies.values()):
            client(leader, "test:protected-mode:open")
        wait_converge(ALL_NODES)
        if moved:
            raise AssertionError(f"leadership changed from {leader} under the freeze (P-459)")

    return (f"cookies reached {', '.join(cookie_responders)}; no cookie reached "
            f"{len(round_robin_responders)} masters; {held_key} on {follower} survived the freeze on {leader}")


def scenario_34_a_tab_is_the_same_on_every_master():
    """The operator and a code holder keep their verdict when their next tab lands elsewhere (HIL-1305)."""
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    follower = next(node for node in MASTERS if node != leader)
    term = views[leader].get("term")

    def welcome(master, token=None, pass_code=None):
        args = [master]
        if token is not None:
            args.append(token)
        if pass_code is not None:
            args.append(pass_code)
        answer = control.execute(STAND, "entry-welcome", *args)
        assert answer.code == 0, f"entry welcome on {master} failed: {answer.err or answer.out}"
        name, verdict, received_token = answer.out.strip().split(" ", 2)
        assert name == master and verdict in ("inside", "stub"), f"bad entry welcome: {answer.out!r}"
        if token is not None:
            assert received_token == token, f"entry welcome changed the session token on {master}"
        return verdict, received_token

    try:
        operator_verdict, operator = welcome(leader)
        verifier_verdict, verifier = welcome(follower)
        stranger_verdict, stranger = welcome(follower)
        assert (operator_verdict, verifier_verdict, stranger_verdict) == ("inside", "inside", "inside"), \
            "a browser was held before the freeze began"

        entered = client_out(leader, "test:protected-mode:enter", SETTLE_OPERATION,
                             f"--session-token={operator}")
        assert entered is not None, f"the index agent on {leader} refused the freeze"
        wait_protected_mode(lambda row: row.get("phase") == "active", "every master frozen")
        for master in MASTERS:
            assert welcome(master, operator)[0] == "stub", f"operator reached inside on frozen {master}"

        assert client(leader, "test:protected-mode:leave"), f"the index agent on {leader} did not open the window"
        wait_protected_mode(lambda row: row.get("phase") == "verifying", "every master verifying")
        for master in MASTERS:
            assert welcome(master, operator)[0] == "inside", f"operator was held on {master}"

        minted = client_out(leader, "test:protected-mode:pass")
        assert minted is not None and minted.startswith("Pass: "), f"the index agent returned no pass: {minted!r}"
        pass_code = minted.removeprefix("Pass: ")
        assert welcome(follower, verifier, pass_code)[0] == "inside", "the pass failed on its 101 master"
        for master in MASTERS:
            deadline = time.time() + CONVERGE_TIMEOUT
            while welcome(master, verifier)[0] != "inside":
                if time.time() >= deadline:
                    raise ScenarioTimeout(f"verifier admitted on {follower} stayed on the stub at {master}")
                time.sleep(POLL_INTERVAL)
            assert welcome(master, stranger)[0] == "stub", f"stranger was admitted on {master}"

        assert client(leader, "test:protected-mode:open"), f"the index agent on {leader} did not lift the freeze"
        wait_protected_mode(lambda row: row.get("phase") == "inactive", "every master open again")
    finally:
        now = inspect_all(MASTERS)
        current_leaders = leaders(now)
        moved = current_leaders != [leader] or (now.get(leader) or {}).get("term") != term
        if moved:
            print(f"  leadership moved from {leader} (term {term}) to {current_leaders} "
                  f"(terms {[now[node].get('term') for node in current_leaders]}) under the freeze (P-459)")
        replies = {node: protected_mode(node) for node in MASTERS}
        if any(row is None or row.get("phase") != "inactive" for row in replies.values()):
            client(leader, "test:protected-mode:open")
        wait_converge(ALL_NODES)
        if moved:
            raise AssertionError(f"leadership changed from {leader} under the freeze (P-459)")

    return (f"operator and verifier inside on all {in_words(len(MASTERS))} masters; "
            f"stranger held on all {in_words(len(MASTERS))}")


# What a master writes when it loses its quorum while it carries placed work, when it arms its fence
# and when the fence fires (ClusterPlacement::noteQuorumLost() and selfFence(),
# framework/backend/Cluster/Placement/ClusterPlacement.php, HIL-1217); the fired line carries its
# time in the prefix every log line has (TimeHelper::getTimestampWithMs(),
# framework/backend/Utils/Helpers/TimeHelper.php).
QUORUM_FENCE_ARMED = re.compile(r"Self-fence armed: quorum lost, (\d+) placed agent\(s\)")
QUORUM_FENCE_FIRED = re.compile(r"^\[([^\]]+)\].*Self-fence: quorum lost, stopping (\d+) placed agent\(s\)", re.M)
# What a node writes when the leader it answers to tells it that it leads no more, and when that
# fence fires (ClusterPlacement::onPlacementRelease() and selfFence()); what it writes when it arms
# its fence because that leader went offline instead (ClusterPlacement::noteNodeOffline()); and
# what a leader writes when it loses its quorum (ClusterCoordinator::tickLeader(),
# framework/backend/Cluster/Consensus/ClusterCoordinator.php) - HIL-1287.
RELEASE_FENCE_ARMED = re.compile(r"Self-fence armed: placing leader '([^']+)' stopped leading, (\d+) placed agent\(s\)")
RELEASE_FENCE_FIRED = re.compile(
    r"^\[([^\]]+)\].*Self-fence: placing leader '([^']+)' stopped leading, stopping (\d+) placed agent\(s\)", re.M)
ISOLATION_FENCE_ARMED = re.compile(r"Self-fence armed: placing leader '([^']+)' went offline")
LEADERSHIP_LOST_ON_QUORUM = re.compile(r"^\[([^\]]+)\].*Consensus: lost leadership held in term \d+: quorum lost", re.M)
CONSENSUS_WON_TERM = re.compile(r"^\[([^\]]+)\].*Consensus: won term \d+ with ", re.M)
LOG_LINE_TIME = "%Y-%m-%dT%H:%M:%S.%fZ"


def log_time(stamp):
    """Read a daemon log's `[YYYY-MM-DDTHH:mm:ss.SSSZ]` prefix as a UTC-aware moment."""
    if not re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z", stamp):
        raise ValueError(f"Invalid UTC log timestamp: {stamp!r}")
    return datetime.strptime(stamp, LOG_LINE_TIME).replace(tzinfo=timezone.utc)


def started_on_worker_at(text, agent_id):
    """The moments one agent was started on a worker in a piece of a node's log, oldest first."""
    pattern = re.compile(rf"^\[([^\]]+)\].*Agent '{re.escape(agent_id)}' started on worker #\d+", re.M)
    return [log_time(stamp) for stamp in pattern.findall(text)]


def placed_on_node_at(text, agent_id):
    """Where and when a leader placed one agent in a piece of its log, as (moment, node), oldest first."""
    pattern = re.compile(rf"^\[([^\]]+)\].*Placing agent '{re.escape(agent_id)}' on node '([^']+)'", re.M)
    return [(log_time(stamp), node) for stamp, node in pattern.findall(text)]


def assert_leader_last_among_equals(views):
    """The leader took fleet members only while its share was the lowest (HIL-445, rule 3).

    Every placed agent takes one slot (HIL-447) and equal masters declare the same slots, so a
    master's share is the number of agents placed on it. The leader takes an agent only when its
    share after placement is lower than every other master's - an equal share goes to a master
    that does not lead - so a leader that took any runs no more placed agents than any other
    master. Last among equals is a rule about NEW work: a master that carried members and then
    won a term keeps them, and a stand raised a moment ago can re-elect on its own (P-459), so
    only members the leader started after its own last won term were taken by it; and a master
    that runs no placed agent at all was not there for the first placement, which the others
    shared between them.
    """
    leader = leaders(views)[0]
    on_leader = hosted_by(views, leader)
    if not on_leader:
        return
    log = node_log(leader)
    won = CONSENSUS_WON_TERM.findall(log)
    assert won, f"{leader} leads, but its log has no won-term line (read {node_log_path(leader)})"
    won_at = log_time(won[-1])
    taken = sorted(member for member in on_leader if any(t > won_at for t in started_on_worker_at(log, member)))
    if not taken:
        print(f"    leader {leader} carries {len(on_leader)} member(s) it held before it won its term (P-459)")
        return
    live = {node: live_on(views, node) for node in MASTERS}
    fewer = {node: count for node, count in live.items() if node != leader and 0 < count < live[leader]}
    assert not fewer, (f"leader {leader} took fleet member(s) {taken} after it won its term and runs "
                       f"{live[leader]} placed agent(s), more than {fewer}: the leader takes work only while "
                       "its share is the lowest, last among equal masters (HIL-445, HIL-447)")
    print(f"    leader {leader} took {len(taken)} member(s) in its term, last among equal shares: "
          f"placed agents by master {live}")


def spread_fleet_over(carriers, views):
    """Make both masters that do not lead carry fleet members, and answer the views that show it.

    One of them carries none only when it was not there for the first placement. One worker of the
    other is killed, and the leader places the members it hosted again - on the empty master,
    which runs the fewest agents and so holds the lowest share.
    """
    empty = [node for node in carriers if not hosted_by(views, node)]
    if not empty:
        return views
    full = next(node for node in carriers if node not in empty)
    by_worker = fleet_workers_on(full, hosted_by(views, full))
    assert by_worker, f"{full} carries {sorted(hosted_by(views, full))} but its log names none of their workers"
    worker_index, lost = max(by_worker.items(), key=lambda item: len(item[1]))
    print(f"    {empty[0]} carries no fleet member; killing worker #{worker_index} of {full} "
          f"with {sorted(lost)} so the leader places them again")
    out = ctl_out("kill-worker", full, str(worker_index))
    assert "SIGKILLed" in out, f"the worker kill did not report success: {out}"
    return wait_until(lambda v: fleet_started(v) and all(hosted_by(v, node) for node in carriers),
                      FAILOVER_TIMEOUT, f"both {carriers} carry fleet members")


def liveness_socket(name, action, master=None):
    """Tell one raw socket of scenario 46 what to do: open on a master, answer, keep silent, flood, close."""
    outcome = control.execute(STAND, "liveness-hold", name, action, *([master] if master else []))
    assert outcome.code == 0, f"liveness socket {name} could not {action}: {outcome.err or outcome.out}"


def liveness_socket_state(name):
    """What one raw socket of scenario 46 last published about itself, or None before it has."""
    outcome = control.execute(STAND, "liveness-hold", name, "state")
    if outcome.code != 0:
        return None
    try:
        return json.loads(outcome.out)
    except json.JSONDecodeError:
        return None


def wait_liveness_socket(name, predicate, desc, timeout=CONVERGE_TIMEOUT):
    """Poll one raw socket's state until predicate(state) holds; return that state."""
    last = {}

    def holds(_views):
        last["state"] = liveness_socket_state(name)
        return last["state"] is not None and predicate(last["state"])

    try:
        wait_until(holds, timeout, desc, nodes=[])
    except ScenarioTimeout as timeout_error:
        raise ScenarioTimeout(f"{timeout_error}\nsocket {name} last said: {last.get('state')}") from timeout_error
    return last["state"]


def wait_silence(state, seconds):
    """Sleep until a socket's own last frame, as its state stamps it, is `seconds` old."""
    assert state.get("lastSentAt") is not None, f"the socket never sent a frame to be silent after: {state}"
    time.sleep(max(0.0, state["lastSentAt"] + seconds - time.time()))


def connections_owner_worker(node, offset=0):
    """The worker the last start of the connections owner names in a node's log past `offset`, or None."""
    starts = [int(index) for agent_id, index in AGENT_STARTED_ON_WORKER.findall(node_log(node, offset))
              if agent_id == CONNECTIONS_OWNER_AGENT_TYPE]
    return starts[-1] if starts else None


def scenario_46_browser_liveness_across_masters():
    """A silent browser socket is doubted, cleared, repaired and closed across masters (HIL-1566).

    Three masters, three parts: a raw socket held through the entry on a master that does not lead,
    the owner of `connections` on the leader - a singleton of the leader - and the row read on the
    third master. The socket's master decides and the owner writes: a socket silent past the
    probably-offline span is told to the owner, which writes the flag into the row, and a whole
    frame clears it the same way; the observer sees both, so the flag crosses the mesh twice and is
    written by a node that holds no socket at all.

    A transition the owner misses is the next part. The owner's worker is stopped, the socket
    changes its state, and its master's own copy check repeats it - both go to a worker that never
    reads them, and the observer's row stays as it was, which is asserted. The worker is then
    killed and the leader starts the owner again; the socket's master, checking its copy once an
    interval, finds the row disagreeing and tells the owner again, so the row comes to the socket's
    state within LIVENESS_REPAIR_TIMEOUT - for a missed true and for a missed false alike.

    The close is the socket's master's alone: a socket silent past the close timeout, and one
    flooding pings while reading none of the pongs past the 8 MiB cap, are dropped by their master,
    their rows leave by the ordinary connection close, and another socket of the same master and
    every node live on. The cap's line is in that master's log and in no other master's.

    The spans are the socket master's own for the scenario (LIVENESS_*_MS), given by recreating it,
    and the stand's are back after it.
    """
    views = wait_converge(ALL_NODES)
    leader = leaders(views)[0]
    term = views[leader].get("term")
    socket_master, observer = [node for node in MASTERS if node != leader][:2]
    spans = (f"WEBSOCKET_PING_IDLE_MS={LIVENESS_PING_IDLE_MS}",
             f"WEBSOCKET_PING_INTERVAL_MS={LIVENESS_PING_INTERVAL_MS}",
             f"WEBSOCKET_PROBABLY_OFFLINE_MS={LIVENESS_PROBABLY_OFFLINE_MS}",
             f"WEBSOCKET_CLOSE_TIMEOUT_MS={LIVENESS_CLOSE_TIMEOUT_MS}")
    interval = LIVENESS_PING_INTERVAL_MS / 1000
    doubt_span = LIVENESS_PROBABLY_OFFLINE_MS / 1000
    close_span = LIVENESS_CLOSE_TIMEOUT_MS / 1000
    probe, other, flood = "probe46", "other46", "flood46"
    stopped = []
    took = {}

    def led_as_before(moment):
        now = inspect_all(MASTERS)
        if leaders(now) != [leader] or (now.get(leader) or {}).get("term") != term:
            raise ScenarioPreconditionLost(f"leadership moved from {leader} (term {term}) to {leaders(now)} "
                                           f"{moment}, and the owner of {CONNECTIONS} with it (P-459)")

    def flag(current, key):
        row = rt_rows(current, observer, CONNECTIONS).get(key)
        return None if row is None else row.get("probablyOffline")

    def read_flag(key):
        return flag(inspect_all([observer]), key)

    def open_socket(name):
        before = set(rt_rows(inspect_all([observer]), observer, CONNECTIONS))
        liveness_socket(name, "up", socket_master)
        state = wait_liveness_socket(name, lambda s: s["state"] != "connecting",
                                     f"socket {name} is upgraded through the entry on {socket_master}")
        assert state["state"] == "open" and state["upstream"].startswith(STAND.member(socket_master).ip + ":"), \
            f"socket {name} did not open on {socket_master}: {state}"

        def one_row_more(current):
            return len(set(rt_rows(current, observer, CONNECTIONS)) - before) == 1

        current = wait_until(one_row_more, CONVERGE_TIMEOUT,
                             f"{observer} holds the row of socket {name} on {socket_master}", nodes=[observer])
        key = next(iter(set(rt_rows(current, observer, CONNECTIONS)) - before))
        assert flag(current, key) is False, f"socket {name} came in doubted: {rt_rows(current, observer, CONNECTIONS)[key]}"
        return key

    def stop_owner():
        index = connections_owner_worker(leader)
        assert index is not None, f"{leader}'s log names no worker of {CONNECTIONS_OWNER_AGENT_TYPE}"
        process = control.worker_process(STAND, leader, index)
        # Registered before the STOP, so a failure between the signal and its proof still resumes it.
        stopped.append(process)
        outcome = control.signal_worker_process(STAND, process, 19)
        assert outcome.code == 0 and json.loads(outcome.out) is not None, \
            f"the owner's worker #{index} on {leader} vanished before its STOP: {outcome}"
        wait_until(lambda _v: (json.loads(control.signal_worker_process(STAND, process).out) or {}).get("state") == "T",
                   CONVERGE_TIMEOUT, f"the owner's worker #{index} on {leader} is stopped", nodes=[])
        return process, node_log_size(leader)

    def kill_owner(process, offset):
        outcome = control.signal_worker_process(STAND, process, 9)
        assert outcome.code == 0, f"could not kill the owner's worker #{process.index} on {leader}: {outcome}"
        wait_until(lambda _v: connections_owner_worker(leader, offset) is not None, CONVERGE_TIMEOUT,
                   f"{leader} starts {CONNECTIONS_OWNER_AGENT_TYPE} again after worker #{process.index} died",
                   nodes=[])
        deaths = [match.group(2) for match in WORKER_DIED_HOSTING.finditer(node_log(leader, offset))
                  if int(match.group(1)) == process.index]
        assert deaths and CONNECTIONS_OWNER_AGENT_TYPE in deaths[-1], \
            f"{leader} never named {CONNECTIONS_OWNER_AGENT_TYPE} lost with worker #{process.index}: {deaths}"
        wait_until(lambda current: rt_collection(current, leader, CONNECTIONS).get("owned") is True,
                   CONVERGE_TIMEOUT, f"{leader} owns {CONNECTIONS} again", nodes=[leader])
        return time.time()

    def missed(state_name, wanted, switch):
        """Stop the owner, let the probe change its state and a check go unheard, kill, wait the repair."""
        process, offset = stop_owner()
        asked_at = time.time()
        liveness_socket(probe, switch)
        state = wait_liveness_socket(probe, lambda s: s["mode"] == switch, f"socket {probe} is {switch}")
        if switch == "silent":
            # The transition at the probably-offline span, then a check of the copy an interval later.
            wait_silence(state, doubt_span + interval + 1.0)
        else:
            # The unasked pong is the whole frame that ends the silence; one interval more for a check.
            wait_liveness_socket(probe, lambda s: (s["lastSentAt"] or 0) >= asked_at,
                                 f"socket {probe} sends a frame once told to answer")
            time.sleep(interval + 1.0)
        assert read_flag(probe_key) is (not wanted), \
            f"{observer} read socket {probe} as {read_flag(probe_key)} while its owner was stopped"
        back_at = kill_owner(process, offset)
        stopped.remove(process)
        wait_until(lambda current: flag(current, probe_key) is wanted, LIVENESS_REPAIR_TIMEOUT,
                   f"{observer} reads the missed {state_name} of socket {probe} once its owner is back",
                   nodes=[observer])
        took[f"missed {state_name}"] = time.time() - back_at
        assert (liveness_socket_state(probe) or {}).get("state") == "open", f"socket {probe} closed before its repair"
        led_as_before(f"while the missed {state_name} was repaired")

    try:
        print(f"    recreating {socket_master} with {' '.join(spans)}")
        outcome = control.execute(STAND, "own-env", socket_master, *spans)
        assert outcome.code == 0, f"could not give {socket_master} liveness spans of its own: {outcome.err}"
        wait_converge(ALL_NODES)
        led_as_before(f"while {socket_master} came back")

        other_key = open_socket(other)
        probe_key = open_socket(probe)

        liveness_socket(probe, "silent")
        state = wait_liveness_socket(probe, lambda s: s["mode"] == "silent", f"socket {probe} is silent")
        current = wait_until(lambda v: flag(v, probe_key) is not False, doubt_span + CONVERGE_TIMEOUT,
                             f"{observer} reads socket {probe} on {socket_master} probably offline",
                             nodes=[observer])
        took["doubted"] = time.time() - state["lastSentAt"]
        assert flag(current, probe_key) is True, f"socket {probe} lost its row instead of being doubted"
        assert took["doubted"] >= doubt_span - LIVENESS_CLOCK_SLACK_SEC, \
            f"socket {probe} was doubted {took['doubted']:.2f}s into its silence, before the {doubt_span:g}s span"

        liveness_socket(probe, "answer")
        cleared_from = time.time()
        wait_until(lambda v: flag(v, probe_key) is False, CONVERGE_TIMEOUT,
                   f"{observer} reads socket {probe} back once it answers", nodes=[observer])
        took["cleared"] = time.time() - cleared_from
        led_as_before("while the socket's flag crossed the mesh")

        missed("true", True, "silent")
        missed("false", False, "answer")
        assert read_flag(other_key) is False, f"socket {other}, answering all along, was doubted"

        liveness_socket(probe, "silent")
        state = wait_liveness_socket(probe, lambda s: s["mode"] == "silent", f"socket {probe} is silent again")
        wait_until(lambda v: flag(v, probe_key) is True, doubt_span + CONVERGE_TIMEOUT,
                   f"{observer} reads socket {probe} probably offline before it is closed", nodes=[observer])
        closed = wait_liveness_socket(probe, lambda s: s["state"] == "closed",
                                      f"{socket_master} closes socket {probe} silent past {close_span:g}s",
                                      timeout=close_span + CONVERGE_TIMEOUT)
        took["closed"] = closed["closedAt"] - state["lastSentAt"]
        assert took["closed"] >= close_span - LIVENESS_CLOCK_SLACK_SEC, \
            f"socket {probe} was closed {took['closed']:.2f}s into its silence, before the {close_span:g}s timeout"
        wait_until(lambda v: flag(v, probe_key) is None, CONVERGE_TIMEOUT,
                   f"{observer} drops the row of closed socket {probe}", nodes=[observer])

        flood_key = open_socket(flood)
        marks = {node: node_log_mark(node) for node in MASTERS}
        liveness_socket(flood, "flood")
        wait_until(lambda v: flag(v, flood_key) is None, CONVERGE_TIMEOUT,
                   f"{observer} drops the row of socket {flood}, which reads no pong, once {socket_master} "
                   "drops it past its cap", nodes=[observer])
        dropped_by = [node for node in MASTERS if OUTBOUND_CAP_LINE in node_log_since(node, marks[node])]
        assert dropped_by == [socket_master], \
            f"the {BROWSER_OUTBOUND_CAP_BYTES}-byte cap was logged by {dropped_by}, not by {socket_master} alone"
        # The entry keeps the browser's side open while it still holds pongs the socket has not
        # read, so the socket sees the drop only once it reads on to the end of them.
        flooded = liveness_socket_state(flood)
        liveness_socket(flood, "silent")
        wait_liveness_socket(flood, lambda s: s["state"] == "closed",
                             f"socket {flood} reads to the end of what {socket_master} sent and finds it closed")

        assert (liveness_socket_state(other) or {}).get("state") == "open", f"socket {other} closed beside the others"
        assert read_flag(other_key) is False, f"socket {other} lost its row or was doubted beside the others"
        wait_converge(ALL_NODES)
        led_as_before("by the end of the scenario")
    finally:
        for process in stopped:
            control.signal_worker_process(STAND, process, 18)
        for name in (probe, other, flood):
            control.execute(STAND, "liveness-hold", name, "down")
        outcome = control.execute(STAND, "own-env", socket_master, "off")
        assert outcome.code == 0, f"could not restore {socket_master} to the stand's environment: {outcome.err}"
        wait_converge(ALL_NODES)

    return (f"a socket on {socket_master}, its owner on {leader}, read on {observer}: doubted "
            f"{took['doubted']:.1f}s into its silence, cleared {took['cleared']:.1f}s after it answered; a missed "
            f"true and a missed false repaired {took['missed true']:.1f}s and {took['missed false']:.1f}s after "
            f"the owner was back; {socket_master} closed it {took['closed']:.1f}s into its silence and dropped "
            f"another past {BROWSER_OUTBOUND_CAP_BYTES} bytes after {flooded['floodBytes']} bytes of pings, "
            f"while {other} and every node lived on")


def scenario_24_cut_off_leader_stops_its_work():
    """A leader that carries work and is cut off stops it before the majority runs it (HIL-1217).

    On a stand of equal masters that carry the work themselves every placed agent takes a slot
    (HIL-447), so the leader takes its share of the fleet too, last only among equal shares
    (HIL-445, rule 3) - asserted first, on the fleet as the first placement laid it. The leader
    cut off below carries work it inherited as well: the first leader dies and a master carrying
    members wins the next term, keeping them, while its neighbours' members keep writing past the
    fence window of the leader that placed them (as in scenario 5). One worker is killed first
    when only one of the masters that do not lead carries any, so whichever of the two wins
    carries members.

    That leader is then cut off. Without a quorum it leads nobody and nobody can take its work
    over, so it fences the work itself; and the majority's fresh leader places nothing it has no
    record of until every node has reported or been away for a failover grace. Both halves are
    asserted on the logs, read from the host since the partitioned node cannot be asked: the
    cut-off leader wrote that it armed and fired a fence for at least its fleet members; the
    majority placed none of them anywhere before that fence fired - a leader that did not wait
    places them back onto the cut-off node, still online to it, and only the link timeout keeps
    that from being a copy; and each of them came up on its new host only after the fence fired -
    an earlier start is two copies running at once. The cut-off leader is recreated, as in
    scenario 8.
    """
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before the leader moves")
    assert_leader_last_among_equals(views)
    old_leader = leaders(views)[0]
    carriers = [n for n in MASTERS if n != old_leader]
    spread_fleet_over(carriers, views)

    print(f"    killing leader {old_leader}; whichever of {carriers} wins carries fleet members")
    ctl("kill", old_leader)
    try:
        def carrier_leads(v):
            ls = [n for n in carriers if is_leader(v.get(n))]
            return len(ls) == 1 and v[ls[0]].get("hasQuorum") is True
        views = wait_until(carrier_leads, ELECTION_TIMEOUT, "a carrier leads with quorum", nodes=carriers)
        heir = next(n for n in carriers if is_leader(views.get(n)))

        def carriers_saw_old_leader_offline(v):
            for carrier in carriers:
                old = next((node for node in (v.get(carrier) or {}).get("nodes", [])
                            if node.get("nodeId") == old_leader), None)
                if old is None or old.get("online") is not False:
                    return False
            return True

        wait_until(carriers_saw_old_leader_offline, CONVERGE_TIMEOUT,
                   f"both carriers see {old_leader} offline", nodes=carriers)
        fenced_by = int(time.time() + SLAVE_WORK_GRACE_SEC)

        def fleet_wrote_past_fence(v):
            updates = newest_row_updates(v)
            return (fleet_started(v)
                    and all(updates.get(str(index), 0) > fenced_by for index in range(WORKER_FLEET_SIZE)))

        try:
            wait_until(fleet_wrote_past_fence,
                       SLAVE_WORK_GRACE_SEC + 3 * WORKER_REPORT_INTERVAL_SEC * TIMEOUT_SCALE + FAILOVER_TIMEOUT,
                       f"the fleet {heir} leads writes past the fence window of {old_leader}", nodes=carriers)
        except ScenarioTimeout as error:
            updates = newest_row_updates(inspect_all(carriers))
            silent = [f"{WORKER_AGENT_TYPE}:{index}" for index in range(WORKER_FLEET_SIZE)
                      if updates.get(str(index), 0) <= fenced_by]
            if not silent:
                raise
            raise AssertionError(
                f"fleet member(s) {silent} stopped writing after {heir} took them over from {old_leader}: "
                "fenced by a carrier after the new leader took it over"
            ) from error
    finally:
        ctl("start", old_leader)
        wait_converge(ALL_NODES)

    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet started under one leader")
    cut_off = leaders(views)[0]
    members = hosted_by(views, cut_off)
    if not members:
        raise ScenarioPreconditionLost(
            f"{cut_off} leads after {heir} took over from {old_leader}, and carries no fleet member: the "
            "fleet moved under a re-election of the stand's own before this one (P-459)")
    majority = [n for n in MASTERS if n != cut_off]
    marks = {n: node_log_mark(n) for n in MASTERS}
    print(f"    cutting {cut_off} off the network with {len(members)} fleet member(s): {sorted(members)}")
    ctl("partition", cut_off)
    try:
        def majority_leads(v):
            ls = [n for n in majority if is_leader(v.get(n))]
            return len(ls) == 1 and v[ls[0]].get("hasQuorum") is True
        views = wait_until(majority_leads, ELECTION_TIMEOUT, "the majority elects a leader with quorum",
                           nodes=majority)
        new_leader = next(n for n in majority if is_leader(views.get(n)))

        def fence_fired(_views):
            return QUORUM_FENCE_FIRED.search(node_log_since(cut_off, marks[cut_off])) is not None

        try:
            wait_until(fence_fired, CONVERGE_TIMEOUT, f"{cut_off} fences its work", nodes=majority)
        except ScenarioTimeout as error:
            raise AssertionError(
                f"{cut_off} led with {len(members)} fleet member(s) and was cut off, yet never fenced them: "
                f"no \"Self-fence: quorum lost\" line in {node_log_path(cut_off)}"
            ) from error
        tail = node_log_since(cut_off, marks[cut_off])
        armed = QUORUM_FENCE_ARMED.search(tail)
        assert armed, f"{cut_off} fired a quorum fence it never armed (read {node_log_path(cut_off)})"
        fired_stamp, fired_count = QUORUM_FENCE_FIRED.findall(tail)[0]
        for count, said in ((int(armed.group(1)), "armed"), (int(fired_count), "fired")):
            assert count >= len(members), (f"{cut_off} {said} its quorum fence for {count} placed agent(s), "
                                           f"fewer than its {len(members)} fleet members")
        fired_at = log_time(fired_stamp)

        def fleet_moved_off(v):
            rows = worker_placements(v)
            return fleet_started(v) and all(rows[member].get("nodeId") != cut_off for member in members)

        views = wait_until(fleet_moved_off, CONVERGE_TIMEOUT,
                           f"the whole fleet started in the majority, none of it on {cut_off}", nodes=majority)
        rows = worker_placements(views)
        # The new leader placing a member at all before the fence fired is the rebuild not waiting:
        # a member placed back onto the cut-off node, which still looks online to it, starts no
        # copy only for as long as the link takes to time out.
        for member in sorted(members):
            for node in majority:
                early = [(at, target) for at, target in placed_on_node_at(node_log_since(node, marks[node]), member)
                         if at <= fired_at]
                assert not early, (f"{node} placed {member} on {early[0][1]} at {early[0][0]} while {cut_off} "
                                   f"stopped its copy only at {fired_at}: the rebuild did not wait for {cut_off}")

        def started_since_cut():
            hosts_of = {member: rows[member]["nodeId"] for member in members}
            return {member: started_on_worker_at(node_log_since(host, marks[host]), member)
                    for member, host in hosts_of.items()}

        # The leader writes a member started as soon as its host takes the placement, and the host
        # logs the start a moment later, once its worker reports the agent up: wait for those lines.
        try:
            wait_until(lambda _views: all(started_since_cut().values()), CONVERGE_TIMEOUT,
                       "every moved member's start in its new host's log", nodes=majority)
        except ScenarioTimeout:
            pass
        started = started_since_cut()
        first_up = None
        hosts = set()
        for member in sorted(members):
            host = rows[member]["nodeId"]
            hosts.add(host)
            starts = started[member]
            assert starts, (f"{member} runs on {host} by the leader's table, but {host}'s log has not "
                            "started it since the cut")
            assert starts[0] > fired_at, (f"{member} came up on {host} at {starts[0]} while {cut_off} stopped its "
                                          f"copy only at {fired_at}: two copies ran at once")
            first_up = starts[0] if first_up is None else min(first_up, starts[0])
        gap = (first_up - fired_at).total_seconds()
        return (f"{cut_off} led with {len(members)} fleet member(s) and was cut off: it stopped them {gap:.1f}s "
                f"before the first came up on {', '.join(sorted(hosts))}; {new_leader} leads the rest")
    finally:
        ctl("recreate", cut_off)
        wait_converge(ALL_NODES)


def scenario_36_slave_cut_off_with_its_leader_stops_its_work():
    """A slave cut off together with its leader stops its work once that leader stops leading (HIL-1287).

    A slave knows nothing of a quorum, and its leader stays online to it while the two are cut off
    from the majority together, so nothing but the leader can tell it that the work it placed is no
    longer led. The leader and the slave carrying the most fleet members are cut off as an island
    that keeps the link between them (`cut`); the leader, left without a quorum, stops leading and
    tells the slave so, and the slave fences the members it carries. The majority elects a leader
    that places those members elsewhere only after its rebuild waits for the slave (HIL-1217).

    Asserted on the logs, read from the host: the slave fired a fence naming its leader's release
    for at least its fleet members and armed it for as many; it never armed the fence for a leader
    gone offline - then the island did not hold, and the scenario proves nothing; the leader lost
    its leadership to a lost quorum; the majority placed none of those members before the fence
    fired, and each came up on its new host only after it - an earlier start is two copies running
    at once. The island is mended and both of its nodes recreated, as in scenario 8.
    """
    views = wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before the cut")
    leader = leaders(views)[0]
    carrying = {slave: hosted_by(views, slave) for slave in SLAVES}
    slave = max(sorted(carrying), key=lambda node: len(carrying[node]))
    members = carrying[slave]
    if not members:
        raise ScenarioPreconditionLost(f"no slave carries a fleet member under {leader}: the fleet is placed "
                                       "elsewhere, and there is no slave to cut off with it")
    majority = [n for n in MASTERS if n != leader]
    asked = majority + [n for n in SLAVES if n != slave]
    marks = {n: node_log_mark(n) for n in ALL_NODES}
    print(f"    cutting {leader} off with {slave}, which carries {len(members)} fleet member(s): {sorted(members)}")
    try:
        out = ctl_out("cut", leader, slave)
        assert out.startswith("cluster: cut"), f"the cut of {leader} and {slave} did not report success: {out!r}"

        def majority_leads(v):
            ls = [n for n in majority if is_leader(v.get(n))]
            return len(ls) == 1 and v[ls[0]].get("hasQuorum") is True
        views = wait_until(majority_leads, ELECTION_TIMEOUT, "the majority elects a leader with quorum", nodes=asked)
        new_leader = next(n for n in majority if is_leader(views.get(n)))

        def fence_fired(_views):
            return any(said == leader for _stamp, said, _count
                       in RELEASE_FENCE_FIRED.findall(node_log_since(slave, marks[slave])))

        try:
            wait_until(fence_fired, CONVERGE_TIMEOUT, f"{slave} fences its work", nodes=asked)
            fired = True
        except ScenarioTimeout:
            fired = False
        tail = node_log_since(slave, marks[slave])
        assert not any(said == leader for said in ISOLATION_FENCE_ARMED.findall(tail)), (
            f"{slave} lost its link to {leader} while the two were cut off together: the island did not hold, "
            f"and the scenario proves nothing (read {node_log_path(slave)})")
        assert fired, (f"{slave} carried {len(members)} fleet member(s) for {leader} and was cut off with it, yet "
                       f"never fenced them: no \"Self-fence: placing leader '{leader}' stopped leading\" line in "
                       f"{node_log_path(slave)}")
        lost = LEADERSHIP_LOST_ON_QUORUM.findall(node_log_since(leader, marks[leader]))
        assert lost, (f"{leader} was cut off with {slave} and never lost its leadership to a lost quorum "
                      f"(read {node_log_path(leader)})")
        armed = [int(count) for said, count in RELEASE_FENCE_ARMED.findall(tail) if said == leader]
        fired_stamp, fired_count = next((stamp, int(count)) for stamp, said, count
                                        in RELEASE_FENCE_FIRED.findall(tail) if said == leader)
        assert armed, f"{slave} fired a release fence it never armed (read {node_log_path(slave)})"
        for count, said in ((armed[0], "armed"), (fired_count, "fired")):
            assert count >= len(members), (f"{slave} {said} its release fence for {count} placed agent(s), "
                                           f"fewer than its {len(members)} fleet members")
        fired_at = log_time(fired_stamp)
        lost_at = log_time(lost[0])

        def fleet_moved_off(v):
            rows = worker_placements(v)
            return fleet_started(v) and all(rows[member].get("nodeId") != slave for member in members)

        views = wait_until(fleet_moved_off, CONVERGE_TIMEOUT,
                           f"the whole fleet started under {new_leader}, none of it on {slave}", nodes=asked)
        rows = worker_placements(views)
        # A member placed at all before the slave's fence fired is the rebuild not waiting for it: the
        # slave still ran its copy then.
        for member in sorted(members):
            for node in majority:
                early = [(at, target) for at, target in placed_on_node_at(node_log_since(node, marks[node]), member)
                         if at <= fired_at]
                assert not early, (f"{node} placed {member} on {early[0][1]} at {early[0][0]} while {slave} "
                                   f"stopped its copy only at {fired_at}: the rebuild did not wait for {slave}")

        def started_since_cut():
            hosts_of = {member: rows[member]["nodeId"] for member in members}
            return {member: started_on_worker_at(node_log_since(host, marks[host]), member)
                    for member, host in hosts_of.items()}

        # The leader writes a member started as soon as its host takes the placement, and the host
        # logs the start a moment later, once its worker reports the agent up: wait for those lines.
        try:
            wait_until(lambda _views: all(started_since_cut().values()), CONVERGE_TIMEOUT,
                       "every moved member's start in its new host's log", nodes=asked)
        except ScenarioTimeout:
            pass
        started = started_since_cut()
        first_up = None
        hosts = set()
        for member in sorted(members):
            host = rows[member]["nodeId"]
            hosts.add(host)
            starts = started[member]
            assert starts, (f"{member} runs on {host} by the leader's table, but {host}'s log has not "
                            "started it since the cut")
            assert starts[0] > fired_at, (f"{member} came up on {host} at {starts[0]} while {slave} stopped its "
                                          f"copy only at {fired_at}: two copies ran at once")
            first_up = starts[0] if first_up is None else min(first_up, starts[0])
        stopped_after = (fired_at - lost_at).total_seconds()
        gap = (first_up - fired_at).total_seconds()
        return (f"{slave} was cut off with {leader} carrying {len(members)} fleet member(s): {leader} stopped "
                f"leading and {slave} stopped them {stopped_after:.1f}s later, {gap:.1f}s before the first came up "
                f"on {', '.join(sorted(hosts))}; {new_leader} leads the rest")
    finally:
        ctl("mend", leader, slave)
        ctl("recreate", leader)
        ctl("recreate", slave)
        wait_converge(ALL_NODES)


# What scenario 26 asks every member of a clustered database about itself.
CLUSTER_STATUS_SQL = ("SHOW GLOBAL STATUS WHERE Variable_name IN "
                      "('wsrep_cluster_size', 'wsrep_cluster_status', 'wsrep_local_state_comment')")
SYNC_WAIT_SQL = "SELECT @@GLOBAL.wsrep_sync_wait"


def member_status(member):
    """The Galera status a declared member reports about itself."""
    status = {}
    for line in db_sql(CLUSTER_STATUS_SQL, member).splitlines():
        name, _, value = line.partition("\t")
        status[name.lower()] = value
    assert status, f"{member} did not answer: {CLUSTER_STATUS_SQL}"
    return status


def scenario_26_database_is_one_cluster():
    """Every member of the stand's clustered database is one synced cluster the application writes
    to (HIL-1230).

    Every other scenario passes the same on a database that is one server: a proxy that forgot a
    member, or a database quietly living on one member, reads and writes as well as three. So the
    shape itself is asked, of each member the stand declares, through db-sql on that member:

    - the cluster it sees is as large as the stand declares, and it is in the primary component;
    - it is Synced, that is it has applied what the cluster committed and takes writes;
    - wsrep_sync_wait is at least 1: a read there waits for the writes the other members
      committed, which is the READ_AFTER_WRITE the demo declares (docs/agents/app-topology.md,
      "Database Guarantees") and what scenarios 11 and 22 read through;
    - the application's user holds a connection there, not counting the one asking: the one
      address the nodes know leads to every member, so every member is written to. The daemons
      and the workers of the nodes hold more long connections than there are members, and the
      proxy lays each next one on the least busy member.

    Only reads, and asked once: the stand has converged before the matrix, so there is nothing to
    wait for, and every miss is a hard failure naming the member. A user without the PROCESS
    privilege still sees every thread of its own account in PROCESSLIST, and the application signs
    in as the same user db-sql does, so no privilege is added for this.
    """
    members = [member.service for member in STAND.database_members]
    count = len(members)
    user = STAND.database.user
    sync_waits = set()
    held = {}
    for member in members:
        status = member_status(member)
        size = status.get("wsrep_cluster_size")
        assert size == str(count), f"{member} sees a database cluster of {size}, the stand declares {count} members"
        state = status.get("wsrep_cluster_status")
        assert state == "Primary", f"{member} is outside the primary component: wsrep_cluster_status={state}"
        local = status.get("wsrep_local_state_comment")
        assert local == "Synced", f"{member} is {local}, not Synced"

        answer = db_sql(SYNC_WAIT_SQL, member)
        assert answer.isdigit(), f"{member} did not answer: {SYNC_WAIT_SQL}"
        sync_wait = int(answer)
        assert sync_wait >= 1, (f"{member} runs wsrep_sync_wait={sync_wait}: a read there may miss a write "
                                "another member committed, and the demo declares READ_AFTER_WRITE")
        sync_waits.add(sync_wait)

        connections_sql = app_connections_sql(user)
        answer = db_sql(connections_sql, member)
        assert answer.isdigit(), f"{member} did not answer: {connections_sql}"
        held[member] = int(answer)
        assert held[member] >= 1, (f"{member} holds no connection of {user}: the one address does not lead "
                                   "to every member, so the database is not written as a multi-primary")

    spread = ", ".join(f"{held[member]} connection(s) on {member}" if index == 0 else f"{held[member]} on {member}"
                       for index, member in enumerate(members))
    waits = "/".join(str(value) for value in sorted(sync_waits))
    return (f"one cluster of {in_words(count)} members, all synced in the primary component with "
            f"wsrep_sync_wait={waits}; the application holds {spread}")


def app_connections_sql(user):
    """Count the application's other connections, excluding this db-sql probe."""
    return (f"SELECT COUNT(*) FROM information_schema.PROCESSLIST "
            f"WHERE USER = '{user}' AND ID <> CONNECTION_ID()")


def scenario_31_replica_keeps_up_with_the_primary():
    """Every replica took the writes of all preceding scenarios and remains read-only.

    A replica stopped on an early Hilos write, lagging behind, or accepting writes would leave
    every application scenario green: the application never connects to it. Run this last, after
    all other writes, and ask each replica directly through the harness.
    """
    primary = STAND.database.service
    user = STAND.database.user
    position = db_sql(PRIMARY_POSITION_SQL)
    assert position, (f"{primary} writes no binary log: @@gtid_binlog_pos is empty, "
                      "so nothing reaches a replica")
    primary_marker = db_sql(DATABASE_MARKER_SQL)
    assert primary_marker, f"{primary} holds no database marker"

    passed = []
    for replica in STAND.database_replicas:
        member = replica.service
        running_rows = db_sql(REPLICA_RUNNING_SQL, member)
        running = next((value for name, _, value in
                        (row.partition("\t") for row in running_rows.splitlines())
                        if name == "Slaves_running"), "")
        if running != "1":
            lines = db_log(member).splitlines()
            # The server prints a generic [ERROR] after the specific warning. Prefer the
            # diagnostic with its MariaDB error code, then fall back to the generic line.
            reason = next((line for mark in REPLICATION_ERROR_MARKS for line in reversed(lines)
                           if mark in line), "its log names no error")
            raise AssertionError(f"{member} does not replicate: Slaves_running={running or '?'}; {reason}")

        started = time.monotonic()
        waited = db_sql(f"SELECT MASTER_GTID_WAIT('{position}', {REPLICA_CATCH_UP_TIMEOUT:.0f})", member)
        if waited != "0":
            at = db_sql(REPLICA_POSITION_SQL, member)
            raise ScenarioTimeout(f"{member} did not catch up with the primary within "
                                  f"{REPLICA_CATCH_UP_TIMEOUT:.0f} s: it stands at {at or '?'}, "
                                  f"{primary} at {position}")
        elapsed = time.monotonic() - started

        marker = db_sql(DATABASE_MARKER_SQL, member)
        assert marker == primary_marker, (f"{member} holds the database marker {marker or '(none)'}, "
                                          f"{primary} {primary_marker}: the replica is not a copy "
                                          "of the stand's database")
        read_only = db_sql(READ_ONLY_SQL, member)
        assert read_only == "1", (f"{member} takes writes (read_only={read_only or '?'}): "
                                  "a node that reached it by mistake would write past the primary")
        connections_sql = app_connections_sql(user)
        answer = db_sql(connections_sql, member)
        assert answer.isdigit(), f"{member} did not answer: {connections_sql}"
        count = int(answer)
        assert count == 0, (f"{member} holds {count} connection(s) of {user}: the nodes' one "
                            "address leads past the primary, and nothing may read a replica "
                            "(docs/agents/app-topology.md, Database Guarantees)")
        passed.append(f"{member} replicates {primary}: caught up to {position} within "
                      f"{elapsed:.1f} s of asking, read-only, the same database marker, "
                      f"no connection of {user}")
    return "; ".join(passed)


class DbWriteAttempt(namedtuple("DbWriteAttempt", "started finished node value ok member error")):
    """One write from a node, including its failure and the member named by a success."""


class DbLoad:
    """One continuous DB probe writer per node, with every attempt retained for assertions."""

    def __init__(self):
        self._stop = threading.Event()
        self._lock = threading.Lock()
        self._attempts = {node: [] for node in ALL_NODES}
        self._threads = []

    def start(self):
        for node in ALL_NODES:
            thread = threading.Thread(target=self._write, args=(node,), daemon=True)
            self._threads.append(thread)
            thread.start()

    def stop(self):
        self._stop.set()
        for thread in self._threads:
            thread.join()

    def _write(self, node):
        key = f"cluster_db_load_{node}"
        index = 0
        while not self._stop.is_set():
            index += 1
            value = f"w{index}"
            started = time.monotonic()
            outcome = control.client(STAND, node, "test:cluster:db:write", key, value)
            prefix = f"Wrote {key}={value} via "
            member = next((line[len(prefix):] for line in outcome.out.splitlines()
                           if line.startswith(prefix)), None)
            ok = outcome.code == 0 and bool(member)
            error = "" if ok else (outcome.err or outcome.out or f"exit {outcome.code}").strip()
            attempt = DbWriteAttempt(started, time.monotonic(), node, value, ok, member if ok else None, error)
            with self._lock:
                self._attempts[node].append(attempt)
            self._stop.wait(DB_LOAD_INTERVAL_SECONDS)

    def attempts(self, node):
        with self._lock:
            return list(self._attempts[node])

    def last_acknowledged(self, node):
        return next((attempt for attempt in reversed(self.attempts(node)) if attempt.ok), None)

    def member_of(self, node):
        last = self.last_acknowledged(node)
        return last.member if last else None

    def failures_since(self, since):
        return [attempt for node in ALL_NODES for attempt in self.attempts(node)
                if attempt.started >= since and not attempt.ok]


def wait_db_steady(load, since, timeout, victim=None):
    """Wait for three consecutive acknowledged writes per node, begun after `since`."""
    deadline = time.monotonic() + timeout
    while True:
        recovered = {}
        for node in ALL_NODES:
            attempts = [attempt for attempt in load.attempts(node) if attempt.started >= since]
            streak = []
            for attempt in reversed(attempts):
                if not attempt.ok:
                    break
                streak.append(attempt)
            if len(streak) >= DB_LOAD_STEADY:
                recovered[node] = streak[0].started - since
        if len(recovered) == len(ALL_NODES):
            return recovered
        if time.monotonic() >= deadline:
            node = next(node for node in ALL_NODES if node not in recovered)
            attempts = [attempt for attempt in load.attempts(node) if attempt.started >= since]
            last_error = next((attempt.error for attempt in reversed(attempts) if not attempt.ok), "no reply")
            if victim is not None:
                raise ScenarioTimeout(f"{node} did not write again within {timeout:.0f}s of killing "
                                      f"{victim}: {last_error}")
            raise ScenarioTimeout(f"{node} did not produce {DB_LOAD_STEADY} steady writes "
                                  f"within {timeout:.0f}s: {last_error}")
        time.sleep(POLL_INTERVAL)


def database_members_by_hostname():
    """Map the hostname in a probe reply to the service name the controller switches."""
    mapped = {}
    for member in STAND.database_members:
        hostname = db_sql("SELECT @@hostname", member.service)
        assert hostname, f"{member.service} did not answer SELECT @@hostname"
        assert hostname not in mapped, f"two database members answer as {hostname}"
        mapped[hostname] = member.service
    return mapped


def member_back(member, count, timeout, since_start=None):
    """Wait until a returned member is Synced in the expected cluster and UP at the proxy."""
    started = time.monotonic()
    deadline = started + timeout
    synced_at = None
    up_at = None
    while True:
        raw = db_sql(CLUSTER_STATUS_SQL, member)
        status = dict((name.lower(), value) for name, _, value in
                      (line.partition("\t") for line in raw.splitlines()))
        state = status.get("wsrep_local_state_comment", "unavailable")
        size = status.get("wsrep_cluster_size")
        proxy = proxy_states().get(member)
        now = time.monotonic()
        if state == "Synced" and size == str(count) and synced_at is None:
            synced_at = now - started
        if proxy == "UP":
            assert state == "Synced" and size == str(count), \
                f"{member} got new connections while it was {state} in a cluster of {size}"
            if up_at is None:
                up_at = now - started
        if synced_at is not None and up_at is not None:
            return synced_at, up_at
        if time.monotonic() >= deadline:
            if since_start is not None:
                raise ScenarioTimeout(f"{member} is not back within {timeout:.0f}s of its start: "
                                      f"{state}, proxy {proxy}")
            raise ScenarioTimeout(f"{member} did not come back within {timeout:.0f}s: {state}")
        time.sleep(POLL_INTERVAL)


def database_value(member, node):
    """The setting a node's load writer last placed, read directly from one member."""
    return db_sql(f"SELECT `value` FROM `hilos_setting` WHERE `key` = 'cluster_db_load_{node}'", member)


def assert_member_synced(member, count):
    """Assert the same size, Primary and Synced checks as scenario 26."""
    status = member_status(member)
    size = status.get("wsrep_cluster_size")
    assert size == str(count), f"{member} sees a database cluster of {size}, the stand declares {count} members"
    state = status.get("wsrep_cluster_status")
    assert state == "Primary", f"{member} is outside the primary component: wsrep_cluster_status={state}"
    local = status.get("wsrep_local_state_comment")
    assert local == "Synced", f"{member} is {local}, not Synced"


def scenario_27_database_member_dies_under_load():
    """Every node writes through the survivors when the member under the first probe dies."""
    wait_converge(ALL_NODES)
    members = [member.service for member in STAND.database_members]
    for member in members:
        assert_member_synced(member, len(members))
    by_host = database_members_by_hostname()
    started_at = {}
    for node in ALL_NODES:
        outcome = control.node_started_at(STAND, node)
        assert outcome.code == 0, f"could not inspect {node}'s start: {outcome.err}"
        started_at[node] = outcome.out.strip()

    load = DbLoad()
    victim = None
    killed = False
    back = 0.0
    try:
        load.start()
        wait_db_steady(load, 0, DB_MEMBER_DEATH_TIMEOUT)
        victim = by_host.get(load.member_of(MASTERS[0]))
        assert victim is not None, f"{MASTERS[0]} wrote through an unknown database member"
        db_kill(victim)
        killed = True
        killed_at = time.monotonic()
        recovered = wait_db_steady(load, killed_at, DB_MEMBER_DEATH_TIMEOUT, victim)
        load.stop()

        new_member = by_host.get(load.member_of(MASTERS[0]))
        assert new_member != victim, f"{MASTERS[0]} still writes through {victim}, which was killed"
        survivors = [member for member in members if member != victim]
        for member in survivors:
            status = member_status(member)
            size = status.get("wsrep_cluster_size")
            expected = len(survivors)
            assert size == str(expected), (f"{member} sees a database cluster of {size} after {victim} "
                                           f"was killed, {expected} members are left")
            state = status.get("wsrep_cluster_status")
            assert state == "Primary", f"{member} is outside the primary component: wsrep_cluster_status={state}"
            local = status.get("wsrep_local_state_comment")
            assert local == "Synced", f"{member} is {local}, not Synced"
            for node in ALL_NODES:
                last = load.last_acknowledged(node)
                assert last is not None, f"{node} acknowledged no database write"
                held = database_value(member, node)
                assert held == last.value, f"{member} holds {held} for {node}, which last wrote {last.value}"
        for node in ALL_NODES:
            outcome = control.node_started_at(STAND, node)
            assert outcome.code == 0 and outcome.out.strip() == started_at[node], \
                f"{node} was restarted while {victim} was down"
        failed = len(load.failures_since(killed_at))
    finally:
        load.stop()
        if killed:
            db_start(victim)
            _, back = member_back(victim, len(members), DB_MEMBER_RETURN_TIMEOUT)

    recovery = ", ".join(f"{node} after {recovered[node]:.1f}s" for node in ALL_NODES)
    return (f"{victim} killed under {MASTERS[0]}'s probe; every node wrote again ({recovery}), "
            f"{MASTERS[0]} through {new_member}; {failed} write(s) failed in the window; "
            f"{', '.join(survivors)} kept a primary cluster of {in_words(len(survivors))} "
            f"holding every acknowledged write; {victim} back and synced after {back:.0f}s")


def scenario_28_database_member_comes_back_and_catches_up():
    """The returned member catches every confirmed write and takes new links only when Synced."""
    wait_converge(ALL_NODES)
    members = [member.service for member in STAND.database_members]
    for member in members:
        assert_member_synced(member, len(members))
    victim = next((member for member in sorted(members, reverse=True)
                   if member != STAND.database.service), None)
    assert victim is not None, "no non-primary database member to kill"

    load = DbLoad()
    killed = False
    started = False
    try:
        load.start()
        wait_db_steady(load, 0, DB_MEMBER_DEATH_TIMEOUT)
        db_kill(victim)
        killed = True
        killed_at = time.monotonic()
        wait_db_steady(load, killed_at, DB_MEMBER_DEATH_TIMEOUT, victim)
        wait_db_steady(load, time.monotonic(), DB_MEMBER_DEATH_TIMEOUT, victim)
        mark = time.monotonic()
        db_start(victim)
        started = True
        since_start = time.monotonic()
        synced, up = member_back(victim, len(members), DB_MEMBER_RETURN_TIMEOUT, since_start)
        load.stop()

        for node in ALL_NODES:
            last = load.last_acknowledged(node)
            assert last is not None, f"{node} acknowledged no database write"
            held = database_value(victim, node)
            assert held == last.value, f"{victim} came back without {node}'s write: holds {held}, {node} last wrote {last.value}"
        after_start = [attempt for node in ALL_NODES for attempt in load.attempts(node)
                       if attempt.finished >= mark]
        for attempt in after_start:
            assert attempt.ok, f"{attempt.node}'s write failed while {victim} was coming back: {attempt.error}"
    finally:
        load.stop()
        if killed and not started:
            db_start(victim)
            member_back(victim, len(members), DB_MEMBER_RETURN_TIMEOUT)

    return (f"{victim} killed and brought back: synced in a cluster of {in_words(len(members))} "
            f"after {synced:.0f}s and up behind the proxy after {up:.0f}s, holding every write "
            f"acknowledged while it was away; none of {len(after_start)} writes failed while it came back")


class Need(namedtuple("Need", "masters slaves stranger slave_ram nodes master_ram database_members "
                     "cluster_directory entry database_replicas",
                     defaults=(0, 0, False, False, 0, False, 0, False, False, 0))):
    """The shape of stand a scenario is written against: at least `masters` masters and `slaves`
    slaves, a stranger, a slave that declares ram, at least `nodes` members in all, every master
    declaring ram - masters that carry placed work themselves - and a database of at least
    `database_members` members, a cluster directory, a browser entry the stand names, and at least
    `database_replicas` replicas of its database. What a
    scenario names by role - the third master, the second slave - is what it needs."""


class Scenario(namedtuple("Scenario", "name run need")):
    """One scenario of the matrix: its name, number first, the function, and what it needs."""

    @property
    def number(self):
        return int(self.name.split(" ", 1)[0])


# The registry, in the order the matrix runs. A stand names which of these it carries
# (x-hilos-cluster.scenarios) and never their order: the order is what the comments above and
# below explain, and a stand carrying a subset runs it in this same order.
SCENARIOS = [
    # Read every node's original i18n startup and commit before any kill/start rotates its log.
    # Its carrier does not run 21/24/40, whose original startup/placement observations must stay intact.
    Scenario("41 i18n catalog taken once", scenario_41_i18n_catalog_taken_once, Need(nodes=2, slaves=1)),
    # First, because it reads the container logs of the stand `cluster scenarios` has just
    # raised: 9 and 16 kill and recreate nodes, and a recreated container starts a new log.
    Scenario("21 schema rolled out once", scenario_21_schema_rolled_out_once, Need(nodes=2)),
    # Right after 21 because it needs the fleet as the first placement laid it; every later
    # scenario that kills or recreates a node reshuffles it.
    Scenario("24 cut-off leader stops its work", scenario_24_cut_off_leader_stops_its_work,
             Need(masters=3, master_ram=True)),
    # Right after 24 and before every scenario that leans on the shared database: a database that
    # is not one cluster of every member is named here rather than failed for in 11 or 22. It
    # only reads.
    Scenario("26 database is one cluster", scenario_26_database_is_one_cluster, Need(database_members=2)),
    # Keep 24's initial fleet placement, then read fresh global DDL counters before later
    # scenarios can touch the database or replace containers.
    Scenario("40 journal triggers are installed once", scenario_40_journal_triggers_are_installed_once, Need(nodes=2)),
    Scenario("1 master-slave mesh", scenario_1_master_slave_mesh, Need(masters=1, slaves=1)),
    Scenario("2 master-master", scenario_2_master_master, Need(masters=1)),
    Scenario("3 placement", scenario_3_placement, Need(slaves=1)),
    Scenario("12 rt replication", scenario_12_rt_replication, Need(slaves=1)),
    Scenario("20 rt set width across nodes", scenario_20_rt_set_width_across_nodes, Need(masters=2, slaves=2)),
    Scenario("35 rt row deleted while cut off is swept", scenario_35_rt_row_deleted_while_cut_off_is_swept,
             Need(masters=2, slaves=2)),
    Scenario("14 rt claim refused", scenario_14_rt_claim_refused, Need(slaves=1)),
    Scenario("13 rt partition converges", scenario_13_rt_partition_converges, Need(masters=3)),
    Scenario("19 worker death on a live node", scenario_19_worker_death_on_live_node, Need(slaves=1)),
    Scenario("4 slave-kill failover", scenario_4_slave_kill_failover, Need(slaves=2)),
    Scenario("5 leader-kill re-election", scenario_5_leader_kill_reelection, Need(masters=3, slaves=1)),
    Scenario("6 hot-join", scenario_6_hot_join, Need(slaves=2)),
    Scenario("7 quorum-loss", scenario_7_quorum_loss, Need(masters=3, slaves=2)),
    Scenario("8 split-brain prevention", scenario_8_split_brain, Need(masters=3, slaves=2)),
    # After ordinary leadership changes: fill the slaves, inherit waiting demand through both
    # re-election and quorum loss, then restart the whole stand before its later scenarios.
    Scenario("44 waiting work survives leadership changes", scenario_44_waiting_work_survives_leadership_changes,
             Need(masters=3, slaves=2)),
    # After startup observers and 44; its full restart restores the original stand too.
    Scenario("45 upload pool across nodes", scenario_45_upload_pool_across_nodes, Need(masters=2, slaves=2)),
    # Beside 8: both cut the network and recreate whom they cut; it needs the fleet placed, not as
    # the first placement laid it.
    Scenario("36 slave cut off with its leader stops its work",
             scenario_36_slave_cut_off_with_its_leader_stops_its_work, Need(masters=3, slaves=2, slave_ram=True)),
    Scenario("9 daemon-crash self-heal", scenario_9_daemon_crash_selfheal, Need(slaves=2)),
    Scenario("39 person agents across nodes", scenario_39_person_agents_across_nodes, Need(masters=1, slaves=2)),
    Scenario("10 cross-node browser", scenario_10_cross_node_browser, Need(masters=2, slaves=1)),
    Scenario("11 cross-node db fact", scenario_11_cross_node_db_fact, Need(masters=2)),
    Scenario("15 db interest addressing", scenario_15_db_interest_addressing, Need(masters=2)),
    Scenario("16 recreated node leaves no phantom fleet", scenario_16_recreated_node_leaves_no_phantom_fleet,
             Need(slaves=1)),
    Scenario("17 foreign certificate refused", scenario_17_foreign_certificate_refused,
             Need(masters=1, stranger=True)),
    # A slave, or a master that does not lead when there is none: three nodes guarantee one or the
    # other with a quorum left behind. Of the shapes that could carry it, this refuses only a lone
    # master with a lone slave.
    Scenario("22 other database refused", scenario_22_other_database_refused, Need(nodes=3)),
    # These kill one member only after every shared-database scenario has read the intact stand,
    # and each returns it before the next scenario begins.
    Scenario("27 database member dies under load", scenario_27_database_member_dies_under_load,
             Need(database_members=3)),
    Scenario("28 database member comes back and catches up", scenario_28_database_member_comes_back_and_catches_up,
             Need(database_members=3)),
    # After the ordinary failovers: the master alone must retain waiting work, then start it on a
    # returning slave without another ask. Its full restart removes the ballast before either ceiling.
    Scenario("43 waiting work retries when a node returns", scenario_43_waiting_work_retries_when_a_node_returns,
             Need(masters=1, slaves=2)),
    # Right before 18: it fills both slaves to slots of its own and leaves by a full restart of the
    # stand, so 18 meets the room the compose file declares, and nothing it placed on the way.
    Scenario("37 costless agents consume slots", scenario_37_costless_agents_consume_slots, Need(slaves=2)),
    Scenario("18 capacity is consumed", scenario_18_capacity_is_consumed,
             Need(masters=1, slaves=1, slave_ram=True)),
    # Both recreate or stop a slave and restore it before the freeze pair stops every master's agents.
    Scenario("29 cluster directory of its own refused", scenario_29_cluster_directory_of_its_own_refused,
             Need(nodes=3, cluster_directory=True)),
    # The victim is a slave or a non-leading master, so three nodes leave a quorum to observe it.
    Scenario("32 a node with another admin view mode refused", scenario_32_another_admin_view_mode_refused,
             Need(nodes=3)),
    Scenario("42 another default language refused", scenario_42_another_default_language_refused,
             Need(nodes=3, slaves=1)),
    Scenario("30 ready copy outlives its node", scenario_30_ready_copy_outlives_its_node,
             Need(slaves=2, cluster_directory=True)),
    # Before the freeze pair too: it recreates a master that does not lead with liveness spans of
    # its own and kills the worker of the connections owner on the leader, and restores both.
    Scenario("46 browser liveness across masters", scenario_46_browser_liveness_across_masters,
             Need(masters=3, entry=True)),
    # Last, both of them, because the freeze stops the agents of every master: a lift that fails
    # here must not leave its neighbours in the matrix running against a frozen stand.
    Scenario("23 verifier circle on every master", scenario_23_verifier_circle_on_every_master, Need(masters=2)),
    Scenario("25 freeze settles on every master", scenario_25_freeze_settles_on_every_master, Need(masters=2)),
    Scenario("33 every master takes browsers", scenario_33_every_master_takes_browsers,
             Need(masters=2, slaves=1, entry=True)),
    Scenario("34 a tab is the same on every master under protected mode", scenario_34_a_tab_is_the_same_on_every_master,
             Need(masters=3, slaves=1, entry=True)),
    Scenario("38 manual maintenance from any node", scenario_38_manual_maintenance_from_any_node,
             Need(masters=3, slaves=1)),
    # Last of all: each replica took the writes of every preceding scenario. This only reads,
    # so a freeze 23 or 25 may leave behind does not touch it.
    Scenario("31 replica keeps up with the primary", scenario_31_replica_keeps_up_with_the_primary,
             Need(database_replicas=1)),
]

# Park a scenario here (name -> reason) to skip it as known timing-flaky -- the
# cluster analogue of a Playwright test.fixme. "7 quorum-loss" used to sit here for slow
# re-convergence, which turned out to be the membership gossip echoing between nodes
# rather than host load; parking it hid that for as long as it stood, which is the price
# every entry here carries. So an entry is a LOAN, not a cure: it names who owes and for
# what, and it is paid off by the write it points at, not by time passing.
FLAKY_SKIP = {
    # A node that rejoins while the fleet is between placements gets an empty collection
    # and never fills it: its rows are offered only by a node that CLAIMS them, a claim
    # lives exactly as long as the agent writing it, and in that window nobody claims
    # anything while every node still holds all ten rows. Two neighbouring defects were
    # found with it and are fixed (a frame answering for rows it could not carry, and a
    # row born after its claim never being re-offered); this third one is not a fix but a
    # question the interview never answered - what a collection nobody claims right now
    # belongs to - so it is parked rather than guessed at. Until then this scenario
    # guards nothing, and RT convergence after a partition has no other cover.
    #
    # Whoever pays this loan off rewrites the scenario as well as fixing the defect: since
    # HIL-717 a node is sent a collection only while a worker of its own reads one, and the
    # victim here is a master, which reads nothing of this one and so holds no replica to
    # freeze. The victim has to become a node that reads it - which in this demo means a
    # fleet host, and a partitioned fleet host has its members re-placed onto its neighbour,
    # so the rows it is judged by must be the ones it does NOT own.
    "13 rt partition converges": "P-169: an owner with no claim hands over nothing",
    # The one below runs on the ecommerce-shop stand now and is still parked. The owner's
    # review of P-441 (2026-09-28) gave paying it off to a pending hotfix, P-441/2: find why a
    # recreated node keeps a copy of the fleet statuses it no longer reads, and return 16. It
    # is paid off by its line removed and `-- 16` green on the ecommerce-shop stand; until then
    # 16 no longer guards HIL-719.
    #
    # 16 waits for the fleet rows after a recreate and times out at 180s: red in five full
    # runs over two days and retried in two more, and the snapshot after the timeout is one and
    # the same every time: the recreated node reads nothing and owns nothing, yet holds all ten
    # rows.
    "16 recreated node leaves no phantom fleet":
        "P-441/2: a recreated node keeps a copy of the fleet rows it no longer reads",
    # The one below was parked on the owner's word on 2026-10-04, ahead of the HIL-1186 and
    # HIL-1232 full runs: a timing flake foreign to both, red in full runs and green on the
    # rerun of the same sha. It is paid off by finding the cause, its line removed, and
    # `-- 8` green on the stand it failed on.
    #
    # 8, on the cluster stand: red eleven times (0418 to 0567; last 2026-09-26), every time timed
    # out at 180s waiting for the majority {m1,m2} to keep one leader while m3 steps down.
    "8 split-brain prevention":
        "flake: the majority keeps no single leader within 180s (red 11x, last 2026-09-26)",
    # The one below was parked on the owner's word on 2026-10-10 (HOTFIX), without a
    # diagnosis. It is paid off by finding the cause, its line removed, and `-- 36` green on
    # the stand it failed on.
    #
    # 36, on the binance-btc-tracker cluster stand since HIL-1287 (2026-10-08): red in 10 of
    # 72 full runs (0962 to 1056, ten different leaves; last 2026-10-10 on HIL-1350), each
    # time green on the leaf's next run. Every red is the same: s1 carried five fleet members
    # for m1 and was cut off with it, yet its log never got the "Self-fence: placing leader
    # 'm1' stopped leading" line. One symptom every time may be a race in the fence itself
    # rather than in the scenario's wait - that is the first thing to rule out.
    "36 slave cut off with its leader stops its work":
        "flake: the slave cut off with its leader never self-fences (red 10/72, last 2026-10-10)",
    # The one below was parked on the owner's word on 2026-10-10 (HOTFIX), without a
    # diagnosis. It is paid off by finding the cause, its line removed, and `-- 24` green on
    # the online-testing cluster stand.
    #
    # 24, on the online-testing cluster stand, the only one that carries it: red in 5 of 246
    # runs (0733 to 1064; last 2026-10-10 on HIL-1391, green alone on the rerun of the same
    # sha, after a retry), four of them since 2026-10-09, and retried in 9 more. Two symptoms. Three times m1's log never started a
    # fleet member the leader's table placed on it after the cut. Twice both attempts found the
    # new leader carrying no fleet member, because the fleet had already moved under a
    # re-election of the stand's own - the startup link flap of P-459.
    "24 cut-off leader stops its work":
        "flake: a re-election of its own moves the fleet first, or m1 never starts a member (red 5/246, last 2026-10-10)",
    # The one below was parked on the owner's word on 2026-10-16 (HOTFIX), without a
    # diagnosis. It is paid off by finding the cause, its line removed, and `-- 15` green on
    # the online-testing cluster stand.
    #
    # 15, on the online-testing cluster stand: red once in 420 runs (0005 to 1241), on HIL-1538
    # in seven lanes (1240), green alone on the rerun of the same sha (1241). The red is a hard
    # assertion, so it was not retried: m3 counted two database facts taken in where only the one
    # about 'settings' was addressed to it. That counter moves for every database fact a node
    # accepts from another, not only for the two the scenario announces, so a write of the
    # stand's own landing in that window reads exactly like 'fileVariants' carried across the
    # mesh - that is the first thing to rule out.
    "15 db interest addressing":
        "flake: m3 counts two facts taken in where one was addressed to it (red 1/420, last 2026-10-16)",
    # FIXME(F-015, 2026-10-17): the one below was parked on the owner's word (HOTFIX), without
    # a diagnosis, as a known flake. It is paid off by finding the cause, its line removed, and
    # `-- 39` green on the ecommerce-shop cluster stand.
    #
    # 39, on the ecommerce-shop cluster stand since HIL-1412: red once in the 8 runs that carried
    # it (1251 to 1257), on HIL-1353 in seven lanes (1257), green alone on the rerun of the same
    # sha (1258). The wake phase lost second-factor:unlock: s2 refused the signal with "Agent
    # 'hilos_user:1' does not exist after startAgent() call" and started that agent 70 ms later,
    # so the command went unanswered for 15 s. WorkerServer::sendSignalToAgent() throws when the
    # agent is not there right after startAgent(), so a signal that outruns a cold start may be
    # dropped rather than held - that is the first thing to rule out.
    "39 person agents across nodes":
        "F-015: a cold person agent on s2 refused the unlock it started 70 ms later (red 1/8, last 2026-10-17)",
}


def run_scenario(name, fn):
    """Run one scenario, retrying a PURE convergence timeout (transient) up to
    SCENARIO_RETRIES times after re-converging the mesh. Returns the pass detail,
    reporting a successful retry once through hilos-unstable for the suite runner,
    or raises the FIRST failure (hard invariant assertions are never retried).

    If no attempt succeeds, a later failure cannot replace the first. A retry runs
    on a stand the first attempt left skewed, so what it trips over is usually a
    precondition of its own making - and naming that in the summary hides the
    cause behind its consequence and burns the retry budget getting there.

    The branch order is load-bearing: ScenarioTimeout subclasses AssertionError,
    so the narrow except must come first or the broad one would swallow every
    timeout and no scenario would ever be retried. On the FIRST attempt the broad
    branch changes nothing - first_failure is empty there, and a hard assertion
    flies out untouched, exactly as before."""
    first_failure = None
    for attempt in range(1, SCENARIO_RETRIES + 2):
        try:
            detail = fn()
            if first_failure is not None:
                print(f"hilos-unstable: 1 (cluster/scenario-{name.split()[0]})")
            return detail
        except ScenarioTimeout as e:
            if first_failure is None:
                first_failure = e
            if attempt > SCENARIO_RETRIES:
                raise first_failure
            print(f"  RETRY ({attempt}/{SCENARIO_RETRIES}) after timeout: {e}")
            # The scenario's own finally has already restored any perturbation;
            # settle the full mesh before the next attempt so it starts clean.
            try:
                wait_converge(ALL_NODES)
            except ScenarioTimeout:
                pass
        except Exception as e:
            if first_failure is None:
                raise
            print(f"  the retry failed on something the first failure caused: {e}")
            raise first_failure from e


def unmet_need(stand, scenario):
    """What a stand lacks to carry a scenario, said the way the refusal says it, or None."""
    need = scenario.need
    for wanted, has, what in ((need.masters, len(stand.masters), "masters"),
                              (need.slaves, len(stand.slaves), "slaves"),
                              (need.nodes, len(stand.members), "nodes"),
                              (need.database_members, len(stand.database_members), "database members"),
                              (need.database_replicas, len(stand.database_replicas), "database replicas")):
        if has < wanted:
            return f"it needs {wanted} {what}, the stand has {has}"
    if need.stranger and stand.stranger is None:
        return "it needs a stranger, the stand has none"
    if need.cluster_directory and stand.cluster_directory is None:
        return "it needs a cluster directory, the stand names none"
    if need.entry and stand.entry is None:
        return "it needs a browser entry, the stand names none"
    if need.slave_ram and not any(stand.members[s].ram for s in stand.slaves):
        return "it needs a slave that declares ram, the stand has none"
    if need.master_ram:
        bare = next((m for m in stand.masters if not stand.members[m].ram), None)
        if bare is not None:
            return f"it needs every master to declare ram, {bare} declares none"
    return None


def bind(stand):
    """Take the stand the matrix drives: its nodes by role, its stranger, its slaves' room."""
    global STAND, MASTERS, SLAVES, ALL_NODES, STRANGER, STRANGER_IP
    global SLAVE_RAM, SLAVE_WORK_GRACE_SEC, BALLAST_ASKED
    STAND = stand
    MASTERS = list(stand.masters)
    SLAVES = list(stand.slaves)
    ALL_NODES = MASTERS + SLAVES
    STRANGER = stand.stranger.id if stand.stranger else None
    STRANGER_IP = stand.stranger.ip if stand.stranger else None
    SLAVE_RAM = {s: stand.members[s].ram for s in SLAVES if stand.members[s].ram}
    SLAVE_WORK_GRACE_SEC = stand.slave_work_grace_sec
    BALLAST_ASKED = sum(ram // BALLAST_RAM_COST for ram in SLAVE_RAM.values()) + 1


def run_matrix(stand, numbers):
    """Run the named scenarios on a stand that is up, in the registry's order: 0 when all pass."""
    def _fmt(x):
        return f"{x:.2f}" if isinstance(x, float) else "n/a"

    bind(stand)
    selected = [scenario for scenario in SCENARIOS if scenario.number in numbers]
    print(f"cluster e2e: stand {stand.project}, {len(stand.members)} nodes ({len(stand.masters)} masters, "
          f"{len(stand.slaves)} slaves), scenarios {', '.join(str(s.number) for s in selected)}")
    print(f"cluster e2e: timeout scale={TIMEOUT_SCALE:g} "
          f"(load/cpu={_fmt(_load_per_cpu())}, free={_fmt(_free_gib())} GiB), "
          f"retries={SCENARIO_RETRIES}")
    print("cluster e2e: waiting for the initial mesh to converge...")
    try:
        wait_converge(ALL_NODES)
    except AssertionError as e:
        print(f"FATAL: cluster never converged: {e}")
        return 1

    failures = []
    skipped = []
    for name, fn, _need in selected:
        print(f"\n== scenario {name} ==")
        if name in FLAKY_SKIP:
            print(f"  SKIP (fixme): {FLAKY_SKIP[name]}")
            skipped.append(name)
            continue
        try:
            detail = run_scenario(name, fn)
            print(f"  PASS: {detail}")
        except AssertionError as e:
            print(f"  FAIL: {e}")
            failures.append(name)
        except Exception as e:  # noqa: BLE001 - report and continue
            print(f"  ERROR: {type(e).__name__}: {e}")
            failures.append(name)

    ran = len(selected) - len(skipped)
    print("\n=== summary ===")
    print(f"  {ran - len(failures)}/{ran} scenarios passed"
          + (f"; {len(skipped)} skipped ({', '.join(skipped)})" if skipped else ""))
    if failures:
        print(f"  failed: {', '.join(failures)}")
        return 1
    print("  all cluster scenarios passed")
    return 0

"""
scenarios.py - the assertion matrix of the daemon-cluster e2e harness (HIL-185), one for
every cluster stand.

It assumes the stand is already up (cluster.py raises it fresh before the matrix) and drives
it: for each scenario it perturbs the cluster through the controller (control.py: docker kill
-9 for node-down, docker network disconnect for partition, and a SIGKILL of the daemon or one
worker inside a live container), polls each node's `test:cluster:inspect` reply until the
topology converges (bounded by a hard cap), and asserts the expected invariants against the
machine-readable reply. Destructive scenarios restore the cluster and re-converge before the
next.

The stand is not written down here. bind() takes it once, before the matrix, and the nodes a
scenario perturbs are named by their role on it - the first master, the second slave - never
by id. Each scenario says what shape it needs (Need), and a stand that names a scenario it
cannot carry is refused before it is raised.

Timing on a loaded host (HIL-367): the convergence caps below are sized for an
adequately-provisioned stand (nova-lt / HIL-348). On a resource-constrained host
the grace-driven detection windows (keepalive-timeout + failover-grace, HIL-183)
run longer than the fixed caps and the matrix flakes on pure "timed out after Ns"
without the cluster logic being wrong. Two guards keep the run honest without
falsely passing:
  * the caps are multiplied by a TIMEOUT_SCALE (>= 1.0) derived from the host's
    load-per-cpu and free memory, the same inside the full run and outside it;
    the runner no longer exports a factor from its lane count (HIL-1227).
    CLUSTER_E2E_TIMEOUT_SCALE is a pin made by hand, and a FLOOR which little
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
  9 daemon-crash self-heal     a node whose daemon is SIGKILLed rebinds and rejoins
                               inside the same container (HIL-450)
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
                               never lands on a master, and a full cluster places no more (HIL-448)
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

run_matrix() answers 0 when every scenario passes, 1 otherwise.
"""

import json
import os
import re
import time
from collections import namedtuple
from datetime import datetime

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

    Nothing in the repository sets CLUSTER_E2E_TIMEOUT_SCALE: it is a pin made by
    hand — someone debugging a scenario, a CI that knows its own box. The runner
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
# then does the new daemon sweep the orphans, bind, and gossip its way back in.
CRASH_RECOVERY_TIMEOUT = 90.0 * TIMEOUT_SCALE


# --------------------------------------------------------------------------- io

def ctl(*args):
    """Run a controller command (kill/start/recreate/partition/heal/stranger), its answer dropped."""
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


def db_sql(statement, member=None):
    """Run one SQL statement in the stand's database, or on the member of a clustered one that
    `member` names by service: its rows as printed, or '' when it failed."""
    return ctl_out("db-sql", statement, *([member] if member else []))


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


def client_deliveries(views, node):
    """Cross-node client deliveries a node reports having accepted, or -1 when unreachable."""
    view = views.get(node)
    return -1 if view is None else int(view.get("clientDeliveries", 0))


def db_replicas(views, node):
    """Cross-node DB replicas a node reports having accepted, or -1 when unreachable."""
    view = views.get(node)
    return -1 if view is None else int(view.get("dbReplicas", 0))


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
    """Predicate: exactly one leader, and every expected node is online in its roster."""
    expected = set(expected_nodes)

    def check(views):
        ls = leaders(views)
        if len(ls) != 1:
            return False
        roster = views[ls[0]].get("nodes", [])
        online = {n["nodeId"] for n in roster if n.get("online")}
        return expected.issubset(online)

    return check


def wait_converge(expected_nodes=None, timeout=CONVERGE_TIMEOUT):
    expected_nodes = ALL_NODES if expected_nodes is None else expected_nodes
    return wait_until(converged(expected_nodes), timeout,
                      f"single leader + online roster {sorted(expected_nodes)}")


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


def scenario_9_daemon_crash_selfheal():
    """A daemon killed inside a live container must come back on its own (HIL-450).

    This is the crash the harness used to paper over with `--force-recreate`: the
    daemon dies, its workers survive as orphans on the watchdog and keep holding its
    listening sockets, and without a sweep every restart fails to bind forever. The
    container is deliberately NOT replaced, so the only way back is the watchdog
    reaping its own children before the next daemon start.
    """
    victim = SLAVES[0]
    wait_until(fleet_started, CONVERGE_TIMEOUT, "the fleet is placed before the crash")
    before = container_id(victim)
    assert before, f"could not read the container id of {victim}"

    killed = ctl_out("crash-daemon", victim)
    assert "SIGKILLed" in killed, f"could not kill the daemon inside {victim}: {killed or '(no output)'}"
    print(f"    {killed.removeprefix('cluster: ')}; its workers stay behind holding the ports")

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
        return (f"{victim} self-healed in the same container ({before[:12]}) "
                f"and then took all {WORKER_FLEET_SIZE} workers")
    finally:
        # Recreate rather than start: a node that did NOT self-heal is still running, so
        # `start` would be a no-op on it and every later attempt would inherit the wedge.
        # Replacing the container is the only way back, and after a pass it is a cheap reset.
        ctl("recreate", victim)
        wait_converge(ALL_NODES)


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
    locally, and the counter here is of frames that came off the mesh.

    The demo is headless, so the browser is attached through the CLI and the delivery is read
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
        before = {n: client_deliveries(views, n) for n in ALL_NODES}
        assert client(sender, "test:cluster:client:send", key, "hello"), \
            f"could not raise an addressed signal on {sender}"

        def addressed_arrived(views):
            view = views.get(holder)
            return (bool(view)
                    and client_deliveries(views, holder) > before[holder]
                    and view.get("lastClientAcceptKey") == key)

        wait_until(addressed_arrived, CONVERGE_TIMEOUT,
                   f"{holder} takes in the signal {sender} addressed to its browser")

        others = [n for n in ALL_NODES if n not in (holder, sender)]
        quiet = inspect_all(others)
        for node in others:
            assert client_deliveries(quiet, node) == before[node], \
                f"an addressed signal reached {node}, which holds no such browser"

        views = inspect_all()
        before = {n: client_deliveries(views, n) for n in ALL_NODES}
        assert client(sender, "test:cluster:client:fanout", "everyone"), \
            f"could not raise a fan-out on {sender}"

        receivers = [n for n in ALL_NODES if n != sender]
        wait_until(lambda v: all(client_deliveries(v, n) > before[n] for n in receivers),
                   CONVERGE_TIMEOUT, "every node but the sender takes in the fan-out",
                   nodes=receivers)
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
    """
    sender, watcher = MASTERS[0], MASTERS[1]
    wait_converge(ALL_NODES)

    sender_replicas_before = db_replicas(inspect_all([sender]), sender)

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
    # them it has counted.
    assert db_replicas(inspect_all([sender]), sender) == sender_replicas_before, \
        f"{sender} counted its own writes as replicas; a fact must not come back to its raiser"

    # Tact three: a link that went away and came back.
    print(f"    recreating {watcher} to break and re-establish its links")
    ctl("recreate", watcher)
    wait_converge(ALL_NODES)

    # The container is new, so its copy is taken here rather than inherited - and only once it
    # holds one is the write below asking anything of the mesh.
    wait_db_value(watcher, DB_PROBE_KEY, "v2", CONVERGE_TIMEOUT,
                  f"the recreated {watcher} takes a copy of the row")
    assert client(sender, "test:cluster:db:write", DB_PROBE_KEY, "v3"), \
        f"could not write the probe row on {sender} after the reconnect"
    wait_db_value(watcher, DB_PROBE_KEY, "v3", CONVERGE_TIMEOUT,
                  f"{watcher} follows a write made after it re-linked")

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
    - hilos_probe_ballast:7 has no record, and still has none after the retry interval has
      passed twice and a second ask: a node whose stock is spent is no candidate, and with both
      slaves full there is nowhere left. What should become of such work is HIL-446, not this
      scenario.

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
            if row:
                placed[row.get("nodeId")] = placed.get(row.get("nodeId"), 0) + 1
        return placed

    placed = layout(views)
    for slave, ram in SLAVE_RAM.items():
        assert placed.get(slave, 0) == ram // BALLAST_RAM_COST, \
            f"{slave} (ram={ram}) holds {placed.get(slave, 0)} ballasts, not {ram // BALLAST_RAM_COST}: {placed}"
    for master in MASTERS:
        assert master not in placed, f"{master} declares no capacity yet holds {placed[master]} ballasts"
    last = ids[BALLAST_ASKED - 1]
    assert placement_row(views, last) == {}, \
        f"{last} was placed although both slaves are full: {placement_row(views, last)}"

    # Full stays full: past two retry intervals and a second ask, the last ballast still has
    # nowhere to go and the split has not moved.
    time.sleep(POLICY_PLACEMENT_RETRY_SEC * 2)
    assert client(leader, "test:cluster:agent:place", BALLAST_AGENT_TYPE, str(BALLAST_ASKED - 1)), \
        f"could not ask {leader} for {last} a second time"
    time.sleep(POLICY_PLACEMENT_RETRY_SEC)
    views = inspect_all()
    assert placement_row(views, last) == {}, \
        f"{last} was placed on a second ask although both slaves are full: {placement_row(views, last)}"
    assert layout(views) == placed, f"the ballast layout moved from {placed} to {layout(views)}"

    return (f"{leader} placed {fit} ballasts as {placed} in proportion to {SLAVE_RAM}, none on a "
            f"master, and found no room for {last}")


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


# The row that names the database every node of the stand reads (HIL-1206); a marker is 32 hex.
DATABASE_MARKER_SQL = "SELECT marker FROM hilos_database_marker WHERE id = 1"
DATABASE_MARKER = re.compile(r"^[0-9a-f]{32}$")
# Mirrors ClusterDirectoryMarker::FILE_NAME and HilosAgentType::HILOS_DATA_EXPORT.
CLUSTER_DIRECTORY_MARKER_FILE = ".hilos-cluster-directory.json"
DATA_EXPORT_AGENT_TYPE = "hilos_data_export"


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


def scenario_30_ready_copy_outlives_its_node():
    """The export agent builds a copy on one slave and finds it after moving to another.

    A preparing database row asks the real agent to build it. On start the replacement agent
    removes a ready row if its file is missing, so the unchanged ready row after failover proves
    that the new host could see the shared file before it started work.
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

    return (f"{holder} built {stored_name[:8]}….zip; after {holder} died the agent started "
            f"on {new_holder} and the copy was still ready there")


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
    wrong = {n: (None if r is None else {k: r.get(k) for k in ("phase", "circleSize", "circleAdmitted")})
             for n, r in replies.items() if r is None or not predicate(r)}
    raise ScenarioTimeout(f"timed out after {CONVERGE_TIMEOUT:.0f}s waiting for: {desc}\n"
                          f"masters still not there: {wrong}")


def scenario_23_verifier_circle_on_every_master():
    """The verifier circle photographed at a freeze lands on every master's row (HIL-1125).

    A member of the circle is let into the verification window by the session its tab already
    carries, and a tab connects to whichever node the balancer hands it - so each node decides
    that admission against its own copy of the freeze row, and the photograph has to be on all of
    them. One address is named to the circle, the stand is frozen through the test drive of the
    index agent, and every master is asked for its circle: one named, nobody online. The window
    opens and every master still holds it; the system opens and every master has dropped it.

    Headless, so what is proved is the photograph on the rows, not a browser walking in: a
    signed-in tab let in through a node that does not host the initiator is HIL-1232's. Slaves
    are not asked: the freeze frames reach masters only.

    The drive commands go to the leader, because that is where the index agent runs, and a reply
    to a command answered by an agent on another node never makes it back to the node that asked.
    So the initiator here is the leader; a follower or a slave initiating is covered by the unit
    tests of ClusterProtectedMode.

    Leadership must not move while the freeze holds. The index agent follows leadership, while the
    freeze stays authorized by the node that asked for it, so a re-election strands the freeze:
    the agent answers on the new leader, and the new leader refuses its lift as coming from the
    wrong node. A stand raised a moment ago can re-elect on its own - its links flap once as late
    seed dials land (P-459) - which is why this runs last in the matrix rather than first. When
    it happens anyway, the failure says so instead of leaving only a timeout to read.
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
                  f"(term {now[moved[0]].get('term')}) under the freeze; its lift is refused there (P-459)")
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

    The drive commands go to the leader, because that is where the index agent runs, and a reply
    to a command answered by an agent on another node never makes it back to the node that asked.
    So the initiator here is the leader; a follower or a slave initiating is covered by the unit
    tests of ClusterProtectedMode.

    Leadership must not move while the freeze holds. The index agent follows leadership, while the
    freeze stays authorized by the node that asked for it, so a re-election strands the freeze:
    the agent answers on the new leader, and the new leader refuses its lift as coming from the
    wrong node. A stand raised a moment ago can re-elect on its own - its links flap once as late
    seed dials land (P-459) - which is why this runs last in the matrix rather than first. When
    it happens anyway, the failure says so instead of leaving only a timeout to read.
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
                  f"(term {now[moved[0]].get('term')}) under the freeze; its lift is refused there (P-459)")
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
        # leave reaches the verification window, and open lifts it (HIL-1304 Data Result
        # abbreviates this drive to enter -> open, which the driver refuses).
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
CONSENSUS_WON_TERM = re.compile(r"^\[([^\]]+)\].*Consensus: won term \d+ with ", re.M)
LOG_LINE_TIME = "%Y-%m-%d %H:%M:%S.%f"


def log_time(stamp):
    """The moment a daemon log line was written, read off its `[Y-m-d H:i:s.mmm]` prefix."""
    return datetime.strptime(stamp, LOG_LINE_TIME)


def started_on_worker_at(text, agent_id):
    """The moments one agent was started on a worker in a piece of a node's log, oldest first."""
    pattern = re.compile(rf"^\[([^\]]+)\].*Agent '{re.escape(agent_id)}' started on worker #\d+", re.M)
    return [log_time(stamp) for stamp in pattern.findall(text)]


def placed_on_node_at(text, agent_id):
    """Where and when a leader placed one agent in a piece of its log, as (moment, node), oldest first."""
    pattern = re.compile(rf"^\[([^\]]+)\].*Placing agent '{re.escape(agent_id)}' on node '([^']+)'", re.M)
    return [(log_time(stamp), node) for stamp, node in pattern.findall(text)]


def assert_leader_took_no_new_member(views):
    """The leader carries no fleet member it did not hold before it won its term (HIL-445, rule 3).

    Last among equals is a rule about NEW work: a master that carried members and then won a term
    keeps them, and a stand raised a moment ago can re-elect on its own (P-459). So a member on
    the leader is a failure only when the leader started it after its own last won term.
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
    assert not taken, (f"leader {leader} took fleet member(s) {taken} after it won its term while "
                       f"{[n for n in MASTERS if n != leader]} could carry them: the leader is last among "
                       "equal masters (HIL-445)")
    print(f"    leader {leader} carries {len(on_leader)} member(s) it held before it won its term (P-459)")


def spread_fleet_over(carriers, views):
    """Make both masters that do not lead carry fleet members, and answer the views that show it.

    One of them carries none only when it was not there for the first placement. One worker of the
    other is killed, and the leader places the members it hosted again - on the empty master,
    which runs the fewest agents, and the leader stays last.
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


def scenario_24_cut_off_leader_stops_its_work():
    """A leader that carries work and is cut off stops it before the majority runs it (HIL-1217).

    On a stand of equal masters that carry the work themselves the leader takes none while another
    master can (HIL-445, rule 3), so it holds work only by inheritance: the leader dies and a
    master carrying members wins the next term, keeping them, while its neighbours' members keep
    writing past the fence window of the leader that placed them (as in scenario 5). One worker is
    killed first when only one of the masters that do not lead carries any, so whichever of the
    two wins carries members.

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
    assert_leader_took_no_new_member(views)
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


# What scenario 26 asks every member of a clustered database about itself.
CLUSTER_STATUS_SQL = ("SHOW GLOBAL STATUS WHERE Variable_name IN "
                      "('wsrep_cluster_size', 'wsrep_cluster_status', 'wsrep_local_state_comment')")
SYNC_WAIT_SQL = "SELECT @@GLOBAL.wsrep_sync_wait"


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
        status = {}
        for line in db_sql(CLUSTER_STATUS_SQL, member).splitlines():
            name, _, value = line.partition("\t")
            status[name.lower()] = value
        assert status, f"{member} did not answer: {CLUSTER_STATUS_SQL}"
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

        connections_sql = (f"SELECT COUNT(*) FROM information_schema.PROCESSLIST "
                           f"WHERE USER = '{user}' AND ID <> CONNECTION_ID()")
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


class Need(namedtuple("Need", "masters slaves stranger slave_ram nodes master_ram database_members "
                     "cluster_directory entry", defaults=(0, 0, False, False, 0, False, 0, False, False))):
    """The shape of stand a scenario is written against: at least `masters` masters and `slaves`
    slaves, a stranger, a slave that declares ram, at least `nodes` members in all, every
    master declaring ram - masters that carry placed work themselves - and a database of at
    least `database_members` members, a cluster directory, and a browser entry the stand names.
    What a scenario
    names by role - the third master, the second slave - is what it needs."""


class Scenario(namedtuple("Scenario", "name run need")):
    """One scenario of the matrix: its name, number first, the function, and what it needs."""

    @property
    def number(self):
        return int(self.name.split(" ", 1)[0])


# The registry, in the order the matrix runs. A stand names which of these it carries
# (x-hilos-cluster.scenarios) and never their order: the order is what the comments above and
# below explain, and a stand carrying a subset runs it in this same order.
SCENARIOS = [
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
    Scenario("1 master-slave mesh", scenario_1_master_slave_mesh, Need(masters=1, slaves=1)),
    Scenario("2 master-master", scenario_2_master_master, Need(masters=1)),
    Scenario("3 placement", scenario_3_placement, Need(slaves=1)),
    Scenario("12 rt replication", scenario_12_rt_replication, Need(slaves=1)),
    Scenario("20 rt set width across nodes", scenario_20_rt_set_width_across_nodes, Need(masters=2, slaves=2)),
    Scenario("14 rt claim refused", scenario_14_rt_claim_refused, Need(slaves=1)),
    Scenario("13 rt partition converges", scenario_13_rt_partition_converges, Need(masters=3)),
    Scenario("19 worker death on a live node", scenario_19_worker_death_on_live_node, Need(slaves=1)),
    Scenario("4 slave-kill failover", scenario_4_slave_kill_failover, Need(slaves=2)),
    Scenario("5 leader-kill re-election", scenario_5_leader_kill_reelection, Need(masters=3, slaves=1)),
    Scenario("6 hot-join", scenario_6_hot_join, Need(slaves=2)),
    Scenario("7 quorum-loss", scenario_7_quorum_loss, Need(masters=3, slaves=2)),
    Scenario("8 split-brain prevention", scenario_8_split_brain, Need(masters=3, slaves=2)),
    Scenario("9 daemon-crash self-heal", scenario_9_daemon_crash_selfheal, Need(slaves=2)),
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
    Scenario("18 capacity is consumed", scenario_18_capacity_is_consumed,
             Need(masters=1, slaves=1, slave_ram=True)),
    # Both recreate or stop a slave and restore it before the freeze pair stops every master's agents.
    Scenario("29 cluster directory of its own refused", scenario_29_cluster_directory_of_its_own_refused,
             Need(nodes=3, cluster_directory=True)),
    Scenario("30 ready copy outlives its node", scenario_30_ready_copy_outlives_its_node,
             Need(slaves=2, cluster_directory=True)),
    # Last, both of them, because the freeze stops the agents of every master: a lift that fails
    # here must not leave its neighbours in the matrix running against a frozen stand.
    Scenario("23 verifier circle on every master", scenario_23_verifier_circle_on_every_master, Need(masters=2)),
    Scenario("25 freeze settles on every master", scenario_25_freeze_settles_on_every_master, Need(masters=2)),
    Scenario("33 every master takes browsers", scenario_33_every_master_takes_browsers,
             Need(masters=2, slaves=1, entry=True)),
    Scenario("34 a tab is the same on every master under protected mode", scenario_34_a_tab_is_the_same_on_every_master,
             Need(masters=3, slaves=1, entry=True)),
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
    # The two below run on the ecommerce-shop stand now, and both are still parked. The owner's
    # review of P-441 (2026-09-28) gave paying them off to two pending hotfixes: P-441/1 returns
    # 19 (every retry it was parked on predates HIL-1162, which fixed exactly that), P-441/2
    # finds why a recreated node keeps a copy of the fleet statuses it no longer reads and
    # returns 16. Each is paid off by its line removed and `-- 16` / `-- 19` green on the
    # ecommerce-shop stand; until then 16 no longer guards HIL-719 and 19 no longer guards
    # HIL-440.
    #
    # 16 waits for the fleet rows after a recreate and times out at 180s: red in five full
    # runs over two days and retried in two more, and the snapshot after the timeout is one and
    # the same every time: the recreated node reads nothing and owns nothing, yet holds all ten
    # rows.
    "16 recreated node leaves no phantom fleet":
        "P-441/2: a recreated node keeps a copy of the fleet rows it no longer reads",
    # 19 waits for the victim to name the agents it lost with the killed worker: retried three
    # times in the same two days, green each time on the second attempt, never red.
    "19 worker death on a live node":
        "P-441/1: parked on retries that all predate HIL-1162",
    # 20 is red on the binance-btc-tracker stand - the first stand of a whole demo - for a defect
    # of the demo, not of the scenario: the auth throttle (AuthThrottleAgent, SCOPE NODE) claims
    # hilosAuthAttempts whole on every node, the leader refuses all of them but one, and which
    # one it keeps changes with every re-link, so the count of refused claims that step (5) reads
    # moves under it. With the throttle taken out of the demo the scenario is green on the same
    # stand. How the throttle holds its collection on a cluster is not decided (P-456, the return
    # of P-082). Whoever fixes the throttle pays this loan off: this line removed and `-- 20`
    # green on the binance stand. Until then no scenario guards the width of a set across nodes
    # (HIL-1116).
    "20 rt set width across nodes":
        "P-456: the auth throttle claims its collection whole on every node",
    # 2026-10-04: foreign baseline failure, attempt 0 for HIL-1305. After scenario 23,
    # scenario 25 closes a freeze while m2 has not stopped hilos_auth_throttle again.
    # The throttle claims hilosAuthAttempts whole on every node (P-456), so the
    # owner's decision parks this assertion. TODO(HIL-1280): fix the claim, remove
    # this line and run -- 25 on the binance stand to pay off the loan.
    "25 freeze settles on every master":
        "P-456: m2 does not stop hilos_auth_throttle again after the freeze closes",
}


def run_scenario(name, fn):
    """Run one scenario, retrying a PURE convergence timeout (transient) up to
    SCENARIO_RETRIES times after re-converging the mesh. Returns the pass detail,
    or raises the FIRST failure (hard invariant assertions are never retried).

    The first failure is the verdict, and a retry cannot replace it. A retry runs
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
            return fn()
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
                              (need.database_members, len(stand.database_members), "database members")):
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

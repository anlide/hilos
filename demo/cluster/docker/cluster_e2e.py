#!/usr/bin/env python3
"""
cluster_e2e.py - the 8-scenario assertion matrix for the daemon-cluster e2e
harness (HIL-185).

It assumes the stack is already up (via `cluster up`) and drives it: for each
scenario it perturbs the cluster through the sibling `cluster` bash controller
(docker kill -9 for node-down, docker network disconnect for partition), polls
each node's `cluster:test:inspect` reply until the topology converges (bounded by
a hard cap), and asserts the expected invariants against the machine-readable
reply. Destructive scenarios restore the cluster and re-converge before the next.

Timing on a loaded host (HIL-367): the convergence caps below are sized for an
adequately-provisioned stand (nova-lt / HIL-348). On a resource-constrained host
the grace-driven detection windows (keepalive-timeout + failover-grace, HIL-183)
run longer than the fixed caps and the matrix flakes on pure "timed out after Ns"
without the cluster logic being wrong. Two guards keep the run honest without
falsely passing:
  * the caps are multiplied by an adaptive TIMEOUT_SCALE (>= 1.0) — set
    CLUSTER_E2E_TIMEOUT_SCALE to override, otherwise it is auto-derived from the
    host's load-per-cpu and free memory. A provisioned host stays at 1.0.
  * a scenario that fails PURELY on a convergence timeout is retried a bounded
    number of times (CLUSTER_E2E_RETRIES, default 1) after re-converging; a hard
    invariant assertion never retries and fails immediately.

Covers the full spike-HIL-176 matrix:
  1 master-slave mesh          exactly one leader, slaves follow
  2 master-master              one leader among masters, slaves never lead
  3 placement                  the no-op agent is placed on a data-plane node
  4 slave-kill failover        leader re-places the agent onto another slave
  5 leader-kill re-election     survivors elect a new leader
  6 hot-join                   a returning node is admitted; full roster
  7 quorum-loss                a minority stops leading; no new leader
  8 split-brain prevention     the majority keeps one leader; the minority steps down

Exit code 0 when every scenario passes, 1 otherwise.
"""

import json
import os
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
CLUSTER = str(HERE / "cluster")

MASTERS = ["m1", "m2", "m3"]
SLAVES = ["s1", "s2"]
ALL_NODES = MASTERS + SLAVES

WORKER_AGENT_TYPE = "worker"
# Fleet size the leader keeps placed; mirrors ClusterDaemonManager::WORKER_FLEET_SIZE.
WORKER_FLEET_SIZE = 10


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

    An explicit CLUSTER_E2E_TIMEOUT_SCALE wins; otherwise it is derived from the
    host's load-per-cpu and free memory. Capped at 4.0 so a runaway host still
    fails in bounded time. A well-provisioned host resolves to 1.0 (no change).
    """
    override = os.environ.get("CLUSTER_E2E_TIMEOUT_SCALE")
    if override:
        try:
            return max(1.0, float(override))
        except ValueError:
            print(f"  (ignoring non-numeric CLUSTER_E2E_TIMEOUT_SCALE={override!r})")

    scale = 1.0
    lpc = _load_per_cpu()
    if lpc and lpc > 0.75:
        scale = max(scale, 1.0 + (lpc - 0.75))
    free = _free_gib()
    if free is not None:
        if free < 1.0:
            scale = max(scale, 3.0)
        elif free < 2.0:
            scale = max(scale, 2.0)
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


# --------------------------------------------------------------------------- io

def ctl(*args):
    """Run the sibling bash controller (kill/start/partition/heal)."""
    subprocess.run([CLUSTER, *args], check=False,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def _inspect(subcmd, node):
    proc = subprocess.run([CLUSTER, subcmd, node], capture_output=True, text=True)
    out = proc.stdout
    brace = out.find("{")
    if brace < 0:
        return None
    try:
        obj, _ = json.JSONDecoder().raw_decode(out[brace:])
        return obj
    except json.JSONDecodeError:
        return None


def inspect(node):
    """Return a node's cluster:test:inspect reply as a dict, or None if unreachable."""
    return _inspect("inspect", node)


def inspect_local(node):
    """Inspect a node from inside its own container (works while it is partitioned off the network)."""
    return _inspect("inspect-local", node)


def inspect_all(nodes=ALL_NODES):
    return {n: inspect(n) for n in nodes}


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


def hosted_by(views, node):
    """Agent ids of the fleet members the leader reports started on one node."""
    return {i for i, row in worker_placements(views).items()
            if row.get("nodeId") == node and row.get("state") == "started"}


def fleet_started(views):
    """Predicate: every fleet member is placed and started somewhere."""
    rows = worker_placements(views)
    return len(rows) == WORKER_FLEET_SIZE and all(r.get("state") == "started" for r in rows.values())


# ------------------------------------------------------------------- polling

class ScenarioTimeout(AssertionError):
    """A convergence poll hit its cap. A subclass of AssertionError so existing
    handlers still catch it, but distinct so the runner can retry a pure timeout
    (transient, env-driven) while failing hard invariant assertions immediately."""


def wait_until(predicate, timeout, desc, nodes=ALL_NODES):
    """Poll inspect(nodes) until predicate(views) is truthy; return the final views."""
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        views = inspect_all(nodes)
        last = views
        if predicate(views):
            return views
        time.sleep(POLL_INTERVAL)
    raise ScenarioTimeout(f"timed out after {timeout:.0f}s waiting for: {desc}\n"
                          f"last view: {summarize(last)}")


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


def wait_converge(expected_nodes=ALL_NODES, timeout=CONVERGE_TIMEOUT):
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
    views = wait_converge(ALL_NODES)
    old_leader = leaders(views)[0]
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
        return f"re-elected {new_leader} after {old_leader} died"
    finally:
        ctl("start", old_leader)
        wait_converge(ALL_NODES)


def scenario_6_hot_join():
    wait_converge(ALL_NODES)
    joiner = "s2"
    print(f"    taking {joiner} down, then hot-joining it back")
    ctl("kill", joiner)
    wait_until(
        lambda v: (lambda ls: len(ls) == 1 and not any(
            n["nodeId"] == joiner and n.get("online") for n in v[ls[0]].get("nodes", [])
        ))(leaders(v)),
        CONVERGE_TIMEOUT, f"{joiner} seen offline in the roster",
        nodes=[n for n in ALL_NODES if n != joiner])
    ctl("start", joiner)
    wait_until(
        lambda v: (lambda ls: len(ls) == 1 and {n["nodeId"] for n in v[ls[0]].get("nodes", []) if n.get("online")} >= set(ALL_NODES))(leaders(v)),
        CONVERGE_TIMEOUT, f"{joiner} re-admitted; full roster of {len(ALL_NODES)}")
    return f"{joiner} hot-joined; gossip shows the full roster"


def scenario_7_quorum_loss():
    wait_converge(ALL_NODES)
    victims = ["m2", "m3"]  # leave m1 alone as the isolated minority (1 of 3 < quorum 2)
    print(f"    killing masters {victims}; the lone survivor m1 must stop leading")
    for v in victims:
        ctl("kill", v)
    try:
        def minority_no_quorum(views):
            m1 = views.get("m1")
            if not m1 or m1.get("hasQuorum") is not False:
                return False
            # No node anywhere may still claim leadership.
            return len(leaders(inspect_all(ALL_NODES))) == 0
        wait_until(lambda v: minority_no_quorum(v), QUORUM_TIMEOUT,
                   "m1 without quorum and no leader cluster-wide", nodes=["m1", "s1", "s2"])
        return "minority (m1) lost quorum and stopped leading; no new leader"
    finally:
        for v in victims:
            ctl("start", v)
        wait_converge(ALL_NODES)


def scenario_8_split_brain():
    views = wait_converge(ALL_NODES)
    print("    partitioning m3 off the network (1 | 2 split of the master set)")
    ctl("partition", "m3")
    try:
        def split_ok(v):
            majority = {n: v.get(n) for n in ["m1", "m2"]}
            maj_leaders = [n for n, view in majority.items() if is_leader(view)]
            if len(maj_leaders) != 1:
                return False
            if v[maj_leaders[0]].get("hasQuorum") is not True:
                return False
            # m3 is off the network now, so inspect it from inside its own container.
            m3 = inspect_local("m3")
            if m3 is None:
                return False
            return m3.get("hasQuorum") is False and not is_leader(m3)
        wait_until(split_ok, CONVERGE_TIMEOUT,
                   "majority {m1,m2} keeps one leader; minority m3 steps down",
                   nodes=["m1", "m2", "s1", "s2"])
        return "majority kept a single leader; isolated m3 has no quorum and does not lead"
    finally:
        # Rejoin the isolated node as a fresh container rather than a raw `docker network
        # connect`: reconnecting the interface leaves half-open TCP sockets on both sides
        # (no RST), which is not how a real partition heals — a recovering node comes back
        # clean. A recreate gives m3 a fresh socket stack, and the survivors reset their
        # stale links to it on the next write and re-dial, so the mesh reconverges.
        ctl("start", "m3")
        wait_converge(ALL_NODES)


SCENARIOS = [
    ("1 master-slave mesh", scenario_1_master_slave_mesh),
    ("2 master-master", scenario_2_master_master),
    ("3 placement", scenario_3_placement),
    ("4 slave-kill failover", scenario_4_slave_kill_failover),
    ("5 leader-kill re-election", scenario_5_leader_kill_reelection),
    ("6 hot-join", scenario_6_hot_join),
    ("7 quorum-loss", scenario_7_quorum_loss),
    ("8 split-brain prevention", scenario_8_split_brain),
]

# Park a scenario here (name -> reason) to skip it as known timing-flaky -- the
# cluster analogue of a Playwright test.fixme. Empty on purpose: "7 quorum-loss"
# used to sit here for slow re-convergence, which turned out to be the membership
# gossip echoing between nodes rather than host load. With that echo fixed the
# scenario passes repeatedly, so the whole matrix runs.
FLAKY_SKIP = {}


def run_scenario(name, fn):
    """Run one scenario, retrying a PURE convergence timeout (transient) up to
    SCENARIO_RETRIES times after re-converging the mesh. Returns the pass detail,
    or raises the final failure (hard invariant assertions are never retried)."""
    for attempt in range(1, SCENARIO_RETRIES + 2):
        try:
            return fn()
        except ScenarioTimeout as e:
            if attempt > SCENARIO_RETRIES:
                raise
            print(f"  RETRY ({attempt}/{SCENARIO_RETRIES}) after timeout: {e}")
            # The scenario's own finally has already restored any perturbation;
            # settle the full mesh before the next attempt so it starts clean.
            try:
                wait_converge(ALL_NODES)
            except ScenarioTimeout:
                pass


def main():
    def _fmt(x):
        return f"{x:.2f}" if isinstance(x, float) else "n/a"

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
    for name, fn in SCENARIOS:
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

    ran = len(SCENARIOS) - len(skipped)
    print("\n=== summary ===")
    print(f"  {ran - len(failures)}/{ran} scenarios passed"
          + (f"; {len(skipped)} skipped ({', '.join(skipped)})" if skipped else ""))
    if failures:
        print(f"  failed: {', '.join(failures)}")
        return 1
    print("  all cluster scenarios passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())

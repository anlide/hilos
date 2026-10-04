"""Two-phase Playwright runner for a cluster stand's optional e2e profile (HIL-1232)."""

import json

import control
import scenarios


def _environment(stand, leader, follower, phase, stopped=None):
    """Browser role and node addresses, read from the stand that is actually running."""
    env = {
        "BASE_URL": f"https://{stand.entry.container}",
        "COMMAND_HOST": stand.members[follower].container,
        "CLUSTER_LEADER": leader,
        "CLUSTER_FOLLOWER": follower,
        "CLUSTER_MASTERS": ",".join(stand.masters),
        "CLUSTER_SLAVES": ",".join(stand.slaves),
        "CLUSTER_NODE_HOSTS": ",".join(f"{node}={member.container}" for node, member in stand.members.items()),
        "CLUSTER_FAULT_FILE": f"/hilos/demo/{stand.demo_dir.name}/data/cluster-e2e-fault.json",
        "CLUSTER_E2E_PHASE": phase,
    }
    if stopped is not None:
        env["CLUSTER_STOPPED"] = stopped
    return env


def _phase(stand, env, phase, extra_args):
    """Run one project in a fresh runner container, keeping stdout in the harness log."""
    return control.run_e2e_runner(stand, env, "npx", "playwright", "test", "-c",
                                  "playwright.cluster.config.ts", "--project", phase, *extra_args)


def run(stand, extra_args):
    """Run live browsers, perform the spec-requested slave loss, then run after-loss browsers."""
    scenarios.bind(stand)
    print(f"cluster e2e: stand {stand.project}, browser suite through {stand.entry.container}")
    try:
        views = scenarios.wait_converge(stand.members)
    except scenarios.ScenarioTimeout as error:
        print(f"FATAL: cluster never converged: {error}")
        return 1

    leader = scenarios.leaders(views)[0]
    follower = next(master for master in stand.masters if master != leader)
    print(f"cluster e2e: leader {leader} (term {views[leader].get('term')}), browser on {follower}")
    fault_file = stand.demo_dir / "data" / "cluster-e2e-fault.json"
    fault_file.unlink(missing_ok=True)

    live_env = _environment(stand, leader, follower, "live")
    install = control.run_e2e_runner(
        stand, live_env, "node", "../../../../framework/frontend/scripts/npm-install-if-stale.mjs")
    if install != 0:
        print("cluster e2e: failed: runner dependencies")
        return 1
    if _phase(stand, live_env, "live", extra_args) != 0:
        print("cluster e2e: failed: phase live")
        return 1

    if not fault_file.exists():
        print("cluster e2e: the suite asked no node to stop; after-loss not run")
        print("=== summary ===\n  browser suite passed")
        return 0
    try:
        fault = json.loads(fault_file.read_text(encoding="utf-8"))
        stopped = fault["stop"]
        reason = fault["reason"]
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f"cluster e2e: invalid fault request: {error}")
        return 1
    if stopped not in stand.slaves:
        print(f"cluster e2e: the suite asked to stop {stopped}, which is not a slave; after-loss not run")
        return 1

    print(f"== stopping {stopped}: {reason} ==")
    outcome = control.kill(stand, stopped)
    if outcome.code != 0:
        print(f"cluster e2e: failed to stop {stopped}: {outcome.err}")
        return 1
    survivors = [node for node in stand.members if node != stopped]
    try:
        scenarios.wait_until(
            lambda current: len(scenarios.leaders(current)) == 1
            and all(row.get("nodeId") != stopped or row.get("state") not in ("placing", "started")
                    for row in scenarios.leader_placements(current)),
            scenarios.FAILOVER_TIMEOUT,
            f"no hosted placement remains on {stopped}",
            nodes=survivors,
        )
    except scenarios.ScenarioTimeout as error:
        print(f"cluster e2e: failed: after-loss convergence: {error}")
        return 1

    after_env = _environment(stand, leader, follower, "after-loss", stopped)
    if _phase(stand, after_env, "after-loss", extra_args) != 0:
        print("=== summary ===\n  browser suite failed: phase after-loss")
        return 1
    print("=== summary ===\n  browser suite passed")
    return 0

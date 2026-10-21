"""Playwright phases for a cluster stand, with host-controlled node loss and recovery."""

import json
import threading
import time
import uuid

import control
import delivery
import scenarios
from stand import StandRefused


def daemon_phase_selected(stand, extra_args):
    """Only the Binance carrier has the daemon project and its destructive scenario."""
    carrier = stand.demo_dir.name == "binance-btc-tracker"
    if "--daemon" in extra_args and not carrier:
        raise StandRefused("e2e daemon is carried only by binance-btc-tracker")
    selected = carrier and (not extra_args or "--daemon" in extra_args)
    if selected and (len(stand.masters) < 3 or not stand.slaves):
        raise StandRefused("e2e daemon needs at least three masters and one slave")
    return selected


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
        "CLUSTER_NODE_IPS": ",".join(f"{node}={member.ip}" for node, member in stand.members.items()),
        "CLUSTER_NODE_LOGS": json.dumps({
            node: "/hilos/" + control.node_log_path(member).relative_to(stand.demo_dir.parent.parent).as_posix()
            for node, member in stand.members.items()
        }),
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


def _switch(outcome):
    """Keep the fault and recovery evidence in the harness log, including refused switches."""
    if outcome.code != 0:
        raise RuntimeError(outcome.err or outcome.out or f"cluster switch exited {outcome.code}")
    print(outcome.out.strip())


def _atomic_json(path, value):
    """Publish a complete controller reply on the runner's shared filesystem."""
    temporary = path.with_suffix(".json.tmp")
    temporary.write_text(json.dumps(value), encoding="utf-8")
    temporary.replace(path)


class _DaemonController:
    """One run's private handshake, owned by the host until the runner has exited.

    This is test orchestration state, not application state: no DB/RT owner or product
    agent participates. Only this thread kills a node; the caller joins it before recovery.
    """

    def __init__(self, stand, directory, run_id, leader, term):
        self.stand = stand
        self.directory = directory
        self.run_id = run_id
        self.leader = leader
        self.term = term
        self.stop = threading.Event()
        self.kill_attempted = False
        self.completed = False
        self.error = None

    def run(self):
        """Accept one matching readiness request, then inspect only surviving members."""
        try:
            # A missing readiness request must not leave a thread alive behind a hung runner.
            # Use the existing host budgets; do not change any browser timeout or its scale.
            deadline = time.monotonic() + scenarios.CONVERGE_TIMEOUT + scenarios.ELECTION_TIMEOUT
            request_path = self.directory / "request.json"
            while not self.stop.is_set():
                if request_path.exists():
                    request = json.loads(request_path.read_text(encoding="utf-8"))
                    if request != {"runId": self.run_id, "action": "kill-leader", "nodeId": self.leader}:
                        raise RuntimeError(f"invalid daemon readiness request: {request!r}")
                    # The runner may have exited while the file was being read.
                    if self.stop.is_set():
                        return
                    initial = scenarios.inspect(self.leader)
                    if (not scenarios.is_leader(initial) or initial.get("term") != self.term
                            or initial.get("hasQuorum") is not True):
                        raise RuntimeError("initial daemon leader moved before the requested kill")
                    if self.stop.is_set():
                        return
                    self.kill_attempted = True
                    _switch(control.kill(self.stand, self.leader))
                    survivors = [node for node in self.stand.members if node != self.leader]

                    def elected(views):
                        leaders = scenarios.leaders(views)
                        return (len(leaders) == 1 and leaders[0] in self.stand.masters
                                and views[leaders[0]].get("hasQuorum") is True
                                and isinstance(views[leaders[0]].get("term"), int)
                                and views[leaders[0]]["term"] > self.term)

                    views = scenarios.wait_until(elected, scenarios.ELECTION_TIMEOUT,
                                                 "a new daemon leader with quorum and a higher term",
                                                 nodes=survivors)
                    leader = scenarios.leaders(views)[0]
                    result = {"runId": self.run_id, "ok": True, "stopped": self.leader,
                              "leaderId": leader, "term": views[leader]["term"]}
                    print(f"cluster e2e daemon: elected {json.dumps(result)}")
                    _atomic_json(self.directory / "result.json", result)
                    self.completed = True
                    return
                if time.monotonic() >= deadline:
                    raise RuntimeError("timed out waiting for daemon browser readiness request")
                self.stop.wait(scenarios.POLL_INTERVAL)
        except Exception as error:
            self.error = str(error)
            print(f"cluster e2e daemon: controller failed: {error}")
            try:
                _atomic_json(self.directory / "result.json",
                             {"runId": self.run_id, "ok": False, "error": self.error})
            except OSError as write_error:
                print(f"cluster e2e daemon: could not publish failure: {write_error}")


def _daemon_phase(stand, extra_args):
    """Run the live leader-loss spec, restoring every fault before any older phase starts.

    Every node the host starts - the remote under its own environment and back, the killed
    leader - is read in its own log once the cluster has converged after it: a start refused for
    want of a regular worker fails the phase, as a retry that converged later would not (HIL-1671).
    """
    run_id = uuid.uuid4().hex
    directory = stand.demo_dir / "data" / "cluster-daemon-e2e" / run_id
    remote = stand.slaves[0]
    env_key, env_value = "MAIL_FROM_NAME", "hil1401-foreign-value"
    controller = None
    thread = None
    directory_created = False
    override_attempted = False
    restarts = []
    failed = False
    try:
        directory.mkdir(parents=True)
        directory_created = True
        override_attempted = True
        mark = scenarios.node_log_mark(remote)
        _switch(control.own_env(stand, remote, f"{env_key}={env_value}"))
        views = scenarios.wait_converge(stand.members)
        scenarios.assert_no_startup_refusal(remote, mark, f"after its recreation with {env_key} of its own")
        leader = scenarios.leaders(views)[0]
        follower = next(master for master in stand.masters if master != leader)
        term = views[leader]["term"]
        print(f"cluster e2e daemon: run {run_id}, remote {remote}, "
              f"leader {leader} (term {term}), browser on {follower}")
        env = _environment(stand, leader, follower, "daemon")
        env.update({"CLUSTER_DAEMON_NODE": remote, "CLUSTER_DAEMON_ENV_KEY": env_key,
                    "CLUSTER_DAEMON_ENV_VALUE": env_value, "CLUSTER_DAEMON_RUN_ID": run_id,
                    "CLUSTER_DAEMON_CONTROL_DIR": "/hilos/" + directory.relative_to(
                        stand.demo_dir.parent.parent).as_posix()})
        controller = _DaemonController(stand, directory, run_id, leader, term)
        thread = threading.Thread(target=controller.run, name=f"daemon-e2e-{run_id}")
        thread.start()
        try:
            failed = _phase(stand, env, "daemon", extra_args) != 0
        finally:
            controller.stop.set()
            thread.join()
        if controller.error is not None or not controller.completed:
            print("cluster e2e daemon: failed: no successful leader kill/election handshake")
            failed = True
    except Exception as error:
        print(f"cluster e2e daemon: failed: {error}")
        failed = True
    finally:
        # Join before starting the master: an in-flight election must finish before recovery.
        if thread is not None and thread.is_alive():
            controller.stop.set()
            thread.join()
        if controller is not None and controller.kill_attempted:
            try:
                restarts.append((controller.leader, scenarios.node_log_mark(controller.leader),
                                 "after its restart"))
                _switch(control.start(stand, controller.leader))
            except Exception as error:
                print(f"cluster e2e daemon: master recovery failed: {error}")
                failed = True
        if override_attempted:
            try:
                restarts.append((remote, scenarios.node_log_mark(remote),
                                 "after its recreation on the stand's environment"))
                _switch(control.own_env(stand, remote, "off"))
            except Exception as error:
                print(f"cluster e2e daemon: environment recovery failed: {error}")
                failed = True
            try:
                views = scenarios.wait_converge(stand.members)
                print(f"cluster e2e daemon: recovery converged all members: {scenarios.summarize(views)}")
            except Exception as error:
                print(f"cluster e2e daemon: recovery convergence failed: {error}")
                failed = True
            for node, mark, window in restarts:
                try:
                    scenarios.assert_no_startup_refusal(node, mark, window)
                except AssertionError as error:
                    print(f"cluster e2e daemon: recovery start failed: {error}")
                    failed = True
        try:
            # Never sweep another run, or even the parent directory. Temp files belong to
            # this handshake too and may remain if a writer failed before its rename.
            if directory_created:
                for name in ("request.json", "result.json", "request.json.tmp", "result.json.tmp"):
                    (directory / name).unlink(missing_ok=True)
                directory.rmdir()
        except OSError as error:
            print(f"cluster e2e daemon: control cleanup failed: {error}")
            failed = True
    print(f"cluster e2e: phase daemon {'failed' if failed else 'passed; master and environment restored'}")
    return 1 if failed else 0


def _live_phase(stand, env, extra_args, leader, term):
    """Automatically offer in-phase worker faults in both pointed and full carrier runs."""
    if stand.demo_dir.name != "binance-btc-tracker":
        return _phase(stand, env, "live", extra_args)
    run_id = uuid.uuid4().hex
    directory = stand.demo_dir / "data" / "cluster-delivery-e2e" / run_id
    directory.mkdir(parents=True)
    controller = delivery.DeliveryController(stand, directory, run_id, leader, term)
    thread = threading.Thread(target=controller.run, name=f"delivery-e2e-{run_id}")
    env = {**env, "CLUSTER_DELIVERY_RUN_ID": run_id,
           "CLUSTER_DELIVERY_CONTROL_DIR": "/hilos/" + directory.relative_to(
               stand.demo_dir.parent.parent).as_posix()}
    failed = False
    try:
        thread.start()
        failed = _phase(stand, env, "live", extra_args) != 0
    finally:
        controller.stop.set()
        if thread.ident is not None:
            thread.join()
        try:
            controller.restore()
        except Exception as error:
            print(f"cluster delivery: restoration failed: {error}", flush=True)
            failed = True
        if controller.error is not None:
            print(f"cluster delivery: controller failed: {controller.error}", flush=True)
            failed = True
        try:
            # Sequence files belong to this UUID only. Never sweep the parent,
            # browser artifacts or journals, even after an interrupted write.
            for sequence in range(1, controller.sequence + 2):
                for side in ("request", "result"):
                    for suffix in ("json", "json.tmp"):
                        (directory / f"{side}-{sequence}.{suffix}").unlink(missing_ok=True)
            directory.rmdir()
        except OSError as error:
            print(f"cluster delivery: control cleanup failed: {error}", flush=True)
            failed = True
    return 1 if failed else 0


def run(stand, extra_args):
    """The carrier adds daemon/recovery before live; pointing preserves each stand's projects."""
    run_daemon = daemon_phase_selected(stand, extra_args)
    scenarios.bind(stand)
    daemon_only = "--daemon" in extra_args
    extra_args = [arg for arg in extra_args if arg != "--daemon"]
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
    if run_daemon:
        if _daemon_phase(stand, extra_args) != 0:
            return 1
        if daemon_only:
            print("=== summary ===\n  browser suite passed: phase daemon")
            return 0
        # Recovery can change both roles. Older specs must start from the restored stand.
        views = scenarios.wait_converge(stand.members)
        leader = scenarios.leaders(views)[0]
        follower = next(master for master in stand.masters if master != leader)
        live_env = _environment(stand, leader, follower, "live")
        print(f"cluster e2e: restored leader {leader} (term {views[leader].get('term')}), "
              f"live browser on {follower}")
    if _live_phase(stand, live_env, extra_args, leader, views[leader]["term"]) != 0:
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

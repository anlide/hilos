"""Playwright phases for a cluster stand, with host-controlled node loss and recovery."""

import calendar
import json
import re
import threading
import time
import uuid
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import PurePosixPath

import control
import delivery
import galera_waits
import scenarios
from stand import StandRefused


PLAYWRIGHT_ENTRY = "../../../../framework/frontend/scripts/playwright-run.mjs"
QUARANTINE_STATUSES = frozenset(("passed", "failed", "timedOut", "skipped", "interrupted", "notRun"))
ENV_MISMATCH_SPEC = "live/daemon-env-mismatch.spec.ts"
DELIVERY_SPEC = "live/delivery-window.spec.ts"
# Host-owned run files live under the demo's tracked tests/, never data/: Docker may create
# data/ as root on a fresh tree before this step starts (HIL-2111).
RUN_FILES = ".hilos-cluster-e2e"

# A phase switched off whole, the host-side twin of a static `test.fixme`: the host
# prepares neither its main nor its quarantine run. A test that fails on its own test
# timeout is switched off rather than quarantined, because quarantine would spend the
# whole cap and the phase setup on every full run.
FLAKY_SKIP = {
    # FIXME(F-023, 2026-10-25): timed out in all 14 quarantine runs · чужой · попытка 1 · HIL-1641
    "daemon": "F-023: the daemon journey times out after leader loss",
}


@dataclass
class _PhaseResult:
    """Browser rc and host checks of one invocation, before any final reporting."""

    native_rc: int = 0
    started: bool = False
    errors: list[str] = field(default_factory=list)

    @property
    def code(self):
        return self.native_rc or (1 if self.errors else 0)

    def fail(self, reason):
        self.errors.append(reason)
        print(reason, flush=True)


@dataclass
class _LossState:
    """Keep the fault boundary across main and quarantine convergence attempts."""

    required_agent: str | None = None
    stopped: str | None = None
    ready: bool = False
    agent_id: str | None = None
    restart_marks: dict[str, list[control.JournalSegment]] = field(default_factory=dict)
    finished_at: int | None = None


def _refuse_unfit_shape(stand):
    """Every declared capability must fit the stand, even when the caller filters it out."""
    if "daemon" in stand.e2e.capabilities and (len(stand.masters) < 3 or not stand.slaves):
        raise StandRefused("e2e daemon needs at least three masters and one slave")
    if "delivery" in stand.e2e.capabilities and len(stand.masters) < 3:
        raise StandRefused("e2e delivery needs at least three masters")
    if "env-mismatch" in stand.e2e.capabilities and not stand.slaves:
        raise StandRefused("e2e env-mismatch needs at least one slave")


def daemon_phase_selected(stand, extra_args):
    """Validate the declaration, then select its daemon phase from the caller's arguments."""
    _refuse_unfit_shape(stand)
    carrier = "daemon" in stand.e2e.capabilities
    if "--daemon" in extra_args and not carrier:
        raise StandRefused(f"{stand.compose}: x-hilos-cluster.e2e does not declare daemon")
    if "--daemon" in extra_args and "--require-after-loss" in extra_args:
        raise StandRefused("--require-after-loss needs live and after-loss; --daemon selects daemon only")
    return carrier and (not extra_args or "--daemon" in extra_args)


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
        "CLUSTER_NODE_NAMES": json.dumps({node: member.name for node, member in stand.members.items()}),
        "CLUSTER_NODE_IPS": ",".join(f"{node}={member.ip}" for node, member in stand.members.items()),
        "CLUSTER_NODE_LOGS": json.dumps({
            node: "/hilos/" + control.node_log_path(member).relative_to(stand.demo_dir.parent.parent).as_posix()
            for node, member in stand.members.items()
        }),
        "CLUSTER_FAULT_FILE": _child_path(stand, _run_path(stand, "fault.json")),
        "CLUSTER_E2E_PHASE": phase,
    }
    if stopped is not None:
        env["CLUSTER_STOPPED"] = stopped
        env["CLUSTER_FAILOVER_TIMEOUT_MS"] = str(round(scenarios.FAILOVER_TIMEOUT * 1000))
    return env


def _phase(stand, env, phase, extra_args, hilos_phase="main", run_env=None):
    """Use the container gateway and one explicit set; diagnostic keeps native repeats."""
    internal = [] if hilos_phase == "diagnostic" else [f"--hilos-phase={hilos_phase}"]
    # The collected plan already intersects the caller's project filters. Keeping
    # them here would union another project into this one native invocation.
    args = []
    index = 0
    while index < len(extra_args):
        arg = extra_args[index]
        index += 1
        if arg == "--project":
            while index < len(extra_args) and not extra_args[index].startswith("-"):
                index += 1
        elif not arg.startswith("--project="):
            args.append(arg)
    return control.run_e2e_runner(
        stand, {**env, **(run_env or {})}, "node", PLAYWRIGHT_ENTRY, *internal,
        "-c", "playwright.cluster.config.ts", *args, f"--project={phase}")


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


def _daemon_phase(stand, extra_args, hilos_phase="main", run_env=None):
    """Run leader loss and restore every fault before returning its host verdict.

    Every node the host starts - the remote under its own environment and back, the killed
    leader - is read in its own log once the cluster has converged after it: a start refused for
    want of a regular worker fails the phase, as a retry that converged later would not (HIL-1671).
    """
    run_id = uuid.uuid4().hex
    directory = _run_path(stand, "daemon", run_id)
    remote = stand.slaves[0]
    env_key, env_value = "MAIL_FROM_NAME", "hil1401-foreign-value"
    controller = None
    thread = None
    directory_created = False
    override_attempted = False
    restarts = []
    result = _PhaseResult()
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
                    "CLUSTER_DAEMON_CONTROL_DIR": _child_path(stand, directory)})
        controller = _DaemonController(stand, directory, run_id, leader, term)
        thread = threading.Thread(target=controller.run, name=f"daemon-e2e-{run_id}")
        thread.start()
        try:
            result.started = True
            result.native_rc = _phase(stand, env, "daemon", extra_args, hilos_phase, run_env)
        finally:
            controller.stop.set()
            thread.join()
        if controller.error is not None or not controller.completed:
            result.fail("cluster e2e daemon: failed: " + (controller.error or
                        "no successful leader kill/election handshake"))
    except Exception as error:
        result.fail(f"cluster e2e daemon: failed: {error}")
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
                result.fail(f"cluster e2e daemon: master recovery failed: {error}")
        if override_attempted:
            try:
                restarts.append((remote, scenarios.node_log_mark(remote),
                                 "after its recreation on the stand's environment"))
                _switch(control.own_env(stand, remote, "off"))
            except Exception as error:
                result.fail(f"cluster e2e daemon: environment recovery failed: {error}")
            try:
                views = scenarios.wait_converge(stand.members)
                print(f"cluster e2e daemon: recovery converged all members: {scenarios.summarize(views)}")
            except Exception as error:
                result.fail(f"cluster e2e daemon: recovery convergence failed: {error}")
            for node, mark, window in restarts:
                try:
                    scenarios.assert_no_startup_refusal(node, mark, window)
                except AssertionError as error:
                    result.fail(f"cluster e2e daemon: recovery start failed: {error}")
        try:
            # Never sweep another run, or even the parent directory. Temp files belong to
            # this handshake too and may remain if a writer failed before its rename.
            if directory_created:
                for name in ("request.json", "result.json", "request.json.tmp", "result.json.tmp"):
                    (directory / name).unlink(missing_ok=True)
                directory.rmdir()
        except OSError as error:
            result.fail(f"cluster e2e daemon: control cleanup failed: {error}")
    print(f"cluster e2e: phase daemon {'failed' if result.code else 'passed; master and environment restored'}")
    return result


def _env_mismatch_selected(tests):
    """The native plan, after filters and phase partitioning, owns fixture selection."""
    return any(test["project"] == "live" and test["file"] == ENV_MISMATCH_SPEC for test in tests)


def _needed_capability(test):
    """Only the known host-controlled projects and files need an explicit capability."""
    if test["project"] == "daemon":
        return "daemon"
    if test["project"] == "live" and test["file"] == ENV_MISMATCH_SPEC:
        return "env-mismatch"
    if test["project"] == "live" and test["file"] == DELIVERY_SPEC:
        return "delivery"
    return None


def _refuse_undeclared(stand, tests):
    """Check the whole native plan before project filtering or any phase preparation."""
    for test in tests:
        needed = _needed_capability(test)
        if needed is not None and needed not in stand.e2e.capabilities:
            raise StandRefused(f"{stand.compose}: {test['file']} ({test['project']}) needs capability "
                               f"{needed} in x-hilos-cluster.e2e.capabilities")


def _live_phase(stand, env, extra_args, leader, term, hilos_phase="main", run_env=None,
                env_mismatch=False):
    """Join delivery control before restoring its faults and collecting its host verdict."""
    result = _PhaseResult()
    controller = None
    thread = None
    directory = _run_path(stand, "delivery", uuid.uuid4().hex)
    directory_created = False
    override_attempted = False
    env_key, env_value = "MAIL_FROM_NAME", "hilos-env-mismatch-fixture"
    try:
        if env_mismatch:
            remote = stand.slaves[0]
            mark = scenarios.node_log_mark(remote)
            override_attempted = True
            _switch(control.own_env(stand, remote, f"{env_key}={env_value}"))
            env, leader, term = _roles(stand, "live env mismatch")
            scenarios.assert_no_startup_refusal(remote, mark, "after its environment mismatch fixture start")
            env.update({"CLUSTER_ENV_MISMATCH_NODE": remote, "CLUSTER_ENV_MISMATCH_KEY": env_key,
                        "CLUSTER_ENV_MISMATCH_VALUE": env_value})
        if "delivery" in stand.e2e.capabilities:
            directory.mkdir(parents=True)
            directory_created = True
            run_id = directory.name
            controller = delivery.DeliveryController(stand, directory, run_id, leader, term)
            thread = threading.Thread(target=controller.run, name=f"delivery-e2e-{run_id}")
            env = {**env, "CLUSTER_DELIVERY_RUN_ID": run_id,
                   "CLUSTER_DELIVERY_CONTROL_DIR": _child_path(stand, directory)}
            thread.start()
        result.started = True
        result.native_rc = _phase(stand, env, "live", extra_args, hilos_phase, run_env)
    except Exception as error:
        result.fail(f"cluster delivery: phase failed: {error}")
    finally:
        if controller is not None:
            controller.stop.set()
            if thread is not None and thread.ident is not None:
                thread.join()
            try:
                controller.restore()
            except Exception as error:
                result.fail(f"cluster delivery: restoration failed: {error}")
            if controller.error is not None:
                result.fail(f"cluster delivery: controller failed: {controller.error}")
        if override_attempted:
            restore_mark = None
            try:
                restore_mark = scenarios.node_log_mark(remote)
            except Exception as error:
                result.fail(f"cluster env mismatch: recovery log mark failed: {error}")
            try:
                _switch(control.own_env(stand, remote, "off"))
            except Exception as error:
                result.fail(f"cluster env mismatch: environment recovery failed: {error}")
            try:
                _roles(stand, "env mismatch recovery converged all members")
            except Exception as error:
                result.fail(f"cluster env mismatch: recovery convergence failed: {error}")
            if restore_mark is not None:
                try:
                    scenarios.assert_no_startup_refusal(
                        remote, restore_mark, "after its recreation on the stand's environment")
                except Exception as error:
                    result.fail(f"cluster env mismatch: recovery start failed: {error}")
        try:
            # Sequence files belong to this UUID only, including interrupted writes.
            if directory_created:
                if controller is not None:
                    for sequence in range(1, controller.sequence + 2):
                        for side in ("request", "result"):
                            for suffix in ("json", "json.tmp"):
                                (directory / f"{side}-{sequence}.{suffix}").unlink(missing_ok=True)
                directory.rmdir()
        except OSError as error:
            result.fail(f"cluster delivery: control cleanup failed: {error}")
    return result


def _child_path(stand, path):
    """The existing repository bind is the only host/runner file bridge."""
    return "/hilos/" + path.relative_to(stand.demo_dir.parent.parent).as_posix()


def _run_path(stand, *parts):
    """This step's own files, under the demo's tracked tests/ rather than Docker's data/."""
    return stand.demo_dir.joinpath("tests", RUN_FILES, *parts)


def _identity(test):
    return test["flake"], test["project"], test["file"], tuple(test["titlePath"])


def _read_tests(path, *, plan=False):
    """Validate the version-1 Node boundary, without treating bool as an integer."""
    value = json.loads(path.read_text(encoding="utf-8"))
    if (not isinstance(value, dict) or type(value.get("version")) is not int
            or value["version"] != 1 or not isinstance(value.get("tests"), list)):
        raise ValueError("invalid quarantine sidecar version or tests")
    seen = set()
    for row in value["tests"]:
        required = {"flake", "project", "file", "line", "titlePath"}
        required |= {"selector"} if plan else {"status", "reason"}
        if not isinstance(row, dict) or not required.issubset(row):
            raise ValueError("invalid quarantine test row")
        flake = row.get("flake")
        file = row.get("file")
        titles = row.get("titlePath")
        if (not isinstance(row.get("project"), str) or not isinstance(file, str) or not file
                or "\\" in file or ":" in file or PurePosixPath(file).is_absolute()
                or ".." in PurePosixPath(file).parts
                or type(row.get("line")) is not int or row["line"] < 1
                or not isinstance(titles, list) or not titles
                or any(not isinstance(title, str) or not title for title in titles)
                or not (plan and flake is None or isinstance(flake, str)
                        and re.fullmatch(r"F-[0-9]{3,}", flake))):
            raise ValueError("invalid quarantine test identity")
        if plan:
            tokens = [row["project"], file, *titles]
            selector = f"[{row['project']}] › {file}:{row['line']} › " + " › ".join(titles)
            if (any(token.strip() != token or re.search(r"[\r\n›]", token) for token in tokens)
                    or row.get("selector") != selector):
                raise ValueError("invalid quarantine plan selector")
        elif (not isinstance(row.get("status"), str) or row["status"] not in QUARANTINE_STATUSES
              or not isinstance(row.get("reason"), str)):
            raise ValueError("invalid quarantine observation")
        key = _identity(row)
        if key in seen:
            raise ValueError("duplicate quarantine test identity")
        seen.add(key)
    return value["tests"]


def _normalize(tests, observations, reason, host_error=""):
    """One final row per selected identity; only a browser pass can be demoted."""
    observed = {_identity(row): row for row in observations}
    result = []
    for test in tests:
        row = observed.get(_identity(test))
        status = row["status"] if row else "notRun"
        explanation = row["reason"] if row else reason
        if status == "passed" and host_error:
            status, explanation = "failed", host_error
        result.append({key: test[key] for key in ("flake", "file", "line", "project", "titlePath")} |
                      {"status": status, "reason": explanation})
    return result


def _observations(tests, path, result):
    observations = []
    if result.started:
        try:
            observations = _read_tests(path)
        except (OSError, ValueError, TypeError) as error:
            result.fail(f"cluster quarantine: cannot read {path.name}: {error}")
    reason = "; ".join(result.errors) or f"No browser result was reported (Playwright rc={result.native_rc})"
    return _normalize(tests, observations, reason, "; ".join(result.errors))


def _roles(stand, phase, stopped=None):
    nodes = [node for node in stand.members if node != stopped]
    views = scenarios.wait_converge(nodes)
    leader = scenarios.leaders(views)[0]
    follower = next(master for master in stand.masters if master != leader)
    print(f"cluster e2e: {phase} leader {leader} (term {views[leader]['term']}), browser on {follower}")
    return _environment(stand, leader, follower, phase, stopped), leader, views[leader]["term"]


def _started_hosts(views, agent_id):
    return [row.get("nodeId") for row in scenarios.leader_placements(views)
            if row.get("agentId") == agent_id and row.get("state") == "started"]


def _docker_time(stamp):
    """Read Docker UTC RFC3339Nano as integer nanoseconds, including on Python 3.10."""
    match = re.fullmatch(r"([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,9}))?Z", stamp)
    if not match:
        raise ValueError(f"invalid Docker UTC timestamp: {stamp!r}")
    moment = datetime.fromisoformat(match[1])
    if moment.year == 1:
        raise ValueError(f"unset Docker UTC timestamp: {stamp!r}")
    fraction = (match[2] or "").ljust(9, "0")
    return calendar.timegm(moment.timetuple()) * 1_000_000_000 + int(fraction)


def _live_start(node, mark, agent_id):
    """Find the sole current worker copy and retain its position relative to the fault cursor."""
    journal = control.node_log_segments(node)
    boundary = control.node_log_boundary(journal.parts, mark)
    active = []
    position = journal.life_start
    agent = re.compile(rf"\] Agent '{re.escape(agent_id)}' (started|stopped) on worker #(\d+)$")
    for record in journal.life.splitlines(keepends=True):
        fresh = position >= boundary
        position += len(record)
        line = record.decode("utf-8", errors="replace").rstrip("\n")
        if re.search(r"\] Daemon stopped(?: after a failure)?$", line):
            return None
        worker = re.search(r"\] Worker #(\d+) (?:died hosting |started \[type=)", line)
        event = agent.search(line)
        if worker:
            active = [copy for copy in active if copy[0] != worker[1]]
        elif event and event[1] == "stopped":
            active = [copy for copy in active if copy[0] != event[2]]
        elif event:
            stamp = line.split("]", 1)[0].removeprefix("[")
            active.append((event[2], scenarios.log_time(stamp) if fresh else None))
        elif f"] Agent '{agent_id}' " in line and " on worker #" in line:
            raise ValueError("incomplete agent lifecycle record")
    return active[0][1] if len(active) == 1 else None


def _stop_agent_owner(stand, target, agent_id, loss):
    """Capture the survivor logs, check the owner, and identify the finish of that same container run."""
    def capture(_views):
        try:
            loss.restart_marks = {node: control.node_log_segments(stand.members[node]).parts
                                  for node in stand.slaves if node != target}
            return True
        except control.JournalChanging:
            return False

    if not capture(None):
        scenarios.wait_until(capture, scenarios.FAILOVER_TIMEOUT, "stable survivor journals before kill", nodes=stand.masters)
    state = control.container_state(stand, target)
    running = state.out.split()
    if state.code or len(running) != 3 or running[1] != "true":
        raise ValueError(f"cannot identify running container on {target}")
    container, _, started = running
    started_at = _docker_time(started)
    hosts = _started_hosts(scenarios.inspect_all(stand.masters), agent_id)
    if hosts != [target]:
        raise ValueError(f"stale fault for {agent_id}: requested {target}, started on {hosts}")
    print(f"cluster e2e: leader confirms {agent_id} started on {target}")
    loss.agent_id = agent_id
    loss.stopped = target
    _switch(control.kill(stand, target, container_id=container))
    state = control.container_state(stand, target, include_finished=True)
    stopped = state.out.split()
    if state.code or len(stopped) != 4 or stopped[:3] != [container, "false", started]:
        raise ValueError(f"cannot confirm the stopped container incarnation on {target}")
    finished_at = _docker_time(stopped[3])
    if finished_at <= started_at:
        raise ValueError(f"invalid Docker finish time on {target}: {stopped[3]}")
    loss.finished_at = finished_at
    print(f"cluster e2e: {target} container {container} finished at {stopped[3]}")


def _prepare_loss(stand, fault_file, loss):
    """Keep the original request and stopped node until every after-loss set has finished."""
    if loss.stopped is None:
        fault = json.loads(fault_file.read_text(encoding="utf-8"))
        if (not isinstance(fault, dict) or fault.get("stop") not in stand.slaves
                or not isinstance(fault.get("reason"), str)):
            raise ValueError("invalid after-loss fault request: expected a slave and reason")
        target = fault["stop"]
        if loss.required_agent is not None and fault.get("agentId") != loss.required_agent:
            raise ValueError(f"invalid after-loss fault request: expected agentId {loss.required_agent!r}")
        print(f"== stopping {target}: {fault['reason']} ==")
        if "agentId" in fault:
            agent_id = fault["agentId"]
            if not isinstance(agent_id, str) or not agent_id:
                raise ValueError("invalid after-loss fault request: expected a nonempty agentId")
            _stop_agent_owner(stand, target, agent_id, loss)
        else:
            loss.stopped = target
            _switch(control.kill(stand, target))
    if loss.agent_id and loss.finished_at is None:
        raise ValueError(f"unconfirmed container stop on {loss.stopped}")
    survivors = [node for node in stand.members if node != loss.stopped]

    def recovered(current):
        if len(scenarios.leaders(current)) != 1 or any(
                row.get("nodeId") == loss.stopped and row.get("state") in ("placing", "started")
                for row in scenarios.leader_placements(current)):
            return False
        if loss.agent_id is None:
            return True
        hosts = _started_hosts(current, loss.agent_id)
        if len(hosts) != 1 or hosts[0] not in loss.restart_marks:
            return False
        try:
            stamp = _live_start(stand.members[hosts[0]], loss.restart_marks[hosts[0]], loss.agent_id)
        except ValueError:
            return False
        if stamp is not None:
            nanos = calendar.timegm(stamp.utctimetuple()) * 1_000_000_000 + stamp.microsecond * 1000
            if nanos > loss.finished_at:
                print(f"cluster e2e: post-kill start of {loss.agent_id} on {hosts[0]} at {stamp.isoformat()}")
                return True
        return False

    condition = f"no hosted placement remains on {loss.stopped}"
    if loss.agent_id:
        condition += f" and {loss.agent_id} started on a surviving slave after the kill"
    scenarios.wait_until(recovered, scenarios.FAILOVER_TIMEOUT, condition, nodes=survivors)
    loss.ready = True


def _main(stand, tests, extra_args, run_env, fault_file, loss, hilos_phase, proof_file=None):
    """Finish the selected main dependency chain before the caller starts any quarantine."""
    projects = {test["project"] for test in tests}
    if proof_file is not None and (hilos_phase != "main" or not {"live", "after-loss"} <= projects):
        raise ValueError("--require-after-loss needs both live and after-loss in the main plan")
    if "daemon" in projects:
        result = _daemon_phase(stand, extra_args, hilos_phase, run_env)
        if result.code:
            return result.code
    if "live" in projects:
        env, leader, term = _roles(stand, "live")
        result = _live_phase(stand, env, extra_args, leader, term, hilos_phase, run_env,
                             env_mismatch=_env_mismatch_selected(tests))
        if result.code:
            print("cluster e2e: failed: phase live")
            return result.code
    if "after-loss" not in projects:
        return 0
    if not fault_file.exists():
        print("cluster e2e: the suite asked no node to stop; after-loss not run")
        return int(proof_file is not None)
    _prepare_loss(stand, fault_file, loss)
    env, _, _ = _roles(stand, "after-loss", loss.stopped)
    if proof_file is not None:
        env["CLUSTER_AFTER_LOSS_PROOF_FILE"] = _child_path(stand, proof_file)
    code = _phase(stand, env, "after-loss", extra_args, hilos_phase, run_env)
    if code or proof_file is None:
        return code
    proof = json.loads(proof_file.read_text(encoding="utf-8"))
    if not isinstance(proof, dict) or proof.get("verified") is not True:
        raise ValueError("after-loss proof must confirm verified=true")
    print("cluster e2e: after-loss verification confirmed")
    return 0


def _quarantine_tail(stand, tests, extra_args, directory, run_env, fault_file, loss):
    """After-loss uses the captured fault, live restores it, daemon is always last."""
    observations = []
    for project in ("after-loss", "live", "daemon"):
        selected = [test for test in tests if test["project"] == project]
        if not selected:
            continue
        path = directory / f"{project}.json"
        env_files = {**run_env, "HILOS_E2E_RESULT_FILE": _child_path(stand, path),
                     "HILOS_E2E_DEFER_REPORT": "1"}
        result = _PhaseResult()
        try:
            if project == "after-loss":
                if loss.required_agent and loss.stopped is None:
                    raise ValueError("main did not stop the required agent owner")
                if not loss.ready:
                    _prepare_loss(stand, fault_file, loss)
                env, _, _ = _roles(stand, project, loss.stopped)
                result.started = True
                result.native_rc = _phase(stand, env, project, extra_args, "quarantine", env_files)
            else:
                if loss.stopped is not None:
                    _switch(control.start(stand, loss.stopped))
                    scenarios.wait_converge(stand.members)
                    loss = _LossState()
                control.ensure_open_for_tests(stand, scenarios.CONVERGE_TIMEOUT)
                env, leader, term = _roles(stand, project)
                if project == "daemon":
                    result = _daemon_phase(stand, extra_args, "quarantine", env_files)
                else:
                    result = _live_phase(stand, env, extra_args, leader, term, "quarantine", env_files,
                                         env_mismatch=_env_mismatch_selected(selected))
        except Exception as error:
            result.fail(f"cluster quarantine {project}: host preparation/invocation failed: {error}")
        observations.extend(_observations(selected, path, result))
    return observations


def run(stand, extra_args):
    """Plan once, finish every selected main phase, then quarantine without changing main rc."""
    require_after_loss = "--require-after-loss" in extra_args
    run_daemon = daemon_phase_selected(stand, extra_args)
    scenarios.bind(stand)
    daemon_only = "--daemon" in extra_args
    if daemon_only and "daemon" in FLAKY_SKIP:
        print(f"cluster e2e: phase daemon SKIP ({FLAKY_SKIP['daemon']})")
        print("=== summary ===\n  browser suite skipped: phase daemon")
        return 0
    extra_args = [arg for arg in extra_args if arg not in ("--daemon", "--require-after-loss")]
    diagnostic = any(arg == "--repeat-each" or arg.startswith("--repeat-each=") for arg in extra_args)
    directory = _run_path(stand, "quarantine", uuid.uuid4().hex)
    directory_created = False
    tests, quarantine, observations = [], [], []
    main_rc = 1
    reason = "Cluster setup did not finish"
    cleanup_error = ""
    waits, waits_thread = None, None
    print(f"cluster e2e: stand {stand.project}, browser suite through {stand.entry.container}")
    try:
        env, _, _ = _roles(stand, "live")
        install = control.run_e2e_runner(
            stand, env, "node", "../../../../framework/frontend/scripts/npm-install-if-stale.mjs")
        if install != 0:
            raise RuntimeError(f"runner dependencies exited {install}")
        directory.mkdir(parents=True)
        directory_created = True
        plan_file = directory / "plan.json"
        run_env = {"HILOS_E2E_PLAN_FILE": _child_path(stand, plan_file)}
        collection_env = {**env, **run_env}
        # delivery-window can load while collecting on any stand and reads this
        # directory. No request is sent by --list; start no controller here.
        collection_env.update({"CLUSTER_DELIVERY_RUN_ID": directory.name,
                               "CLUSTER_DELIVERY_CONTROL_DIR": _child_path(stand, directory)})
        # Repeats are execution, not selection. Collect one static identity while
        # forwarding the original repeat count to the native diagnostic phase.
        collection_args = []
        arguments = iter(extra_args)
        for arg in arguments:
            if arg == "--repeat-each":
                next(arguments, None)
            elif not arg.startswith("--repeat-each="):
                collection_args.append(arg)
        # --list evaluates the original filters before any controller can mutate the stand.
        code = control.run_e2e_runner(
            stand, collection_env, "node", PLAYWRIGHT_ENTRY, "--hilos-phase=plan",
            "-c", "playwright.cluster.config.ts", *collection_args)
        if code != 0:
            raise RuntimeError(f"test collection exited {code}")
        tests = _read_tests(plan_file, plan=True)
        _refuse_undeclared(stand, tests)
        allowed = {"daemon"} if daemon_only else {"live", "after-loss"} | ({"daemon"} if run_daemon else set())
        for project in sorted(allowed & FLAKY_SKIP.keys()):
            print(f"cluster e2e: phase {project} SKIP ({FLAKY_SKIP[project]})")
        tests = [test for test in tests if test["project"] in allowed - FLAKY_SKIP.keys()]
        if not tests:
            raise RuntimeError("No tests found in the selected cluster projects")
        _atomic_json(plan_file, {"version": 1, "tests": tests})
        quarantine = [] if diagnostic else [test for test in tests if test["flake"] is not None]
        main = tests if diagnostic else [test for test in tests if test["flake"] is None]
        fault_file = _run_path(stand, "fault.json")
        fault_file.unlink(missing_ok=True)
        if require_after_loss and not stand.e2e.required_loss_agent:
            raise ValueError("--require-after-loss needs x-hilos-cluster.e2e.required-loss-agent")
        loss = _LossState(required_agent=stand.e2e.required_loss_agent if require_after_loss else None)
        if len(stand.database_sql_backends) > 1:
            try:
                waits = galera_waits.GaleraWaits(stand, stand.demo_dir / "data" / "logs-cluster" / "galera")
                waits_thread = threading.Thread(target=waits.run, name="galera-waits")
                waits_thread.daemon = True
                waits_thread.start()
            except Exception as error:
                galera_waits.warn(error)
        try:
            main_rc = _main(stand, main, extra_args, run_env, fault_file, loss,
                            "diagnostic" if diagnostic else "main",
                            directory / "after-loss-proof.json" if require_after_loss else None)
        except Exception as error:
            main_rc = 1
            print(f"cluster e2e: main failed: {error}")
        observations = _quarantine_tail(stand, quarantine, extra_args, directory, run_env, fault_file, loss)
        reason = "Quarantine host did not start the selected test"
    except Exception as error:
        reason = f"cluster e2e: failed: {error}"
        print(reason)
    finally:
        if waits is not None:
            try:
                waits.stop.set()
                if waits_thread is not None and waits_thread.ident is not None:
                    waits_thread.join(timeout=galera_waits.JOIN_SECONDS)
                    if waits_thread.is_alive():
                        galera_waits.warn("observer did not stop before join deadline")
            except Exception as error:
                galera_waits.warn(error)
        if directory_created:
            try:
                # Address only this UUID's own protocol files, never another run or the parent.
                for name in ("plan.json", "plan.json.tmp", "after-loss.json", "live.json", "daemon.json",
                             "after-loss-proof.json"):
                    (directory / name).unlink(missing_ok=True)
                directory.rmdir()
            except OSError as error:
                cleanup_error = f"cluster quarantine: sidecar cleanup failed: {error}"
                print(cleanup_error)
    for row in _normalize(quarantine, observations, reason, cleanup_error):
        print("hilos-quarantine: " + json.dumps(row, ensure_ascii=True, separators=(",", ":")))
    print(f"=== summary ===\n  browser main suite {'failed' if main_rc else 'passed'}")
    return main_rc

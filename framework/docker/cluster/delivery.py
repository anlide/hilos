"""In-phase browser delivery faults on the existing Binance stand.

The host owns the captured process and the pause lease. Runner requests name only
allowlisted agents/actions; they never supply a command, container, PID or signal.
This ledger is private test orchestration, like browser._DaemonController, not
application state. No Hilos DB/RT owner participates.
"""

import json
import re
import signal
import threading
import time

import control
import scenarios


AGENTS = frozenset(("hilos_logs", "hilos_index", "hilos_settings_library"))
FAULT_AGENT = "hilos_logs"


def topology(views):
    """Keep placement/peer delivery evidence without copying unrelated application RT rows."""
    keys = ("localNodeId", "lifecycleState", "leaderId", "term", "hasQuorum", "placements",
            "clientIndex", "clientDeliveries", "lastClientAcceptKey")
    return {node: {key: view.get(key) for key in keys} for node, view in views.items()}


def active_workers(text):
    """Replay the current daemon life, forgetting stops and reused worker indices."""
    active = {}
    running = False
    for line in text.splitlines():
        if line.endswith("] Daemon started with epoll"):
            active, running = {}, True
        elif re.search(r"\] Daemon stopped(?: after a failure)?$", line):
            active, running = {}, False
        elif running:
            worker = re.search(r"\] Worker #(\d+) (?:died hosting|started \[type=)", line)
            if worker:
                active = {agent: index for agent, index in active.items() if index != int(worker[1])}
            agent = re.search(r"\] Agent '([^']+)' (started|stopped) on worker #(\d+)$", line)
            if agent:
                if agent[2] == "started":
                    active[agent[1]] = int(agent[3])
                else:
                    active.pop(agent[1], None)
    return active


class DeliveryController:
    """Sequence-bound atomic handshake, joined before host restoration and cleanup."""

    def __init__(self, stand, directory, run_id, leader, term):
        self.stand, self.directory, self.run_id = stand, directory, run_id
        self.leader, self.term = leader, term
        self.stop = threading.Event()
        self.sequence = 0
        self.process = None
        self.paused_at = None
        self.killed = False
        self.mark = None
        self.error = None
        self.restored = False

    def stable_views(self):
        views = scenarios.inspect_all(self.stand.members)
        # Slaves have no consensus coordinator; their inspect leader/term are
        # deliberately null. All masters must retain the original epoch.
        if (any(not view for view in views.values())
                or any(views[node].get("leaderId") != self.leader
                       or views[node].get("term") != self.term for node in self.stand.masters)):
            raise RuntimeError("delivery fault changed the leader/term or lost a member: "
                               + scenarios.summarize(views))
        return topology(views)

    def locate(self, agent_id):
        self.stable_views()
        holders = []
        for node in self.stand.members:
            active = active_workers(scenarios.node_log(node))
            if agent_id in active:
                holders.append((node, active[agent_id]))
        if len(holders) != 1:
            raise RuntimeError(f"expected one live {agent_id} holder, found {holders}")
        node, index = holders[0]
        process = control.worker_process(self.stand, node, index)
        state = self.process_state(process)
        if state is None or state["state"] in ("T", "t", "Z"):
            raise RuntimeError(f"{agent_id} has no running worker: {state}")
        return process

    def process_state(self, process, number=None):
        outcome = control.signal_worker_process(self.stand, process, number)
        if outcome.code != 0:
            raise RuntimeError(f"captured delivery worker control failed: {outcome}")
        return json.loads(outcome.out)

    def validate(self, request):
        if (not isinstance(request, dict)
                or set(request) != {"runId", "sequence", "action", "agentId", "socketNode"}
                or request["runId"] != self.run_id
                or type(request["sequence"]) is not int or request["sequence"] != self.sequence + 1
                or request["agentId"] not in AGENTS
                or request["socketNode"] not in self.stand.masters
                or request["action"] not in ("locate", "pause", "kill", "recover")):
            raise RuntimeError(f"invalid delivery controller request: {request!r}")
        if request["action"] != "locate" and request["agentId"] != FAULT_AGENT:
            raise RuntimeError("only the Logs page owner may be faulted")

    def dispatch(self, request):
        self.validate(request)
        action = request["action"]
        if action in ("locate", "pause"):
            if self.paused_at is not None or self.killed:
                raise RuntimeError("delivery worker fault already in progress")
            captured = self.locate(request["agentId"])
            if captured.node == request["socketNode"]:
                raise RuntimeError("delivery proof requires a remote owner")
            if action == "pause":
                # Save BEFORE STOP, so every exception and runner exit has a backstop.
                self.process = captured
                self.mark = scenarios.node_log_mark(captured.node)
                self.paused_at = time.monotonic()
                self.process_state(captured, signal.SIGSTOP)
                scenarios.wait_until(
                    lambda _: (self.process_state(captured) or {}).get("state") in ("T", "t"),
                    scenarios.CONVERGE_TIMEOUT, "captured delivery owner stopped", nodes=[])
            result = {"worker": captured._asdict(), "state": self.process_state(captured)}
        elif action == "kill":
            if self.paused_at is None or self.killed or self.process is None:
                raise RuntimeError("delivery kill requires the captured paused owner")
            self.stable_views()
            if (self.process_state(self.process) or {}).get("state") not in ("T", "t"):
                raise RuntimeError("delivery owner was not still stopped before KILL")
            # No runner-supplied identity can replace the captured incarnation.
            self.process_state(self.process, signal.SIGKILL)
            self.killed = True
            self.paused_at = None
            scenarios.wait_until(lambda _: self.process_state(self.process) is None
                                 and self.loss_reported(), scenarios.CONVERGE_TIMEOUT,
                                 "captured worker death reported by its live master", nodes=[])
            result = {"worker": self.process._asdict(), "loss": self.loss_reported()}
        else:
            if not self.killed:
                raise RuntimeError("delivery recovery requires a worker loss")
            result = self.recovered()
            self.restored = True
        self.sequence += 1
        return {"runId": self.run_id, "sequence": self.sequence, "ok": True,
                "action": action, **result, "views": self.stable_views()}

    def loss_reported(self):
        return [match.group(0) for match in scenarios.WORKER_DIED_HOSTING.finditer(
            scenarios.node_log_since(self.process.node, self.mark))
                if int(match[1]) == self.process.index and FAULT_AGENT in match[2].split(", ")]

    def recovered(self):
        ready = None

        def running(_):
            nonlocal ready
            try:
                ready = self.locate(FAULT_AGENT)
            except (RuntimeError, AssertionError):
                return False
            return ready != self.process

        scenarios.wait_until(running, scenarios.CONVERGE_TIMEOUT,
                             "delivery owner ready on a new registered worker", nodes=[])
        views = scenarios.wait_converge(self.stand.members)
        if (ready.node != self.process.node or ready.container != self.process.container
                or not self.loss_reported() or not scenarios.started_on_worker_at(
                    scenarios.node_log_since(ready.node, self.mark), FAULT_AGENT)):
            raise RuntimeError("delivery recovery lacks fresh worker loss/start evidence")
        journal = scenarios.node_log_since(self.process.node, self.mark)
        if "Daemon started with epoll" in journal or "Daemon stopped" in journal:
            raise RuntimeError("delivery worker fault restarted its master")
        return {"worker": ready._asdict(), "loss": self.loss_reported(),
                "journal": journal, "converged": topology(views)}

    def publish(self, sequence, value):
        path = self.directory / f"result-{sequence}.json"
        temporary = path.with_suffix(".json.tmp")
        temporary.write_text(json.dumps(value), encoding="utf-8")
        temporary.replace(path)
        summary = {key: value[key] for key in ("runId", "sequence", "ok", "action", "worker", "loss", "error")
                   if key in value}
        print(f"cluster delivery: {json.dumps(summary)}", flush=True)

    def run(self):
        try:
            while not self.stop.is_set():
                # Independent of the browser request loop: a hung/lost runner
                # cannot leave a STOPped worker past the host's existing budget.
                if self.paused_at is not None and time.monotonic() - self.paused_at >= scenarios.CONVERGE_TIMEOUT:
                    raise RuntimeError("delivery owner pause lease expired")
                sequence = self.sequence + 1
                path = self.directory / f"request-{sequence}.json"
                if path.exists():
                    request = json.loads(path.read_text(encoding="utf-8"))
                    if self.stop.is_set():
                        return
                    self.publish(sequence, self.dispatch(request))
                else:
                    self.stop.wait(0.05)
        except Exception as error:
            self.error = str(error)
            self.publish(self.sequence + 1, {"runId": self.run_id, "sequence": self.sequence + 1,
                                           "ok": False, "error": self.error})
        finally:
            # Resume only this incarnation, including after a failed STOP reply.
            if self.process is not None:
                try:
                    self.process_state(self.process, signal.SIGCONT)
                except Exception as error:
                    self.error = f"{self.error or ''}; delivery CONT backstop failed: {error}"

    def restore(self):
        """Called after join on every exit; any failed restoration makes live red."""
        if self.process is not None:
            self.process_state(self.process, signal.SIGCONT)
            if not self.restored:
                if self.killed:
                    self.recovered()
                else:
                    self.locate(FAULT_AGENT)
            views = scenarios.wait_converge(self.stand.members)
            print(f"cluster delivery: cleanup converged all members: {scenarios.summarize(views)}", flush=True)

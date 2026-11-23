"""The real browser host flow with an injected gateway, controllers and filesystem sidecars."""

import io
import json
import tempfile
import threading
import unittest
from contextlib import ExitStack, redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import browser
from stand import StandRefused, _node


def planned(project, flake=None, title=None, file=None):
    title = title or f"{project} check"
    file = file or f"{project}/check.spec.ts"
    return {"project": project, "flake": flake, "file": file, "line": 12,
            "titlePath": [title], "selector": f"[{project}] › {file}:12 › {title}"}


def observed(test, status="passed", reason=""):
    return {key: test[key] for key in ("flake", "project", "file", "line", "titlePath")} | {
        "status": status, "reason": reason}


class _InlineThread:
    """Exercise stop/join ordering deterministically without background work."""

    def __init__(self, target, name):
        self.target = target
        self.ident = None
        self.alive = False

    def start(self):
        self.ident = 1
        self.alive = True
        self.target()

    def join(self, timeout=None):
        self.alive = False

    def is_alive(self):
        return self.alive


class BrowserQuarantineTest(unittest.TestCase):
    """Main dependencies, native rc and deferred host-aware results are one protocol."""

    def setUp(self):
        stack = ExitStack()
        self.addCleanup(stack.close)
        self.output = io.StringIO()
        stack.enter_context(redirect_stdout(self.output))
        self.root = Path(stack.enter_context(tempfile.TemporaryDirectory()))
        self.stand = SimpleNamespace(
            demo_dir=self.root / "demo" / "carrier", project="stand",
            compose=self.root / "docker-compose.cluster.yml",
            e2e=SimpleNamespace(capabilities=frozenset(("daemon", "delivery", "env-mismatch")),
                                required_loss_agent="stock_library"),
            masters=["m1", "m2", "m3"], slaves=["s1", "s2"],
            entry=SimpleNamespace(container="entry"), database_sql_backends=())
        self.stand.members = {node: SimpleNamespace(container=node, ip="127.0.0.1", name=f"Node {node}")
                              for node in self.stand.masters + self.stand.slaves}
        (self.stand.demo_dir / "tests").mkdir(parents=True)
        # The protocol is exercised with no phase switched off; parking has its own cases.
        stack.enter_context(patch.dict(browser.FLAKY_SKIP, clear=True))
        self.plan = [planned("live"), planned("after-loss"), planned("live", "F-016"),
                     planned("daemon", "F-023")]
        self.events = []
        self.calls = []
        self.codes = {}
        self.statuses = {}
        self.write_fault = True
        self.fault = {"stop": "s1", "reason": "the captured archive"}
        self.write_results = True
        self.leader, self.term = "m1", 4
        self.daemon_error = None
        self.daemon_completed = True
        self.delivery_error = None
        self.restore_error = None
        self.gateway_hook = None
        self.bind = stack.enter_context(patch.object(browser.scenarios, "bind"))
        self.wait = stack.enter_context(patch.object(browser.scenarios, "wait_converge", side_effect=self.views))
        stack.enter_context(patch.object(browser.scenarios, "wait_until", side_effect=self.wait_until))
        stack.enter_context(patch.object(browser.scenarios, "node_log_mark", return_value=0))
        self.refusal = stack.enter_context(patch.object(browser.scenarios, "assert_no_startup_refusal"))
        stack.enter_context(patch.object(browser.control, "node_log_path", side_effect=lambda member:
                                        self.stand.demo_dir / "data" / "logs" / member.container))
        self.gateway = stack.enter_context(patch.object(browser.control, "run_e2e_runner", side_effect=self.runner))
        self.kill = stack.enter_context(patch.object(browser.control, "kill", side_effect=self.switch("kill")))
        self.start = stack.enter_context(patch.object(browser.control, "start", side_effect=self.switch("start")))
        self.own_env = stack.enter_context(patch.object(browser.control, "own_env", side_effect=self.switch("env")))
        self.ensure = stack.enter_context(patch.object(browser.control, "ensure_open_for_tests", side_effect=self.open))
        self.daemon_factory = stack.enter_context(patch.object(browser, "_DaemonController", side_effect=self.daemon))
        self.delivery_factory = stack.enter_context(patch.object(browser.delivery, "DeliveryController",
                                                                side_effect=self.delivery))
        stack.enter_context(patch.object(browser.threading, "Thread", _InlineThread))

    def test_galera_observer_guards_zero_and_one_sql_backend(self):
        for members in ((), ("db1",)):
            self.stand.database_sql_backends = members
            self.output.seek(0)
            self.output.truncate()
            with patch.object(browser.galera_waits, "GaleraWaits") as factory:
                self.assertEqual(0, browser.run(self.stand, []))
            factory.assert_not_called()

    def test_galera_observer_spans_main_and_quarantine_then_stops_and_joins(self):
        self.stand.database_sql_backends = ("db1", "db2")
        observer = SimpleNamespace(stop=Mock(), run=lambda: self.events.append(("galera-start",)))
        observer.stop.set.side_effect = lambda: self.events.append(("galera-stop",))
        def joined(thread, timeout=None):
            thread.alive = False
            if timeout == browser.galera_waits.JOIN_SECONDS:
                self.events.append(("galera-join",))
        with patch.object(browser.galera_waits, "GaleraWaits", return_value=observer) as factory, \
                patch.object(_InlineThread, "join", autospec=True, side_effect=joined):
            self.assertEqual(0, browser.run(self.stand, []))
        factory.assert_called_once_with(self.stand, self.stand.demo_dir / "data/logs-cluster/galera")
        start = self.events.index(("galera-start",))
        stop = self.events.index(("galera-stop",))
        phases = [index for index, event in enumerate(self.events) if event[0] in ("main", "quarantine")]
        self.assertTrue(all(start < index < stop for index in phases))
        self.assertGreater(self.events.index(("galera-join",)), stop)
        observer.stop.set.assert_called_once()

    def test_galera_start_and_stop_errors_preserve_main_rc(self):
        self.stand.database_sql_backends = ("db1", "db2")
        for code in (0, 7):
            for failing in ("start", "stop", "factory"):
                with self.subTest(code=code, failing=failing):
                    self.output.seek(0)
                    self.output.truncate()
                    self.codes[("main", "live")] = code
                    observer = SimpleNamespace(stop=Mock(), run=Mock())
                    if failing == "start":
                        observer.run.side_effect = RuntimeError("observer start failed")
                    if failing == "stop":
                        observer.stop.set.side_effect = RuntimeError("observer stop failed")
                    with patch.object(browser.galera_waits, "GaleraWaits", return_value=observer,
                                      side_effect=RuntimeError("observer factory failed") if failing == "factory" else None):
                        self.assertEqual(code, browser.run(self.stand, []))
                    self.assertIn("cluster e2e: galera waits: observer", self.output.getvalue())

    def views(self, nodes):
        self.events.append(("converge", tuple(nodes)))
        return {node: {"consensusRole": "leader" if node == self.leader else "follower",
                       "lifecycleState": "MasterLeader" if node == self.leader else "MasterFollower",
                       "hasQuorum": True, "leaderId": self.leader, "term": self.term}
                for node in nodes}

    def wait_until(self, predicate, timeout, description, nodes):
        views = self.views(nodes)
        self.assertTrue(predicate(views))
        return views

    def switch(self, action):
        def execute(stand, node, *args):
            self.events.append((action, node, *args))
            if action == "start" and node == "s1":
                self.leader, self.term = "m2", 5
            return SimpleNamespace(code=0, out="switched", err="")
        return execute

    def open(self, stand, timeout):
        self.events.append(("open",))

    def daemon(self, stand, directory, run_id, leader, term):
        return SimpleNamespace(stop=threading.Event(), kill_attempted=True, completed=self.daemon_completed,
                               error=self.daemon_error, leader=leader, run=Mock())

    def delivery(self, stand, directory, run_id, leader, term):
        controller = SimpleNamespace(stop=threading.Event(), sequence=0, error=self.delivery_error, run=Mock())

        def restore():
            self.assertTrue(controller.stop.is_set())
            self.events.append(("delivery-restore",))
            if self.restore_error:
                raise RuntimeError(self.restore_error)
        controller.restore = restore
        return controller

    def host_path(self, child):
        return self.root / child.removeprefix("/hilos/")

    def write_sidecar(self, path, tests):
        path.write_text(json.dumps({"version": 1, "tests": tests}), encoding="utf-8")

    def runner(self, stand, env, *args):
        if "npm-install-if-stale.mjs" in args[-1]:
            self.events.append(("install",))
            return self.codes.get("install", 0)
        self.assertEqual(("node", browser.PLAYWRIGHT_ENTRY), args[:2])
        phase = next((arg.split("=", 1)[1] for arg in args if arg.startswith("--hilos-phase=")), "diagnostic")
        self.assertNotIn("--require-after-loss", args)
        if "CLUSTER_AFTER_LOSS_PROOF_FILE" in env:
            self.assertEqual(("main", "--project=after-loss"), (phase, args[-1]))
            proof = self.host_path(env["CLUSTER_AFTER_LOSS_PROOF_FILE"])
            self.assertEqual(self.host_path(env["HILOS_E2E_PLAN_FILE"]).parent, proof.parent)
        if phase == "plan":
            self.events.append(("plan",))
            self.assertFalse(any(arg.startswith("--repeat-each") for arg in args))
            directory = self.host_path(env["CLUSTER_DELIVERY_CONTROL_DIR"])
            self.assertTrue(directory.is_dir())
            self.assertEqual(directory.name, env["CLUSTER_DELIVERY_RUN_ID"])
            projects = [arg.removeprefix("--project=") for arg in args if arg.startswith("--project=")]
            selected = [test for test in self.plan if not projects or test["project"] in projects]
            self.write_sidecar(self.host_path(env["HILOS_E2E_PLAN_FILE"]), selected)
            return self.codes.get("plan", 0)
        project = args[-1].removeprefix("--project=")
        self.assertEqual(1, sum(arg.startswith("--project") for arg in args))
        if "delivery" in stand.e2e.capabilities and project == "live":
            self.assertEqual(browser._run_path(self.stand, "delivery"),
                             self.host_path(env["CLUSTER_DELIVERY_CONTROL_DIR"]).parent)
        self.calls.append((phase, project, dict(env), args))
        self.events.append((phase, project))
        # Publication is delayed until every host check and its cleanup have finished.
        self.assertEqual([], self.lines())
        if phase == "main" and project == "live" and self.write_fault:
            self.host_path(env["CLUSTER_FAULT_FILE"]).write_text(
                json.dumps(self.fault), encoding="utf-8")
        if phase == "quarantine":
            self.assertEqual("1", env["HILOS_E2E_DEFER_REPORT"])
            if self.write_results:
                selected = [test for test in self.plan if test["project"] == project and test["flake"]]
                rows = [observed(test, self.statuses.get(project, "passed")) for test in selected]
                self.write_sidecar(self.host_path(env["HILOS_E2E_RESULT_FILE"]), rows)
        if self.gateway_hook:
            self.gateway_hook(phase, project, env)
        return self.codes.get((phase, project), 0)

    def lines(self):
        return [json.loads(line.removeprefix("hilos-quarantine: "))
                for line in self.output.getvalue().splitlines() if line.startswith("hilos-quarantine: ")]

    def test_all_main_before_tail_restores_slave_refreshes_roles_and_daemon_is_last(self):
        self.stand.e2e.required_loss_agent = None
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([("main", "live"), ("main", "after-loss"),
                          ("quarantine", "live"), ("quarantine", "daemon")],
                         [(phase, project) for phase, project, _, _ in self.calls])
        live_index = self.events.index(("quarantine", "live"))
        self.assertLess(self.events.index(("start", "s1")), live_index)
        self.assertLess(self.events.index(("converge", tuple(self.stand.members)),
                                          self.events.index(("start", "s1"))), live_index)
        self.assertEqual("m2", self.calls[2][2]["CLUSTER_LEADER"])
        self.assertEqual("m1", self.calls[2][2]["CLUSTER_FOLLOWER"])
        for _, _, env, _ in self.calls:
            self.assertEqual({node: f"Node {node}" for node in self.stand.members},
                             json.loads(env["CLUSTER_NODE_NAMES"]))
        self.assertEqual(["passed", "passed"], [row["status"] for row in self.lines()])
        self.assertEqual([], list(browser._run_path(self.stand, "quarantine").iterdir()))

    def test_demo_data_neither_present_nor_writable_changes_no_verdict(self):
        data = self.stand.demo_dir / "data"
        for case in ("absent", "read-only"):
            with self.subTest(data=case):
                if case == "read-only":
                    data.mkdir()
                    data.chmod(0o555)
                    # Handed back writable before the temporary tree is removed.
                    self.addCleanup(data.chmod, 0o755)
                self.output.seek(0)
                self.output.truncate()
                self.events.clear()
                self.calls.clear()
                self.leader, self.term = "m1", 4
                self.assertEqual(0, browser.run(self.stand, []))
                self.assertEqual([("main", "live"), ("main", "after-loss"),
                                  ("quarantine", "live"), ("quarantine", "daemon")],
                                 [(phase, project) for phase, project, _, _ in self.calls])
                self.assertEqual(["passed", "passed"], [row["status"] for row in self.lines()])
                # The run removed its own files from every kind of run directory.
                for kind in ("quarantine", "daemon", "delivery"):
                    self.assertEqual([], list(browser._run_path(self.stand, kind).iterdir()))
                if case == "absent":
                    self.assertFalse(data.exists())
                else:
                    self.assertEqual([], list(data.iterdir()))

    def test_native_main_red_still_runs_tail_and_does_not_run_dependent_main(self):
        self.codes[("main", "live")] = 7
        self.codes[("quarantine", "daemon")] = 9
        self.assertEqual(7, browser.run(self.stand, []))
        self.assertEqual([("main", "live"), ("quarantine", "live"), ("quarantine", "daemon")],
                         [(phase, project) for phase, project, _, _ in self.calls])

    def test_required_loss_refuses_either_missing_main_phase(self):
        for missing in ("live", "after-loss"):
            with self.subTest(missing=missing):
                self.output.seek(0)
                self.output.truncate()
                self.plan = [planned("after-loss" if missing == "live" else "live"),
                             planned(missing, "F-101")]
                self.assertEqual(1, browser.run(self.stand, ["--require-after-loss"]))
                self.assertIn("both live and after-loss in the main plan", self.output.getvalue())
                self.assertFalse(any(phase == "main" for phase, _, _, _ in self.calls))
                self.kill.assert_not_called()

    def test_required_loss_refuses_absent_or_invalid_fault(self):
        self.plan = [planned("live"), planned("after-loss")]
        self.write_fault = False
        for fault in (None, {"stop": "m1", "reason": "a master"}, {"stop": "s1"}):
            with self.subTest(fault=fault):
                def publish(phase, project, env):
                    if fault is not None:
                        self.host_path(env["CLUSTER_FAULT_FILE"]).write_text(json.dumps(fault))
                self.gateway_hook = publish
                self.calls.clear()
                self.assertEqual(1, browser.run(self.stand, ["--require-after-loss"]))
                self.assertEqual([("main", "live")], [call[:2] for call in self.calls])
                self.kill.assert_not_called()

    def placement_views(self, nodes, host):
        views = self.views(nodes)
        views[self.leader]["placements"] = [{"agentId": "stock_library", "nodeId": host, "state": "started"}]
        return views

    def test_required_agent_cannot_be_changed_or_omitted_in_main_or_quarantine(self):
        self.plan = [planned("live"), planned("after-loss"), planned("after-loss", "F-101")]
        for agent in (None, "other_library"):
            with self.subTest(agent=agent):
                self.output.seek(0)
                self.output.truncate()
                if agent:
                    self.fault["agentId"] = agent
                self.calls.clear()
                self.assertEqual(1, browser.run(self.stand, ["--require-after-loss"]))
                self.assertIn("expected agentId 'stock_library'", self.output.getvalue())
                self.assertIn("main did not stop", self.lines()[-1]["reason"])
                self.assertEqual("notRun", self.lines()[-1]["status"])
                self.assertEqual([("main", "live")], [call[:2] for call in self.calls])
                self.kill.assert_not_called()

    def arrange_agent_loss(self, owner="s1"):
        self.plan = [planned("live"), planned("after-loss")]
        self.fault["agentId"] = "stock_library"
        self.boot = "2026-05-01T10:00:00Z"
        self.stopped_state = f"container-id false {self.boot} 2026-05-01T10:01:00.123999999Z"
        self.start_stamp = "2026-05-01T10:01:00.124Z"
        survivor = self.stand.members["s2"]
        journal = browser.control.node_log_path(survivor)
        journal.parent.mkdir(parents=True)
        self.journal = journal
        self.after_start = lambda: None
        self.before_kill = ("[2026-05-01T10:00:00.000Z] Daemon started with epoll\n"
                            "[2026-05-01T10:02:00.000Z] Agent 'stock_library' started on worker #1\n"
                            "[2026-05-01T10:02:00.001Z] Agent 'stock_library' stopped on worker #1\n")
        journal.write_text(self.before_kill)
        snapshot = browser.control.node_log_segments

        def mark(node):
            parts = snapshot(node)
            self.events.append(("mark", node.container))
            return parts

        def inspect_owner(nodes):
            views = self.placement_views(nodes, owner)
            self.events.append(("owner", owner))
            return views

        def kill(stand, node, *, container_id):
            self.assertEqual("container-id", container_id)
            self.assertEqual(("owner", "s1"), self.events[-1])
            self.assertIn(("mark", "s2"), self.events)
            # The host is still in Docker kill when this line becomes readable.
            with journal.open("a") as log:
                log.write(f"[{self.start_stamp}] Agent 'stock_library' started on worker #2\n")
            self.after_start()
            return self.switch("kill")(stand, node)

        def inspect_container(stand, node, *, include_finished=False):
            state = self.stopped_state if include_finished else f"container-id true {self.boot}"
            return SimpleNamespace(code=0, out=state)

        def after_kill(predicate, timeout, desc, nodes):
            views = self.placement_views(nodes, "s2")
            if not predicate(views):
                raise browser.scenarios.ScenarioTimeout(desc)
            return views

        self.kill.side_effect = kill
        self.enterContext(patch.object(browser.control, "container_state", side_effect=inspect_container))
        self.enterContext(patch.object(browser.scenarios, "inspect_all", side_effect=inspect_owner))
        self.enterContext(patch.object(browser.control, "node_log_segments", side_effect=mark))
        self.enterContext(patch.object(browser.scenarios, "wait_until", side_effect=after_kill))

    def test_required_loss_refuses_a_changed_owner_before_kill(self):
        self.arrange_agent_loss(owner="s2")
        self.assertEqual(1, browser.run(self.stand, ["--require-after-loss"]))
        self.assertIn("requested s1, started on ['s2']", self.output.getvalue())
        self.kill.assert_not_called()
        self.assertEqual([("main", "live")], [call[:2] for call in self.calls])

    def test_required_loss_orders_the_worker_start_against_the_actual_stop(self):
        self.arrange_agent_loss()

        def proof(phase, project, env):
            if project == "after-loss":
                self.host_path(env["CLUSTER_AFTER_LOSS_PROOF_FILE"]).write_text('{"verified":true}')

        self.gateway_hook = proof
        for finish, start, expected in (("123999999", "122", 1), ("123999999", "123", 1),
                                        ("123", "123", 1), ("123999999", "124", 0)):
            with self.subTest(finish=finish, start=start):
                self.stopped_state = f"container-id false {self.boot} 2026-05-01T10:01:00.{finish}Z"
                self.start_stamp = f"2026-05-01T10:01:00.{start}Z"
                self.journal.write_text(self.before_kill)
                self.calls.clear()
                self.assertEqual(expected, browser.run(self.stand, ["--require-after-loss"]))
                self.assertEqual(expected == 0, any(project == "after-loss" for _, project, _, _ in self.calls))

    def test_docker_time_preserves_all_nine_fraction_digits(self):
        whole = "2026-11-23T07:58:03"
        base = browser._docker_time(whole + "Z")
        for length in range(1, 10):
            digits = "986883271"[:length]
            self.assertEqual(base + int(digits.ljust(9, "0")), browser._docker_time(f"{whole}.{digits}Z"))
        self.assertLess(browser._docker_time(whole + ".123000001Z"), browser._docker_time(whole + ".123000002Z"))
        for invalid in ("2026-02-30T00:00:00Z", whole + ".Z", whole + "+00:00", whole + ".1234567890Z"):
            with self.assertRaises(ValueError):
                browser._docker_time(invalid)

    def test_only_current_post_cut_copy_admits_after_loss_through_rotation(self):
        self.arrange_agent_loss()
        self.gateway_hook = lambda phase, project, env: (
            self.host_path(env["CLUSTER_AFTER_LOSS_PROOF_FILE"]).write_text('{"verified":true}')
            if project == "after-loss" else None)
        start = "Agent 'stock_library' started on worker #2"
        for location, events, success in (
                ("staging", [], True), ("archive", [], True),
                ("archive", ["Worker #2 died hosting 1 agent(s): stock_library"], False),
                ("archive", ["Worker #2 started [type=agent]"], False),
                ("archive", ["Agent 'stock_library' stopped on worker #2"], False),
                ("staging", [start], False), ("archive", ["Agent 'stock_library' started on worker #3"], False),
                ("archive", ["Daemon stopped"], False), ("archive", ["Daemon started with epoll"], False),
                ("archive", ["Daemon started with epoll", start], True)):
            with self.subTest(location=location, events=events):
                self.journal.write_text(self.before_kill)
                batch = self.journal.parent / location / "2026-05-01-10-01-01Z" / self.journal.name
                batch.parent.mkdir(parents=True, exist_ok=True)

                def rotate():
                    self.journal.rename(batch)
                    self.journal.write_text("".join(f"[{self.start_stamp}] {event}\n" for event in events))

                self.after_start = rotate
                self.calls.clear()
                self.assertEqual(0 if success else 1, browser.run(self.stand, ["--require-after-loss"]))
                self.assertEqual(success, any(project == "after-loss" for _, project, _, _ in self.calls))
                batch.unlink()

    def test_interrupted_batch_before_a_new_daemon_keeps_the_post_cut_proof(self):
        self.arrange_agent_loss()
        self.gateway_hook = lambda phase, project, env: (
            self.host_path(env["CLUSTER_AFTER_LOSS_PROOF_FILE"]).write_text('{"verified":true}')
            if project == "after-loss" else None)
        batch = self.journal.parent / "staging" / "2026-05-01-10-01-01Z" / self.journal.name
        batch.parent.mkdir(parents=True)
        boot = "[2026-05-01T10:01:00.125Z] Daemon started with epoll\n"
        start = "[2026-05-01T10:01:00.126Z] Agent 'stock_library' started on worker #7\n"
        for current, success in ((boot + start, True), (boot + start.rstrip(), False),
                                 (start, False), (boot.rstrip(), False),
                                 ("interrupted " + boot + start, False)):
            with self.subTest(current=current):
                self.journal.write_text(self.before_kill)

                def rotate():
                    with self.journal.open("a") as stream:
                        stream.write(f"[{self.start_stamp}] interrupted record")
                    self.journal.rename(batch)
                    self.journal.write_text(current)

                self.after_start = rotate
                self.calls.clear()
                self.assertEqual(0 if success else 1, browser.run(self.stand, ["--require-after-loss"]))
                self.assertEqual(success, any(project == "after-loss" for _, project, _, _ in self.calls))
                batch.unlink()

    def test_quarantine_waits_for_same_cut_after_the_main_worker_dies(self):
        self.arrange_agent_loss()
        self.plan.append(planned("after-loss", "F-101"))
        def dead():
            with self.journal.open("a") as stream:
                stream.write(f"[{self.start_stamp}] Worker #2 died hosting 1 agent(s): stock_library\n")
        self.after_start = dead
        observations = []
        def converge(predicate, timeout, desc, nodes):
            views = self.placement_views(nodes, "s2")
            if observations:
                with self.journal.open("a") as stream:
                    stream.write(f"[{self.start_stamp}] Agent 'stock_library' started on worker #7\n")
            observations.append(predicate(views))
            if not observations[-1]:
                raise browser.scenarios.ScenarioTimeout(desc)
            return views
        self.enterContext(patch.object(browser.scenarios, "wait_until", side_effect=converge))
        self.assertEqual(1, browser.run(self.stand, ["--require-after-loss"]))
        self.assertEqual([False, True], observations)
        self.assertEqual([("main", "live"), ("quarantine", "after-loss")], [call[:2] for call in self.calls])
        self.kill.assert_called_once()

    def test_required_loss_refuses_unconfirmed_docker_finish_including_in_quarantine(self):
        self.arrange_agent_loss()
        self.plan.append(planned("after-loss", "F-101"))
        for stopped in (f"replacement false {self.boot} 2026-05-01T10:01:00Z",
                        "container-id false 2026-05-01T09:59:00Z 2026-05-01T10:01:00Z",
                        f"container-id true {self.boot} 2026-05-01T10:01:00Z",
                        f"container-id false {self.boot}", f"container-id false {self.boot} unknown",
                        f"container-id false {self.boot} 2026-05-01T10:01:00",
                        f"container-id false {self.boot} 0001-01-01T00:00:00Z",
                        f"container-id false {self.boot} 2026-05-01T09:59:00Z"):
            with self.subTest(state=stopped):
                self.stopped_state = stopped
                self.output.seek(0)
                self.output.truncate()
                self.calls.clear()
                self.kill.reset_mock()
                self.assertEqual(1, browser.run(self.stand, ["--require-after-loss"]))
                self.assertEqual([("main", "live")], [call[:2] for call in self.calls])
                self.assertEqual("notRun", self.lines()[-1]["status"])
                self.kill.assert_called_once()

    def test_required_loss_reads_only_this_runs_proof_after_native_success(self):
        self.arrange_agent_loss()
        self.plan.append(planned("live", "F-101"))
        stale = browser._run_path(self.stand, "quarantine", "earlier", "after-loss-proof.json")
        stale.parent.mkdir(parents=True)
        stale.write_text('{"verified":true}')
        for proof, native, expected in (
            (None, 0, 1), ("{", 0, 1), ("true", 0, 1), ("{}", 0, 1),
            ('{"verified":false}', 0, 1), ('{"verified":1}', 0, 1),
            ('{"verified":"true"}', 0, 1), ('{"verified":true}', 17, 17),
            ('{"verified":true}', 0, 0),
        ):
            with self.subTest(proof=proof, native=native):
                def publish(phase, project, env):
                    if (phase, project) == ("main", "after-loss") and proof is not None:
                        self.host_path(env["CLUSTER_AFTER_LOSS_PROOF_FILE"]).write_text(proof)
                self.gateway_hook = publish
                self.calls.clear()
                self.journal.write_text(self.before_kill)
                self.codes[("main", "after-loss")] = native
                self.output.seek(0)
                self.output.truncate()
                self.assertEqual(expected, browser.run(self.stand, ["--require-after-loss"]))
                self.assertEqual([("main", "live"), ("main", "after-loss"), ("quarantine", "live")],
                                 [call[:2] for call in self.calls])
                self.assertEqual("passed", self.lines()[-1]["status"])
                self.assertEqual([stale.parent], list(stale.parent.parent.iterdir()))

    def test_browser_environment_carries_the_composed_node_name_as_json(self):
        name = "Главный, узел=🌍"
        member, _ = _node("stand.yml", "master-two", {
            "container_name": "m2",
            "environment": {"CLUSTER_NODE_ROLE": "master", "CLUSTER_NODE_NAME": name},
            "networks": {"cluster": {"ipv4_address": "192.0.2.2"}},
            "volumes": [{"type": "bind", "target": "/var/log/hilos",
                         "source": str(self.stand.demo_dir / "data" / "logs" / "m2")}],
        }, "m2")
        self.stand.members["m2"] = member
        env = browser._environment(self.stand, "m1", "m2", "live")
        self.assertEqual(name, json.loads(env["CLUSTER_NODE_NAMES"])["m2"])
        self.assertEqual(set(self.stand.members), set(json.loads(env["CLUSTER_NODE_NAMES"])))

    def test_only_daemon_runs_once_and_empty_main_never_creates_a_controller(self):
        self.assertEqual(0, browser.run(self.stand, ["--daemon"]))
        self.assertEqual([("quarantine", "daemon")], [(p, t) for p, t, _, _ in self.calls])
        self.daemon_factory.assert_called_once()
        self.delivery_factory.assert_not_called()
        self.assertEqual(["F-023"], [row["flake"] for row in self.lines()])

    def test_main_daemon_after_tag_removal_keeps_its_original_place(self):
        self.plan[3] = planned("daemon")
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([("main", "daemon"), ("main", "live"), ("main", "after-loss"),
                          ("quarantine", "live")], [(p, t) for p, t, _, _ in self.calls])

    def test_switched_off_daemon_runs_in_neither_main_nor_quarantine(self):
        browser.FLAKY_SKIP["daemon"] = "F-023: switched off"
        self.plan[3] = planned("daemon")
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([("main", "live"), ("main", "after-loss"), ("quarantine", "live")],
                         [(p, t) for p, t, _, _ in self.calls])
        self.daemon_factory.assert_not_called()
        self.assertIn("cluster e2e: phase daemon SKIP (F-023: switched off)", self.output.getvalue())
        self.assertEqual(["F-016"], [row["flake"] for row in self.lines()])

    def test_switched_off_daemon_only_run_skips_before_any_runner_call(self):
        browser.FLAKY_SKIP["daemon"] = "F-023: switched off"
        self.assertEqual(0, browser.run(self.stand, ["--daemon"]))
        self.gateway.assert_not_called()
        self.daemon_factory.assert_not_called()
        self.assertIn("browser suite skipped: phase daemon", self.output.getvalue())
        self.assertEqual([], self.lines())

    def test_undeclared_daemon_argument_is_refused_before_any_runner_call(self):
        self.stand.e2e = SimpleNamespace(capabilities=frozenset(("delivery", "env-mismatch")))
        browser.FLAKY_SKIP["daemon"] = "F-023: switched off"
        with self.assertRaises(StandRefused) as refused:
            browser.run(self.stand, ["--daemon"])
        self.assertEqual(f"{self.stand.compose}: x-hilos-cluster.e2e does not declare daemon",
                         str(refused.exception))
        self.gateway.assert_not_called()
        self.assertNotIn("SKIP", self.output.getvalue())

    def test_unfit_shape_is_refused_before_any_runner_call(self):
        cases = (
            ("daemon", ["m1", "m2"], ["s1"],
             "e2e daemon needs at least three masters and one slave"),
            ("daemon", ["m1", "m2", "m3"], [],
             "e2e daemon needs at least three masters and one slave"),
            ("delivery", ["m1", "m2"], ["s1"], "e2e delivery needs at least three masters"),
            ("env-mismatch", ["m1", "m2", "m3"], [], "e2e env-mismatch needs at least one slave"),
        )
        for capability, masters, slaves, message in cases:
            with self.subTest(capability=capability, masters=masters, slaves=slaves):
                self.stand.e2e = SimpleNamespace(capabilities=frozenset((capability,)))
                self.stand.masters, self.stand.slaves = masters, slaves
                with self.assertRaises(StandRefused) as refused:
                    browser.run(self.stand, ["--grep=unrelated"])
                self.assertEqual(message, str(refused.exception))
                self.gateway.assert_not_called()

    def test_plan_needing_an_undeclared_capability_starts_no_phase(self):
        self.stand.e2e = SimpleNamespace(capabilities=frozenset())
        browser.FLAKY_SKIP["daemon"] = "F-023: switched off"
        for test, capability in ((planned("daemon"), "daemon"),
                                 (planned("live", file=browser.ENV_MISMATCH_SPEC), "env-mismatch"),
                                 (planned("live", file=browser.DELIVERY_SPEC), "delivery")):
            with self.subTest(capability=capability):
                self.output.seek(0)
                self.output.truncate()
                self.events.clear()
                self.plan = [test]
                self.assertEqual(1, browser.run(self.stand, ["--grep=check"]))
                self.assertEqual([("converge", tuple(self.stand.members)), ("install",), ("plan",)],
                                 self.events)
                self.assertEqual([], self.calls)
                self.daemon_factory.assert_not_called()
                self.delivery_factory.assert_not_called()
                self.own_env.assert_not_called()
                self.assertIn(f"{self.stand.compose}: {test['file']} ({test['project']}) needs capability "
                              f"{capability} in x-hilos-cluster.e2e.capabilities", self.output.getvalue())
                self.assertEqual([], self.lines())
                self.assertEqual([], list(browser._run_path(self.stand, "quarantine").iterdir()))

    def test_declared_delivery_serves_a_live_phase_without_its_spec(self):
        self.plan = [planned("live")]
        self.assertEqual(0, browser.run(self.stand, []))
        self.delivery_factory.assert_called_once()
        _, directory, run_id, _, _ = self.delivery_factory.call_args.args
        env = self.calls[0][2]
        self.assertEqual(("main", "live"), self.calls[0][:2])
        self.assertEqual(run_id, env["CLUSTER_DELIVERY_RUN_ID"])
        self.assertEqual(self.host_path(env["CLUSTER_DELIVERY_CONTROL_DIR"]), directory)

    def test_undeclared_delivery_starts_no_controller(self):
        self.stand.e2e = SimpleNamespace(capabilities=frozenset(("daemon", "env-mismatch")))
        self.plan = [planned("live")]
        self.assertEqual(0, browser.run(self.stand, []))
        self.delivery_factory.assert_not_called()
        self.assertEqual(("main", "live"), self.calls[0][:2])
        self.assertNotIn("CLUSTER_DELIVERY_RUN_ID", self.calls[0][2])
        self.assertNotIn("CLUSTER_DELIVERY_CONTROL_DIR", self.calls[0][2])

    def test_main_daemon_native_rc_survives_cleanup_failure(self):
        self.plan = [planned("daemon")]
        self.codes[("main", "daemon")] = 17
        self.own_env.side_effect = [SimpleNamespace(code=0, out="ok", err=""), RuntimeError("env cleanup")]
        self.assertEqual(17, browser.run(self.stand, ["--daemon"]))
        self.assertIn("env cleanup", self.output.getvalue())
        self.assertEqual([], self.lines())

    def test_pointed_live_preserves_filters_and_does_not_add_daemon(self):
        self.assertEqual(0, browser.run(self.stand, ["--project=live", "--grep", "check"]))
        self.assertEqual([("main", "live"), ("quarantine", "live")],
                         [(p, t) for p, t, _, _ in self.calls])
        self.daemon_factory.assert_not_called()
        self.assertTrue(all("--grep" in args for _, _, _, args in self.calls))

    def test_env_mismatch_unfiltered_selection_sets_fixture_and_refreshes_roles_before_browser(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]

        def switch(stand, node, assignment):
            self.events.append(("env", node, assignment))
            self.leader, self.term = "m2", 5
            return SimpleNamespace(code=0, out="switched", err="")
        self.own_env.side_effect = switch
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([("s1", "MAIL_FROM_NAME=hilos-env-mismatch-fixture"), ("s1", "off")],
                         [call.args[1:] for call in self.own_env.call_args_list])
        self.assertLess(self.events.index(("plan",)), self.events.index(
            ("env", "s1", "MAIL_FROM_NAME=hilos-env-mismatch-fixture")))
        env = self.calls[0][2]
        self.assertEqual(("s1", "MAIL_FROM_NAME", "hilos-env-mismatch-fixture"), tuple(
            env[key] for key in ("CLUSTER_ENV_MISMATCH_NODE", "CLUSTER_ENV_MISMATCH_KEY",
                                "CLUSTER_ENV_MISMATCH_VALUE")))
        self.assertEqual(("m2", "m1"), (env["CLUSTER_LEADER"], env["CLUSTER_FOLLOWER"]))
        self.assertEqual(("m2", 5), self.delivery_factory.call_args.args[-2:])
        off = self.events.index(("env", "s1", "off"))
        self.assertLess(self.events.index(("delivery-restore",)), off)
        self.assertEqual(("converge", tuple(self.stand.members)), self.events[off + 1])
        self.assertEqual(2, self.refusal.call_count)
        self.kill.assert_not_called()
        self.start.assert_not_called()
        self.daemon_factory.assert_not_called()

    def test_env_mismatch_native_plan_exclusion_never_sets_fixture_even_with_filename_argument(self):
        self.plan = [planned("live")]
        self.assertEqual(0, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC, "--grep=other"]))
        self.own_env.assert_not_called()
        self.assertNotIn("CLUSTER_ENV_MISMATCH_NODE", self.calls[0][2])

    def test_env_mismatch_quarantine_does_not_prepare_fixture_for_unrelated_main(self):
        self.plan = [planned("live"), planned("live", "F-100", file=browser.ENV_MISMATCH_SPEC)]
        self.assertEqual(0, browser.run(self.stand, ["--project=live"]))
        self.assertNotIn("CLUSTER_ENV_MISMATCH_NODE", self.calls[0][2])
        self.assertEqual("s1", self.calls[1][2]["CLUSTER_ENV_MISMATCH_NODE"])
        self.assertLess(self.events.index(("main", "live")), self.events.index(
            ("env", "s1", "MAIL_FROM_NAME=hilos-env-mismatch-fixture")))
        self.assertEqual(2, self.own_env.call_count)
        self.assertEqual("passed", self.lines()[0]["status"])

    def test_env_mismatch_diagnostic_uses_fixture_once_and_preserves_native_repeats(self):
        self.plan = [planned("live", "F-100", file=browser.ENV_MISMATCH_SPEC)]
        self.assertEqual(0, browser.run(self.stand, ["--repeat-each=5"]))
        self.assertEqual("diagnostic", self.calls[0][0])
        self.assertIn("--repeat-each=5", self.calls[0][3])
        self.assertEqual(2, self.own_env.call_count)
        self.assertEqual([], self.lines())

    def test_env_mismatch_setup_switch_failure_still_restores_and_checks_start(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]
        self.own_env.side_effect = [RuntimeError("fixture setup failed"),
                                    SimpleNamespace(code=0, out="restored", err="")]
        self.assertEqual(1, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.assertEqual([], self.calls)
        self.refusal.assert_called_once()
        self.assertEqual(3, self.wait.call_count)
        self.delivery_factory.assert_not_called()

    def test_env_mismatch_setup_convergence_failure_still_restores(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]

        def converge(nodes):
            if self.wait.call_count == 3:
                raise RuntimeError("fixture did not converge")
            return self.views(nodes)
        self.wait.side_effect = converge
        self.assertEqual(1, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.assertEqual(4, self.wait.call_count)
        self.refusal.assert_called_once()
        self.assertEqual([], self.calls)

    def test_env_mismatch_setup_startup_refusal_still_restores(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]
        self.refusal.side_effect = [AssertionError("fixture start refused"), None]
        self.assertEqual(1, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.assertEqual(2, self.refusal.call_count)
        self.assertEqual([], self.calls)

    def test_env_mismatch_browser_exception_still_restores(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]

        def broken_browser(phase, project, env):
            raise RuntimeError("browser invocation failed")
        self.gateway_hook = broken_browser
        self.assertEqual(1, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.assertEqual(2, self.refusal.call_count)
        self.assertIn("browser invocation failed", self.output.getvalue())

    def test_env_mismatch_restore_failure_makes_browser_pass_red(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]
        self.own_env.side_effect = [SimpleNamespace(code=0, out="setup", err=""),
                                    SimpleNamespace(code=9, out="", err="fixture restore failed")]
        self.assertEqual(1, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual(4, self.wait.call_count)
        self.assertEqual(2, self.refusal.call_count)
        self.assertIn("fixture restore failed", self.output.getvalue())

    def test_env_mismatch_restore_convergence_failure_preserves_browser_native_rc(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]
        self.codes[("main", "live")] = 7

        def converge(nodes):
            if self.wait.call_count == 4:
                raise RuntimeError("restored node did not converge")
            return self.views(nodes)
        self.wait.side_effect = converge
        self.assertEqual(7, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.assertEqual(2, self.refusal.call_count)
        self.assertIn("restored node did not converge", self.output.getvalue())

    def test_env_mismatch_restore_startup_refusal_makes_browser_pass_red(self):
        self.plan = [planned("live", file=browser.ENV_MISMATCH_SPEC)]
        self.refusal.side_effect = [None, AssertionError("restored start refused")]
        self.assertEqual(1, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.assertIn("restored start refused", self.output.getvalue())

    def test_env_mismatch_quarantine_cleanup_failure_demotes_pass_without_altering_main_rc(self):
        self.plan = [planned("live", "F-100", file=browser.ENV_MISMATCH_SPEC)]
        self.own_env.side_effect = [SimpleNamespace(code=0, out="setup", err=""), RuntimeError("restore failed")]
        self.assertEqual(0, browser.run(self.stand, [browser.ENV_MISMATCH_SPEC]))
        self.assertEqual("failed", self.lines()[0]["status"])
        self.assertIn("restore failed", self.lines()[0]["reason"])

    def test_online_testing_only_live_with_no_slave_or_daemon(self):
        self.stand.demo_dir = self.root / "demo" / "online-testing"
        (self.stand.demo_dir / "tests").mkdir(parents=True)
        self.stand.e2e = SimpleNamespace(capabilities=frozenset())
        self.stand.slaves = []
        self.stand.members = {key: val for key, val in self.stand.members.items() if key in self.stand.masters}
        self.plan = [planned("live"), planned("live", "F-100")]
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([("main", "live"), ("quarantine", "live")], [(p, t) for p, t, _, _ in self.calls])
        self.kill.assert_not_called()
        self.start.assert_not_called()
        self.daemon_factory.assert_not_called()
        self.delivery_factory.assert_not_called()
        self.own_env.assert_not_called()

    def test_no_quarantine_is_silent(self):
        self.plan = [planned("live")]
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([], self.lines())
        self.assertEqual([("main", "live")], [(p, t) for p, t, _, _ in self.calls])
        self.ensure.assert_not_called()

    def test_daemon_controller_and_recovery_failures_demote_pass_once_and_keep_first_reason(self):
        self.daemon_error = "original election failure"
        self.own_env.side_effect = [SimpleNamespace(code=0, out="ok", err=""), RuntimeError("cleanup failure")]
        self.assertEqual(0, browser.run(self.stand, ["--daemon"]))
        self.assertEqual(1, len(self.lines()))
        self.assertEqual("failed", self.lines()[0]["status"])
        self.assertIn("original election failure", self.lines()[0]["reason"])
        self.assertIn("cleanup failure", self.lines()[0]["reason"])
        self.start.assert_called_once()
        self.refusal.assert_called()

    def test_delivery_cleanup_and_controller_errors_demote_browser_pass_once(self):
        self.plan = [planned("live", "F-016")]
        self.restore_error, self.delivery_error = "restore failed", "controller failed"
        self.assertEqual(0, browser.run(self.stand, ["--grep=check"]))
        self.assertEqual(1, len(self.lines()))
        self.assertEqual("failed", self.lines()[0]["status"])
        self.assertIn("restore failed", self.lines()[0]["reason"])
        self.assertIn("controller failed", self.lines()[0]["reason"])

    def test_missing_daemon_handshake_cannot_count_as_a_pass(self):
        self.daemon_completed = False
        self.assertEqual(0, browser.run(self.stand, ["--daemon"]))
        self.assertEqual("failed", self.lines()[0]["status"])
        self.assertIn("no successful leader kill/election handshake", self.lines()[0]["reason"])

    def test_daemon_setup_failure_is_not_run_and_still_restores_environment(self):
        self.own_env.side_effect = [RuntimeError("initial environment failed"),
                                    SimpleNamespace(code=0, out="restored", err="")]
        self.assertEqual(0, browser.run(self.stand, ["--daemon"]))
        self.assertEqual("notRun", self.lines()[0]["status"])
        self.assertIn("initial environment failed", self.lines()[0]["reason"])
        self.assertEqual("off", self.own_env.call_args.args[-1])
        self.daemon_factory.assert_not_called()

    def test_browser_failure_or_timeout_is_not_overwritten_by_host_error(self):
        for status in ("failed", "timedOut"):
            with self.subTest(status=status):
                self.output.seek(0)
                self.output.truncate()
                self.plan = [planned("live", "F-016")]
                self.statuses["live"] = status
                self.restore_error = "cleanup"
                self.assertEqual(0, browser.run(self.stand, ["--grep=check"]))
                self.assertEqual(status, self.lines()[0]["status"])
                self.assertEqual("", self.lines()[0]["reason"])

    def test_pre_start_failure_marks_all_known_tests_not_run_without_changing_main_rc(self):
        self.ensure.side_effect = RuntimeError("cannot open saved freeze")
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual(["notRun", "notRun"], [row["status"] for row in self.lines()])
        self.assertTrue(all("cannot open saved freeze" in row["reason"] for row in self.lines()))
        self.daemon_factory.assert_not_called()

    def test_missing_sidecar_is_not_run_even_when_native_quarantine_rc_is_zero(self):
        self.write_results = False
        self.assertEqual(0, browser.run(self.stand, ["--daemon"]))
        self.assertEqual("notRun", self.lines()[0]["status"])

    def test_after_loss_quarantine_precedes_recovery_and_live(self):
        self.plan.append(planned("after-loss", "F-101"))
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertLess(self.events.index(("quarantine", "after-loss")), self.events.index(("start", "s1")))
        self.assertEqual(["live", "daemon", "after-loss"], [row["project"] for row in self.lines()])
        self.assertEqual(1, sum(p == "quarantine" and t == "after-loss" for p, t, _, _ in self.calls))

    def test_after_loss_without_a_fault_is_not_run_not_replaced_with_live(self):
        self.plan = [planned("after-loss", "F-101")]
        self.assertEqual(0, browser.run(self.stand, ["--grep=check"]))
        self.assertEqual([], self.calls)
        self.assertEqual("notRun", self.lines()[0]["status"])
        self.kill.assert_not_called()

    def test_failed_slave_recovery_cannot_report_live_or_daemon_as_passed(self):
        self.start.side_effect = RuntimeError("slave still stopped")
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual(["notRun", "notRun"], [row["status"] for row in self.lines()])
        self.daemon_factory.assert_not_called()

    def test_initial_install_or_unknown_plan_failure_remains_main_error_without_invented_tests(self):
        self.codes["install"] = 6
        self.assertEqual(1, browser.run(self.stand, []))
        self.assertEqual([], self.lines())
        self.assertEqual([], self.calls)

    def test_collection_failure_starts_no_controller_and_does_not_invent_observations(self):
        self.codes["plan"] = 8
        self.assertEqual(1, browser.run(self.stand, []))
        self.daemon_factory.assert_not_called()
        self.delivery_factory.assert_not_called()
        self.assertEqual([], self.lines())

    def test_diagnostic_repeat_uses_native_path_without_quarantine(self):
        self.assertEqual(0, browser.run(self.stand, ["--daemon", "--repeat-each=5"]))
        self.assertEqual([("diagnostic", "daemon")], [(p, t) for p, t, _, _ in self.calls])
        self.assertIn("--repeat-each=5", self.calls[0][3])
        self.assertEqual([], self.lines())

    def test_diagnostic_live_keeps_selector_and_both_native_repeat_forms(self):
        self.plan = [planned("live")]
        cases = (
            ["cluster/live/moderation.spec.ts:59", "--repeat-each=5"],
            ["cluster/live/moderation.spec.ts:59", "--repeat-each", "5"],
        )
        for extra_args in cases:
            with self.subTest(extra_args=extra_args):
                self.output.seek(0)
                self.output.truncate()
                self.events.clear()
                self.calls.clear()
                self.gateway.reset_mock()
                selector = extra_args[0]
                self.assertEqual(0, browser.run(self.stand, extra_args))
                plan_calls = [call.args for call in self.gateway.call_args_list
                              if "--hilos-phase=plan" in call.args]
                self.assertEqual(1, len(plan_calls))
                plan = plan_calls[0]
                self.assertIn(selector, plan)
                self.assertNotIn("--repeat-each=5", plan)
                self.assertNotIn("--repeat-each", plan)
                self.assertNotIn("5", plan)
                self.assertEqual([("diagnostic", "live")],
                                 [(phase, project) for phase, project, _, _ in self.calls])
                executed = self.calls[0][3]
                start = executed.index(selector)
                self.assertEqual(extra_args, list(executed[start:start + len(extra_args)]))
                self.assertEqual([], self.lines())

    def test_own_uuid_cleanup_leaves_other_runs_untouched(self):
        other = browser._run_path(self.stand, "quarantine", "other-run")
        other.mkdir(parents=True)
        marker = other / "plan.json"
        marker.write_text("someone else's plan", encoding="utf-8")
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual("someone else's plan", marker.read_text(encoding="utf-8"))
        self.assertEqual([other], list(other.parent.iterdir()))

    def test_sidecar_cleanup_failure_demotes_pass_without_altering_main_rc(self):
        self.plan = [planned("live", "F-016")]

        def extra_file(phase, project, env):
            if phase == "quarantine":
                (self.host_path(env["HILOS_E2E_RESULT_FILE"]).parent / "unexpected").write_text("x")
        self.gateway_hook = extra_file
        self.assertEqual(0, browser.run(self.stand, ["--grep=check"]))
        self.assertEqual("failed", self.lines()[0]["status"])
        self.assertIn("sidecar cleanup failed", self.lines()[0]["reason"])

    def test_invalid_result_schema_is_not_a_success(self):
        self.plan = [planned("live", "F-016")]

        def invalid(phase, project, env):
            if phase == "quarantine":
                row = observed(self.plan[0])
                row["line"] = True
                self.write_sidecar(self.host_path(env["HILOS_E2E_RESULT_FILE"]), [row])
        self.gateway_hook = invalid
        self.assertEqual(0, browser.run(self.stand, ["--grep=check"]))
        self.assertEqual("notRun", self.lines()[0]["status"])

    def test_plan_boundary_rejects_bad_version_fields_and_duplicates(self):
        path = self.root / "invalid.json"
        for field, value in (("line", True), ("line", 0), ("flake", "F-x"), ("file", "/absolute"),
                             ("file", "../escape"), ("project", None), ("titlePath", []),
                             ("titlePath", [False]), ("selector", "different")):
            with self.subTest(field=field, value=value):
                self.write_sidecar(path, [planned("live") | {field: value}])
                with self.assertRaises(ValueError):
                    browser._read_tests(path, plan=True)
        for version in (True, 2, "1"):
            path.write_text(json.dumps({"version": version, "tests": []}), encoding="utf-8")
            with self.assertRaises(ValueError):
                browser._read_tests(path, plan=True)
        self.write_sidecar(path, [planned("live"), planned("live")])
        with self.assertRaises(ValueError):
            browser._read_tests(path, plan=True)

    def test_result_boundary_requires_every_observation_key(self):
        path = self.root / "result.json"
        valid = observed(planned("live", "F-016"))
        for key in valid:
            with self.subTest(key=key):
                self.write_sidecar(path, [{k: v for k, v in valid.items() if k != key}])
                with self.assertRaises(ValueError):
                    browser._read_tests(path)
        self.write_sidecar(path, [valid])
        self.assertEqual([valid], browser._read_tests(path))


class DaemonHandshakeTest(unittest.TestCase):
    """Keep the real UUID-bound readiness, election and cancellation checks."""

    def test_matching_request_kills_once_and_publishes_the_higher_term(self):
        with tempfile.TemporaryDirectory() as temporary, ExitStack() as stack:
            directory = Path(temporary)
            stand = SimpleNamespace(masters=["m1", "m2", "m3"],
                                    members={node: None for node in ("m1", "m2", "m3", "s1")})
            (directory / "request.json").write_text(json.dumps(
                {"runId": "this-run", "action": "kill-leader", "nodeId": "m1"}), encoding="utf-8")
            controller = browser._DaemonController(stand, directory, "this-run", "m1", 4)
            stack.enter_context(redirect_stdout(io.StringIO()))
            stack.enter_context(patch.object(browser.scenarios, "inspect", return_value={
                "consensusRole": "leader", "lifecycleState": "MasterLeader", "term": 4, "hasQuorum": True}))
            kill = stack.enter_context(patch.object(browser.control, "kill", return_value=SimpleNamespace(
                code=0, out="killed", err="")))
            elected = {"m2": {"consensusRole": "leader", "lifecycleState": "MasterLeader",
                              "term": 5, "hasQuorum": True}}

            def wait(predicate, timeout, description, nodes):
                self.assertNotIn("m1", nodes)
                self.assertTrue(predicate(elected))
                self.assertFalse(predicate({"m2": elected["m2"] | {"term": 4}}))
                return elected
            stack.enter_context(patch.object(browser.scenarios, "wait_until", side_effect=wait))
            controller.run()
            self.assertTrue(controller.completed)
            self.assertTrue(controller.kill_attempted)
            self.assertIsNone(controller.error)
            kill.assert_called_once_with(stand, "m1")
            self.assertEqual({"runId": "this-run", "ok": True, "stopped": "m1", "leaderId": "m2", "term": 5},
                             json.loads((directory / "result.json").read_text(encoding="utf-8")))

    def test_foreign_readiness_cannot_kill_a_node_and_retains_its_reason(self):
        with tempfile.TemporaryDirectory() as temporary, ExitStack() as stack:
            directory = Path(temporary)
            (directory / "request.json").write_text(json.dumps(
                {"runId": "other-run", "action": "kill-leader", "nodeId": "m1"}), encoding="utf-8")
            controller = browser._DaemonController(SimpleNamespace(), directory, "this-run", "m1", 4)
            stack.enter_context(redirect_stdout(io.StringIO()))
            kill = stack.enter_context(patch.object(browser.control, "kill"))
            controller.run()
            kill.assert_not_called()
            self.assertFalse(controller.completed)
            self.assertFalse(controller.kill_attempted)
            self.assertIn("invalid daemon readiness request", controller.error)
            self.assertEqual(controller.error, json.loads(
                (directory / "result.json").read_text(encoding="utf-8"))["error"])


if __name__ == "__main__":
    unittest.main()

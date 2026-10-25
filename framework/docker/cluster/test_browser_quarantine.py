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


def planned(project, flake=None, title=None):
    title = title or f"{project} check"
    file = f"{project}/check.spec.ts"
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

    def join(self):
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
            demo_dir=self.root / "demo" / "binance-btc-tracker", project="stand",
            masters=["m1", "m2", "m3"], slaves=["s1", "s2"],
            entry=SimpleNamespace(container="entry"))
        self.stand.members = {node: SimpleNamespace(container=node, ip="127.0.0.1")
                              for node in self.stand.masters + self.stand.slaves}
        (self.stand.demo_dir / "data").mkdir(parents=True)
        # The protocol is exercised with no phase switched off; parking has its own cases.
        stack.enter_context(patch.dict(browser.FLAKY_SKIP, clear=True))
        self.plan = [planned("live"), planned("after-loss"), planned("live", "F-016"),
                     planned("daemon", "F-023")]
        self.events = []
        self.calls = []
        self.codes = {}
        self.statuses = {}
        self.write_fault = True
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
        if phase == "plan":
            self.events.append(("plan",))
            self.assertFalse(any(arg.startswith("--repeat-each") for arg in args))
            if stand.demo_dir.name == "binance-btc-tracker":
                directory = self.host_path(env["CLUSTER_DELIVERY_CONTROL_DIR"])
                self.assertTrue(directory.is_dir())
                self.assertEqual(directory.name, env["CLUSTER_DELIVERY_RUN_ID"])
            projects = [arg.removeprefix("--project=") for arg in args if arg.startswith("--project=")]
            selected = [test for test in self.plan if not projects or test["project"] in projects]
            self.write_sidecar(self.host_path(env["HILOS_E2E_PLAN_FILE"]), selected)
            return self.codes.get("plan", 0)
        project = args[-1].removeprefix("--project=")
        self.assertEqual(1, sum(arg.startswith("--project") for arg in args))
        if stand.demo_dir.name == "binance-btc-tracker" and project == "live":
            self.assertIn("/cluster-delivery-e2e/", env["CLUSTER_DELIVERY_CONTROL_DIR"])
            self.assertNotIn("/cluster-quarantine/", env["CLUSTER_DELIVERY_CONTROL_DIR"])
        self.calls.append((phase, project, dict(env), args))
        self.events.append((phase, project))
        # Publication is delayed until every host check and its cleanup have finished.
        self.assertEqual([], self.lines())
        if phase == "main" and project == "live" and self.write_fault:
            (self.stand.demo_dir / "data" / "cluster-e2e-fault.json").write_text(
                json.dumps({"stop": "s1", "reason": "the captured archive"}), encoding="utf-8")
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
        self.assertEqual(["passed", "passed"], [row["status"] for row in self.lines()])
        self.assertEqual([], list((self.stand.demo_dir / "data" / "cluster-quarantine").iterdir()))

    def test_native_main_red_still_runs_tail_and_does_not_run_dependent_main(self):
        self.codes[("main", "live")] = 7
        self.codes[("quarantine", "daemon")] = 9
        self.assertEqual(7, browser.run(self.stand, []))
        self.assertEqual([("main", "live"), ("quarantine", "live"), ("quarantine", "daemon")],
                         [(phase, project) for phase, project, _, _ in self.calls])

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

    def test_online_testing_only_live_with_no_slave_or_daemon(self):
        self.stand.demo_dir = self.root / "demo" / "online-testing"
        (self.stand.demo_dir / "data").mkdir(parents=True)
        self.stand.slaves = []
        self.stand.members = {key: val for key, val in self.stand.members.items() if key in self.stand.masters}
        self.plan = [planned("live"), planned("live", "F-100")]
        self.assertEqual(0, browser.run(self.stand, []))
        self.assertEqual([("main", "live"), ("quarantine", "live")], [(p, t) for p, t, _, _ in self.calls])
        self.kill.assert_not_called()
        self.start.assert_not_called()
        self.daemon_factory.assert_not_called()
        self.delivery_factory.assert_not_called()

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

    def test_own_uuid_cleanup_leaves_other_runs_untouched(self):
        other = self.stand.demo_dir / "data" / "cluster-quarantine" / "other-run"
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

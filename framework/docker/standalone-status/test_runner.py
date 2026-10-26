"""The pinned control parser and independent recovery, without a Docker daemon."""

import copy
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch

import runner


class ControlTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name)
        self.manifest = {
            "runId": "own-run", "project": runner.PROJECT + "-s2", "demo": "/own/demo",
            "browserName": "hilos-status-browser-own-run",
            "daemon": {"id": "daemon-id", "created": "first", "service": runner.SERVICE},
            "nginx": {"id": "nginx-id", "created": "first", "service": runner.NGINX},
        }
        self.containers = {}
        for role in ("daemon", "nginx"):
            expected = self.manifest[role]
            self.containers[expected["id"]] = {
                "Id": expected["id"], "Created": "first", "RestartCount": 0,
                "Config": {"Env": ["APP_ENV=test"], "Labels": {
                    "com.docker.compose.project": self.manifest["project"],
                    "com.docker.compose.service": expected["service"]}},
                "HostConfig": {"RestartPolicy": {"Name": "no"}},
                "Mounts": [{"Destination": "/app", "Source": "/own/demo"}],
                "State": {"Running": True, "Pid": 123, "Status": "running"},
            }
        self.calls = []
        self.control = runner.Control(self.manifest, self.state, self.execute, self.containers.__getitem__)

    def execute(self, *args):
        self.calls.append(args)
        self.assertEqual("daemon-id", args[-1])
        running = args[0] == "start"
        self.containers["daemon-id"]["State"] = {
            "Running": running, "Pid": 123 if running else 0,
            "Status": "running" if running else "exited"}

    def test_exact_request_and_no_target_or_arbitrary_command(self):
        request = {"runId": "own-run", "sequence": 1, "operation": "stop"}
        self.assertEqual("stop", runner.validate_request(request, "own-run", 1))
        for change in ({"runId": "foreign"}, {"sequence": 0}, {"sequence": True},
                       {"operation": "kill"}, {"target": runner.NGINX},
                       {"timeout": 1000}, {"command": ["sh"]}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                runner.validate_request({**request, **change}, "own-run", 1)

    def test_foreign_container_or_lifetime_never_gets_a_command(self):
        original = copy.deepcopy(self.containers["daemon-id"])
        for mutate in (
            lambda c: c.update(Id="other-id"),
            lambda c: c.update(Created="replacement"),
            lambda c: c["Config"]["Labels"].update({"com.docker.compose.project": runner.PROJECT}),
            lambda c: c["Config"]["Labels"].update({"com.docker.compose.service": runner.NGINX}),
            lambda c: c["Config"].update(Env=["APP_ENV=prod"]),
            lambda c: c["Mounts"][0].update(Source="/foreign/tree"),
            lambda c: c["HostConfig"]["RestartPolicy"].update(Name="always"),
        ):
            self.containers["daemon-id"] = copy.deepcopy(original)
            mutate(self.containers["daemon-id"])
            with self.assertRaises(ValueError):
                self.control.operate("stop")
        self.assertEqual([], self.calls)

    def test_stop_proves_container_dead_and_finally_recovers_same_container(self):
        result = self.control.operate("stop")
        self.assertEqual({"Running": False, "Pid": 0, "Status": "exited"}, result["daemon"]["state"])
        self.assertTrue(result["nginx"]["state"]["Running"])
        self.assertTrue((self.state / "lease.json").exists())
        self.assertTrue(self.control.recover()["daemon"]["state"]["Running"])
        self.assertFalse((self.state / "lease.json").exists())
        self.control.recover()
        self.assertEqual([("stop", "--time", "5", "daemon-id"), ("start", "daemon-id")], self.calls)

    def test_stop_timeout_retains_lease_and_cleanup_failure_is_not_swallowed(self):
        self.control.execute = Mock(side_effect=subprocess.TimeoutExpired("docker stop", 30))
        with self.assertRaises(subprocess.TimeoutExpired):
            self.control.operate("stop")
        self.assertTrue((self.state / "lease.json").exists())
        self.containers["daemon-id"]["State"]["Running"] = False
        self.control.execute = Mock(side_effect=RuntimeError("start failed"))
        with self.assertRaisesRegex(RuntimeError, "start failed"):
            self.control.recover()

    def test_a_live_pid_or_dead_nginx_cannot_be_reported_as_a_full_stop(self):
        for role in ("daemon", "nginx"):
            with self.subTest(role=role):
                self.control.execute = Mock()
                if role == "nginx":
                    self.control.execute = self.execute
                    self.containers["nginx-id"]["State"]["Running"] = False
                with self.assertRaises(ValueError):
                    self.control.operate("stop")

    def test_expired_backstop_restores_and_terminates_only_its_browser(self):
        (self.state / "manifest.json").write_text(json.dumps(self.manifest))
        (self.state / "lease.json").write_text(json.dumps({"until": 0}))
        with patch.object(runner, "Control", return_value=self.control), patch.object(runner, "docker") as docker:
            self.control.operate("stop")
            (self.state / "lease.json").write_text(json.dumps({"until": 0}))
            runner.watchdog(self.state)
            docker.assert_called_once_with("rm", "-f", self.manifest["browserName"])
        self.assertTrue((self.state / "backstop.json").exists())
        self.assertTrue(self.containers["daemon-id"]["State"]["Running"])


class SuiteTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.state = Path(temporary.name)
        self.demo = self.state / "demo"
        self.e2e = self.demo / "tests/e2e"
        self.last_run = self.e2e / "test-results/main/.last-run.json"
        self.rows = [
            {"file": "delivery-window.spec.ts", "selector": "ordinary-test", "id": "ordinary", "flake": None},
            {"file": "status.spec.ts", "selector": "status-test", "id": "status", "flake": None},
            {"file": "a11y.spec.ts", "selector": "accessibility-test", "id": "a11y", "flake": None},
        ]
        self.calls = []
        self.selection_rc = 0
        self.phase_rc = {}

    def execute(self, command, **_kwargs):
        self.calls.append(command)
        if any(arg.endswith("/plan-reporter.mjs") for arg in command):
            (self.state / "plan.json").write_text(json.dumps({
                "version": 1, "tests": self.rows,
                "lastRunFile": "test-results/main/.last-run.json"}))
            return subprocess.CompletedProcess(command, self.selection_rc)
        name = next((arg.removeprefix("--output=test-results/") for arg in command
                     if arg.startswith("--output=")), "")
        if name:
            ids = {"main": ["ordinary"], "status-main": ["status", "a11y"]}[name]
            native = self.e2e / "test-results" / name / ".last-run.json"
            native.parent.mkdir(parents=True, exist_ok=True)
            native.write_text(json.dumps({
                "status": "failed" if self.phase_rc.get(name) else "passed",
                "failedTests": ids if self.phase_rc.get(name) else []}))
        return subprocess.CompletedProcess(command, self.phase_rc.get(name, 0))

    def run_suite(self, args):
        with patch.object(runner.subprocess, "run", side_effect=self.execute), \
                patch.object(runner, "cycle") as cycle, \
                patch.object(runner.Path, "cwd", return_value=self.demo):
            rc = runner.suite(self.state, args)
        return rc, cycle

    def test_full_gate_partitions_every_identity_once_and_retains_standalone(self):
        rc, cycle = self.run_suite([])
        self.assertEqual(0, rc)
        self.assertEqual("ordinary-test\n", (self.state / "main.txt").read_text())
        self.assertEqual("status-test\naccessibility-test\n", (self.state / "status-main.txt").read_text())
        cycle.assert_called_once_with(self.state)
        boots = [call for call in self.calls if "up" in call]
        self.assertEqual(2, len(boots))
        self.assertIn("docker/docker-compose.e2e-status-fast.yml", boots[0])
        self.assertNotIn("docker/docker-compose.e2e-status-fast.yml", boots[1])
        browsers = [call for call in self.calls if runner.ENTRY in call]
        self.assertEqual(3, len(browsers))
        self.assertTrue(all("--no-deps" in call for call in browsers))

    def test_pointed_selection_and_shard_are_applied_once_without_extra_tests(self):
        self.rows = self.rows[1:2]
        args = ["tests/status.spec.ts:25", "--grep", "cold|recovery", "--shard=1/2",
                "--test-list", "/original/list", "--last-failed", "--repeat-each=2"]
        rc, cycle = self.run_suite(args)
        self.assertEqual(0, rc)
        cycle.assert_not_called()
        self.assertFalse((self.state / "main.txt").exists())
        collection = self.calls[0]
        for arg in args:
            self.assertIn(arg, collection)
        execution = next(call for call in self.calls if "--output=test-results/status-main" in call)
        for arg in ("tests/status.spec.ts:25", "--grep", "cold|recovery", "--repeat-each=2"):
            self.assertIn(arg, execution)
        for arg in ("--shard=1/2", "/original/list", "--last-failed"):
            self.assertNotIn(arg, execution)
        self.assertIn("--test-list=/status-suite/status-main.txt", execution)

    def test_ordinary_pointed_run_never_restarts_daemon_or_runs_status(self):
        self.rows = self.rows[:1]
        rc, cycle = self.run_suite(["--grep=deliberately delayed"])
        self.assertEqual(0, rc)
        cycle.assert_not_called()
        self.assertFalse(any("up" in call for call in self.calls))
        self.assertFalse((self.state / "status-main.txt").exists())

    def test_original_selection_failure_remains_red_without_any_boot(self):
        self.selection_rc = 1
        rc, cycle = self.run_suite(["missing.spec.ts"])
        self.assertEqual(1, rc)
        cycle.assert_not_called()
        self.assertEqual(1, len(self.calls))

    def test_main_failure_still_runs_status_and_standalone_and_stays_red(self):
        self.phase_rc = {"main": 17}
        rc, cycle = self.run_suite([])
        self.assertEqual(17, rc)
        cycle.assert_called_once_with(self.state)
        self.assertTrue(any("--output=test-results/status-main" in call for call in self.calls))

    def test_status_failure_stays_red_and_restores_default_environment(self):
        self.phase_rc = {"status-main": 19}
        rc, _cycle = self.run_suite([])
        self.assertEqual(19, rc)
        self.assertIn("up", self.calls[-1])
        self.assertNotIn("docker/docker-compose.e2e-status-fast.yml", self.calls[-1])

    def test_status_exception_restores_default_environment(self):
        original = self.execute

        def failed(command, **kwargs):
            if "--output=test-results/status-main" in command:
                raise RuntimeError("runner lost")
            return original(command, **kwargs)

        with patch.object(runner.subprocess, "run", side_effect=failed), \
                patch.object(runner.Path, "cwd", return_value=self.demo), \
                self.assertRaisesRegex(RuntimeError, "runner lost"):
            runner.suite(self.state, [])
        self.assertIn("up", self.calls[-1])
        self.assertNotIn("docker/docker-compose.e2e-status-fast.yml", self.calls[-1])
        self.assertEqual({"status": "failed", "failedTests": ["a11y", "status"]},
                         json.loads(self.last_run.read_text()))

    def test_native_results_retain_either_or_both_phase_failures_and_reset(self):
        for failures, ids in (({"main": 1}, ["ordinary"]),
                              ({"status-main": 1}, ["a11y", "status"]),
                              ({"main": 1, "status-main": 1}, ["a11y", "ordinary", "status"]),
                              ({}, [])):
            with self.subTest(failures=failures):
                self.phase_rc = failures
                rc, _cycle = self.run_suite(["--grep=selected"])
                self.assertEqual(bool(failures), bool(rc))
                self.assertEqual({"status": "failed" if failures else "passed", "failedTests": ids},
                                 json.loads(self.last_run.read_text()))

    def test_status_only_replaces_stale_ordinary_state(self):
        self.phase_rc = {"main": 1}
        self.run_suite(["--grep=selected"])
        self.rows = self.rows[1:2]
        self.phase_rc = {}
        self.run_suite(["status.spec.ts"])
        self.assertEqual({"status": "passed", "failedTests": []}, json.loads(self.last_run.read_text()))

    def test_cleanup_exception_writes_failed_state_and_propagates(self):
        original = self.execute

        def failed(command, **kwargs):
            if "up" in command and "docker/docker-compose.e2e-status-fast.yml" not in command:
                raise RuntimeError("cleanup failed")
            return original(command, **kwargs)

        with patch.object(runner.subprocess, "run", side_effect=failed), \
                patch.object(runner.Path, "cwd", return_value=self.demo), \
                self.assertRaisesRegex(RuntimeError, "cleanup failed"):
            runner.suite(self.state, ["--grep=selected"])
        self.assertEqual({"status": "failed", "failedTests": []}, json.loads(self.last_run.read_text()))

    def test_missing_native_result_does_not_reuse_an_old_success(self):
        self.last_run.parent.mkdir(parents=True)
        self.last_run.write_text(json.dumps({"status": "passed", "failedTests": []}))
        original = self.execute

        def missing(command, **kwargs):
            if "--output=test-results/main" in command:
                return subprocess.CompletedProcess(command, 0)
            return original(command, **kwargs)

        with patch.object(runner.subprocess, "run", side_effect=missing), \
                patch.object(runner.Path, "cwd", return_value=self.demo), \
                self.assertRaisesRegex(RuntimeError, "did not write"):
            runner.suite(self.state, ["--grep=selected"])
        self.assertEqual({"status": "failed", "failedTests": ["a11y", "ordinary", "status"]},
                         json.loads(self.last_run.read_text()))

    def test_main_dispatches_the_e2e_entry_without_shadowing_the_suite(self):
        with patch.object(runner.sys, "argv", ["runner.py", "e2e", "--grep=Status"]), \
                patch.dict(runner.os.environ, {"STATUS_CONTROL_DIR": str(self.state)}), \
                patch.object(runner, "suite", return_value=17) as suite:
            self.assertEqual(17, runner.main())
        suite.assert_called_once_with(self.state, ["--grep=Status"])

    def test_list_uses_original_native_selection_without_boot_or_partition(self):
        rc, cycle = self.run_suite(["--list", "--grep=Status"])
        self.assertEqual(0, rc)
        cycle.assert_not_called()
        self.assertEqual(1, len(self.calls))
        self.assertNotIn("--hilos-phase=plan", self.calls[0])
        self.assertIn("--grep=Status", self.calls[0])


if __name__ == "__main__":
    unittest.main()

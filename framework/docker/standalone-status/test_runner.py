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
        self.calls = []
        self.rc = 0

    def execute(self, command, **_kwargs):
        self.calls.append(command)
        return subprocess.CompletedProcess(command, self.rc)

    def run_suite(self, args):
        with patch.object(runner.subprocess, "run", side_effect=self.execute), \
                patch.object(runner, "cycle") as cycle:
            rc = runner.suite(self.state, args)
        return rc, cycle

    def browsers(self):
        return [call for call in self.calls if runner.ENTRY in call]

    def assert_no_stand_change(self):
        # The stand runs as it was brought up: nothing is recreated or overlaid.
        self.assertFalse(any("up" in call for call in self.calls))

    def test_full_gate_is_one_native_run_then_the_standalone_cycle(self):
        rc, cycle = self.run_suite([])
        self.assertEqual(0, rc)
        self.assertEqual(1, len(self.calls))
        browser = self.calls[0]
        self.assertEqual([], browser[browser.index(runner.ENTRY) + 1:])
        self.assertIn("--no-deps", browser)
        self.assertIn("PLAYWRIGHT_HTML_OUTPUT_DIR=playwright-report/main", browser)
        cycle.assert_called_once_with(self.state)
        self.assert_no_stand_change()

    def test_pointed_selection_reaches_the_entry_verbatim_without_cycle(self):
        args = ["tests/status.spec.ts:25", "--grep", "cold|recovery", "--shard=1/2",
                "--test-list", "/original/list", "--last-failed", "--repeat-each=2"]
        rc, cycle = self.run_suite(args)
        self.assertEqual(0, rc)
        cycle.assert_not_called()
        self.assertEqual(1, len(self.calls))
        browser = self.calls[0]
        self.assertEqual(args, browser[browser.index(runner.ENTRY) + 1:])
        self.assertIn("PLAYWRIGHT_HTML_OUTPUT_DIR=playwright-report/main", browser)
        self.assertFalse(any(arg.startswith("--output") for arg in browser))
        self.assert_no_stand_change()

    def test_list_and_help_run_natively_without_the_stand(self):
        for args in (["--list", "--grep=Status"], ["--help"], ["-h"], ["--ui-port=9323"]):
            with self.subTest(args=args):
                self.calls = []
                rc, cycle = self.run_suite(args)
                self.assertEqual(0, rc)
                cycle.assert_not_called()
                self.assertEqual(1, len(self.calls))
                browser = self.calls[0]
                self.assertEqual(args, browser[browser.index(runner.ENTRY) + 1:])
                self.assertNotIn("-e", browser)
                self.assert_no_stand_change()

    def test_red_main_still_runs_the_cycle_and_stays_red(self):
        self.rc = 17
        rc, cycle = self.run_suite([])
        self.assertEqual(17, rc)
        cycle.assert_called_once_with(self.state)

    def test_red_pointed_run_stays_red_without_cycle(self):
        self.rc = 19
        rc, cycle = self.run_suite(["tests/status-works.spec.ts"])
        self.assertEqual(19, rc)
        cycle.assert_not_called()

    def test_cycle_failure_after_a_green_main_is_not_swallowed(self):
        with patch.object(runner.subprocess, "run", side_effect=self.execute), \
                patch.object(runner, "cycle", side_effect=RuntimeError("cycle failed")), \
                self.assertRaisesRegex(RuntimeError, "cycle failed"):
            runner.suite(self.state, [])
        self.assertEqual(1, len(self.browsers()))

    def test_cycle_photographs_the_running_stand_without_recreating_it(self):
        demo = self.state / "demo"
        docker_calls = []

        def docker(*args):
            docker_calls.append(args)
            return "" if args[0] == "ps" else args[-1] + "-id"

        def execute(command, **_kwargs):
            self.calls.append(command)
            raise RuntimeError("preparation stopped")

        with patch.object(runner, "docker", side_effect=docker), \
                patch.object(runner, "inspect", side_effect=lambda container: {
                    "Id": container, "Created": "first"}), \
                patch.object(runner, "Control"), \
                patch.object(runner.subprocess, "run", side_effect=execute), \
                patch.object(runner.Path, "cwd", return_value=demo), \
                patch.dict(runner.os.environ, {"HILOS_STAND_SUFFIX": "-s2"}), \
                self.assertRaisesRegex(RuntimeError, "preparation stopped"):
            runner.cycle(self.state)
        manifest = json.loads((self.state / "manifest.json").read_text())
        self.assertEqual(runner.PROJECT + "-s2", manifest["project"])
        self.assertEqual(runner.SERVICE + "-id", manifest["daemon"]["id"])
        self.assertEqual(runner.NGINX + "-id", manifest["nginx"]["id"])
        lookups = [call for call in docker_calls if "-q" in call and call[0] != "ps"]
        self.assertEqual(2, len(lookups))
        for lookup in lookups:
            self.assertEqual(runner.COMPOSE, list(lookup[:len(runner.COMPOSE)]))
        self.assertEqual(1, len(self.calls))
        preparation = self.calls[0]
        self.assertEqual(runner.COMPOSE, preparation[1:1 + len(runner.COMPOSE)])
        self.assertIn("prepare.spec.ts", preparation)
        self.assert_no_stand_change()

    def test_main_dispatches_the_e2e_entry_without_shadowing_the_suite(self):
        with patch.object(runner.sys, "argv", ["runner.py", "e2e", "--grep=Status"]), \
                patch.dict(runner.os.environ, {"STATUS_CONTROL_DIR": str(self.state)}), \
                patch.object(runner, "suite", return_value=17) as suite:
            self.assertEqual(17, runner.main())
        suite.assert_called_once_with(self.state, ["--grep=Status"])


if __name__ == "__main__":
    unittest.main()

"""Startup and watchdog controls refuse unsafe identities before changing a process."""

import io
import json
import subprocess
import tempfile
import unittest
from contextlib import ExitStack, redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import call, patch

import control
import scenarios
from stand import Stand, StandRefused


class SharedOutputStartupTest(unittest.TestCase):
    def setUp(self):
        stack = ExitStack()
        self.addCleanup(stack.close)
        demo = Path(stack.enter_context(tempfile.TemporaryDirectory())) / "demo"
        self.stand = SimpleNamespace(project="example", demo_dir=demo, cli_service="example-cli",
                                     cli_profiles=(),
                                     members={"m1": SimpleNamespace(id="m1", service="example-m1",
                                                                   log_dir=demo / "data/logs-cluster/m1")},
                                     stranger=SimpleNamespace(id="x1", service="example-x1",
                                                              log_dir=demo / "data/logs-cluster/x1"))
        stack.enter_context(redirect_stdout(io.StringIO()))

    def test_live_up_stops_members_and_stranger_before_preparation_and_restores_only_the_active_stranger(self):
        with patch.object(control, "compose", side_effect=[control.Outcome(0, "example-m1\nexample-x1\n", ""),
                                                           0, control.Outcome(0, "example-cli\n", ""),
                                                           0, 0, 0]) as compose:
            self.assertEqual(0, control.up(self.stand, "cluster"))
        requests = compose.call_args_list
        self.assertEqual(call(self.stand, "--profile", "*", "ps", "--status", "running", "--services"), requests[0])
        self.assertEqual(call(self.stand, "--profile", "*", "stop", "example-m1", "example-x1", capture=False),
                         requests[1])
        self.assertEqual(requests[0], requests[2])
        self.assertEqual((self.stand, "run", "--build", "--rm", "--no-deps", "--entrypoint", "sh", "example-cli"),
                         requests[3].args[:8])
        self.assertIn("test-writer-entrypoint.sh true", requests[3].args[-1])
        self.assertEqual(call(self.stand, "up", "-d", "--build", "--force-recreate", capture=False), requests[4])
        self.assertEqual(call(self.stand, "up", "-d", "--build", "--no-deps", "example-x1", capture=False), requests[5])

    def test_cold_up_prepares_before_default_start_without_enabling_a_profile_or_cleaning_logs(self):
        for stranger in (self.stand.stranger, None):
            self.stand.stranger = stranger
            with self.subTest(stranger=stranger):
                with patch.object(control, "compose", side_effect=[control.Outcome(0, "", ""),
                                                                   control.Outcome(0, "", ""), 0, 0]) as compose:
                    with patch.object(control, "clear_test_logs", side_effect=AssertionError("ordinary up clears logs")):
                        self.assertEqual(0, control.up(self.stand, "cluster"))
                self.assertEqual(4, compose.call_count)
                self.assertEqual(call(self.stand, "up", "-d", "--build", "--force-recreate", capture=False),
                                 compose.call_args_list[-1])

    def test_failed_inspection_stop_preparation_or_startup_never_proceeds_to_the_next_stage(self):
        live = control.Outcome(0, "example-m1\nexample-x1\n", "")
        stopped = control.Outcome(0, "", "")
        for replies, code in [([control.Outcome(11, "", "ps refused")], 11),
                              ([live, 12], 12),
                              ([stopped, control.Outcome(13, "", "ps refused")], 13),
                              ([live, 0, stopped, 14], 14),
                              ([live, 0, stopped, 0, 15], 15),
                              ([live, 0, stopped, 0, 0, 16], 16)]:
            with self.subTest(replies=replies):
                with patch.object(control, "compose", side_effect=replies) as compose:
                    self.assertEqual(code, control.up(self.stand, "cluster"))
                self.assertEqual(len(replies), compose.call_count)

    def test_a_node_still_running_after_stop_refuses_preparation_and_startup(self):
        for service in ("example-m1", "example-x1"):
            with self.subTest(service=service):
                with patch.object(control, "compose", side_effect=[control.Outcome(0, service + "\n", ""),
                                                                   0, control.Outcome(0, service + "\n", "")]) as compose:
                    with self.assertRaisesRegex(StandRefused, "needs stopped nodes"):
                        control.up(self.stand, "cluster")
                self.assertEqual(3, compose.call_count)

    def test_restart_keeps_ordinary_down_then_prepared_up_without_wiping_stores(self):
        with patch.object(control, "compose", side_effect=[0, control.Outcome(0, "", ""),
                                                           control.Outcome(0, "", ""), 0, 0]) as compose:
            self.assertEqual(0, control.restart(self.stand, "cluster"))
        self.assertEqual(call(self.stand, "--profile", "*", "down", capture=False), compose.call_args_list[0])
        self.assertEqual(5, compose.call_count)
        with patch.object(control, "compose", return_value=19) as compose:
            self.assertEqual(19, control.restart(self.stand, "cluster"))
        compose.assert_called_once_with(self.stand, "--profile", "*", "down", capture=False)

    def test_long_lived_cli_has_only_nonrecursive_access_to_the_shared_log_parent(self):
        repo = Path(__file__).resolve().parents[3]
        for demo in ("binance-btc-tracker", "concert-tickets", "ecommerce-shop", "file-gallery-manager",
                     "online-testing", "roblox-game-server"):
            with self.subTest(demo=demo):
                tooling = (repo / "demo" / demo / "docker/cluster/tooling.yml").read_text()
                self.assertIn("      HILOS_TEST_WRITE_DIRS: /app/data /app/vendor\n", tooling)
                self.assertIn("      HILOS_TEST_OWN_DIRS: /hilos-test-logs\n", tooling)
                self.assertNotIn("HILOS_TEST_WRITE_DIRS: /app/data /app/vendor /hilos-test-logs", tooling)
                self.assertIn('    command: ["sleep", "infinity"]', tooling)


class SavedFreezeStartupTest(unittest.TestCase):
    def setUp(self):
        stack = ExitStack()
        self.addCleanup(stack.close)
        self.output = io.StringIO()
        stack.enter_context(redirect_stdout(self.output))
        self.stand = SimpleNamespace(masters=["m1", "m2", "m3"],
                                     members={node: SimpleNamespace() for node in ("m1", "m2", "m3", "s1", "s2")})
        self.views = {node: self.frozen() for node in self.stand.members}
        self.now = 0.0
        self.open_outcome = control.Outcome(0, "Protected mode opened (phase: inactive)\n", "")
        self.open_error = None
        self.stays_frozen = set()
        stack.enter_context(patch.object(control.time, "monotonic", side_effect=lambda: self.now))
        stack.enter_context(patch.object(control.time, "sleep", side_effect=self.advance))
        stack.enter_context(patch.object(control, "_run", side_effect=AssertionError("unexpected subprocess")))
        self.channel = stack.enter_context(patch.object(control, "client", side_effect=self.command))

    @staticmethod
    def frozen(**changes):
        return {"rtMounted": True, "phase": "active", "operation": scenarios.SETTLE_OPERATION,
                "initiatorAgentType": "hilos_backup", "initiatorAgentIndex": None,
                "initiatorNodeId": "s2"} | changes

    @staticmethod
    def inactive():
        return {"rtMounted": True, "phase": "inactive", "operation": None,
                "initiatorAgentType": None, "initiatorAgentIndex": None, "initiatorNodeId": None}

    def advance(self, seconds):
        self.now += seconds

    def command(self, stand, node, command, *, timeout):
        self.assertIs(self.stand, stand)
        self.assertGreater(timeout, 0)
        if command == "protected-mode:inspect":
            return control.Outcome(0, json.dumps(self.views[node]), "")
        if self.open_error is not None:
            raise self.open_error
        if self.open_outcome.code == 0:
            self.views = {node: self.views[node] if node in self.stays_frozen else self.inactive()
                          for node in stand.members}
        return self.open_outcome

    def test_saved_scenario_25_freeze_opens_on_the_recorded_slave_before_success(self):
        self.assertEqual(0, control.ensure_open_for_tests(self.stand, 5))
        self.assertEqual([
            call(self.stand, "m1", "protected-mode:inspect", timeout=5),
            call(self.stand, "m2", "protected-mode:inspect", timeout=5),
            call(self.stand, "m3", "protected-mode:inspect", timeout=5),
            call(self.stand, "s2", "protected-mode:inspect", timeout=5),
            call(self.stand, "s2", "test:backup:freeze-open", timeout=5),
            call(self.stand, "m1", "protected-mode:inspect", timeout=5),
            call(self.stand, "m2", "protected-mode:inspect", timeout=5),
            call(self.stand, "m3", "protected-mode:inspect", timeout=5),
        ], self.channel.call_args_list)

    def test_restore_keeps_the_production_opener_on_the_recorded_slave(self):
        self.views = {node: self.frozen(operation="restore") for node in self.stand.members}
        self.assertEqual(0, control.ensure_open_for_tests(self.stand, 5))
        self.assertEqual([call(self.stand, "s2", "protected-mode:open", timeout=5)],
                         [request for request in self.channel.call_args_list
                          if request.args[2] != "protected-mode:inspect"])

    def test_unknown_operation_owner_index_or_node_never_drives_an_open(self):
        cases = [
            {"operation": "unknown-backup-operation"},
            {"operation": "cluster-settle-extra"},
            {"operation": ""},
            {"operation": None},
            {"initiatorAgentType": "unknown-owner"},
            {"initiatorAgentIndex": "instance-1"},
            {"initiatorAgentIndex": 0},
            {"initiatorNodeId": "unknown-node"},
            {"initiatorNodeId": None},
        ]
        for changes in cases:
            with self.subTest(changes=changes):
                self.views = {node: self.frozen(**changes) for node in self.stand.members}
                self.channel.reset_mock()
                with self.assertRaisesRegex(StandRefused, "unknown"):
                    control.ensure_open_for_tests(self.stand, 5)
                self.assertEqual([call(self.stand, "m1", "protected-mode:inspect", timeout=5)],
                                 self.channel.call_args_list)

    def test_masters_disagreeing_about_a_recognized_identity_never_drive_an_open(self):
        for changes in [{"operation": "restore"}, {"initiatorAgentType": "hilos_index"},
                        {"initiatorNodeId": "s1"}]:
            with self.subTest(changes=changes):
                self.views = {node: self.frozen() for node in self.stand.members}
                self.views["m2"].update(changes)
                self.channel.reset_mock()
                with self.assertRaisesRegex(StandRefused, "masters disagree"):
                    control.ensure_open_for_tests(self.stand, 5)
                self.assertEqual([
                    call(self.stand, "m1", "protected-mode:inspect", timeout=5),
                    call(self.stand, "m2", "protected-mode:inspect", timeout=5),
                    call(self.stand, "m3", "protected-mode:inspect", timeout=5),
                ], self.channel.call_args_list)

    def test_refused_test_open_is_not_success_or_a_production_fallback(self):
        self.open_outcome = control.Outcome(1, "", "initiator refused release")
        with self.assertRaisesRegex(StandRefused, "test:backup:freeze-open on s2 failed: initiator refused release"):
            control.ensure_open_for_tests(self.stand, 5)
        self.assertEqual(5, self.channel.call_count)
        self.channel.assert_called_with(self.stand, "s2", "test:backup:freeze-open", timeout=5)

    def test_open_subprocess_timeout_is_not_success_or_retried(self):
        self.open_error = subprocess.TimeoutExpired("test:backup:freeze-open", 5)
        with self.assertRaisesRegex(StandRefused, "timeout running test:backup:freeze-open on s2"):
            control.ensure_open_for_tests(self.stand, 5)
        self.assertEqual(5, self.channel.call_count)

    def test_successful_open_reply_still_requires_every_master_to_be_inactive(self):
        self.stays_frozen = {"m2"}
        with self.assertRaisesRegex(StandRefused, "timeout waiting for protected-mode:inspect on m1"):
            control.ensure_open_for_tests(self.stand, 2)
        self.assertEqual(2, self.now)
        self.assertEqual([call(self.stand, "s2", "test:backup:freeze-open", timeout=2)],
                         [request for request in self.channel.call_args_list
                          if request.args[2] != "protected-mode:inspect"])
        for node in self.stand.masters:
            self.assertEqual(3, sum(request.args[1:3] == (node, "protected-mode:inspect")
                                    for request in self.channel.call_args_list))


class WatchdogFaultCommandsTest(unittest.TestCase):
    def setUp(self):
        self.stand = SimpleNamespace(members={"m2": SimpleNamespace(container="example-m2-s2")})
        self.stand.member = lambda node_id: Stand.member(self.stand, node_id)
        self.running = control.Outcome(0, "captured-container true\n", "")
        self.watchdog = control.Outcome(0, "php\0backend/Bootstrap/docker.php\0", "")

    def test_expiry_checks_pid_one_and_signals_only_the_captured_node_container(self):
        for command, signal in [("watchdog-expire-heartbeat", "SIGUSR1"),
                                ("watchdog-expire-stop", "SIGUSR2")]:
            with self.subTest(command=command):
                with patch.object(control, "_run", side_effect=[self.running, self.watchdog,
                                                                control.Outcome(0, "captured-container\n", "")]) as run:
                    outcome = control.execute(self.stand, command, "m2")
                self.assertEqual(0, outcome.code)
                self.assertIn(f"sent {signal} to watchdog pid 1 inside example-m2-s2", outcome.out)
                self.assertEqual([
                    call(["docker", "inspect", "-f", "{{.Id}} {{.State.Running}}", "example-m2-s2"]),
                    call(["docker", "exec", "captured-container", "cat", "/proc/1/cmdline"]),
                    call(["docker", "kill", "--signal", signal, "captured-container"]),
                ], run.call_args_list)

    def test_a_different_pid_one_is_refused_without_sending_either_expiry_signal(self):
        for command in ("watchdog-expire-heartbeat", "watchdog-expire-stop"):
            for cmdline in ("", "php\0backend/Bootstrap/daemon.php\0",
                            "sh\0backend/Bootstrap/docker.php\0",
                            "php\0-r\0echo 'backend/Bootstrap/docker.php';\0",
                            "php\0backend/Bootstrap/docker.php.other\0"):
                with self.subTest(command=command, cmdline=cmdline):
                    with patch.object(control, "_run", side_effect=[self.running,
                                                                    control.Outcome(0, cmdline, "")]) as run:
                        outcome = control.execute(self.stand, command, "m2")
                    self.assertNotEqual(0, outcome.code)
                    self.assertEqual("", outcome.out)
                    self.assertIn("PID 1 inside example-m2-s2 is not the stand's watchdog", outcome.err)
                    self.assertEqual(2, run.call_count)

    def test_stopped_missing_or_unreadable_container_never_receives_an_expiry_signal(self):
        for replies in ([control.Outcome(1, "", "no such container\n")],
                        [control.Outcome(0, "captured-container false\n", "")],
                        [control.Outcome(0, "", "")],
                        [self.running, control.Outcome(1, "partial", "container stopped\n")]):
            with self.subTest(replies=replies):
                with patch.object(control, "_run", side_effect=replies) as run:
                    outcome = control.execute(self.stand, "watchdog-expire-heartbeat", "m2")
                self.assertNotEqual(0, outcome.code)
                self.assertEqual("", outcome.out)
                self.assertTrue(outcome.err)
                self.assertEqual(len(replies), run.call_count)

    def test_failed_signal_is_reported_without_success_output(self):
        with patch.object(control, "_run", side_effect=[self.running, self.watchdog,
                                                        control.Outcome(1, "partial", "container stopped\n")]):
            outcome = control.execute(self.stand, "watchdog-expire-stop", "m2")
        self.assertEqual(control.Outcome(1, "", "container stopped\n"), outcome)

    def test_absolute_watchdog_bootstrap_is_accepted(self):
        watchdog = control.Outcome(0, "/usr/local/bin/php\0/app/backend/Bootstrap/docker.php\0", "")
        with patch.object(control, "_run", side_effect=[self.running, watchdog, control.Outcome(0, "", "")]):
            self.assertEqual(0, control.execute(self.stand, "watchdog-expire-heartbeat", "m2").code)

    def test_freeze_runs_inside_only_the_addressed_node_and_reports_the_master_pid(self):
        with patch.object(control, "_run", return_value=control.Outcome(0, "17\n", "")) as run:
            outcome = control.execute(self.stand, "freeze-daemon", "m2")
        self.assertEqual(control.Outcome(0, "cluster: SIGSTOPped daemon pid 17 inside example-m2-s2\n", ""), outcome)
        run.assert_called_once_with(["docker", "exec", "example-m2-s2", "php", "-d", "display_errors=stderr",
                                     "-r", control.FREEZE_DAEMON_PHP])

    def test_failed_freeze_keeps_the_process_error_and_no_success_output(self):
        with patch.object(control, "_run", return_value=control.Outcome(1, "partial", "no daemon process found\n")):
            outcome = control.execute(self.stand, "freeze-daemon", "m2")
        self.assertNotEqual(0, outcome.code)
        self.assertEqual("", outcome.out)
        self.assertIn("no daemon process found", outcome.err)

    def test_all_three_commands_require_one_known_node_before_any_docker_call(self):
        for command in ("freeze-daemon", "watchdog-expire-heartbeat", "watchdog-expire-stop"):
            for args in ((), ("m2", "extra"), ("unknown",)):
                with self.subTest(command=command, args=args):
                    with patch.object(control, "_run") as run:
                        with self.assertRaises(StandRefused):
                            control.execute(self.stand, command, *args)
                    run.assert_not_called()


if __name__ == "__main__":
    unittest.main()

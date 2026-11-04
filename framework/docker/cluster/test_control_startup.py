"""Saved freezes open through the recorded owner; unsafe identities fail before a drive."""

import io
import json
import subprocess
import unittest
from contextlib import ExitStack, redirect_stdout
from types import SimpleNamespace
from unittest.mock import call, patch

import control
import scenarios
from stand import StandRefused


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


if __name__ == "__main__":
    unittest.main()

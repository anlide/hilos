"""The hang scenario rejects recovery shortcuts and preserves evidence before cleanup."""

import io
import signal
import unittest
from contextlib import ExitStack, redirect_stdout
from unittest.mock import patch

import control
import scenarios


class DaemonHangScenarioTest(unittest.TestCase):
    def setUp(self):
        contexts = ExitStack()
        self.addCleanup(contexts.close)
        self.output = io.StringIO()
        contexts.enter_context(redirect_stdout(self.output))
        self.nodes = ["first", "second", "leader"]
        self.victim = "first"
        self.events = []
        self.phase = "initial"
        self.exit_observations = 0
        self.answer_after_recovery = True
        self.term_pending = True
        self.stop_applied = True
        self.old_present = False
        self.freeze_pid = 41
        self.container = "original-container"
        self.claimed_leader = "leader"
        self.log = "Daemon started (startup time: 1ms)\nDaemon main-loop heartbeat received; hang detection armed\n"
        contexts.enter_context(patch.object(scenarios, "MASTERS", self.nodes))
        contexts.enter_context(patch.object(scenarios, "ALL_NODES", self.nodes))
        contexts.enter_context(patch.object(scenarios, "wait_converge", side_effect=lambda _nodes: self.inspect()))
        contexts.enter_context(patch.object(scenarios, "inspect_all", side_effect=self.inspect))
        contexts.enter_context(patch.object(scenarios, "container_id", side_effect=lambda _node: self.container))
        contexts.enter_context(patch.object(scenarios, "container_log", side_effect=lambda _node: self.log))
        contexts.enter_context(patch.object(scenarios, "node_log_mark", return_value=("log", 0)))
        contexts.enter_context(patch.object(scenarios, "node_log_since", side_effect=self.evidence))
        contexts.enter_context(patch.object(scenarios, "_daemon_hang_processes", side_effect=self.processes))
        contexts.enter_context(patch.object(scenarios, "wait_until", side_effect=self.poll))
        contexts.enter_context(patch.object(control, "execute", side_effect=self.fault))

    def inspect(self, nodes=None):
        selected = self.nodes if nodes is None else nodes
        if self.phase in ("stopped", "term", "exit"):
            self.assertNotIn(self.victim, selected, "commandPort reached the stopped victim")
        self.events.append(("inspect", tuple(selected)))
        return {
            node: None if self.phase == "recovered" and node == self.victim and not self.answer_after_recovery else {
                "nodeId": node, "consensusRole": "leader" if node == "leader" else "follower",
                "lifecycleState": "MasterLeader" if node == "leader" else "MasterFollowerOrCandidate",
                "leaderId": self.claimed_leader if node == self.victim else "leader", "term": 7, "hasQuorum": True,
                "nodes": [{"nodeId": peer, "online": True} for peer in self.nodes],
            } for node in selected
        }

    def poll(self, predicate, _timeout, desc, nodes=None):
        for _ in range(3):
            views = {} if nodes == [] else self.inspect(nodes)
            if predicate(views):
                return views
        raise scenarios.ScenarioTimeout(desc)

    def processes(self, container, old_pid=None):
        self.assertEqual("original-container", container)
        self.events.append(("processes", self.phase))
        if self.phase == "exit":
            self.exit_observations += 1
            if self.exit_observations > 1:
                self.phase = "recovered"
                self.log += "Starting daemon process...\nDaemon main-loop heartbeat received; hang detection armed\n"
        master = dict(pid=42 if self.phase == "recovered" else 41, startTicks="new" if self.phase == "recovered" else "old",
                      state="T" if self.phase in ("stopped", "term") else "R",
                      termPending=self.phase == "term" and self.term_pending)
        return dict(watchdog=dict(pid=1, startTicks="watchdog", state="S"),
                    masters=[] if self.phase == "exit" else [master],
                    oldPresent=old_pid is not None and (self.old_present or self.phase not in ("exit", "recovered")))

    def fault(self, _stand, command, victim):
        self.assertEqual(self.victim, victim)
        self.events.append(("fault", command))
        if command == "freeze-daemon":
            self.phase = "stopped"
            return control.Outcome(0, f"cluster: SIGSTOPped daemon pid {self.freeze_pid} inside node\n", "")
        if command == "watchdog-expire-heartbeat":
            self.assertEqual("stopped", self.phase)
            self.phase = "term"
            self.log += (f"Watchdog test request {signal.SIGUSR1} applied to the current deadline.\n"
                         "Daemon main loop stalled: no completed pass for 60.00 seconds; sending SIGTERM.\n")
        elif command == "watchdog-expire-stop":
            self.assertEqual("term", self.phase)
            self.phase = "exit"
            if self.stop_applied:
                self.log += f"Watchdog test request {signal.SIGUSR2} applied to the current deadline.\n"
            self.log += ("Daemon main loop still stalled after stop grace; sending SIGKILL.\n"
                         "Daemon process has stopped after main-loop hang: killed by signal 9\n"
                         "Skipping daemon restart: only 0.00 seconds passed since last restart\n")
        elif command == "recreate":
            self.phase = "cleanup"
        else:
            self.fail(command)
        return control.Outcome(0, command, "")

    def evidence(self, _node, _mark):
        self.events.append(("evidence", self.phase))
        return "active log evidence"

    def run_scenario(self):
        return scenarios.scenario_48_daemon_hang_selfheal()

    def assert_cleanup(self):
        evidence = next(index for index, event in enumerate(self.events) if event[0] == "evidence")
        cleanup = self.events.index(("fault", "recreate"))
        self.assertLess(evidence, cleanup)

    def test_dynamic_follower_real_phases_fresh_inspect_and_evidence_before_recreate(self):
        self.assertIn("first: heartbeat -> STOP -> TERM -> KILL/exit", self.run_scenario())
        self.assertEqual(["freeze-daemon", "watchdog-expire-heartbeat", "watchdog-expire-stop", "recreate"],
                         [event[1] for event in self.events if event[0] == "fault"])
        self.assert_cleanup()
        self.assertIn('"event": "recovered"', self.output.getvalue())

    def test_leaders_roster_cannot_substitute_for_a_fresh_victim_reply(self):
        self.answer_after_recovery = False
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "fresh victim inspect"):
            self.run_scenario()
        self.assert_cleanup()

    def test_a_new_master_does_not_prove_the_old_pid_was_collected(self):
        self.old_present = True
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "collected the old master"):
            self.run_scenario()
        self.assert_cleanup()

    def test_term_log_without_the_actual_pending_signal_never_advances_stop_expiry(self):
        self.term_pending = False
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "delivered TERM"):
            self.run_scenario()
        self.assertNotIn(("fault", "watchdog-expire-stop"), self.events)
        self.assert_cleanup()

    def test_natural_grace_expiry_does_not_substitute_for_the_addressed_stop_request(self):
        self.stop_applied = False
        with self.assertRaisesRegex(AssertionError, "lifecycle was out of order"):
            self.run_scenario()
        self.assert_cleanup()

    def test_freezing_another_child_fails_and_still_saves_evidence(self):
        self.freeze_pid = 99
        with self.assertRaisesRegex(AssertionError, "STOP targeted another child"):
            self.run_scenario()
        self.assert_cleanup()

    def test_follower_must_recognize_the_current_leader_before_any_fault(self):
        self.claimed_leader = "stale-leader"
        # Neither potential victim may qualify.
        with patch.object(scenarios, "MASTERS", [self.victim, "leader"]):
            with self.assertRaisesRegex(AssertionError, "no confirmed follower"):
                self.run_scenario()
        self.assertFalse(any(event[0] == "fault" for event in self.events))

    def test_hang_scenario_stays_last_and_needs_only_three_masters(self):
        scenario = scenarios.SCENARIOS[-1]
        self.assertEqual(48, scenario.number)
        self.assertEqual(scenarios.Need(masters=3), scenario.need)


if __name__ == "__main__":
    unittest.main()

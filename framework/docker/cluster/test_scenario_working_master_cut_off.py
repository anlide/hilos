"""A working master cut off from the cluster stops its work before the leader moves it elsewhere."""

import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

import scenarios
from stand import Node, Stand

VICTIM = "m2"


def make_stand(masters, slaves=()):
    """A stand of masters and slaves declaring the given ram, None for a node that declares none."""
    roles = [(f"m{index}", "master", ram) for index, ram in enumerate(masters, 1)]
    roles += [(f"s{index}", "slave", ram) for index, ram in enumerate(slaves, 1)]
    members = {node_id: Node(node_id, node_id, node_id, f"example-{node_id}", f"192.0.2.{10 + index}", role, ram,
                             Path("/example/logs") / node_id, None)
               for index, (node_id, role, ram) in enumerate(roles)}
    return Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                 network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                 entry=None, members=members, masters=[node_id for node_id, role, _ in roles if role == "master"],
                 slaves=[node_id for node_id, role, _ in roles if role == "slave"], stranger=None,
                 cluster_directory=None, scenarios=[51], slave_work_grace_sec=0, database=None, database_members=())


def working_master_cut_off():
    """Scenario 51 as the registry lists it, so these tests read the entry the matrix runs."""
    return next(scenario for scenario in scenarios.SCENARIOS if scenario.number == 51)


def at(stamp):
    """A moment of 2026-10-24 16:10 UTC, by its seconds and milliseconds as a daemon log stamps them."""
    return scenarios.log_time(f"2026-10-24T16:10:{stamp}Z")


class ScenarioWorkingMasterCutOffTest(unittest.TestCase):
    def arrange_cut(self, hosts, leader="m1", restarts=(), operations=(), extras=()):
        """Run the real scenario through finite transport snapshots, without clock or stand I/O."""
        stand = make_stand([None, 10, 10], [4])
        self.leader = leader
        self.views = self.snapshot(hosts, leader, extras=extras)
        self.pending = []
        self.restarts = list(restarts)
        self.operations = list(operations)
        self.events = []
        self.partition_response = RuntimeError("partition reached")
        contexts = ExitStack()
        self.addCleanup(contexts.close)
        for name, value in (("STAND", stand), ("MASTERS", stand.masters), ("SLAVES", stand.slaves),
                            ("ALL_NODES", list(stand.members))):
            contexts.enter_context(patch.object(scenarios, name, value))
        contexts.enter_context(patch.object(scenarios, "wait_until", side_effect=self.poll))
        contexts.enter_context(patch.object(scenarios, "inspect_all", side_effect=lambda _nodes: self.views))
        self.converge = contexts.enter_context(patch.object(scenarios, "wait_converge",
                                                            side_effect=lambda _nodes: self.views))
        self.workers = contexts.enter_context(patch.object(scenarios, "fleet_workers_on",
                                                          side_effect=AssertionError("whole-worker preparation")))
        self.old_control = contexts.enter_context(patch.object(scenarios, "ctl_out",
                                                               side_effect=AssertionError("whole-worker kill")))
        self.command = contexts.enter_context(patch.object(scenarios.control, "client", side_effect=self.restart))
        self.transport = contexts.enter_context(patch.object(scenarios.control, "execute", side_effect=self.operate))
        contexts.enter_context(patch.object(scenarios, "node_log_mark", side_effect=self.mark))

    @staticmethod
    def snapshot(hosts, leader="m1", offline=(), extras=()):
        """Explicit role, term, roster, capabilities and placement observations; no policy simulation."""
        views = {node: {"consensusRole": "leader" if node == leader else "follower", "term": 7,
                        "lifecycleState": "MasterLeader" if node == leader else "MasterFollowerOrCandidate",
                        "leaderId": leader, "hasQuorum": True}
                 for node in ("m1", "m2", "m3")}
        views["s1"] = {"leaderId": leader}
        views[leader]["nodes"] = [{"nodeId": node, "online": node not in offline,
                                   "capabilities": [] if node == "m1" else ["worker", "slots=64"]}
                                  for node in ("m1", "m2", "m3", "s1")]
        views[leader]["placements"] = [{"agentId": f"hilos_probe_fleet:{index}", "nodeId": host,
                                        "state": "started"} for index, host in enumerate(hosts)]
        views[leader]["placements"] += [{"agentId": member, "nodeId": host, "state": "started"}
                                         for member, host in extras]
        for node in offline:
            views[node] = None
        return views

    def poll(self, predicate, _timeout, desc, **kwargs):
        while True:
            view = {n: self.views[n] for n in kwargs.get("nodes", self.views)}
            if predicate(view):
                return view
            if not self.pending:
                raise scenarios.ScenarioTimeout(f"no supplied snapshot satisfies {desc}: {scenarios.summarize(view)}")
            self.views = self.pending.pop(0)

    def advance(self, response):
        if isinstance(response, Exception):
            raise response
        if isinstance(response, scenarios.control.Outcome):
            return response
        self.assertFalse(self.pending, "the preceding operation has not settled")
        self.pending = list(response)
        if self.pending:
            self.views = self.pending.pop(0)
        return scenarios.control.Outcome(0, "accepted", "")

    def restart(self, stand, leader, action, member, guard):
        self.assertIs(stand, scenarios.STAND)
        self.assertEqual(self.leader, leader)
        self.assertEqual("agent:restart", action)
        self.assertEqual(f"--node={scenarios.worker_placements(self.views)[member]['nodeId']}", guard)
        self.events.append(("restart", member, guard))
        self.assertTrue(self.restarts, "an extra restart exceeds the supplied preparation")
        return self.advance(self.restarts.pop(0))

    def mark(self, node):
        self.assertTrue(scenarios.fleet_started(self.views))
        self.assertTrue(scenarios.hosted_by(self.views, "s1"))
        self.events.append(("mark", node))
        return ("log", 0)

    def operate(self, stand, action, node):
        self.assertIs(stand, scenarios.STAND)
        self.events.append((action, node))
        if action == "partition":
            self.assertTrue(scenarios.fleet_started(self.views))
            self.assertTrue(scenarios.hosted_by(self.views, "s1"))
            self.assertTrue(scenarios.hosted_by(self.views, node))
            self.assertNotEqual(self.leader, node)
            self.assertEqual([self.leader], scenarios.leaders(self.views))
            return self.advance(self.partition_response)
        self.assertIn(action, ("kill", "start", "recreate"))
        if self.operations:
            expected_action, expected_node, response = self.operations.pop(0)
            self.assertEqual((expected_action, expected_node), (action, node))
            return self.advance(response)
        return scenarios.control.Outcome(0, "accepted", "")

    def assert_cut(self, victim, restarts):
        with self.assertRaisesRegex(RuntimeError, "^partition reached$"):
            working_master_cut_off().run()
        self.assertEqual(restarts, self.command.call_count)
        self.assertEqual([("partition", victim), ("recreate", victim)],
                         [event for event in self.events if event[0] in ("partition", "recreate")])
        self.workers.assert_not_called()
        self.old_control.assert_not_called()
        self.assertFalse(self.restarts)
        self.assertFalse(self.operations)
        self.converge.assert_called_once_with(scenarios.ALL_NODES)

    def assert_refused(self, reason):
        with self.assertRaisesRegex((scenarios.ScenarioPreconditionLost, AssertionError), reason):
            working_master_cut_off().run()
        self.assertFalse(any(event[0] in ("mark", "partition") for event in self.events))
        self.assertLessEqual(self.command.call_count, 2)

    def test_single_member_restart_keeps_the_donor_share_and_waits_for_started(self):
        hosts = ["m2"] * 5 + ["m3"] * 5
        moved = ["s1", *hosts[1:]]
        starting = self.snapshot(moved)
        starting["m1"]["placements"][0]["state"] = "starting"
        self.arrange_cut(hosts, restarts=[[starting, self.snapshot(moved)]])
        self.assert_cut("m3", 1)
        self.assertEqual({f"hilos_probe_fleet:{i}" for i in range(1, 5)}, scenarios.hosted_by(self.views, "m2"))
        self.assertEqual(("restart", "hilos_probe_fleet:0", "--node=m2"), self.events[0])
        self.assertFalse(any(event[0] in ("kill", "start") for event in self.events))

    def test_all_fleet_on_working_leader_fills_both_empty_roles_in_either_order(self):
        for first, second in (("m2", "s1"), ("s1", "m2")):
            with self.subTest(first=first):
                once = [first, *["m3"] * 9]
                twice = [first, second, *["m3"] * 8]
                self.arrange_cut(["m3"] * 10, leader="m3",
                                 restarts=[[self.snapshot(once, "m3")], [self.snapshot(twice, "m3")]])
                self.assert_cut("m2", 2)
                self.assertEqual({f"hilos_probe_fleet:{i}" for i in range(2, 10)},
                                 scenarios.hosted_by(self.views, "m3"))
                self.assertEqual([("restart", "hilos_probe_fleet:0", "--node=m3"),
                                  ("restart", "hilos_probe_fleet:1", "--node=m3")], self.events[:2])
                self.assertFalse(any(event[0] in ("kill", "start") for event in self.events))

    def test_only_follower_keeps_nine_members_when_one_moves_to_slave(self):
        self.arrange_cut(["m2"] * 10, leader="m3", restarts=[[self.snapshot(["s1", *["m2"] * 9], "m3")]])
        self.assert_cut("m2", 1)
        self.assertEqual(9, len(scenarios.hosted_by(self.views, "m2")))

    def test_slave_keeps_nine_members_when_one_moves_to_a_follower(self):
        self.arrange_cut(["s1"] * 10, leader="m3", restarts=[[self.snapshot(["m2", *["s1"] * 9], "m3")]])
        self.assert_cut("m2", 1)
        self.assertEqual(9, len(scenarios.hosted_by(self.views, "s1")))

    def test_ready_pair_needs_no_mutation_for_bare_or_working_leader(self):
        for leader in ("m1", "m3"):
            with self.subTest(leader=leader):
                self.arrange_cut(["s1"] * 5 + ["m2"] * 5, leader=leader)
                self.assert_cut("m2", 0)
                self.assertFalse(any(event[0] in ("kill", "start") for event in self.events))

    def test_two_empty_competitors_under_bare_leader_need_two_transfers(self):
        self.arrange_cut(["m2"] * 10, restarts=[[self.snapshot(["m3", *["m2"] * 9])],
                                                [self.snapshot(["m3", "s1", *["m2"] * 8])]])
        self.assert_cut("m2", 2)

    def occupied(self, only_slave=False, evacuation=None, returned=None):
        """The selected follower and slave both stop before either returns."""
        hosts = (["m2"] * 5 + ["m3"] * 5) if only_slave else ["m3"] * 10
        extras = [("other:slave", "s1")] + ([] if only_slave else [("other:master", "m2")])
        moved_extras = [(member, "m3") for member, _ in extras]
        evacuated = self.snapshot(["m3"] * 10, "m3", offline=("m2", "s1"), extras=moved_extras)
        arrived = self.snapshot(["m3"] * 10, "m3", extras=moved_extras)
        operations = [("kill", "m2", []), ("kill", "s1", evacuation if evacuation is not None else [evacuated]),
                      ("start", "m2", []), ("start", "s1", returned if returned is not None else [arrived])]
        self.arrange_cut(hosts, "m3", extras=extras, operations=operations,
                         restarts=[[self.snapshot(["m2", *["m3"] * 9], "m3", extras=moved_extras)],
                                   [self.snapshot(["m2", "s1", *["m3"] * 8], "m3", extras=moved_extras)]])
        return evacuated, arrived

    def test_occupied_missing_carriers_are_drained_together_once(self):
        for only_slave in (False, True):
            with self.subTest(only_slave=only_slave):
                self.occupied(only_slave)
                self.assert_cut("m2", 2)
                self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")],
                                 self.events[:4])

    def test_unconfirmed_follower_or_quorum_never_triggers_preparation(self):
        for field, value in (("consensusRole", "candidate"), ("lifecycleState", "MasterCandidate"),
                             ("leaderId", "m2"), ("hasQuorum", False)):
            with self.subTest(field=field):
                self.arrange_cut(["m3"] * 10)
                self.views["m3"][field] = value
                self.assert_refused("before preparation")
                self.assertFalse(self.events)

    def test_baseline_can_settle_before_any_mutation(self):
        hosts = ["s1"] * 5 + ["m2"] * 5
        self.arrange_cut(hosts)
        self.views["m2"]["hasQuorum"] = False
        self.pending = [self.snapshot(hosts)]
        self.assert_cut("m2", 0)

    def test_leader_change_before_preparation_refuses_without_mutations(self):
        self.arrange_cut(["m3"] * 10)
        self.pending = [self.snapshot(["m3"] * 10, "m2")]
        self.views["m3"]["hasQuorum"] = False
        self.assert_refused("m2 leads before the cut, not m1")
        self.assertFalse(self.events)

    def test_leader_change_after_restart_does_not_take_marks(self):
        self.arrange_cut(["m2"] * 10, restarts=[[self.snapshot(["s1", *["m2"] * 9], "m3")]])
        self.assert_refused("m3 leads before the cut, not m1")
        self.assertEqual(1, self.command.call_count)

    def test_leader_change_during_evacuation_returns_both_nodes(self):
        changed = self.snapshot(["m3"] * 10, "m1", offline=("m2", "s1"))
        self.occupied(evacuation=[changed])
        self.assert_refused("m1 leads before the cut, not m3")
        self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")], self.events)

    def test_in_place_success_or_refusal_is_not_restarted_again(self):
        for response, reason in (([self.snapshot(["m3"] * 10, "m3")], "no safe progress"),
                                 (scenarios.control.Outcome(1, "refused", "guard mismatch"), "guard mismatch"),
                                 (RuntimeError("reply lost"), "reply lost")):
            with self.subTest(reason=reason):
                self.arrange_cut(["m3"] * 10, "m3", restarts=[response])
                with self.assertRaisesRegex((AssertionError, RuntimeError), reason):
                    working_master_cut_off().run()
                self.assertEqual(1, self.command.call_count)
                self.assertFalse(any(event[0] in ("partition", "mark") for event in self.events))

    def test_incomplete_fleet_or_new_work_on_empty_target_refuses_the_cut(self):
        incomplete = self.snapshot(["m2", *["m3"] * 9], "m3")
        incomplete["m3"]["placements"][0]["state"] = "starting"
        occupied = self.snapshot(["m2", *["m3"] * 9], "m3", extras=[("other:late", "m2")])
        for snapshot, reason in ((incomplete, "restarted from"), (occupied, "no safe progress")):
            with self.subTest(reason=reason):
                self.arrange_cut(["m3"] * 10, "m3", restarts=[[snapshot]])
                self.assert_refused(reason)
                self.assertEqual(1, self.command.call_count)

    def test_changed_slot_stocks_refuse_before_preparation(self):
        self.arrange_cut(["m3"] * 10, "m3")
        self.views["m3"]["nodes"][1]["capabilities"] = ["worker", "slots=32"]
        self.assert_refused("stock 64 slots")
        self.assertFalse(self.events)

    def test_unsettled_saved_work_does_not_return_carriers_early(self):
        unsettled = self.snapshot(["m3"] * 10, "m3", offline=("m2", "s1"),
                                  extras=[("other:slave", "m3"), ("other:master", "m3")])
        unsettled["m3"]["placements"][-1]["state"] = "placing"
        self.occupied(evacuation=[unsettled])
        self.assert_refused("bounded evacuation")
        self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")], self.events)
        self.command.assert_not_called()

    def test_missing_saved_record_or_pending_failover_refuses_evacuation(self):
        for state in ("missing", "failed", "unplaced", "old host"):
            with self.subTest(state=state):
                snapshot = self.snapshot(["m3"] * 10, "m3", offline=("m2", "s1"),
                                         extras=[("other:slave", "m3"), ("other:master", "m3")])
                row = snapshot["m3"]["placements"][-1]
                if state == "missing":
                    snapshot["m3"]["placements"].pop()
                elif state == "old host":
                    row["nodeId"] = "m2"
                else:
                    row["state"] = state
                self.occupied(evacuation=[snapshot])
                self.assert_refused("bounded evacuation")
                self.command.assert_not_called()

    def test_work_returning_to_drained_carriers_refuses_without_second_drain(self):
        self.occupied(returned=[self.snapshot(["m3"] * 10, "m3", extras=[("other:late", "s1")])])
        self.assert_refused("received POLICY work on return")
        self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")], self.events)

    def test_failed_second_kill_still_returns_both_registered_nodes(self):
        self.occupied()
        self.operations[1] = ("kill", "s1", scenarios.control.Outcome(1, "", "kill failed"))
        self.assert_refused("kill failed")
        self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")], self.events)

    def test_failed_first_start_does_not_skip_second_start(self):
        self.occupied()
        self.operations[2] = ("start", "m2", scenarios.control.Outcome(1, "", "start failed"))
        self.assert_refused("start failed")
        self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")], self.events)

    def test_failed_cleanup_preserves_partition_error_and_still_waits_for_convergence(self):
        self.arrange_cut(["m2"] * 5 + ["s1"] * 5,
                         operations=[("recreate", "m2", scenarios.control.Outcome(1, "", "recreate failed"))])
        with patch.object(scenarios.sys, "stderr") as stderr:
            self.assert_cut("m2", 0)
        self.assertIn("recreate failed", "".join(call.args[0] for call in stderr.write.call_args_list))

    def test_evacuation_waits_until_saved_work_is_started_before_returning_nodes(self):
        settled, _arrived = self.occupied()
        unsettled = self.snapshot(["m3"] * 10, "m3", offline=("m2", "s1"),
                                  extras=[("other:slave", "m3"), ("other:master", "m3")])
        unsettled["m3"]["placements"][-1]["state"] = "placing"
        self.operations[1] = ("kill", "s1", [unsettled, settled])
        self.assert_cut("m2", 2)

    def test_a_second_restart_that_makes_no_progress_is_final(self):
        once = self.snapshot(["m2", *["m3"] * 9], "m3")
        self.arrange_cut(["m3"] * 10, "m3", restarts=[[once], [once]])
        self.assert_refused("no safe progress")
        self.assertEqual(2, self.command.call_count)

    def test_restart_cannot_empty_a_previously_positive_role(self):
        self.arrange_cut(["m3"] * 9 + ["s1"], "m3",
                         restarts=[[self.snapshot(["m2", *["m3"] * 9], "m3")]])
        self.assert_refused("no safe progress")
        self.assertEqual(1, self.command.call_count)

    def test_partition_refusal_still_recreates_the_final_victim(self):
        self.arrange_cut(["m2"] * 5 + ["s1"] * 5)
        self.partition_response = scenarios.control.Outcome(1, "", "partition reply failed")
        with self.assertRaisesRegex(AssertionError, "partition reply failed"):
            working_master_cut_off().run()
        self.assertEqual([("partition", "m2"), ("recreate", "m2")], self.events[-2:])
        self.converge.assert_called_once_with(scenarios.ALL_NODES)

    def test_failed_restoration_cannot_retry_a_precondition_failure(self):
        self.occupied(evacuation=[self.snapshot(["m3"] * 10, "m1", offline=("m2", "s1"))])
        self.operations[2] = ("start", "m2", scenarios.control.Outcome(1, "", "restore failed"))
        with self.assertRaisesRegex(AssertionError, "restore failed") as caught:
            working_master_cut_off().run()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertIsInstance(caught.exception.__cause__, scenarios.ScenarioPreconditionLost)
        self.assertEqual([("kill", "m2"), ("kill", "s1"), ("start", "m2"), ("start", "s1")], self.events)

    def test_only_a_stand_with_two_working_masters_and_a_bare_one_carries_it(self):
        cases = [
            ("roblox-game-server", make_stand([None, 10, 10], [4]), None),
            ("online-testing", make_stand([10, 10, 10]), "it needs 1 slaves, the stand has 0"),
            ("ecommerce-shop", make_stand([None], [4, 4]), "it needs 3 masters, the stand has 1"),
            ("binance", make_stand([None, None, None], [10, 10]),
             "it needs 2 masters that declare ram, the stand has 0"),
            ("file-gallery-manager", make_stand([None, None], [10, 6, 4]), "it needs 3 masters, the stand has 2"),
            ("concert-tickets", make_stand([10, 10, 10, 10, 10]), "it needs 1 slaves, the stand has 0"),
            ("every master working", make_stand([10, 10, 10], [4]),
             "it needs a master that declares no ram, the stand has none"),
        ]
        for shape, stand, expected in cases:
            with self.subTest(shape=shape):
                self.assertEqual(expected, scenarios.unmet_need(stand, working_master_cut_off()))

    def test_judge_passes_stops_before_starts_and_returns_the_smallest_gap(self):
        stops = {"hilos_probe_fleet:0": [at("11.000"), at("14.000")], "hilos_probe_fleet:1": [at("11.100")]}
        starts = {"hilos_probe_fleet:0": ("m3", [at("12.500"), at("13.000")]),
                  "hilos_probe_fleet:1": ("s1", [at("11.350")])}
        self.assertEqual(0.25, scenarios.assert_one_executor(VICTIM, stops, starts))

    def test_judge_fails_two_executors_hard_and_waits_on_a_missing_line(self):
        for started in ("10.900", "11.000"):
            with self.subTest(started=started):
                with self.assertRaises(AssertionError) as caught:
                    scenarios.assert_one_executor(VICTIM, {"hilos_probe_fleet:0": [at("11.000")]},
                                                  {"hilos_probe_fleet:0": ("m3", [at(started)])})
                self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
                self.assertEqual(f"hilos_probe_fleet:0 came up on m3 at 2026-10-24T16:10:{started}+00:00 while m2 "
                                 "stopped it only at 2026-10-24T16:10:11.000+00:00: two executors at once",
                                 str(caught.exception))
        with self.assertRaises(AssertionError) as caught:
            scenarios.assert_one_executor(VICTIM, {"hilos_probe_fleet:1": [at("11.000")]},
                                          {"hilos_probe_fleet:0": ("m3", []),
                                           "hilos_probe_fleet:1": ("s1", [at("10.900")])})
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual("hilos_probe_fleet:1 came up on s1 at 2026-10-24T16:10:10.900+00:00 while m2 stopped it "
                         "only at 2026-10-24T16:10:11.000+00:00: two executors at once", str(caught.exception))
        with self.assertRaises(AssertionError) as caught:
            scenarios.assert_one_executor(VICTIM, {"hilos_probe_fleet:0": [at("11.000")]},
                                          {"hilos_probe_fleet:0": ("m3", [at("12.000")]),
                                           "hilos_probe_fleet:1": ("s1", [at("12.000")])})
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual("hilos_probe_fleet:1 came up on s1 at 2026-10-24T16:10:12.000+00:00 while m2 never "
                         "reported it stopped: two executors at once", str(caught.exception))
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "s1's log has not started it since the cut"):
            scenarios.assert_one_executor(VICTIM, {"hilos_probe_fleet:1": [at("11.000")]},
                                          {"hilos_probe_fleet:1": ("s1", [])})

    def test_judge_checks_hard_violations_before_missing_starts_in_either_order(self):
        cases = [
            ([], "12.000", "hilos_probe_fleet:1 came up on s1 at 2026-10-24T16:10:12.000+00:00 while m2 never "
             "reported it stopped: two executors at once"),
            ([at("11.000")], "10.900", "hilos_probe_fleet:1 came up on s1 at 2026-10-24T16:10:10.900+00:00 "
             "while m2 stopped it only at 2026-10-24T16:10:11.000+00:00: two executors at once"),
        ]
        for stopped, started, expected in cases:
            for reverse in (False, True):
                with self.subTest(started=started, reverse=reverse):
                    stops = {"hilos_probe_fleet:0": [at("11.000")], "hilos_probe_fleet:1": stopped}
                    starts = [("hilos_probe_fleet:0", ("m3", [])),
                              ("hilos_probe_fleet:1", ("s1", [at(started)]))]
                    with self.assertRaises(AssertionError) as caught:
                        scenarios.assert_one_executor(VICTIM, stops, dict(reversed(starts) if reverse else starts))
                    self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
                    self.assertEqual(expected, str(caught.exception))


if __name__ == "__main__":
    unittest.main()

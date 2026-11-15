"""A working master cut off from the cluster stops its work before the leader moves it elsewhere."""

import unittest
from pathlib import Path

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
                                          {"hilos_probe_fleet:0": ("m3", [at("12.000")]),
                                           "hilos_probe_fleet:1": ("s1", [at("10.900")])})
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual("hilos_probe_fleet:1 came up on s1 at 2026-10-24T16:10:10.900+00:00 while m2 stopped it "
                         "only at 2026-10-24T16:10:11.000+00:00: two executors at once", str(caught.exception))
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "^m2 never reported hilos_probe_fleet:1 stopped$"):
            scenarios.assert_one_executor(VICTIM, {"hilos_probe_fleet:0": [at("11.000")]},
                                          {"hilos_probe_fleet:0": ("m3", [at("12.000")]),
                                           "hilos_probe_fleet:1": ("s1", [at("12.000")])})
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "s1's log has not started it since the cut"):
            scenarios.assert_one_executor(VICTIM, {"hilos_probe_fleet:1": [at("11.000")]},
                                          {"hilos_probe_fleet:1": ("s1", [])})


if __name__ == "__main__":
    unittest.main()

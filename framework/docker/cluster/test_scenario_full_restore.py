"""A hot FULL restore needs a remote reader and a real slave archive holder."""

import unittest
from pathlib import Path

import scenarios
from stand import Stand


class ScenarioFullRestoreTest(unittest.TestCase):
    def test_57_needs_two_masters_and_a_slave_after_the_other_freezes(self):
        matches = [item for item in scenarios.SCENARIOS if item.number == 57]
        self.assertEqual(1, len(matches))
        scenario = matches[0]
        self.assertIs(scenarios.scenario_57_millisecond_moments_survive_full_restore, scenario.run)
        self.assertEqual(scenarios.Need(masters=2, slaves=1), scenario.need)
        for number in (23, 25, 33, 34, 38):
            previous = next(item for item in scenarios.SCENARIOS if item.number == number)
            self.assertLess(scenarios.SCENARIOS.index(previous), scenarios.SCENARIOS.index(scenario))
        for masters, slaves, expected in ((2, 1, None), (3, 2, None),
                                          (1, 1, "it needs 2 masters, the stand has 1"),
                                          (2, 0, "it needs 1 slaves, the stand has 0")):
            with self.subTest(masters=masters, slaves=slaves):
                master_ids = [f"m{i}" for i in range(masters)]
                slave_ids = [f"s{i}" for i in range(slaves)]
                stand = Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                              network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                              entry=None, members={node: None for node in master_ids + slave_ids},
                              masters=master_ids, slaves=slave_ids, stranger=None, cluster_directory=None,
                              scenarios=[57], slave_work_grace_sec=0, database=None, database_members=())
                self.assertEqual(expected, scenarios.unmet_need(stand, scenario))


if __name__ == "__main__":
    unittest.main()

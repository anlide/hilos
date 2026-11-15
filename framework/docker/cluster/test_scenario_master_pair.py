"""Scenario 52 is carried only by a stand whose quorum is both of its two masters."""

import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

import control
import scenarios
from stand import Stand


class ScenarioMasterPairTest(unittest.TestCase):
    def setUp(self):
        self.contexts = ExitStack()
        self.addCleanup(self.contexts.close)
        self.contexts.enter_context(patch.object(control, "_run",
                                               side_effect=AssertionError("unexpected subprocess")))
        self.contexts.enter_context(patch.object(control, "compose",
                                               side_effect=AssertionError("unexpected compose")))

    @staticmethod
    def make_stand(masters, slaves):
        masters = [f"m{index}" for index in range(1, masters + 1)]
        slaves = [f"s{index}" for index in range(1, slaves + 1)]
        return Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                     network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                     entry=None, members={node: None for node in masters + slaves}, masters=masters, slaves=slaves,
                     stranger=None, cluster_directory=None, scenarios=[52], slave_work_grace_sec=0,
                     database=None, database_members=())

    def test_52_needs_exactly_two_masters_and_a_slave(self):
        scenario = next(item for item in scenarios.SCENARIOS if item.number == 52)
        for masters, slaves, expected in ((2, 3, None),
                                          (3, 2, "it needs exactly 2 masters, the stand has 3"),
                                          (2, 0, "it needs 1 slaves, the stand has 0")):
            with self.subTest(masters=masters, slaves=slaves):
                self.assertEqual(expected, scenarios.unmet_need(self.make_stand(masters, slaves), scenario))


if __name__ == "__main__":
    unittest.main()

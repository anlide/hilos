"""Scenario 11 keeps exact loopback and reconnect checks beside lawful receipt traffic."""

import copy
import io
import unittest
from contextlib import ExitStack, redirect_stdout
from unittest.mock import patch

import scenarios


class ScenarioDbFactTest(unittest.TestCase):
    def setUp(self):
        contexts = ExitStack()
        self.addCleanup(contexts.close)
        self.output = io.StringIO()
        contexts.enter_context(redirect_stdout(self.output))
        contexts.enter_context(patch.object(scenarios, "MASTERS", ["m1", "m2", "m3"]))
        contexts.enter_context(patch.object(scenarios, "ALL_NODES", ["m1", "m2", "m3"]))
        self.sender, self.watcher = "m2", "m1"
        self.views = {
            node: {"lifecycleState": "MasterLeader" if node == self.sender else "MasterFollower",
                   "consensusRole": "leader" if node == self.sender else "follower",
                   "dbReplicas": 0, "dbReplicasByCollection": {},
                   "dbCollectionsRead": ["settings", "changeLogReceipts"] if node == self.sender else ["settings"]}
            for node in scenarios.ALL_NODES
        }
        self.value = None
        self.events = []
        self.echo_on = None
        self.duplicate_on = None
        self.drop_on = None
        contexts.enter_context(patch.object(scenarios, "inspect_all", side_effect=self.inspect))
        contexts.enter_context(patch.object(scenarios, "wait_converge", side_effect=lambda _nodes: self.inspect()))
        contexts.enter_context(patch.object(scenarios, "client", side_effect=self.write))
        contexts.enter_context(patch.object(scenarios, "db_read", side_effect=self.read))
        contexts.enter_context(patch.object(scenarios, "ctl", side_effect=self.recreate))

    def inspect(self, nodes=None):
        return copy.deepcopy({node: self.views[node] for node in nodes or scenarios.ALL_NODES})

    def accept(self, node, collection):
        view = self.views[node]
        view["dbReplicas"] += 1
        counts = view["dbReplicasByCollection"]
        counts[collection] = counts.get(collection, 0) + 1

    def write(self, node, command, key, value):
        self.assertEqual((self.sender, "test:cluster:db:write", scenarios.DB_PROBE_KEY), (node, command, key))
        self.events.append(("write", value))
        self.value = value
        if value != self.drop_on:
            self.accept(self.watcher, "settings")
        if value == self.duplicate_on:
            self.accept(self.watcher, "settings")
        if value == self.echo_on:
            self.accept(self.sender, "settings")
        return True

    def read(self, node, key):
        self.assertEqual((self.watcher, scenarios.DB_PROBE_KEY), (node, key))
        self.events.append(("read", self.value))
        # An empty CLI receipt is created and deleted on the remote watcher. The journal's
        # reader interest brings both facts to the sender without echoing its settings write.
        self.accept(self.sender, "changeLogReceipts")
        self.accept(self.sender, "changeLogReceipts")
        return self.value

    def recreate(self, command, node):
        self.assertEqual(("recreate", self.watcher), (command, node))
        self.events.append(("recreate", node))
        self.views[node]["dbReplicas"] = 0
        self.views[node]["dbReplicasByCollection"] = {}

    def test_receipts_do_not_impersonate_a_loop_and_all_three_tacts_still_run(self):
        scenarios.scenario_11_cross_node_db_fact()

        self.assertEqual([("write", "v1"), ("read", "v1"), ("write", "v2"), ("read", "v2"),
                          ("recreate", self.watcher), ("read", "v2"), ("write", "v3"), ("read", "v3")],
                         self.events)
        self.assertEqual(8, scenarios.db_replicas(self.views, self.sender))
        self.assertEqual(0, scenarios.db_replicas(self.views, self.sender, "settings"))
        self.assertIn("accepted 4 other DB facts", self.output.getvalue())

    def test_a_returned_settings_fact_still_fails_beside_receipts(self):
        self.echo_on = "v2"
        with self.assertRaisesRegex(AssertionError, "fact must not come back"):
            scenarios.scenario_11_cross_node_db_fact()

    def test_a_returned_settings_fact_after_reconnect_also_fails(self):
        self.echo_on = "v3"
        with self.assertRaisesRegex(AssertionError, "own write after the reconnect"):
            scenarios.scenario_11_cross_node_db_fact()

    def test_an_extra_settings_fact_is_not_allowed_in_either_phase(self):
        for value, message in [("v2", "exactly the two settings facts"), ("v3", "exactly one settings fact")]:
            with self.subTest(value=value):
                self.duplicate_on = value
                with self.assertRaisesRegex(AssertionError, message):
                    scenarios.scenario_11_cross_node_db_fact()

    def test_a_value_read_without_a_counted_fact_cannot_satisfy_the_scenario(self):
        self.drop_on = "v2"
        with self.assertRaisesRegex(AssertionError, "exactly the two settings facts"):
            scenarios.scenario_11_cross_node_db_fact()


if __name__ == "__main__":
    unittest.main()

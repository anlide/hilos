"""The application-connection drill rejects stale reads and a reconnect that did not happen."""

import json
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import call, patch

import control
import scenarios
from stand import Database, Stand


class ScenarioApplicationConnectionsTest(unittest.TestCase):
    def setUp(self):
        self.contexts = ExitStack()
        self.addCleanup(self.contexts.close)
        self.contexts.enter_context(patch.object(control, "_run",
                                               side_effect=AssertionError("unexpected subprocess")))
        self.contexts.enter_context(patch.object(control, "compose",
                                               side_effect=AssertionError("unexpected compose")))
        self.nodes = ["m1", "m2", "m3"]
        self.contexts.enter_context(patch.object(scenarios, "ALL_NODES", self.nodes))
        self.contexts.enter_context(patch.object(scenarios, "MASTERS", self.nodes))
        self.use_stand()
        self.members = {node: "db1-host" for node in self.nodes}
        self.ids = {node: 101 + index for index, node in enumerate(self.nodes)}
        self.overrides = {}
        self.reconnected_overrides = {}
        self.reconnected = False
        self.keep_connection_id = False
        self.query_returns_copy = False
        self.query_member = None
        self.initial_query_value = "causal-1"
        self.reconnected_query_value = "direct"
        self.copy_refreshed = False
        self.value = None
        self.copies = {}
        self.events = []
        self.key = f"{scenarios.DB_PROBE_KEY}_causal_m1"
        self.out = self.contexts.enter_context(patch.object(scenarios, "client_out", side_effect=self.client_out))
        self.client = self.contexts.enter_context(patch.object(scenarios, "client", side_effect=self.write))
        self.sql = self.contexts.enter_context(patch.object(scenarios, "db_sql", side_effect=self.db_sql))
        self.read = self.contexts.enter_context(patch.object(scenarios, "db_read", side_effect=self.db_read))
        self.wait = self.contexts.enter_context(patch.object(scenarios, "wait_db_value", side_effect=self.wait_db_value))
        self.converge = self.contexts.enter_context(patch.object(scenarios, "wait_converge"))

    def use_stand(self, data_members=1, replicas=0):
        primary = Database("db1", "example-db1", "app", "example", "app")
        data = tuple(Database(f"db{index}", f"example-db{index}", "app", "example", "app")
                     for index in range(1, data_members + 1))
        replicas = tuple(Database(f"replica{index}", f"example-replica{index}", "app", "example", "app",
                                  replica_of="db1") for index in range(1, replicas + 1))
        self.stand = Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                           network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                           entry=None, members=dict.fromkeys(self.nodes), masters=self.nodes, slaves=[],
                           stranger=None, cluster_directory=None, scenarios=[49], slave_work_grace_sec=0,
                           database=primary, database_members=data + replicas if data_members > 1 or replicas else ())
        self.contexts.enter_context(patch.object(scenarios, "STAND", self.stand))

    def session(self, node, database):
        facts = dict(databaseName=database, databaseMember=self.members[node], databaseConnectionId=self.ids[node],
                     wsrepSyncWait=1, wsrepDirtyReads=False)
        facts.update(self.overrides.get((node, database), {}))
        if self.reconnected:
            facts.update(self.reconnected_overrides)
        return facts

    def client_out(self, node, command, argument):
        if command == "test:cluster:db:session":
            self.events.append(("session", node, argument))
            return json.dumps(self.session(node, argument))
        if command == "test:cluster:db:write":
            self.fail("a write needs a value")
        if command == "test:cluster:db:query":
            self.assertEqual(self.key, argument)
            self.events.append(("query", node))
            value = self.reconnected_query_value if self.reconnected else self.value
            if self.value == "causal-1":
                value = self.initial_query_value
            if self.query_returns_copy and self.value == "direct":
                value = self.copies.get(node)
            facts = self.session(node, "primary")
            facts.pop("databaseName")
            facts.update(settingKey=argument, settingValue=value)
            if self.query_member is not None:
                facts["databaseMember"] = self.query_member
            return json.dumps(facts)
        self.fail(f"unexpected command: {command}")

    def write(self, node, command, key, value):
        self.assertEqual("test:cluster:db:write", command)
        self.assertEqual(self.key, key)
        self.events.append(("write", node, value))
        self.value = value
        self.copies[node] = value
        return True

    def write_out(self, node, command, *args):
        if command == "test:cluster:db:write":
            self.write(node, command, *args)
            return f"Wrote {args[0]}={args[1]} via {self.members[node]}"
        return self.client_out(node, command, *args)

    def db_sql(self, statement, member=None):
        self.events.append(("sql", statement, member))
        if statement == "SELECT @@hostname":
            return f"{member}-host"
        if statement.startswith("UPDATE "):
            self.assertEqual(f"UPDATE `hilos_probe_value` SET `value` = 'direct' WHERE `key` = '{self.key}'", statement)
            self.value = "direct"
            return ""
        if statement.startswith("KILL "):
            connection_id = int(statement.split()[1])
            node = next(node for node in self.nodes if self.ids[node] == connection_id)
            if not self.keep_connection_id:
                self.ids[node] += 100
            self.reconnected = True
            return ""
        self.fail(f"unexpected SQL: {statement}")

    def db_read(self, node, key):
        self.assertEqual(self.key, key)
        self.events.append(("read", node))
        return self.value if self.copy_refreshed else self.copies.get(node)

    def wait_db_value(self, node, key, expected, timeout, _desc):
        self.assertEqual(self.key, key)
        self.assertEqual(scenarios.CONVERGE_TIMEOUT, timeout)
        self.assertEqual(expected, self.value)
        self.events.append(("wait", node, expected))
        self.copies[node] = self.value

    def run_scenario(self):
        self.out.side_effect = self.write_out
        return scenarios.scenario_49_application_connections_read_what_was_written()

    def use_multiple_backends(self):
        self.use_stand(data_members=2)
        # The first other daemon uses the writer's backend. The reader must be m3.
        self.members["m3"] = "db2-host"

    def test_single_server_needs_no_read_bit_or_other_backend_and_kills_the_default_service(self):
        self.overrides = {(node, database): dict(wsrepSyncWait=8, wsrepDirtyReads=True)
                          for node in self.nodes for database in ("primary", "change_log")}
        detail = self.run_scenario()
        self.assertIn("m1 wrote via db1-host; m2 read via db1-host", detail)
        self.assertIn("reconnected 102 -> 202 via db1-host", detail)
        self.converge.assert_called_once_with(self.nodes)
        session_calls = [item for item in self.out.call_args_list if item.args[1] == "test:cluster:db:session"]
        self.assertEqual([call(node, "test:cluster:db:session", database)
                          for node in self.nodes for database in ("primary", "change_log")]
                         + [call("m2", "test:cluster:db:session", "primary")], session_calls)
        self.assertEqual([None, None], [item.args[1] for item in self.sql.call_args_list])
        self.assertEqual(call("KILL 102", None), self.sql.call_args_list[-1])
        write_index = self.events.index(("write", "m1", "causal-1"))
        self.assertEqual(("query", "m2"), self.events[write_index + 1])
        self.assertEqual([("wait", "m2", "causal-1"), ("wait", "m2", "causal-2")],
                         [event for event in self.events if event[0] == "wait"])
        self.assertEqual("causal-2", self.copies["m2"])

    def test_multiple_backends_choose_another_daemon_and_route_update_and_kill_by_hostname(self):
        self.use_multiple_backends()
        detail = self.run_scenario()
        self.assertIn("m1 wrote via db1-host; m3 read via db2-host", detail)
        self.assertIn("reconnected 103 -> 203 via db2-host", detail)
        self.assertEqual([call("SELECT @@hostname", "db1"), call("SELECT @@hostname", "db2"),
                          call(f"UPDATE `hilos_probe_value` SET `value` = 'direct' WHERE `key` = '{self.key}'", "db1"),
                          call("KILL 103", "db2")], self.sql.call_args_list)
        self.assertEqual([call("m3", self.key, "causal-1", scenarios.CONVERGE_TIMEOUT,
                               "m3's copy holds m1's causal-1"),
                          call("m3", self.key, "causal-2", scenarios.CONVERGE_TIMEOUT,
                               "m3's copy follows m1's causal-2")], self.wait.call_args_list)
        self.client.assert_called_once_with("m1", "test:cluster:db:write", self.key, "causal-2")

    def test_a_replica_does_not_require_read_bit_or_another_writable_backend(self):
        self.use_stand(replicas=1)
        self.overrides["m2", "primary"] = dict(wsrepSyncWait=0, wsrepDirtyReads=True)
        self.run_scenario()
        self.assertEqual(call("KILL 102", "db1"), self.sql.call_args_list[-1])

    def test_all_application_sessions_on_one_backend_refuse_with_its_name(self):
        self.use_stand(data_members=2)
        with self.assertRaisesRegex(AssertionError, "same backend db1-host"):
            self.run_scenario()
        self.client.assert_not_called()
        self.assertFalse(any(event[0] == "write" for event in self.events))

    def test_every_logical_session_requires_the_read_bit_on_multiple_backends(self):
        self.use_multiple_backends()
        for node in self.nodes:
            for database in ("primary", "change_log"):
                for mask in (0, 2, 4, 6, 8):
                    with self.subTest(node=node, database=database, mask=mask):
                        self.overrides = {(node, database): dict(wsrepSyncWait=mask)}
                        with self.assertRaisesRegex(AssertionError, f"{node} {database}.*={mask}.*READ bit"):
                            self.run_scenario()

    def test_every_logical_session_rejects_dirty_reads_on_multiple_backends(self):
        self.use_multiple_backends()
        for node in self.nodes:
            for database in ("primary", "change_log"):
                with self.subTest(node=node, database=database):
                    self.overrides = {(node, database): dict(wsrepDirtyReads=True)}
                    with self.assertRaisesRegex(AssertionError, f"{node} {database}.*wsrep_dirty_reads=True"):
                        self.run_scenario()

    def test_read_on_the_writers_actual_backend_is_refused(self):
        self.use_multiple_backends()
        self.query_member = "db1-host"
        with self.assertRaisesRegex(AssertionError, "m1 and m3 both used backend db1-host"):
            self.run_scenario()

    def test_immediate_query_must_see_the_acknowledged_write(self):
        self.initial_query_value = "stale"
        with self.assertRaisesRegex(AssertionError, "m2 queried.*'stale'.*immediately after m1"):
            self.run_scenario()
        self.wait.assert_not_called()

    def test_query_returning_the_copy_instead_of_the_direct_update_is_refused(self):
        self.query_returns_copy = True
        with self.assertRaisesRegex(AssertionError, "m2 queried.*'causal-1'.*direct UPDATE"):
            self.run_scenario()

    def test_a_query_that_refreshed_the_copy_is_refused(self):
        self.copy_refreshed = True
        with self.assertRaisesRegex(AssertionError, "m2's copy holds.*'direct'.*expected causal-1"):
            self.run_scenario()

    def test_kill_must_change_the_application_connection_id(self):
        self.keep_connection_id = True
        with self.assertRaisesRegex(AssertionError, "m2 kept databaseConnectionId=102 after KILL"):
            self.run_scenario()

    def test_reconnected_session_rechecks_both_settings_on_multiple_backends(self):
        self.use_multiple_backends()
        for settings, reason in [(dict(wsrepSyncWait=6), "wsrep_sync_wait=6.*READ bit"),
                                 (dict(wsrepDirtyReads=True), "wsrep_dirty_reads=True")]:
            with self.subTest(settings=settings):
                self.reconnected = False
                self.reconnected_overrides = settings
                with self.assertRaisesRegex(AssertionError, f"m3 primary.*{reason}"):
                    self.run_scenario()

    def test_reconnected_query_must_still_see_the_direct_update(self):
        self.reconnected_query_value = "causal-1"
        with self.assertRaisesRegex(AssertionError, "m2 queried.*'causal-1'.*after reconnecting"):
            self.run_scenario()

    def test_helpers_parse_json_without_losing_session_facts(self):
        expected_session = self.session("m2", "change_log")
        self.assertEqual(expected_session, scenarios.application_session("m2", "change_log"))
        self.value = "direct"
        query = scenarios.application_query("m2", self.key)
        self.assertEqual("direct", query["settingValue"])
        self.assertEqual("db1-host", query["databaseMember"])
        self.assertEqual(102, query["databaseConnectionId"])

    def test_missing_probe_refusals_name_the_node_database_and_registration_hint(self):
        self.out.side_effect = None
        self.out.return_value = None
        for database in ("primary", "change_log"):
            with self.subTest(database=database):
                with self.assertRaisesRegex(AssertionError, f"m2.*{database}.*HILOS_PROBE_DB"):
                    scenarios.application_session("m2", database)
        with self.assertRaisesRegex(AssertionError, f"m2.*{self.key}.*primary.*HILOS_PROBE_DB"):
            scenarios.application_query("m2", self.key)

    def test_registry_runs_49_after_trigger_observation_and_accepts_a_single_database_server(self):
        selected = next(item for item in scenarios.SCENARIOS if item.number == 49)
        previous = scenarios.SCENARIOS[scenarios.SCENARIOS.index(selected) - 1]
        self.assertEqual(40, previous.number)
        self.assertEqual(scenarios.Need(nodes=2), selected.need)
        self.assertIsNone(scenarios.unmet_need(self.stand, selected))


if __name__ == "__main__":
    unittest.main()

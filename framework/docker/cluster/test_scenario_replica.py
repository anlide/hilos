"""A fresh replica may need time to receive the application user before scenario 31 asks it."""

import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import call, patch

import control
import scenarios
from stand import Database, Stand


class ScenarioReplicaTest(unittest.TestCase):
    def setUp(self):
        self.contexts = ExitStack()
        self.addCleanup(self.contexts.close)
        self.contexts.enter_context(patch.object(control, "_run",
                                               side_effect=AssertionError("unexpected subprocess")))
        self.contexts.enter_context(patch.object(control, "compose",
                                               side_effect=AssertionError("unexpected compose")))
        primary = Database("db1", "example-db1", "app", "example", "app")
        replica = Database("replica1", "example-replica1", "app", "example", "app", replica_of="db1")
        self.stand = Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                           network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                           entry=None, members={"m1": None}, masters=["m1"], slaves=[],
                           stranger=None, cluster_directory=None, scenarios=[31], slave_work_grace_sec=0,
                           database=primary, database_members=(primary, replica))
        self.contexts.enter_context(patch.object(scenarios, "STAND", self.stand))
        self.now = 0.0
        self.contexts.enter_context(patch.object(scenarios.time, "monotonic", side_effect=lambda: self.now))
        self.sleep = self.contexts.enter_context(patch.object(scenarios.time, "sleep", side_effect=self.advance))
        self.answers = [control.Outcome(0, "Slaves_running\t1\n", "")]
        self.read_only = "1"
        self.execute = self.contexts.enter_context(patch.object(control, "execute", side_effect=self.read_sql))
        self.log = self.contexts.enter_context(patch.object(scenarios, "db_log", return_value=""))

    def advance(self, seconds):
        self.now += seconds

    def read_sql(self, stand, command, statement, member=None):
        self.assertIs(self.stand, stand)
        self.assertEqual("db-sql", command)
        if statement == scenarios.PRIMARY_POSITION_SQL:
            self.assertIsNone(member)
            answer = "0-1-42"
        elif statement == scenarios.DATABASE_MARKER_SQL:
            self.assertIn(member, (None, "replica1"))
            answer = "example-marker"
        else:
            self.assertEqual("replica1", member)
            if statement == scenarios.REPLICA_RUNNING_SQL:
                return self.answers.pop(0) if len(self.answers) > 1 else self.answers[0]
            if statement == f"SELECT MASTER_GTID_WAIT('0-1-42', {scenarios.REPLICA_CATCH_UP_TIMEOUT:.0f})":
                answer = "0"
            elif statement == scenarios.READ_ONLY_SQL:
                answer = self.read_only
            elif statement == scenarios.app_connections_sql("app"):
                answer = "0"
            else:
                self.fail(f"unexpected SQL: {statement}")
        return control.Outcome(0, answer + "\n", "")

    def test_fresh_replica_answers_after_two_access_denials(self):
        denied = control.Outcome(1, "", "ERROR 1045 (28000): Access denied for user 'app'@'localhost'")
        self.answers = [denied, denied, self.answers[0]]
        detail = scenarios.scenario_31_replica_keeps_up_with_the_primary()
        self.assertEqual([call(scenarios.POLL_INTERVAL)] * 2, self.sleep.call_args_list)
        self.assertIn(f"answered after {2 * scenarios.POLL_INTERVAL:.1f} s", detail)
        self.assertIn("caught up to 0-1-42", detail)
        self.assertIn("read-only, the same database marker, no connection of app", detail)
        self.execute.assert_any_call(self.stand, "db-sql", scenarios.app_connections_sql("app"), "replica1")
        self.log.assert_not_called()

    def test_replica_that_never_answers_fails_hard_with_last_error_and_log_reason(self):
        last_error = "ERROR 1045 (28000): Access denied for user 'app'@'localhost'"
        reason = "[Warning] Slave SQL: Error_code: 1146; table does not exist"
        self.answers = [control.Outcome(1, "", "ERROR 2002 (HY000): Can't connect"),
                        control.Outcome(1, "", last_error + "\n")]
        self.log.return_value = reason + "\n[ERROR] Slave SQL thread aborted\n"
        with self.assertRaises(AssertionError) as caught:
            scenarios.scenario_31_replica_keeps_up_with_the_primary()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual(f"replica1 does not replicate: no answer as app within "
                         f"{scenarios.REPLICA_CATCH_UP_TIMEOUT:.0f} s: {last_error}; {reason}",
                         str(caught.exception))
        self.assertGreaterEqual(self.now, scenarios.REPLICA_CATCH_UP_TIMEOUT)
        self.assertLess(self.now, scenarios.REPLICA_CATCH_UP_TIMEOUT + scenarios.POLL_INTERVAL)
        self.log.assert_called_once_with("replica1")

    def test_replica_with_stopped_threads_fails_hard_without_sleep(self):
        self.answers = [control.Outcome(0, "Slaves_running\t0\n", "")]
        reason = "[Warning] Slave SQL: Error_code: 1146; table does not exist"
        self.log.return_value = reason + "\n[ERROR] Slave SQL thread aborted\n"
        with self.assertRaises(AssertionError) as caught:
            scenarios.scenario_31_replica_keeps_up_with_the_primary()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual(f"replica1 does not replicate: Slaves_running=0; {reason}", str(caught.exception))
        self.sleep.assert_not_called()

    def test_first_answer_needs_no_sleep_and_still_rejects_a_writable_replica(self):
        self.read_only = "0"
        with self.assertRaises(AssertionError) as caught:
            scenarios.scenario_31_replica_keeps_up_with_the_primary()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertIn("replica1 takes writes (read_only=0)", str(caught.exception))
        self.sleep.assert_not_called()


if __name__ == "__main__":
    unittest.main()

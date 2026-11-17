"""Database fault scenarios and helpers keep quorum, fencing and cleanup checks without a live stand."""

import threading
import traceback
import unittest
from contextlib import ExitStack
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import ANY, Mock, call, patch

import control
import scenarios
from stand import Database, DatabaseVoter, Stand


class ScenarioDatabaseFaultsTest(unittest.TestCase):
    def setUp(self):
        self.contexts = ExitStack()
        self.addCleanup(self.contexts.close)
        self.contexts.enter_context(patch.object(control, "_run",
                                               side_effect=AssertionError("unexpected subprocess")))
        self.contexts.enter_context(patch.object(control, "compose",
                                               side_effect=AssertionError("unexpected compose")))
        self.execute = self.contexts.enter_context(patch.object(control, "execute",
                                                               side_effect=AssertionError("unexpected control command")))
        self.client = self.contexts.enter_context(patch.object(control, "client",
                                                              side_effect=AssertionError("unexpected CLI command")))
        self.contexts.enter_context(patch.object(scenarios, "MASTERS", ["m1", "m2"]))
        self.contexts.enter_context(patch.object(scenarios, "ALL_NODES", ["m1", "m2"]))
        self.now = 0.0
        self.contexts.enter_context(patch.object(scenarios.time, "monotonic", side_effect=lambda: self.now))
        self.sleep = self.contexts.enter_context(patch.object(scenarios.time, "sleep", side_effect=self.advance))
        self.stand = self.make_stand()
        self.contexts.enter_context(patch.object(scenarios, "STAND", self.stand))

    @staticmethod
    def make_stand():
        data = tuple(Database(f"db{index}", f"example-db{index}", "app", "example", "app")
                     for index in (1, 2))
        return Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                     network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                     entry=None, members={"m1": None, "m2": None}, masters=["m1", "m2"], slaves=[],
                     stranger=None, cluster_directory=None, scenarios=[26, 27], slave_work_grace_sec=0,
                     database=data[0], database_members=data, database_proxy="example-proxy",
                     database_voters=(DatabaseVoter("vote1", "example-vote1", "192.0.2.31"),))

    def advance(self, seconds):
        self.now += seconds

    def wait_for(self, event):
        self.assertTrue(event.wait(5), "a test thread did not reach its synchronization point")

    @staticmethod
    def attempt(node, started, ok=True):
        return scenarios.DbWriteAttempt(started, started, node, f"w{started}", ok,
                                        "db2-host" if ok else None, "" if ok else "connection lost")

    def prepare_fault(self):
        self.lost_voters = set()
        self.isolated = set()
        self.sessions = 0
        self.probe_ids = {"m1": 21, "m2": 22}
        self.probe_members = {node: "db2-host" for node in scenarios.ALL_NODES}
        self.contexts.enter_context(patch.object(scenarios, "application_session", side_effect=lambda node, _database: {
            "databaseConnectionId": self.probe_ids[node], "databaseMember": self.probe_members[node]}))
        self.writes = {node: self.attempt(node, 1) for node in scenarios.ALL_NODES}
        self.load = Mock(spec=scenarios.DbLoad)
        self.load.member_of.side_effect = lambda _node: "db2-host" if self.isolated else "db1-host"
        self.load.last_acknowledged.side_effect = self.writes.get
        self.load.attempts.side_effect = lambda node: [self.writes[node]]
        self.contexts.enter_context(patch.object(scenarios, "DbLoad", return_value=self.load))
        self.converge = self.contexts.enter_context(patch.object(scenarios, "wait_converge"))
        self.steady = self.contexts.enter_context(patch.object(scenarios, "wait_db_steady",
                                                            side_effect=self.fault_steady))
        self.sql = self.contexts.enter_context(patch.object(scenarios, "db_sql", side_effect=self.read_fault_sql))
        self.proxy = self.contexts.enter_context(patch.object(scenarios, "proxy_states", side_effect=self.fault_proxy))
        self.refusal = self.contexts.enter_context(patch.object(scenarios, "db_sql_refusal",
                                                             return_value=scenarios.NON_PRIMARY_REFUSAL))
        self.value = self.contexts.enter_context(patch.object(scenarios, "database_value",
                                                           side_effect=lambda _member, node: self.writes[node].value))
        self.started_at = self.contexts.enter_context(patch.object(control, "node_started_at",
                                                                 return_value=control.Outcome(0, "started", "")))
        self.execute.side_effect = self.control_fault

    def control_fault(self, stand, command, service):
        self.assertIs(self.stand, stand)
        if command == "db-voter-kill":
            self.lost_voters.add(service)
        elif command == "db-voter-start":
            self.lost_voters.discard(service)
        elif command == "db-cut":
            self.isolated.add(service)
        elif command == "db-mend":
            self.isolated.discard(service)
        else:
            self.fail(f"unexpected control command: {command}")
        self.advance(1)
        return control.Outcome(0, "", "")

    def read_fault_sql(self, statement, member):
        self.assertIn(member, ("db1", "db2"))
        if statement == scenarios.CLUSTER_STATUS_SQL:
            if member in self.isolated:
                size, component = 1, "non-Primary"
            else:
                size = self.stand.database_votes - len(self.lost_voters) - len(self.isolated)
                component = "Primary"
            return (f"wsrep_cluster_size\t{size}\nwsrep_cluster_status\t{component}\n"
                    "wsrep_local_state_comment\tSynced")
        if statement == "SELECT @@hostname":
            return f"{member}-host"
        if statement.startswith("KILL "):
            connection_id = int(statement.split()[1])
            node = next(node for node, held_id in self.probe_ids.items() if held_id == connection_id)
            self.assertEqual(self.probe_members[node], f"{member}-host")
            self.assertFalse(self.lost_voters)
            self.probe_ids[node] += 10
            self.probe_members[node] = "db1-host" if node == "m1" else "db2-host"
            return ""
        if statement == scenarios.app_connections_sql("app"):
            return str(sum(host == f"{member}-host" for host in self.probe_members.values()))
        if statement == scenarios.SESSIONS_SQL:
            own = "10\tapp\tprobe:4100\tapp\tQuery\t0\tstarting\tSHOW PROCESSLIST\n"
            return own + "11\tapp\tnode:4101\tapp\tSleep\t0\t\tNULL\n" * self.sessions
        self.fail(f"unexpected SQL: {statement}")

    def fault_proxy(self):
        return {member.service: "DOWN" if member.service in self.isolated else "UP"
                for member in self.stand.database_members}

    def fault_steady(self, *_args, **_kwargs):
        if self.isolated:
            self.advance(1)
        return {"m1": 0.5, "m2": 0.75}

    def test_53_keeps_both_data_members_serving_and_returns_the_vote(self):
        self.prepare_fault()
        with patch.object(scenarios, "wait_database_votes", wraps=scenarios.wait_database_votes) as votes:
            detail = scenarios.scenario_53_database_arbitrator_lost_and_back()
        self.assertIn("vote1 lost: db1, db2 kept a primary component of two votes", detail)
        self.assertIn("vote1 back, three votes", detail)
        self.assertEqual([call(self.stand, "db-voter-kill", "vote1"),
                          call(self.stand, "db-voter-start", "vote1")], self.execute.call_args_list)
        self.assertEqual([call(self.load, 0, scenarios.DB_MEMBER_DEATH_TIMEOUT),
                          call(self.load, 1, scenarios.DB_MEMBER_DEATH_TIMEOUT, "vote1", steady=1)],
                         self.steady.call_args_list)
        self.assertEqual(2, votes.call_count)
        self.assertEqual([call(member, node) for member in ("db1", "db2") for node in ("m1", "m2")],
                         self.value.call_args_list)
        self.assertEqual([call(self.stand, node) for _ in range(2) for node in ("m1", "m2")],
                         self.started_at.call_args_list)
        self.assertFalse(self.lost_voters)
        self.load.stop.assert_called()

    def test_53_reopens_probe_sessions_left_on_the_survivor_before_losing_the_vote(self):
        self.prepare_fault()
        scenarios.scenario_53_database_arbitrator_lost_and_back()
        self.assertEqual({"m1": "db1-host", "m2": "db2-host"}, self.probe_members)
        self.assertEqual([call("KILL 21", "db2"), call("KILL 22", "db2")],
                         [entry for entry in self.sql.call_args_list if entry.args[0].startswith("KILL ")])

    def test_53_requires_a_new_probe_connection_before_losing_the_vote(self):
        self.prepare_fault()
        original = self.sql.side_effect
        self.sql.side_effect = lambda statement, member: (
            "" if statement.startswith("KILL ") else original(statement, member))
        with self.assertRaisesRegex(AssertionError, "m1 kept databaseConnectionId=21"):
            scenarios.scenario_53_database_arbitrator_lost_and_back()
        self.execute.assert_not_called()
        self.load.start.assert_not_called()

    def test_53_quorum_and_sync_failures_are_hard_and_report_the_component(self):
        self.prepare_fault()
        for old, new, message in (("Primary", "non-Primary", "db1 left the primary component.*two votes of three"),
                                  ("wsrep_cluster_size\t2", "wsrep_cluster_size\t1", "db1.*'1', 'Primary', 'Synced'"),
                                  ("Synced", "Joining", "db1.*'2', 'Primary', 'Joining'")):
            with self.subTest(state=new):
                self.sql.side_effect = lambda statement, member: (
                    self.read_fault_sql(statement, member).replace(old, new) if self.lost_voters
                    else self.read_fault_sql(statement, member))
                with self.assertRaisesRegex(AssertionError, message) as caught:
                    scenarios.scenario_53_database_arbitrator_lost_and_back()
                self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
                self.assertFalse(self.lost_voters)

    def test_53_rejects_a_proxy_that_removes_a_data_member_with_the_vote(self):
        self.prepare_fault()
        self.proxy.side_effect = lambda: {"db1": "UP", "db2": "DOWN"}
        with self.assertRaisesRegex(AssertionError, "db2 is DOWN.*arbitrator holds no data"):
            scenarios.scenario_53_database_arbitrator_lost_and_back()
        self.assertFalse(self.lost_voters)

    def test_53_requires_an_application_connection_on_each_data_member(self):
        self.prepare_fault()
        self.sql.side_effect = lambda statement, member: (
            "0" if statement == scenarios.app_connections_sql("app") and member == "db2"
            else self.read_fault_sql(statement, member))
        with self.assertRaisesRegex(AssertionError, "db2 holds no connection of app"):
            scenarios.scenario_53_database_arbitrator_lost_and_back()
        self.assertFalse(self.lost_voters)

    def test_53_missing_returned_vote_is_not_retried(self):
        self.prepare_fault()
        votes = scenarios.wait_database_votes
        self.execute.side_effect = lambda stand, command, service: (
            control.Outcome(0, "", "") if command == "db-voter-start" else self.control_fault(stand, command, service))
        with patch.object(scenarios, "wait_database_votes", side_effect=lambda: votes(timeout=0)), \
                patch.object(scenarios, "SCENARIO_RETRIES", 2):
            with self.assertRaisesRegex(AssertionError, "database voters vote1 did not join") as caught:
                scenarios.run_scenario("53 database arbitrator lost and back",
                                       scenarios.scenario_53_database_arbitrator_lost_and_back)
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.load.start.assert_called_once_with()
        self.converge.assert_called_once_with(["m1", "m2"])

    def test_53_unconfirmed_return_restores_and_waits_without_replacing_the_first_failure(self):
        self.prepare_fault()
        for cleanup_fails in (False, True):
            with self.subTest(cleanup_fails=cleanup_fails):
                original = AssertionError("returned voter is missing")
                cleanup_error = RuntimeError("recovery votes unavailable")
                votes_impl = scenarios.wait_database_votes
                checks = iter((None, original, cleanup_error if cleanup_fails else None))

                def votes():
                    error = next(checks)
                    if error is not None:
                        raise error
                    votes_impl()

                self.execute.reset_mock()
                with patch.object(scenarios, "wait_database_votes", side_effect=votes) as wait, \
                        patch.object(scenarios, "restore_stand", wraps=scenarios.restore_stand) as restore, \
                        patch("builtins.print") as printed:
                    with self.assertRaises(AssertionError) as caught:
                        scenarios.scenario_53_database_arbitrator_lost_and_back()
                self.assertIs(original, caught.exception)
                self.assertEqual([call(self.stand, "db-voter-kill", "vote1"),
                                  call(self.stand, "db-voter-start", "vote1"),
                                  call(self.stand, "db-voter-start", "vote1")], self.execute.call_args_list)
                self.assertEqual(3, wait.call_count)
                self.assertIn(call(True, "scenario 53", ANY), restore.call_args_list)
                if cleanup_fails:
                    printed.assert_called_once_with("    scenario 53 recovery failed: recovery votes unavailable",
                                                    flush=True)
                else:
                    printed.assert_not_called()

    def test_53_failure_while_the_vote_is_down_restores_it_without_hiding_the_error(self):
        self.prepare_fault()
        original = AssertionError("probe failed")
        self.steady.side_effect = [{"m1": 0, "m2": 0}, original]
        self.execute.side_effect = lambda stand, command, service: (
            control.Outcome(1, "", "start refused") if command == "db-voter-start"
            else self.control_fault(stand, command, service))
        with patch("builtins.print") as printed:
            with self.assertRaises(AssertionError) as caught:
                scenarios.scenario_53_database_arbitrator_lost_and_back()
        self.assertIs(original, caught.exception)
        self.assertEqual(call(self.stand, "db-voter-start", "vote1"), self.execute.call_args_list[-1])
        printed.assert_called_once_with("    scenario 53 recovery failed: could not db-voter-start vote1: start refused",
                                        flush=True)

    def test_54_fences_the_live_minority_and_mends_it_before_checking_the_copy(self):
        self.prepare_fault()
        with patch.object(scenarios, "member_back", wraps=scenarios.member_back) as back:
            detail = scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertIn("db1 cut off from db2 and the arbitrator", detail)
        self.assertIn("refusing reads and writes", detail)
        self.assertIn("m1, m2 wrote again through db2", detail)
        self.assertEqual([call(self.stand, "db-cut", "db1"), call(self.stand, "db-mend", "db1")],
                         self.execute.call_args_list)
        self.assertEqual([call(scenarios.FENCE_READ_SQL, "db1"), call(scenarios.FENCE_WRITE_SQL, "db1")],
                         self.refusal.call_args_list)
        self.assertEqual([call(self.load, 0, scenarios.DB_MEMBER_DEATH_TIMEOUT),
                          call(self.load, 1, scenarios.DB_MEMBER_DEATH_TIMEOUT, "db1")],
                         self.steady.call_args_list)
        back.assert_called_once_with("db1", 3, scenarios.DB_MEMBER_RETURN_TIMEOUT, 3)
        self.assertEqual([call(member, node) for member in ("db1", "db2") for node in ("m1", "m2")],
                         self.value.call_args_list)
        self.assertFalse(self.isolated)

    def test_54_accepts_the_proxy_agent_down_status_and_still_requires_no_sessions(self):
        self.prepare_fault()
        self.proxy.side_effect = lambda: {
            member: "DOWN (agent)" if member in self.isolated else "UP" for member in ("db1", "db2")}
        scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.sessions = 1
        with self.assertRaisesRegex(AssertionError, r"1 application session\(s\) remain on db1.*never reconnect"):
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertFalse(self.isolated)

    def test_54_primary_minority_is_a_hard_failure_at_the_ceiling(self):
        self.prepare_fault()
        self.sql.side_effect = lambda statement, member: self.read_fault_sql(statement, member).replace("non-Primary", "Primary")
        with self.assertRaisesRegex(AssertionError, "two primary components") as caught:
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertGreaterEqual(self.now, scenarios.DB_MEMBER_DEATH_TIMEOUT)
        self.assertFalse(self.isolated)

    def test_54_requires_the_minority_to_be_down_at_the_proxy(self):
        self.prepare_fault()
        self.proxy.side_effect = None
        self.proxy.return_value = {"db1": "UP", "db2": "UP"}
        with self.assertRaisesRegex(AssertionError, "db1 is out of the primary component yet UP at the proxy"):
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertFalse(self.isolated)

    def test_54_requires_both_statements_to_refuse_with_the_non_primary_error(self):
        self.prepare_fault()
        for statement in (scenarios.FENCE_READ_SQL, scenarios.FENCE_WRITE_SQL):
            for answer in (None, "connection lost"):
                with self.subTest(statement=statement, answer=answer):
                    self.refusal.side_effect = lambda sql, _member: answer if sql == statement else scenarios.NON_PRIMARY_REFUSAL
                    with self.assertRaises(AssertionError) as caught:
                        scenarios.scenario_54_database_member_cut_off_is_fenced()
                    self.assertIn(f"answered {statement!r} out of the primary component", str(caught.exception))
                    self.assertFalse(self.isolated)

    def test_54_rejects_a_held_application_session_after_proxy_removal(self):
        self.prepare_fault()
        self.sessions = 1
        with self.assertRaisesRegex(AssertionError, r"1 application session\(s\) remain on db1.*never reconnect"):
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertFalse(self.isolated)

    def test_54_rejects_a_confirmed_probe_through_the_isolated_member(self):
        self.prepare_fault()
        minority = scenarios.DbWriteAttempt(1, 1.5, "m2", "minority", True, "db1-host", "")
        self.load.attempts.side_effect = lambda node: [minority, self.writes[node]] if node == "m2" else [self.writes[node]]
        with self.assertRaisesRegex(AssertionError, "m2 wrote minority through db1.*minority took an operation") as caught:
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        for detail in ("cut_at=1.000000", "mend_started_at=2.000000", "mended_at=3.000000",
                       "started=1.000000", "finished=1.500000", "node=m2", "value=minority",
                       "member=db1-host", "ok=True"):
            self.assertIn(detail, str(caught.exception))
        self.assertFalse(self.isolated)

    def test_54_does_not_blame_writes_begun_before_the_cut_or_after_mending(self):
        self.prepare_fault()
        self.load.attempts.side_effect = lambda node: [
            scenarios.DbWriteAttempt(start, 3, node, "outside", True, "db1-host", "") for start in (0, 3)]
        scenarios.scenario_54_database_member_cut_off_is_fenced()

    def test_54_does_not_blame_a_probe_begun_under_cut_and_acknowledged_after_healing(self):
        self.prepare_fault()
        spanning = scenarios.DbWriteAttempt(1.5, 3, "m2", "spanning", True, "db1-host", "")
        self.load.attempts.side_effect = lambda node: [spanning] if node == "m2" else [self.writes[node]]
        scenarios.scenario_54_database_member_cut_off_is_fenced()

    def test_54_does_not_blame_a_probe_completed_after_routes_lift_before_mend_returns(self):
        self.prepare_fault()

        def execute(stand, command, service):
            if command != "db-mend":
                return self.control_fault(stand, command, service)
            self.assertEqual({"db1"}, self.isolated)
            self.isolated.remove(service)
            self.advance(0.25)
            started = self.now
            self.advance(0.5)
            returned = scenarios.DbWriteAttempt(started, self.now, "m2", "returned", True, "db1-host", "")
            self.load.attempts.side_effect = lambda node: [returned] if node == "m2" else [self.writes[node]]
            self.advance(0.25)
            return control.Outcome(0, "", "")

        self.execute.side_effect = execute
        scenarios.scenario_54_database_member_cut_off_is_fenced()

    def test_54_rejects_a_probe_acknowledged_at_the_start_of_mending(self):
        self.prepare_fault()
        minority = scenarios.DbWriteAttempt(1.5, 2, "m2", "minority", True, "db1-host", "")
        self.load.attempts.side_effect = lambda node: [minority] if node == "m2" else [self.writes[node]]
        with self.assertRaisesRegex(AssertionError, "minority took an operation"):
            scenarios.scenario_54_database_member_cut_off_is_fenced()

    def test_54_rejects_a_restarted_node_during_the_cut(self):
        self.prepare_fault()
        self.started_at.side_effect = [control.Outcome(0, "started", ""), control.Outcome(0, "started", ""),
                                       control.Outcome(0, "restarted", "")]
        with self.assertRaisesRegex(AssertionError, "m1 was restarted while db1 was cut off"):
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertFalse(self.isolated)

    def test_54_rejects_a_returned_member_missing_the_last_acknowledged_write(self):
        self.prepare_fault()
        self.value.side_effect = lambda member, node: "stale" if member == "db1" else self.writes[node].value
        with self.assertRaisesRegex(AssertionError, "db1 holds stale for m1, which last wrote w1"):
            scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertFalse(self.isolated)

    def test_54_failure_during_the_cut_mends_it_without_hiding_the_error(self):
        self.prepare_fault()
        original = AssertionError("probe failed")
        self.steady.side_effect = [{"m1": 0, "m2": 0}, original]
        self.execute.side_effect = lambda stand, command, service: (
            control.Outcome(1, "", "mend refused") if command == "db-mend"
            else self.control_fault(stand, command, service))
        with patch("builtins.print") as printed:
            with self.assertRaises(AssertionError) as caught:
                scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertIs(original, caught.exception)
        self.assertEqual(call(self.stand, "db-mend", "db1"), self.execute.call_args_list[-1])
        printed.assert_called_once_with("    scenario 54 recovery failed: could not db-mend ('db1',): mend refused",
                                        flush=True)

    def test_54_unconfirmed_return_mends_and_waits_without_replacing_the_first_failure(self):
        self.prepare_fault()
        for cleanup_fails in (False, True):
            with self.subTest(cleanup_fails=cleanup_fails):
                original = scenarios.ScenarioTimeout("returned member is not Synced")
                cleanup_error = RuntimeError("recovery member unavailable")
                back_impl = scenarios.member_back
                self.execute.reset_mock()
                self.load.stop.reset_mock()
                with patch.object(scenarios, "member_back") as wait, \
                        patch.object(scenarios, "restore_stand", wraps=scenarios.restore_stand) as restore, \
                        patch("builtins.print") as printed:
                    def back(*args):
                        if wait.call_count == 1:
                            self.load.stop.assert_not_called()
                            raise original
                        if cleanup_fails:
                            raise cleanup_error
                        return back_impl(*args)

                    wait.side_effect = back
                    with self.assertRaises(scenarios.ScenarioTimeout) as caught:
                        scenarios.scenario_54_database_member_cut_off_is_fenced()
                self.assertIs(original, caught.exception)
                self.assertEqual([call(self.stand, "db-cut", "db1"), call(self.stand, "db-mend", "db1"),
                                  call(self.stand, "db-mend", "db1")], self.execute.call_args_list)
                self.assertEqual(2, wait.call_count)
                self.assertEqual(call("db1", 3, scenarios.DB_MEMBER_RETURN_TIMEOUT), wait.call_args_list[-1])
                self.assertIn(call(True, "scenario 54", ANY), restore.call_args_list)
                if cleanup_fails:
                    printed.assert_called_once_with("    scenario 54 recovery failed: recovery member unavailable",
                                                    flush=True)
                else:
                    printed.assert_not_called()

    def test_54_partial_failed_cut_still_mends_and_preserves_its_stderr_when_cleanup_fails(self):
        self.prepare_fault()

        def execute(stand, command, service):
            if command == "db-cut":
                self.control_fault(stand, command, service)
                return control.Outcome(1, "partial cut", "second route refused")
            self.assertEqual("db-mend", command)
            return control.Outcome(1, "", "mend refused")

        self.execute.side_effect = execute
        with patch("builtins.print") as printed:
            with self.assertRaisesRegex(AssertionError, "could not db-cut.*second route refused") as caught:
                scenarios.scenario_54_database_member_cut_off_is_fenced()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual([call(self.stand, "db-cut", "db1"), call(self.stand, "db-mend", "db1")],
                         self.execute.call_args_list)
        printed.assert_called_once_with("    scenario 54 recovery failed: could not db-mend ('db1',): mend refused",
                                        flush=True)

    def test_fault_scenario_guards_distinguish_voters_from_data_and_replicas(self):
        selected = [next(item for item in scenarios.SCENARIOS if item.number == number) for number in (53, 54)]
        data = self.stand.database_members
        third = replace(data[0], service="db3", container="example-db3")
        three_data = replace(self.stand, database_members=data + (third,), database_voters=())
        replica = replace(data[1], replica_of="db1")
        with_replica = replace(self.stand, database_members=(data[0], replica), database_voters=())
        cases = ((self.stand, [None, None]),
                 (three_data, ["it needs 1 database voters, the stand has 0", None]),
                 (with_replica, ["it needs 1 database voters, the stand has 0",
                                 "it needs 3 database votes, the stand has 1"]))
        for stand, expected in cases:
            with self.subTest(votes=stand.database_votes):
                self.assertEqual(expected, [scenarios.unmet_need(stand, item) for item in selected])
        self.assertEqual("database_voters", scenarios.Need._fields[-1])
        self.assertEqual(0, scenarios.Need().database_voters)
        self.assertEqual([27, 28, 53, 54], [item.number for item in scenarios.SCENARIOS
                                         if item.number in (27, 28, 53, 54)])

    def test_control_wrappers_keep_the_command_targets_and_failure_stream(self):
        wrappers = ((scenarios.db_voter_kill, "db-voter-kill", ("vote1",)),
                    (scenarios.db_voter_start, "db-voter-start", ("vote1",)),
                    (scenarios.db_cut, "db-cut", ("db1", "vote1")),
                    (scenarios.db_mend, "db-mend", ("db1", "vote1")))
        self.execute.side_effect = None
        for wrapper, command, targets in wrappers:
            for outcome in (control.Outcome(0, "ok", ""), control.Outcome(1, "less detail", "control refused"),
                            control.Outcome(2, "no stderr", "")):
                with self.subTest(command=command, outcome=outcome):
                    self.execute.reset_mock()
                    self.execute.return_value = outcome
                    if outcome.code == 0:
                        wrapper(*targets)
                    else:
                        with self.assertRaises(AssertionError) as caught:
                            wrapper(*targets)
                        self.assertIn(command, str(caught.exception))
                        for target in targets:
                            self.assertIn(target, str(caught.exception))
                        self.assertIn(outcome.err or outcome.out, str(caught.exception))
                    self.execute.assert_called_once_with(self.stand, command, *targets)

    def test_sql_refusal_distinguishes_an_answer_from_both_failure_streams(self):
        self.execute.side_effect = None
        for outcome, expected in ((control.Outcome(0, "", ""), None),
                                  (control.Outcome(0, "0", ""), None),
                                  (control.Outcome(1, "fallback", "1047: outside Primary"), "1047: outside Primary"),
                                  (control.Outcome(1, "connection lost", ""), "connection lost")):
            with self.subTest(outcome=outcome):
                self.execute.reset_mock()
                self.execute.return_value = outcome
                self.assertEqual(expected, scenarios.db_sql_refusal(scenarios.FENCE_READ_SQL, "db2"))
                self.execute.assert_called_once_with(self.stand, "db-sql", scenarios.FENCE_READ_SQL, "db2")

    def test_sessions_count_held_connections_but_exclude_their_own_show(self):
        rows = ("10\tapp\tnode1:4100\tapp\tSleep\t2\t\tNULL\n"
                "11\tapp\tnode2:4101\tapp-change-log\tQuery\t0\tactive\tSELECT 1\n"
                "12\tother\tnode3:4102\tapp\tSleep\t1\t\tNULL\n"
                "13\tapp\tprobe:4103\tapp\tQuery\t0\tstarting\tSHOW PROCESSLIST\t0.0\n")
        with patch.object(scenarios, "db_sql", return_value=rows) as sql:
            self.assertEqual(2, scenarios.app_sessions_on("db2", "app"))
        sql.assert_called_once_with("SHOW PROCESSLIST", "db2")

    def test_sessions_distinguish_no_application_sessions_from_no_answer(self):
        with patch.object(scenarios, "db_sql", return_value=
                          "13\tapp\tprobe:4103\tapp\tQuery\t0\tstarting\tSHOW PROCESSLIST\n"):
            self.assertEqual(0, scenarios.app_sessions_on("db2", "app"))
        with patch.object(scenarios, "db_sql", return_value=""):
            with self.assertRaisesRegex(AssertionError, "db2 did not answer SHOW PROCESSLIST"):
                scenarios.app_sessions_on("db2", "app")

    def test_database_value_reads_the_probe_table_on_the_requested_member(self):
        with patch.object(scenarios, "db_sql", return_value="w7") as sql:
            self.assertEqual("w7", scenarios.database_value("db2", "m1"))
        sql.assert_called_once_with("SELECT `value` FROM `hilos_probe_value` WHERE `key` = 'cluster_db_load_m1'", "db2")

    def prepare_replica_fault(self, contexts):
        data = self.make_stand().database_members
        self.stand = replace(self.make_stand(), database_members=(data[0], replace(data[1], replica_of="db1")),
                             database_voters=(), database_proxy=None)
        contexts.enter_context(patch.object(scenarios, "STAND", self.stand))
        contexts.enter_context(patch.object(scenarios, "wait_converge"))
        contexts.enter_context(patch.object(control, "node_started_at",
                                               return_value=control.Outcome(0, "started", "")))
        self.replica_answers = contexts.enter_context(patch.object(scenarios, "wait_replica_answers"))
        contexts.enter_context(patch.object(scenarios, "db_sql", return_value="0"))
        self.kill = contexts.enter_context(patch.object(scenarios, "db_kill"))
        self.start = contexts.enter_context(patch.object(scenarios, "db_start"))
        self.oracle = Mock(spec=scenarios.JournalOracle)
        self.oracle.node_id = "m1"
        self.oracle.write_batch.side_effect = [SimpleNamespace(outcome=outcome, detail="batch outcome") for outcome in (
            scenarios.JOURNAL_CONFIRMED, scenarios.JOURNAL_REFUSED, scenarios.JOURNAL_CONFIRMED,
            scenarios.JOURNAL_CONFIRMED, scenarios.JOURNAL_CONFIRMED)]
        self.oracle.verify.return_value = "journal proof"
        contexts.enter_context(patch.object(scenarios, "JournalOracle", return_value=self.oracle))
        self.execute.side_effect = [control.Outcome(1, "", "primary down"), control.Outcome(0, "1", "")]

    def test_55_finishes_the_shared_journal_without_starting_background_load(self):
        self.prepare_replica_fault(self.contexts)
        with patch.object(scenarios.threading, "Thread", side_effect=AssertionError("unexpected background load")):
            result = scenarios.scenario_55_primary_and_replica_lost_and_restored()
        self.assertIn("journal proof", result)
        self.assertEqual(5, self.oracle.write_batch.call_count)
        self.oracle.verify.assert_called_once_with(self.oracle.batches.return_value)
        self.assertEqual([call("db1"), call("db2")], self.kill.call_args_list)
        self.assertEqual([call("db1"), call("db2")], self.start.call_args_list)
        self.sleep.assert_not_called()

    def test_55_rejects_a_failed_final_batch_before_verifying(self):
        self.prepare_replica_fault(self.contexts)
        self.oracle.write_batch.side_effect = [SimpleNamespace(outcome=outcome, detail="batch outcome") for outcome in (
            scenarios.JOURNAL_CONFIRMED, scenarios.JOURNAL_REFUSED, scenarios.JOURNAL_CONFIRMED,
            scenarios.JOURNAL_CONFIRMED, scenarios.JOURNAL_AMBIGUOUS)]
        with self.assertRaisesRegex(AssertionError, "last journal batch.*ambiguous"):
            scenarios.scenario_55_primary_and_replica_lost_and_restored()
        self.oracle.verify.assert_not_called()
        self.assertEqual([call("db1"), call("db2")], self.start.call_args_list)

    def test_55_unconfirmed_return_retries_cleanup_and_keeps_the_original_error(self):
        for service in ("db1", "db2"):
            for cleanup_fails in (False, True):
                with self.subTest(service=service, cleanup_fails=cleanup_fails), ExitStack() as contexts:
                    self.prepare_replica_fault(contexts)
                    self.execute.reset_mock()
                    original = OSError("returned server is unavailable")
                    cleanup_error = RuntimeError("recovery unavailable") if cleanup_fails else None
                    if service == "db1":
                        self.execute.side_effect = [control.Outcome(1, "", "primary down"), original,
                                                    cleanup_error or control.Outcome(0, "1", "")]
                        self.replica_answers.side_effect = [None]
                        expected_starts = [call("db1"), call("db1")]
                    else:
                        self.replica_answers.side_effect = [None, original, cleanup_error]
                        expected_starts = [call("db1"), call("db2"), call("db2")]
                    with patch("builtins.print") as printed:
                        with self.assertRaises(OSError) as caught:
                            scenarios.scenario_55_primary_and_replica_lost_and_restored()
                    self.assertIs(original, caught.exception)
                    self.assertEqual(expected_starts, self.start.call_args_list)
                    self.assertEqual(1 if service == "db1" else 3, self.replica_answers.call_count)
                    if service == "db1":
                        self.assertEqual([call(self.stand, "db-sql", "SELECT 1", "db1")] * 3,
                                         self.execute.call_args_list)
                    self.oracle.verify.assert_not_called()
                    if cleanup_fails:
                        label = "primary" if service == "db1" else "replica"
                        printed.assert_called_once_with(f"    scenario 55 {label} recovery failed: recovery unavailable",
                                                        flush=True)
                    else:
                        printed.assert_not_called()

    def prepare_primary_recovery(self, cleanup_returns):
        start = scenarios.db_start
        replica_answers = scenarios.wait_replica_answers
        self.prepare_replica_fault(self.contexts)
        self.start.side_effect = start
        self.replica_answers.side_effect = replica_answers
        original = OSError("returned primary is unavailable")
        events = []

        def execute(stand, command, *args):
            self.assertIs(self.stand, stand)
            events.append((self.now, command, *args))
            if command == "db-start":
                self.assertEqual(("db1",), args)
                return control.Outcome(0, "started", "")
            self.assertEqual("db-sql", command)
            statement, member = args
            if member == "db2":
                self.assertEqual(scenarios.REPLICA_RUNNING_SQL, statement)
                return control.Outcome(0, "Slaves_running\t0\n", "")
            self.assertEqual("db1", member)
            self.assertEqual("SELECT 1", statement)
            starts = sum(event[1] == "db-start" for event in events)
            if starts == 1:
                raise original
            if starts == 2 and cleanup_returns and self.now >= scenarios.POLL_INTERVAL:
                return control.Outcome(0, "1", "")
            return control.Outcome(1, "", "primary down")

        self.execute.side_effect = execute
        return original, events

    def test_55_primary_cleanup_waits_for_primary_sql_while_the_replica_already_answers(self):
        original, events = self.prepare_primary_recovery(cleanup_returns=True)
        with patch("builtins.print") as printed:
            with self.assertRaises(OSError) as caught:
                scenarios.scenario_55_primary_and_replica_lost_and_restored()
        self.assertIs(original, caught.exception)
        self.assertEqual("returned primary is unavailable", str(caught.exception))
        self.assertEqual([call("db1"), call("db1")], self.start.call_args_list)
        self.assertEqual([
            (0.0, "db-sql", scenarios.REPLICA_RUNNING_SQL, "db2"),
            (0.0, "db-sql", "SELECT 1", "db1"),
            (0.0, "db-start", "db1"),
            (0.0, "db-sql", "SELECT 1", "db1"),
            (0.0, "db-start", "db1"),
            (0.0, "db-sql", "SELECT 1", "db1"),
            (scenarios.POLL_INTERVAL, "db-sql", "SELECT 1", "db1"),
        ], events)
        self.sleep.assert_called_once_with(scenarios.POLL_INTERVAL)
        self.oracle.verify.assert_not_called()
        printed.assert_not_called()

    def test_55_primary_cleanup_timeout_reports_recovery_and_preserves_the_first_error(self):
        self.contexts.enter_context(patch.object(
            scenarios, "DB_MEMBER_RETURN_TIMEOUT", 3 * scenarios.POLL_INTERVAL))
        original, events = self.prepare_primary_recovery(cleanup_returns=False)
        with patch("builtins.print") as printed:
            with self.assertRaises(OSError) as caught:
                scenarios.scenario_55_primary_and_replica_lost_and_restored()
        self.assertIs(original, caught.exception)
        self.assertEqual("returned primary is unavailable", str(caught.exception))
        self.assertEqual([call("db1"), call("db1")], self.start.call_args_list)
        self.assertEqual(scenarios.DB_MEMBER_RETURN_TIMEOUT, self.now)
        self.assertEqual((self.now, "db-sql", "SELECT 1", "db1"), events[-1])
        self.assertEqual([call(scenarios.POLL_INTERVAL)] * self.sleep.call_count, self.sleep.call_args_list)
        self.oracle.verify.assert_not_called()
        printed.assert_called_once_with(
            f"    scenario 55 primary recovery failed: db1 did not return within "
            f"{scenarios.DB_MEMBER_RETURN_TIMEOUT:.0f}s", flush=True)

    def test_steady_threshold_keeps_the_default_and_filters_before_since(self):
        load = Mock(spec=scenarios.DbLoad)
        load.attempts.side_effect = lambda node: [self.attempt(node, index) for index in (0, 1, 2)]
        self.assertEqual({"m1": 2, "m2": 2}, scenarios.wait_db_steady(load, 0, 0))
        self.assertEqual({"m1": 1, "m2": 1}, scenarios.wait_db_steady(load, 1, 0, steady=1))
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "m1 did not produce 3 steady writes"):
            scenarios.wait_db_steady(load, 1, 0)
        self.sleep.assert_not_called()

    def test_steady_requires_consecutive_successes_and_names_the_requested_threshold(self):
        load = Mock(spec=scenarios.DbLoad)
        load.attempts.side_effect = lambda node: [self.attempt(node, 0), self.attempt(node, 1, False)]
        with self.assertRaisesRegex(scenarios.ScenarioTimeout, "m1 did not produce 1 steady writes.*connection lost"):
            scenarios.wait_db_steady(load, 0, 0, steady=1)
        self.sleep.assert_not_called()

    def test_journal_load_serializes_calls_and_stop_joins_the_in_flight_batch(self):
        oracle = Mock()
        pairs = (("example_string", "journal-first"),), (("example_string", "journal-second"),)
        oracle.next_pairs.side_effect = pairs
        load = scenarios.JournalLoad(oracle)
        entered = [threading.Event(), threading.Event()]
        release = [threading.Event(), threading.Event()]
        requested_stop = threading.Event()
        stopped = threading.Event()
        calls = []
        order = []
        errors = []

        def write_batch(batch):
            index = len(calls)
            calls.append((batch, "begun"))
            order.append(("begin", batch))
            entered[index].set()
            self.wait_for(release[index])
            calls[index] = (batch, "finished")
            order.append(("end", batch))

        def stop():
            try:
                load.stop()
            except Exception as error:
                errors.append(error)
            finally:
                stopped.set()

        real_set = load._stop.set

        def request_stop():
            real_set()
            requested_stop.set()

        def cleanup():
            real_set()
            for gate in release:
                gate.set()
            load.stop()

        self.addCleanup(cleanup)
        oracle.write_batch.side_effect = write_batch
        with patch.object(load._stop, "wait", return_value=False) as interval, \
                patch.object(load._stop, "set", side_effect=request_stop):
            load.start()
            self.wait_for(entered[0])
            self.assertEqual([(pairs[0], "begun")], calls)
            oracle.next_pairs.assert_called_once_with()
            release[0].set()
            self.wait_for(entered[1])
            self.assertEqual([(pairs[0], "finished"), (pairs[1], "begun")], calls)
            stopper = threading.Thread(target=stop, daemon=True)
            stopper.start()
            self.wait_for(requested_stop)
            self.assertFalse(stopped.is_set())
            release[1].set()
            self.wait_for(stopped)
            stopper.join()
            load.stop()
            self.assertEqual([call(1.0), call(1.0)], interval.call_args_list)
        self.assertEqual([], errors)
        self.assertFalse(load._thread.is_alive())
        self.assertEqual([call(pairs[0]), call(pairs[1])], oracle.write_batch.call_args_list)
        self.assertEqual([("begin", pairs[0]), ("end", pairs[0]), ("begin", pairs[1]), ("end", pairs[1])], order)

    def test_journal_stop_is_safe_before_start(self):
        load = scenarios.JournalLoad(Mock())
        load.stop()
        load.stop()

    def test_journal_writer_exception_reaches_stop_and_finish_before_a_later_successful_write(self):
        for finish, error_type in ((False, OSError), (True, OSError), (False, SystemExit), (True, SystemExit)):
            with self.subTest(finish=finish, error_type=error_type):
                oracle = scenarios.JournalOracle()
                oracle.label, oracle.integer_seed = "failed-load", 1
                entered = threading.Event()
                original = error_type("journal CLI could not start")

                def write(_stand, _node, _command, *args):
                    if not entered.is_set():
                        entered.set()
                        raise original
                    out = "\n".join(scenarios.JOURNAL_WROTE_LINE.format(key=key, value=value) + "1"
                                    for key, value in (arg.split("=", 1) for arg in args))
                    return control.Outcome(0, out, "")

                self.client.reset_mock()
                self.client.side_effect = write
                load = scenarios.JournalLoad(oracle)
                with patch("threading.excepthook") as thread_error, \
                        patch.object(oracle, "settle") as settle, patch.object(oracle, "verify") as verify:
                    load.start()
                    self.wait_for(entered)
                    try:
                        if finish:
                            scenarios.finish_journal(oracle, load)
                        else:
                            load.stop()
                    except BaseException as error:
                        self.assertIs(original, error)
                        self.assertIn("write", [frame.name for frame in traceback.extract_tb(error.__traceback__)])
                    else:
                        self.fail("a failed journal writer was accepted")
                    self.assertFalse(load._thread.is_alive())
                    thread_error.assert_not_called()
                    self.client.assert_called_once()
                    self.assertEqual([], oracle.batches())
                    settle.assert_not_called()
                    verify.assert_not_called()
                    with self.assertRaises(error_type) as caught:
                        load.stop()
                    self.assertIs(original, caught.exception)
                # A later write really can confirm; it cannot erase the earlier writer failure.
                self.assertEqual(scenarios.JOURNAL_CONFIRMED, oracle.write_batch(oracle.next_pairs()).outcome)

    def test_finish_journal_confirms_the_last_batch_before_settling_and_verifying(self):
        steps = Mock()
        oracle, load = steps.oracle, steps.load
        pairs = (("example_string", "journal-last"),)
        oracle.next_pairs.return_value = pairs
        oracle.write_batch.return_value = SimpleNamespace(outcome=scenarios.JOURNAL_CONFIRMED)
        oracle.batches.return_value = ["all batches"]
        oracle.verify.return_value = "journal proof"
        self.assertEqual("journal proof", scenarios.finish_journal(oracle, load))
        self.assertEqual([call.load.stop(), call.oracle.next_pairs(), call.oracle.write_batch(pairs),
                          call.oracle.settle(), call.oracle.batches(), call.oracle.verify(["all batches"])],
                         steps.mock_calls)

    def test_finish_journal_rejects_a_refused_or_ambiguous_last_batch(self):
        for outcome in (scenarios.JOURNAL_REFUSED, scenarios.JOURNAL_AMBIGUOUS):
            with self.subTest(outcome=outcome):
                oracle, load = Mock(), Mock()
                oracle.write_batch.return_value = SimpleNamespace(outcome=outcome, detail="last call failed")
                with self.assertRaisesRegex(AssertionError, f"last journal batch.*{outcome}: last call failed"):
                    scenarios.finish_journal(oracle, load)
                load.stop.assert_called_once_with()
                oracle.settle.assert_not_called()
                oracle.verify.assert_not_called()

    def test_recovery_keeps_the_original_failure_but_raises_its_own_if_the_scenario_passed(self):
        error = RuntimeError("cannot restore")
        action = Mock(side_effect=error)
        with patch("builtins.print") as printed:
            scenarios.restore_stand(True, "database fault", action)
        action.assert_called_once_with()
        printed.assert_called_once_with("    database fault recovery failed: cannot restore", flush=True)
        with self.assertRaises(RuntimeError) as caught:
            scenarios.restore_stand(False, "database fault", action)
        self.assertIs(error, caught.exception)
        action = Mock()
        scenarios.restore_stand(False, "database fault", action)
        action.assert_called_once_with()

    def test_db_load_retains_failed_attempts_and_unique_values_at_the_existing_interval(self):
        load = scenarios.DbLoad()
        self.client.side_effect = (control.Outcome(0, "Wrote cluster_db_load_m1=w1 via db1-host\n", ""),
                                   control.Outcome(1, "", "connection lost\n"),
                                   control.Outcome(0, "Wrote cluster_db_load_m1=w3 via db2-host\n", ""))

        def interval(_seconds):
            if len(load.attempts("m1")) == 3:
                load._stop.set()

        with patch.object(load._stop, "wait", side_effect=interval) as waited:
            load._write("m1")
        self.assertEqual(["w1", "w2", "w3"], [attempt.value for attempt in load.attempts("m1")])
        self.assertEqual([True, False, True], [attempt.ok for attempt in load.attempts("m1")])
        self.assertEqual("connection lost", load.failures_since(0)[0].error)
        self.assertEqual("db2-host", load.member_of("m1"))
        self.assertEqual([call(self.stand, "m1", "test:cluster:db:write", "cluster_db_load_m1", f"w{index}")
                          for index in (1, 2, 3)], self.client.call_args_list)
        self.assertEqual([call(0.5)] * 3, waited.call_args_list)

    def test_ack_check_rejects_missing_or_different_values_and_releases_the_writer(self):
        load = scenarios.DbLoad()
        with patch.object(scenarios, "database_value", return_value="stale") as read:
            with self.assertRaisesRegex(AssertionError, "m1 acknowledged no database write"):
                load.assert_last_acknowledged("db2", "m1")
            read.assert_not_called()
            load._attempts["m1"].append(self.attempt("m1", 1))
            with self.assertRaisesRegex(AssertionError, "db2 holds stale for m1, which last wrote w1"):
                load.assert_last_acknowledged("db2", "m1")
        self.assertTrue(load._writer_locks["m1"].acquire(blocking=False))
        load._writer_locks["m1"].release()

    def test_ack_check_waits_for_the_in_flight_write_and_holds_only_its_node_through_select(self):
        load = scenarios.DbLoad()
        in_flight, release_ack = threading.Event(), threading.Event()
        checking, selected, release_select = threading.Event(), threading.Event(), threading.Event()
        next_m1, next_m2, finish = threading.Event(), threading.Event(), threading.Event()
        other_written, writer_queued = threading.Event(), threading.Event()
        third_recorded, checked = threading.Event(), threading.Event()
        values, writers, intervals = {}, {}, {"m1": 0, "m2": 0}
        results, errors = [], []

        class ObservedLock:
            def __init__(self, lock):
                self.lock = lock
                self.writes = 0

            def __enter__(self):
                if threading.current_thread().name == "ack-check":
                    checking.set()
                else:
                    self.writes += 1
                    if self.writes == 3:
                        writer_queued.set()
                self.lock.acquire()

            def __exit__(self, *_error):
                self.lock.release()

        load._writer_locks["m1"] = ObservedLock(load._writer_locks["m1"])

        def write(_stand, node, _command, key, value):
            writers[threading.get_ident()] = node
            values[node] = value
            if node == "m1" and value == "w2":
                in_flight.set()
                self.wait_for(release_ack)
            return control.Outcome(0, f"Wrote {key}={value} via db2-host\n", "")

        def interval(_seconds):
            node = writers[threading.get_ident()]
            intervals[node] += 1
            if node == "m1" and intervals[node] == 1:
                return
            if node == "m1" and intervals[node] == 2:
                self.wait_for(next_m1)
            elif node == "m2" and intervals[node] == 1:
                self.wait_for(next_m2)
            else:
                if node == "m1":
                    third_recorded.set()
                else:
                    other_written.set()
                self.wait_for(finish)

        def read(member, node):
            self.assertEqual(("db2", "m1"), (member, node))
            selected.set()
            self.wait_for(release_select)
            return values[node]

        def check():
            try:
                results.append(load.assert_last_acknowledged("db2", "m1"))
            except Exception as error:
                errors.append(error)
            finally:
                checked.set()

        checker = threading.Thread(target=check, name="ack-check", daemon=True)

        def cleanup():
            load._stop.set()
            for gate in (release_ack, release_select, next_m1, next_m2, finish):
                gate.set()
            load.stop()
            if checker.ident is not None:
                checker.join()

        self.addCleanup(cleanup)
        self.client.side_effect = write
        with patch.object(load._stop, "wait", side_effect=interval), \
                patch.object(scenarios, "database_value", side_effect=read) as sql:
            load.start()
            self.wait_for(in_flight)
            self.assertEqual("w1", load.last_acknowledged("m1").value)
            self.assertEqual("w2", values["m1"])
            checker.start()
            self.wait_for(checking)
            sql.assert_not_called()
            next_m2.set()
            self.wait_for(other_written)
            release_ack.set()
            self.wait_for(selected)
            self.assertEqual("w2", load.last_acknowledged("m1").value)
            next_m1.set()
            self.wait_for(writer_queued)
            self.assertEqual("w2", values["m1"])
            release_select.set()
            self.wait_for(checked)
            self.wait_for(third_recorded)
            self.assertEqual([], errors)
            self.assertEqual("w2", results[0].value)
            self.assertEqual(["w1", "w2", "w3"], [attempt.value for attempt in load.attempts("m1")])
            self.assertEqual(["w1", "w2"], [attempt.value for attempt in load.attempts("m2")])
            sql.assert_called_once_with("db2", "m1")
            cleanup()
            load.stop()
        self.assertTrue(all(not thread.is_alive() for thread in load._threads))
        self.sleep.assert_not_called()


if __name__ == "__main__":
    unittest.main()

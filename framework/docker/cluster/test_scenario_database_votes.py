"""Database scenarios count Galera votes without giving an arbitrator SQL or proxy traffic."""

import unittest
from contextlib import ExitStack
from dataclasses import replace
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import ANY, Mock, call, patch

import control
import scenarios
from stand import Database, DatabaseVoter, Stand


class ScenarioDatabaseVotesTest(unittest.TestCase):
    def setUp(self):
        self.contexts = ExitStack()
        self.addCleanup(self.contexts.close)
        self.contexts.enter_context(patch.object(control, "_run",
                                               side_effect=AssertionError("unexpected subprocess")))
        self.contexts.enter_context(patch.object(control, "compose",
                                               side_effect=AssertionError("unexpected compose")))
        self.contexts.enter_context(patch.object(scenarios, "MASTERS", ["m1", "m2"]))
        self.contexts.enter_context(patch.object(scenarios, "ALL_NODES", ["m1", "m2"]))
        self.now = 0.0
        self.contexts.enter_context(patch.object(scenarios.time, "monotonic", side_effect=lambda: self.now))
        self.sleep = self.contexts.enter_context(patch.object(scenarios.time, "sleep", side_effect=self.advance))
        self.use_stand()
        self.down = set()
        self.sizes = {}
        self.connections = {}
        self.sql = self.contexts.enter_context(patch.object(scenarios, "db_sql", side_effect=self.read_sql))
        self.proxy = self.contexts.enter_context(patch.object(scenarios, "proxy_states", wraps=scenarios.proxy_states,
                                                           side_effect=self.proxy_states))
        self.converge = self.contexts.enter_context(patch.object(scenarios, "wait_converge"))

    @staticmethod
    def make_stand(data_members=2, voters=1, replicas=0, *, clustered=True):
        data = tuple(Database(f"db{index}", f"example-db{index}", "app", "example", "app")
                     for index in range(1, data_members + 1))
        replicas = tuple(Database(f"replica{index}", f"example-replica{index}", "app", "example", "app",
                                  replica_of="db1") for index in range(1, replicas + 1))
        voters = tuple(DatabaseVoter(f"vote{index}", f"example-vote{index}", f"192.0.2.{30 + index}")
                       for index in range(1, voters + 1))
        return Stand(compose=Path("/example/docker/docker-compose.cluster.yml"), project="example",
                     network="example-nodes", cli_service="cli", cli_container="example-cli", cli_profiles=(),
                     entry=None, members={"m1": None, "m2": None}, masters=["m1", "m2"], slaves=[],
                     stranger=None, cluster_directory=None, scenarios=[26, 27], slave_work_grace_sec=0,
                     database=data[0], database_members=data + replicas if clustered else (),
                     database_proxy="example-proxy", database_voters=voters)

    def use_stand(self, **shape):
        self.stand = self.make_stand(**shape)
        self.contexts.enter_context(patch.object(scenarios, "STAND", self.stand))

    def advance(self, seconds):
        self.now += seconds

    @staticmethod
    def status_rows(size, state="Synced"):
        return (f"wsrep_cluster_size\t{size}\nwsrep_cluster_status\tPrimary\n"
                f"wsrep_local_state_comment\t{state}")

    def read_sql(self, statement, member):
        self.assertIn(member, [item.service for item in self.stand.database_members])
        if statement == scenarios.CLUSTER_STATUS_SQL:
            size = self.sizes.get(member, self.stand.database_votes - len(self.down))
            return self.status_rows(size)
        if statement == scenarios.SYNC_WAIT_SQL:
            return "1"
        if statement == scenarios.DIRTY_READS_SQL:
            return "0"
        if statement == scenarios.app_connections_sql(self.stand.database.user):
            return self.connections.get(member, "2")
        if statement == "SELECT @@hostname":
            return f"{member}-host"
        self.fail(f"unexpected SQL: {statement}")

    def proxy_states(self):
        return {member.service: "DOWN" if member.service in self.down else "UP"
                for member in self.stand.database_members}

    def prepare_load(self):
        self.writes = {node: scenarios.DbWriteAttempt(0, 10, node, f"value-{node}", True, "db2-host", "")
                       for node in scenarios.ALL_NODES}
        self.load = Mock(spec=scenarios.DbLoad)
        self.load.member_of.side_effect = lambda _node: "db2-host" if self.down else "db1-host"
        self.load.last_acknowledged.side_effect = self.writes.get
        self.load.attempts.side_effect = lambda node: [self.writes[node]]
        self.load.failures_since.return_value = []
        self.contexts.enter_context(patch.object(scenarios, "DbLoad", return_value=self.load))
        self.steady = self.contexts.enter_context(patch.object(scenarios, "wait_db_steady",
                                                            return_value={"m1": 0.5, "m2": 0.75}))
        self.kill = self.contexts.enter_context(patch.object(scenarios, "db_kill", side_effect=self.down.add))
        self.start = self.contexts.enter_context(patch.object(scenarios, "db_start", side_effect=self.down.remove))
        self.value = self.contexts.enter_context(patch.object(scenarios, "database_value",
                                                           side_effect=lambda _member, node: self.writes[node].value))
        self.started_at = self.contexts.enter_context(patch.object(control, "node_started_at",
                                                                 return_value=control.Outcome(0, "started", "")))
        self.load.assert_last_acknowledged.side_effect = self.check_acknowledged
        self.oracle = Mock(spec=scenarios.JournalOracle)
        self.oracle.node_id = "m2"
        self.oracle.batches.return_value = []
        batch_text = scenarios.JournalOracle._batch_text
        factory = self.contexts.enter_context(patch.object(scenarios, "JournalOracle", return_value=self.oracle))
        factory._batch_text.side_effect = batch_text
        self.journal = Mock(spec=scenarios.JournalLoad)
        self.contexts.enter_context(patch.object(scenarios, "JournalLoad", return_value=self.journal))
        self.finish_impl = scenarios.finish_journal
        self.finish = self.contexts.enter_context(patch.object(scenarios, "finish_journal", return_value="journal proof"))
        self.session = self.contexts.enter_context(patch.object(scenarios, "application_session",
                                                             return_value={"databaseMember": "db1-host"}))
        self.contexts.enter_context(patch.object(scenarios.time, "time", return_value=100.0))

    def check_acknowledged(self, member, node):
        last = self.writes[node]
        held = self.value(member, node)
        assert held == last.value, f"{member} holds {held} for {node}, which last wrote {last.value}"
        return last

    def test_shape_guards_separate_data_members_from_votes(self):
        cases = [
            ({"data_members": 3, "voters": 0}, [None, None]),
            ({"data_members": 2, "voters": 1}, [None, None]),
            ({"data_members": 2, "voters": 0},
             [None, "it needs 3 database votes, the stand has 2"]),
            ({"data_members": 1, "voters": 0, "replicas": 1},
             ["it needs 2 database votes, the stand has 1", "it needs 3 database votes, the stand has 1"]),
            ({"data_members": 1, "voters": 0, "clustered": False},
             ["it needs 2 database members, the stand has 0"] * 2),
        ]
        selected = [next(item for item in scenarios.SCENARIOS if item.number == number) for number in (26, 27)]
        for shape, expected in cases:
            with self.subTest(shape=shape):
                self.assertEqual(expected, [scenarios.unmet_need(self.make_stand(**shape), item) for item in selected])

    def test_wait_without_voters_does_not_read_sql_or_poll(self):
        for shape in [{"data_members": 3}, {"data_members": 2}, {"data_members": 1, "replicas": 1},
                      {"data_members": 1, "clustered": False}]:
            with self.subTest(shape=shape):
                self.use_stand(voters=0, **shape)
                scenarios.wait_database_votes()
        self.sql.assert_not_called()
        self.sleep.assert_not_called()

    def test_wait_tolerates_no_reply_and_requires_every_data_member_to_see_all_votes(self):
        self.sql.side_effect = ["", self.status_rows(2), self.status_rows(3), self.status_rows(2),
                                self.status_rows(3), self.status_rows(3)]
        scenarios.wait_database_votes()
        self.assertEqual([call(scenarios.CLUSTER_STATUS_SQL, member) for _ in range(3) for member in ("db1", "db2")],
                         self.sql.call_args_list)
        self.assertEqual([call(scenarios.POLL_INTERVAL)] * 2, self.sleep.call_args_list)

    def test_missing_votes_are_a_hard_failure_with_all_voters_and_member_sizes(self):
        self.use_stand(data_members=3, voters=2)
        self.sql.side_effect = lambda _statement, member: self.status_rows(3) if member == "db1" else ""
        with self.assertRaises(AssertionError) as caught:
            scenarios.wait_database_votes(timeout=2 * scenarios.POLL_INTERVAL)
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        message = str(caught.exception)
        for evidence in ("vote1, vote2", "expected component size 5", "db1=3", "db2=unavailable", "db3=unavailable"):
            self.assertIn(evidence, message)
        self.assertEqual(2 * scenarios.POLL_INTERVAL, self.now)

    def test_missing_voter_is_not_retried_by_the_scenario_runner(self):
        self.sizes = {"db1": 2, "db2": 2}
        with patch.object(scenarios, "SCENARIO_RETRIES", 2):
            with self.assertRaises(AssertionError) as caught:
                scenarios.run_scenario("26 database is one cluster", lambda: scenarios.wait_database_votes(timeout=0))
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        self.assertEqual(2, self.sql.call_count)
        self.converge.assert_not_called()

    def test_26_accepts_two_data_members_and_a_voter_or_three_data_members(self):
        for shape, words in [({"data_members": 2, "voters": 1}, "two data members and one voter"),
                             ({"data_members": 3, "voters": 0}, "three members")]:
            with self.subTest(shape=shape):
                self.use_stand(**shape)
                self.sql.reset_mock()
                detail = scenarios.scenario_26_database_is_one_cluster()
                self.assertIn(f"one cluster of {words}, all synced", detail)
                for member in self.stand.database_members:
                    self.assertIn(call(scenarios.SYNC_WAIT_SQL, member.service), self.sql.call_args_list)
                    self.assertIn(call(scenarios.DIRTY_READS_SQL, member.service), self.sql.call_args_list)
                    self.assertIn(call(scenarios.app_connections_sql("app"), member.service), self.sql.call_args_list)
                if not self.stand.database_voters:
                    self.assertEqual(3, sum(args.args[0] == scenarios.CLUSTER_STATUS_SQL
                                            for args in self.sql.call_args_list))

    def test_26_reads_session_settings_and_accepts_every_mask_with_the_read_bit(self):
        self.assertEqual("SELECT @@SESSION.wsrep_sync_wait", scenarios.SYNC_WAIT_SQL)
        self.assertEqual("SELECT @@SESSION.wsrep_dirty_reads", scenarios.DIRTY_READS_SQL)
        for mask in (1, 3, 5, 9):
            with self.subTest(mask=mask):
                self.sql.side_effect = lambda statement, member: (str(mask) if statement == scenarios.SYNC_WAIT_SQL
                                                                  else self.read_sql(statement, member))
                scenarios.scenario_26_database_is_one_cluster()

    def test_26_rejects_every_mask_without_the_read_bit(self):
        for mask in (0, 2, 4, 6, 8):
            with self.subTest(mask=mask):
                self.sql.side_effect = lambda statement, member: (str(mask) if statement == scenarios.SYNC_WAIT_SQL
                                                                  else self.read_sql(statement, member))
                with self.assertRaisesRegex(AssertionError, f"db1.*wsrep_sync_wait={mask}.*READ bit"):
                    scenarios.scenario_26_database_is_one_cluster()

    def test_26_rejects_dirty_reads_on_any_member(self):
        self.sql.side_effect = lambda statement, member: ("1" if statement == scenarios.DIRTY_READS_SQL and member == "db2"
                                                         else self.read_sql(statement, member))
        with self.assertRaisesRegex(AssertionError, "db2.*wsrep_dirty_reads='1'"):
            scenarios.scenario_26_database_is_one_cluster()

    def test_26_rejects_a_component_missing_its_voter(self):
        self.sizes = {"db1": 2, "db2": 2}
        with self.assertRaisesRegex(AssertionError, "expected component size 3, saw db1=2, db2=2"):
            scenarios.scenario_26_database_is_one_cluster()
        self.assertGreaterEqual(self.now, scenarios.DB_VOTER_JOIN_TIMEOUT)
        self.assertLess(self.now, scenarios.DB_VOTER_JOIN_TIMEOUT + scenarios.POLL_INTERVAL)

    def test_26_rejects_a_voter_in_the_proxy_even_when_down(self):
        self.proxy.side_effect = None
        self.proxy.return_value = {"db1": "UP", "db2": "UP", "vote1": "DOWN"}
        with self.assertRaisesRegex(AssertionError, "voter vote1 appears among HAProxy backends"):
            scenarios.scenario_26_database_is_one_cluster()

    def test_26_rejects_a_failed_proxy_read(self):
        self.proxy.side_effect = None
        with patch.object(control, "execute", return_value=control.Outcome(1, "", "wget failed")) as execute:
            with self.assertRaisesRegex(AssertionError, "could not read HAProxy backend states") as caught:
                scenarios.scenario_26_database_is_one_cluster()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        execute.assert_called_once_with(self.stand, "db-proxy")
        self.sleep.assert_not_called()

    def test_26_rejects_an_empty_successful_proxy_read(self):
        self.proxy.side_effect = None
        with patch.object(control, "execute", return_value=control.Outcome(0, "", "")) as execute:
            with self.assertRaisesRegex(AssertionError, "could not read HAProxy backend states") as caught:
                scenarios.scenario_26_database_is_one_cluster()
        self.assertNotIsInstance(caught.exception, scenarios.ScenarioTimeout)
        execute.assert_called_once_with(self.stand, "db-proxy")
        self.sleep.assert_not_called()

    def test_26_accepts_a_proxy_with_only_data_backends(self):
        self.proxy.side_effect = None
        with patch.object(control, "execute", return_value=control.Outcome(0, "db1\tUP\ndb2\tUP\n", "")) as execute:
            detail = scenarios.scenario_26_database_is_one_cluster()
        self.assertIn("one cluster of two data members and one voter, all synced", detail)
        execute.assert_called_once_with(self.stand, "db-proxy")
        self.sleep.assert_not_called()

    def test_26_does_not_read_the_proxy_without_voters(self):
        self.use_stand(data_members=3, voters=0)
        scenarios.scenario_26_database_is_one_cluster()
        self.proxy.assert_not_called()

    def test_26_does_not_read_a_proxy_the_stand_does_not_declare(self):
        with patch.object(scenarios, "STAND", replace(self.stand, database_proxy=None)):
            scenarios.scenario_26_database_is_one_cluster()
        self.proxy.assert_not_called()

    def test_27_survivors_keep_two_votes_and_the_returned_member_restores_three(self):
        self.prepare_load()
        for shape in [{"data_members": 2, "voters": 1}, {"data_members": 3, "voters": 0}]:
            with self.subTest(shape=shape):
                self.use_stand(**shape)
                self.value.reset_mock()
                self.kill.reset_mock()
                self.start.reset_mock()
                self.load.assert_last_acknowledged.reset_mock()
                detail = scenarios.scenario_27_database_member_dies_under_load()
                self.assertIn("primary cluster of two votes holding every acknowledged write", detail)
                self.kill.assert_called_once_with("db1")
                self.start.assert_called_once_with("db1")
                survivors = [call(member.service, node) for member in self.stand.database_members
                             if member.service != "db1" for node in scenarios.ALL_NODES]
                self.assertEqual(survivors, self.load.assert_last_acknowledged.call_args_list)
                self.assertEqual(survivors + [call("db1", node) for node in scenarios.ALL_NODES],
                                 self.value.call_args_list)

    def test_27_rejects_a_survivor_that_lost_the_voters_vote(self):
        self.prepare_load()
        self.kill.side_effect = lambda member: (self.down.add(member), self.sizes.update(db2=1))
        with self.assertRaisesRegex(AssertionError, "db2 sees a database cluster of 1.*2 votes are left"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.start.assert_called_once_with("db1")

    def test_27_still_rejects_a_lost_acknowledged_write(self):
        self.prepare_load()
        self.value.side_effect = None
        self.value.return_value = "stale"
        with self.assertRaisesRegex(AssertionError, "db2 holds stale for m1, which last wrote value-m1"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.start.assert_called_once_with("db1")

    def test_27_still_rejects_a_restarted_node(self):
        self.prepare_load()
        self.started_at.side_effect = [control.Outcome(0, value, "") for value in ("started", "started", "restarted")]
        with self.assertRaisesRegex(AssertionError, "m1 was restarted while db1 was down"):
            scenarios.scenario_27_database_member_dies_under_load()

    def test_27_kills_the_library_backend_instead_of_the_first_probe_backend(self):
        self.prepare_load()
        self.session.return_value = {"databaseMember": "db2-host"}
        self.load.member_of.side_effect = lambda node: "db1-host" if self.down or node == "m1" else "db2-host"
        self.connections = {"db1": "3", "db2": "1"}

        def start(member):
            self.load.stop.assert_not_called()
            self.journal.stop.assert_not_called()
            self.down.remove(member)
            self.connections = {"db1": "4", "db2": "0"}

        self.start.side_effect = start
        detail = scenarios.scenario_27_database_member_dies_under_load()
        self.kill.assert_called_once_with("db2")
        self.session.assert_called_once_with("m2", "primary")
        self.assertIn("db2 killed under m2's settings library", detail)
        self.assertIn("m2 moved through db1", detail)
        self.assertIn("application connections before db1 3, db2 1, after db1 4, db2 0", detail)
        self.assertIn("nodes write via db1 (m1, m2)", detail)
        self.assertEqual(3, self.steady.call_count)
        self.finish.assert_called_once_with(self.oracle, self.journal)

    def test_27_checks_every_node_that_used_the_killed_member(self):
        self.prepare_load()
        self.load.member_of.side_effect = lambda node: "db2-host" if self.down and node == "m1" else "db1-host"
        with self.assertRaisesRegex(AssertionError, "m2 still writes through db1, which was killed"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.start.assert_called_once_with("db1")
        self.finish.assert_not_called()

    def test_27_rejects_an_unknown_library_backend_before_starting_load(self):
        self.prepare_load()
        self.session.return_value = {"databaseMember": "unknown-host"}
        with self.assertRaisesRegex(AssertionError, "m2 uses an unknown data member unknown-host"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.load.start.assert_not_called()
        self.journal.start.assert_not_called()
        self.kill.assert_not_called()

    def test_27_rejects_a_returned_member_missing_an_acknowledged_write(self):
        self.prepare_load()
        self.value.side_effect = lambda member, node: "stale" if member == "db1" else self.writes[node].value
        with self.assertRaisesRegex(AssertionError, "db1 came back without m1's write"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.finish.assert_not_called()

    def test_27_rejects_a_probe_failure_that_finishes_during_return(self):
        self.prepare_load()
        self.load.attempts.side_effect = lambda node: [scenarios.DbWriteAttempt(-1, 10, node, "", False, None, "lost link")]
        with self.assertRaisesRegex(AssertionError, "write failed while db1 was coming back: lost link"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.finish.assert_not_called()

    def test_27_rejects_a_nonconfirmed_journal_batch_started_during_return(self):
        self.prepare_load()
        for outcome in (scenarios.JOURNAL_AMBIGUOUS, scenarios.JOURNAL_REFUSED):
            with self.subTest(outcome=outcome):
                self.oracle.batches.return_value = [scenarios.JournalBatch(
                    1, (("example_string", "value"),), 100000, 100001, outcome, "lost answer")]
                with self.assertRaisesRegex(AssertionError, f"came to {outcome} while db1 was coming back: lost answer"):
                    scenarios.scenario_27_database_member_dies_under_load()
        self.finish.assert_not_called()

    def test_27_rejects_an_early_refusal_even_when_later_and_final_batches_confirm(self):
        self.prepare_load()
        pairs = (("example_string", "value"),)
        self.oracle.batches.return_value = [
            scenarios.JournalBatch(1, pairs, 99998, 99999, scenarios.JOURNAL_REFUSED, "no writer"),
            scenarios.JournalBatch(2, pairs, 100000, 100001, scenarios.JOURNAL_CONFIRMED, "ok")]
        self.oracle.write_batch.return_value = SimpleNamespace(outcome=scenarios.JOURNAL_CONFIRMED)
        self.oracle.verify.return_value = "journal proof"
        self.finish.side_effect = self.finish_impl
        with self.assertRaisesRegex(AssertionError, "was refused before its first write: no writer"):
            scenarios.scenario_27_database_member_dies_under_load()
        self.finish.assert_not_called()

    def test_27_finishes_the_journal_only_after_the_member_returns_and_loads_stop(self):
        self.prepare_load()
        self.oracle.batches.return_value = [scenarios.JournalBatch(
            1, (), 99998, 99999, scenarios.JOURNAL_AMBIGUOUS, "lost answer")]
        steps = Mock()
        steps.attach_mock(self.oracle.start, "oracle_start")
        steps.attach_mock(self.session, "session")
        steps.attach_mock(self.load.start, "load_start")
        steps.attach_mock(self.journal.start, "journal_start")
        steps.attach_mock(self.start, "member_start")
        steps.attach_mock(self.load.stop, "load_stop")
        steps.attach_mock(self.journal.stop, "journal_stop")
        steps.attach_mock(self.finish, "finish")
        with patch.object(scenarios, "member_back", return_value=(2, 3)) as back:
            steps.attach_mock(back, "member_back")
            detail = scenarios.scenario_27_database_member_dies_under_load()
        self.assertEqual([call.oracle_start(), call.session("m2", "primary"), call.load_start(),
                          call.journal_start(), call.member_start("db1"),
                          call.member_back("db1", 3, scenarios.DB_MEMBER_RETURN_TIMEOUT, 0.0),
                          call.load_stop(), call.journal_stop(), call.load_stop(), call.journal_stop(),
                          call.finish(self.oracle, self.journal)], steps.mock_calls)
        self.assertIn("journal: journal proof", detail)

    def test_27_cleanup_does_not_replace_the_original_failure(self):
        self.prepare_load()
        original = AssertionError("load stopped")
        self.steady.side_effect = [{"m1": 0, "m2": 0}, original]
        self.start.side_effect = RuntimeError("cannot start")
        with patch("builtins.print") as printed:
            with self.assertRaises(AssertionError) as caught:
                scenarios.scenario_27_database_member_dies_under_load()
        self.assertIs(original, caught.exception)
        self.start.assert_called_once_with("db1")
        printed.assert_called_once_with("    scenario 27 recovery failed: cannot start", flush=True)

    def test_27_stops_both_loads_and_recovers_when_journal_stop_and_recovery_fail(self):
        self.prepare_load()
        original = AssertionError("probe failed while the member was down")
        self.start.side_effect = RuntimeError("cannot start")
        for error_type in (OSError, SystemExit):
            with self.subTest(error_type=error_type):
                self.down.clear()
                self.steady.side_effect = [{"m1": 0, "m2": 0}, original]
                self.journal.stop.side_effect = error_type("journal writer failed")
                self.load.stop.reset_mock()
                self.journal.stop.reset_mock()
                self.start.reset_mock()
                with patch("builtins.print") as printed:
                    with self.assertRaises(AssertionError) as caught:
                        scenarios.scenario_27_database_member_dies_under_load()
                self.assertIs(original, caught.exception)
                self.load.stop.assert_called_once_with()
                self.journal.stop.assert_called_once_with()
                self.start.assert_called_once_with("db1")
                self.assertEqual([call("    scenario 27 journal load recovery failed: journal writer failed", flush=True),
                                  call("    scenario 27 recovery failed: cannot start", flush=True)], printed.call_args_list)
        self.finish.assert_not_called()

    def test_27_unconfirmed_return_restores_and_waits_without_replacing_the_first_failure(self):
        self.prepare_load()
        self.start.side_effect = self.down.discard
        for cleanup_fails in (False, True):
            with self.subTest(cleanup_fails=cleanup_fails):
                original = scenarios.ScenarioTimeout("returned member is not Synced")
                cleanup_error = RuntimeError("recovery member unavailable")
                back_impl = scenarios.member_back
                self.start.reset_mock()
                self.load.stop.reset_mock()
                self.journal.stop.reset_mock()
                with patch.object(scenarios, "member_back") as wait, \
                        patch.object(scenarios, "restore_stand", wraps=scenarios.restore_stand) as restore, \
                        patch("builtins.print") as printed:
                    def back(*args):
                        if wait.call_count == 1:
                            self.load.stop.assert_not_called()
                            self.journal.stop.assert_not_called()
                            raise original
                        if cleanup_fails:
                            raise cleanup_error
                        return back_impl(*args)

                    wait.side_effect = back
                    with self.assertRaises(scenarios.ScenarioTimeout) as caught:
                        scenarios.scenario_27_database_member_dies_under_load()
                self.assertIs(original, caught.exception)
                self.assertEqual([call("db1"), call("db1")], self.start.call_args_list)
                self.assertEqual(2, wait.call_count)
                self.assertEqual(call("db1", 3, scenarios.DB_MEMBER_RETURN_TIMEOUT), wait.call_args_list[-1])
                self.assertIn(call(True, "scenario 27", ANY), restore.call_args_list)
                if cleanup_fails:
                    printed.assert_called_once_with("    scenario 27 recovery failed: recovery member unavailable",
                                                    flush=True)
                else:
                    printed.assert_not_called()
        self.finish.assert_not_called()

    def test_member_back_proxy_cannot_admit_a_returned_member_before_the_voter_is_in_its_component(self):
        self.sizes["db2"] = 2
        with self.assertRaisesRegex(AssertionError, "db2 got new connections while it was Synced in a cluster of 2"):
            scenarios.member_back("db2", 3, scenarios.DB_MEMBER_RETURN_TIMEOUT)

    def test_member_back_proxy_cannot_admit_a_member_before_it_is_synced(self):
        with patch.object(scenarios, "db_sql", return_value=self.status_rows(3, "Joining")):
            with self.assertRaisesRegex(AssertionError, "db2 got new connections while it was Joining"):
                scenarios.member_back("db2", 3, scenarios.DB_MEMBER_RETURN_TIMEOUT)


if __name__ == "__main__":
    unittest.main()

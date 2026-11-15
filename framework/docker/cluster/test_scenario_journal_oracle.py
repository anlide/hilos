"""The journal oracle reads what a test settings write came to and waits for every replica before it compares."""

import copy
import hashlib
import re
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import call, patch

import control
import scenarios
from stand import Database, Stand

PAIRS = (("example_string", "journal-0a1b2c3d-1"), ("example_integer", "8"))
WROTE = ("Wrote example_string = journal-0a1b2c3d-1, accepted at epoch ms 1763200000001\n",
         "Wrote example_integer = 8, accepted at epoch ms 1763200000002\n")
CATCH_UP = f"SELECT MASTER_GTID_WAIT('0-1-42', {scenarios.REPLICA_CATCH_UP_TIMEOUT:.0f})"
# The project's trigger files, as journal_trigger_sources() reads them, and the hilos_setting rows of
# the example keys; the base holds the string row from an earlier run, and the integer row is new.
SOURCES = {"hilos_cl_hilos_setting_after_insert": ("hilos_setting", "INSERT", "AFTER"),
           "hilos_cl_hilos_setting_after_update": ("hilos_setting", "UPDATE", "AFTER")}
ROWS = {"example_string": "11", "example_integer": "12"}
BASE_AT = 1763199990000


class FakeServer:
    """One database server as the oracle reads it: its catalog, its example rows and its journal,
    answered to the statements the oracle sends as mariadb -B prints them."""

    def __init__(self):
        self.triggers = {name: (table, event, timing,
                                f"BEGIN SET v_record_key = JSON_ARRAY(NEW.`id`); INSERT INTO `app-change-log`."
                                f"`hilos_change_log` (`record_key`) VALUES (v_record_key); END -- {event}")
                         for name, (table, event, timing) in SOURCES.items()}
        self.columns = [("app", "hilos_setting", "id", "1", "int(10) unsigned", "NO", "NULL"),
                        ("app-change-log", "hilos_change_log", "id", "1", "bigint(20) unsigned", "NO", "NULL")]
        self.partitions = [("hilos_change_log", "p_future", "MAXVALUE")]
        self.grants = ["GRANT USAGE ON *.* TO `app`@`%`", "GRANT ALL PRIVILEGES ON `app`.* TO `app`@`%`",
                       "GRANT ALL PRIVILEGES ON `app-change-log`.* TO `app`@`%`"]
        self.settings = {}
        self.journal = {table: [] for table in scenarios.JOURNAL_TABLES}
        self.add("hilos_change_log_table", name="hilos_setting")
        for field in ("key", "type", "value"):
            self.add("hilos_change_log_field", table_id="1", name=field)

    def add(self, table, **columns):
        """Append a row to a journal table under its next id, and answer the id."""
        row_id = str(max((int(row["id"]) for row in self.journal[table]), default=0) + 1)
        row = {"id": row_id, **columns}
        assert set(row) == set(scenarios.JOURNAL_COLUMNS[table]), f"{table} row of the wrong columns: {row}"
        self.journal[table].append(row)
        return row_id

    def receipt(self, at, **columns):
        """Open a receipt of the test write at epoch ms `at`, attributed as the command opens it."""
        return self.add("hilos_change_log_receipt", **{
            "created_at": str(at), "actor_user_id": "NULL", "subject_user_id": "NULL", "session_id": "NULL",
            "channel": "cli", "action": scenarios.SETTINGS_TEST_WRITE, "agent": scenarios.SETTINGS_LIBRARY_AGENT_TYPE,
            "source": scenarios.SETTINGS_TEST_WRITE, "open_handovers": "0", **columns})

    def write(self, receipt_id, at, row_id, mutation):
        """Journal one write of a hilos_setting row under a receipt, as the triggers do."""
        record = f"[{row_id}]"
        log_id = self.add("hilos_change_log", created_at=str(at), receipt_id=receipt_id, table_id="1",
                          record_key=record, record_key_hash=hashlib.sha256(record.encode()).hexdigest().upper(),
                          mutation_type=mutation)
        if mutation == "update":
            self.add("hilos_change_log_change", created_at=str(at), log_id=log_id, field_id="3", kind="fact",
                     old_present="1", new_present="1", old_value="NULL", new_value="NULL")
        return log_id

    def answer(self, statement):
        """What the server prints for one of the oracle's statements, or None for another statement."""
        table = re.search(r"FROM `app-change-log`\.`(\w+)` ORDER BY ", statement)
        if table is not None:
            columns = scenarios.JOURNAL_COLUMNS[table.group(1)]
            rows = [tuple(row[column] for column in columns) for row in self.journal[table.group(1)]]
        elif "FROM INFORMATION_SCHEMA.TRIGGERS " in statement:
            rows = [(name, *trigger) for name, trigger in sorted(self.triggers.items())]
        elif "FROM INFORMATION_SCHEMA.COLUMNS " in statement:
            rows = self.columns
        elif "FROM INFORMATION_SCHEMA.PARTITIONS " in statement:
            rows = self.partitions
        elif statement == "SHOW GRANTS FOR CURRENT_USER()":
            rows = [(grant,) for grant in self.grants]
        elif statement.startswith("SELECT `id`, `key`, `value` FROM hilos_setting WHERE `key` IN "):
            rows = sorted((row_id, key, value) for key, (row_id, value) in self.settings.items())
        else:
            return None
        return control.Outcome(0, "".join("\t".join(row) + "\n" for row in rows), "")


class JournalOracleTest(unittest.TestCase):
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
                           entry=None, members={"m1": None, "m2": None}, masters=["m1", "m2"], slaves=[],
                           stranger=None, cluster_directory=None, scenarios=[50], slave_work_grace_sec=0,
                           database=primary, database_members=(primary, replica))
        self.contexts.enter_context(patch.object(scenarios, "STAND", self.stand))
        self.contexts.enter_context(patch.object(scenarios, "ALL_NODES", ["m1", "m2"]))
        self.now = 1763200000.0
        self.contexts.enter_context(patch.object(scenarios.time, "time", side_effect=lambda: self.now))
        self.contexts.enter_context(patch.object(scenarios.time, "sleep",
                                               side_effect=AssertionError("unexpected sleep")))
        self.answer = None
        self.client = self.contexts.enter_context(patch.object(control, "client", side_effect=self.run_cli))
        self.waited = "0"
        self.replica_log = ""
        self.execute = self.contexts.enter_context(patch.object(control, "execute", side_effect=self.read_sql))
        self.contexts.enter_context(patch.object(scenarios, "journal_trigger_sources",
                                               side_effect=lambda: dict(SOURCES)))
        self.oracle = scenarios.JournalOracle()
        self.started()

    def started(self):
        """The oracle as start() leaves it, over a base whose earlier test write created the string row."""
        primary = FakeServer()
        self.servers = {"db1": primary}
        receipt = primary.receipt(BASE_AT)
        primary.write(receipt, BASE_AT, ROWS["example_string"], "create")
        primary.settings = {"example_string": (ROWS["example_string"], "journal-earlier-0")}
        self.oracle.agent_id, self.oracle.node_id = scenarios.SETTINGS_LIBRARY_AGENT_TYPE, "m1"
        self.oracle.server = self.stand.database_members[0]
        self.oracle.receipts_before = frozenset({receipt})
        self.oracle.settings_before = dict(primary.settings)
        self.oracle.integer_seed, self.oracle.label = 8, "0a1b2c3d"

    @staticmethod
    def batch(number, outcome, pairs=None):
        """A recorded call: by default the oracle's next pairs, answered 250 ms after it started."""
        started_ms = 1763200000000 + 1000 * number
        pairs = pairs or (("example_string", f"journal-0a1b2c3d-{number}"), ("example_integer", str(7 + number)))
        return scenarios.JournalBatch(number, pairs, started_ms, started_ms + 250, outcome, f"{outcome} call")

    def journaled(self, batch, *mutations, at=None):
        """Journal on the primary a receipt inside the batch's window and a write of the first pairs,
        one for each mutation given, and leave their values in the rows; answer the receipt."""
        primary = self.servers["db1"]
        at = batch.started_ms + 100 if at is None else at
        receipt = primary.receipt(at)
        for (key, value), mutation in zip(batch.pairs, mutations):
            primary.write(receipt, at, ROWS[key], mutation)
            primary.settings[key] = (ROWS[key], value)
        return receipt

    def replicated(self):
        """The replica holds all its primary wrote: answer its copy."""
        self.servers["replica1"] = copy.deepcopy(self.servers["db1"])
        return self.servers["replica1"]

    def refused(self, batches):
        """The AssertionError verify() raises over the batches, as text."""
        with self.assertRaises(AssertionError) as caught:
            self.oracle.verify(batches)
        return str(caught.exception)

    def assertSays(self, text, *words):
        for word in words:
            self.assertIn(word, text)

    def run_cli(self, stand, node, *args, timeout=None):
        self.now += 0.25
        return self.answer

    def read_sql(self, stand, command, statement, member=None):
        self.assertIs(self.stand, stand)
        if command == "db-log":
            self.assertEqual("replica1", statement)
            return control.Outcome(0, self.replica_log, "")
        self.assertEqual("db-sql", command)
        if statement == scenarios.PRIMARY_POSITION_SQL:
            self.assertEqual("db1", member)
            return control.Outcome(0, "0-1-42\n", "")
        if statement == CATCH_UP:
            self.assertEqual("replica1", member)
            return control.Outcome(0, self.waited + "\n", "")
        if statement == scenarios.REPLICA_POSITION_SQL:
            self.assertEqual("replica1", member)
            return control.Outcome(0, "0-1-40\n", "")
        answer = self.servers[member].answer(statement)
        if answer is None:
            self.fail(f"unexpected SQL: {statement}")
        return answer

    def test_cli_answer_classifies_the_batch(self):
        cases = (
            ("every pair acknowledged in order", control.Outcome(0, "".join(WROTE), ""),
             scenarios.JOURNAL_CONFIRMED),
            ("refused by the library",
             control.Outcome(1, "", "Refused: Only the example settings keys can be written by this test command.\n"),
             scenarios.JOURNAL_REFUSED),
            ("the daemon did not answer",
             control.Outcome(1, "", "The daemon did not answer test:settings:write within 10s\n"),
             scenarios.JOURNAL_AMBIGUOUS),
            ("the channel is unreachable",
             control.Outcome(1, "", "Cannot reach the daemon command channel at 172.30.0.11:9501\n"),
             scenarios.JOURNAL_AMBIGUOUS),
            ("exit 0 with a pair unacknowledged", control.Outcome(0, WROTE[0], ""), scenarios.JOURNAL_AMBIGUOUS),
            ("exit 0 with the pairs out of order", control.Outcome(0, WROTE[1] + WROTE[0], ""),
             scenarios.JOURNAL_AMBIGUOUS),
            ("the reply carried no writes",
             control.Outcome(1, "Command failed: the reply carries no accepted writes\n", ""),
             scenarios.JOURNAL_AMBIGUOUS),
        )
        for number, (name, answer, expected) in enumerate(cases, 1):
            with self.subTest(name):
                self.answer = answer
                started = self.now
                batch = self.oracle.write_batch(list(PAIRS))
                self.assertEqual(scenarios.JournalBatch(number, PAIRS, int(started * 1000), int(started * 1000) + 250,
                                                        expected, batch.detail), batch)
                self.assertIn(f"exit {answer.code}", batch.detail)
                self.assertIn(repr(answer.out.strip()), batch.detail)
                self.assertIn(repr(answer.err.strip()), batch.detail)
        self.assertEqual([call(self.stand, "m1", scenarios.SETTINGS_TEST_WRITE,
                               "example_string=journal-0a1b2c3d-1", "example_integer=8")] * len(cases),
                         self.client.call_args_list)
        self.assertEqual([expected for _, _, expected in cases], [batch.outcome for batch in self.oracle.batches()])
        self.execute.assert_not_called()

    def test_confirmed_batches_with_one_write_per_pair_on_both_servers_pass(self):
        first, second = self.batch(1, scenarios.JOURNAL_CONFIRMED), self.batch(2, scenarios.JOURNAL_CONFIRMED)
        self.journaled(first, "update", "create")
        self.journaled(second, "update", "update")
        self.replicated()
        summary = self.oracle.verify([first, second])
        self.assertSays(summary, "batches 2: confirmed 2, refused 0, ambiguous 0", "receipts 2, writes 4",
                        "db1, replica1")
        self.assertEqual({"db1", "replica1"}, {sql.args[3] for sql in self.execute.call_args_list})

    def test_write_missing_on_the_replica_names_the_server_and_the_batch(self):
        batch = self.batch(1, scenarios.JOURNAL_CONFIRMED)
        self.journaled(batch, "update", "create")
        replica = self.replicated()
        replica.journal["hilos_change_log"] = replica.journal["hilos_change_log"][:-1]
        self.assertSays(self.refused([batch]), "replica1", "differs from db1 in hilos_change_log at row 3",
                        "under receipt 2 of batch 1 (confirmed", "'record_key': '[12]'", "found no row",
                        "db1 3, replica1 2")

    def test_second_write_of_one_pair_is_a_duplicate(self):
        batch = self.batch(1, scenarios.JOURNAL_CONFIRMED)
        receipt = self.journaled(batch, "update", "create")
        self.servers["db1"].write(receipt, batch.started_ms + 100, ROWS["example_integer"], "update")
        self.replicated()
        self.assertSays(self.refused([batch]), "db1", "batch 1 (confirmed", "receipt 2",
                        "duplicate write of hilos_setting [12]", "found 2")

    def test_refused_batch_with_a_receipt_fails(self):
        batch = self.batch(1, scenarios.JOURNAL_REFUSED, self.oracle.refused_pairs())
        self.journaled(batch)
        self.replicated()
        self.assertSays(self.refused([batch]), "db1", "batch 1 (refused", "expected no receipt", "found 1: ['2']")

    def test_ambiguous_batch_holds_at_most_one_receipt_with_a_prefix_of_the_pairs(self):
        with self.subTest("no receipt"):
            self.started()
            batch = self.batch(1, scenarios.JOURNAL_AMBIGUOUS)
            self.replicated()
            self.assertSays(self.oracle.verify([batch]), "ambiguous 1", "receipts 0, writes 0")
        with self.subTest("one receipt writing the first pair"):
            self.started()
            batch = self.batch(1, scenarios.JOURNAL_AMBIGUOUS)
            self.journaled(batch, "update")
            self.replicated()
            self.assertSays(self.oracle.verify([batch]), "ambiguous 1", "receipts 1, writes 1")
        with self.subTest("two receipts"):
            self.started()
            batch = self.batch(1, scenarios.JOURNAL_AMBIGUOUS)
            self.journaled(batch, "update")
            self.journaled(batch, at=batch.started_ms + 200)
            self.replicated()
            self.assertSays(self.refused([batch]), "db1", "batch 1 (ambiguous", "expected at most one receipt",
                            "found 2: ['2', '3']")

    def test_receipt_outside_every_window_is_unexplained(self):
        batch = self.batch(1, scenarios.JOURNAL_CONFIRMED)
        self.journaled(batch, "update", "create")
        self.servers["db1"].receipt(batch.finished_ms + 1)
        self.replicated()
        self.assertSays(self.refused([batch]), "db1", f"unexplained receipt 3 of {scenarios.SETTINGS_TEST_WRITE}",
                        f"created at {batch.finished_ms + 1} ms", "found none of the windows: batch 1 (confirmed")

    def test_servers_differing_by_one_journal_row_name_the_table_and_the_row(self):
        batch = self.batch(1, scenarios.JOURNAL_CONFIRMED)
        self.journaled(batch, "update", "create")
        replica = self.replicated()
        replica.journal["hilos_change_log_change"][0]["kind"] = "inline"
        self.assertSays(self.refused([batch]), "replica1", "differs from db1 in hilos_change_log_change at row 1",
                        "under receipt 2 of batch 1", "'kind': 'fact'", "'kind': 'inline'",
                        "rows of hilos_change_log_change: db1 1, replica1 1")

    def test_replica_behind_the_primary_is_a_timeout_naming_both_positions_and_its_error(self):
        self.assertIsNone(self.oracle.settle())
        self.waited = "-1"
        error = "[ERROR] Slave SQL: Error 'Duplicate entry' on query. Error_code: 1062"
        self.replica_log = f"[Note] Slave I/O thread: connected\n{error}\n[Note] Slave SQL thread exiting\n"
        with self.assertRaises(scenarios.ScenarioTimeout) as caught:
            self.oracle.settle()
        self.assertEqual(f"replica1 did not take the journal writes within "
                         f"{scenarios.REPLICA_CATCH_UP_TIMEOUT:.0f} s: it stands at 0-1-40, db1 at 0-1-42; {error}",
                         str(caught.exception))
        self.assertEqual([call(self.stand, "db-sql", scenarios.PRIMARY_POSITION_SQL, "db1"),
                          call(self.stand, "db-sql", CATCH_UP, "replica1")] * 2
                         + [call(self.stand, "db-sql", scenarios.REPLICA_POSITION_SQL, "replica1"),
                            call(self.stand, "db-log", "replica1")],
                         self.execute.call_args_list)
        self.client.assert_not_called()

    def test_trigger_body_differing_on_one_server_fails(self):
        batch = self.batch(1, scenarios.JOURNAL_CONFIRMED)
        self.journaled(batch, "update", "create")
        replica = self.replicated()
        name = "hilos_cl_hilos_setting_after_update"
        table, event, timing, body = replica.triggers[name]
        replica.triggers[name] = (table, event, timing, body.replace("JSON_ARRAY(NEW.`id`)", "JSON_ARRAY(OLD.`id`)"))
        self.assertSays(self.refused([batch]), "replica1", f"the body of trigger {name}", "differs from db1",
                        "expected 'BEGIN SET v_record_key = JSON_ARRAY(NEW.",
                        "found 'BEGIN SET v_record_key = JSON_ARRAY(OLD.")


if __name__ == "__main__":
    unittest.main()

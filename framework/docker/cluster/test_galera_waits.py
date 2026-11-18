"""Wait episodes use fake SQL and monotonic time, without Docker or real waits."""

import json
import os
import selectors
import subprocess
import tempfile
import threading
import unittest
from contextlib import ExitStack
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import galera_waits as waits
from galera_waits import _Session


class GaleraWaitsTest(unittest.TestCase):
    def setUp(self):
        stack = ExitStack()
        self.addCleanup(stack.close)
        self.root = Path(stack.enter_context(tempfile.TemporaryDirectory()))
        members = tuple(SimpleNamespace(service=f"db{i}", container=f"example-db{i}", image="example:1")
                        for i in range(1, 4))
        self.stand = SimpleNamespace(demo_dir=self.root, database_sql_backends=members)
        self.observer = waits.GaleraWaits(self.stand, self.root / "data/logs-cluster/galera")
        self.clock = 0
        self.sql = []
        self.long = {}
        self.lock_counts = {}
        self.locked = {}
        self.queue = {}
        self.flow = {}
        self.pressure_errors = {}
        self.partial = {}
        self.failed = set()
        self.sessions = {}
        stack.enter_context(patch.object(waits, "warn"))
        stack.enter_context(patch.object(waits.time, "monotonic", side_effect=lambda: self.clock))
        stack.enter_context(patch.object(waits, "utc", side_effect=lambda: f"2026-01-01T00:00:{self.clock:06.3f}Z"))
        stack.enter_context(patch.object(waits, "_Session", side_effect=self.session))
        stack.enter_context(patch.object(self.observer, "_exchange", side_effect=self.exchange))
        self.forbidden = stack.enter_context(patch.object(waits.subprocess, "Popen",
                                                          side_effect=AssertionError("real process")))
        stack.enter_context(patch.object(waits.subprocess, "run", side_effect=AssertionError("real Docker")))
        self.observer._prepare()

    def session(self, member):
        session = SimpleNamespace(probe=None, connection_id=None, error="", done=True, close=Mock())
        self.sessions[member.service] = session
        return session

    def read_sql(self, sql, name):
        self.sql.append((self.clock, name, sql))
        if sql == waits.PROBE:
            return "41\t1\t1"
        if sql == waits.LONG_QUERIES:
            return str(self.long.get(name, 0))
        if sql == waits.WAITS:
            return self.lock_counts.get(name, "0")
        if sql == "SELECT * FROM information_schema.INNODB_LOCK_WAITS":
            return self.locked.get(name, "")
        if sql == waits.PRESSURE:
            if name in self.pressure_errors:
                return self.pressure_errors[name]
            return (f"wsrep_local_recv_queue\t{self.queue.get(name, 0)}\n"
                    f"wsrep_flow_control_active\t{self.flow.get(name, 'OFF')}")
        if sql in dict(waits.DETAILS).values() or sql == waits.SETTINGS[1]:
            return ""
        self.fail(f"unexpected SQL: {sql}")

    def exchange(self, requests):
        results = {name: ({"error": "member unavailable"} if name in self.failed else
                         {label: self.read_sql(sql, name) for label, sql in queries})
                   for name, queries in requests.items()}
        for name, partial in self.partial.items():
            if name in results and "long queries" in results[name]:
                results[name] = partial
        return results

    def tick(self, second):
        self.clock = second
        self.observer.tick(second)

    def episodes(self):
        return sorted(self.observer.directory.glob("20*.txt"))

    def pipe_failure(self, long_rows, wait_rows):
        """Use the real framing and exchange path for db1, ending at client EOF."""
        def exchange(requests):
            results = self.exchange(requests)
            if "db1" not in requests or requests["db1"][0][0] != "long queries":
                return results
            request_read, request_write = os.pipe()
            response_read, response_write = os.pipe()
            for fd in (request_read, request_write, response_read, response_write):
                os.set_blocking(fd, False)
            with os.fdopen(request_read, "rb", buffering=0), \
                    os.fdopen(request_write, "wb", buffering=0) as input_stream, \
                    os.fdopen(response_read, "rb", buffering=0) as output, \
                    os.fdopen(response_write, "wb", buffering=0) as response:
                session = object.__new__(_Session)
                session.process = SimpleNamespace(stdin=input_stream, stdout=output)
                session.close = Mock()
                self.observer.sessions["db1"] = session
                write = os.write

                def send(fd, data):
                    response.write(f"{session.marker}:0\n{long_rows}"
                                   f"{session.marker}:1\n{wait_rows}".encode())
                    response.close()
                    return write(fd, data)

                selector = selectors.DefaultSelector()
                select = selector.select
                clock = iter(index / 100 for index in range(1000))
                with patch.object(waits.os, "write", side_effect=send), \
                        patch.object(waits.time, "monotonic", side_effect=lambda: next(clock)), \
                        patch.object(waits.selectors, "DefaultSelector", return_value=selector), \
                        patch.object(selector, "select", side_effect=lambda timeout: select(0)):
                    results.update(waits.GaleraWaits._exchange(
                        self.observer, {"db1": requests["db1"]}))
            self.assertEqual("SQL client EOF", results["db1"]["error"])
            self.assertEqual("", results["db1"]["pressure"])
            return results
        return exchange

    def assert_partial_poll_episode(self, partial):
        self.queue["db1"] = 1
        self.tick(0)
        self.tick(1)
        self.assertEqual([], self.episodes())
        self.assertEqual(2, self.observer.pressure["db1"])
        self.partial["db1"] = partial
        self.tick(2)
        self.assertEqual(1, len(self.episodes()))
        episode = self.episodes()[0]
        self.assertEqual(episode, self.observer.episode)
        self.assertEqual(0, self.observer.pressure["db1"])
        text = episode.read_text()
        self.assertIn("SQL deadline exceeded", (self.observer.directory / "diagnostics.txt").read_text())
        for name in ("db2", "db3"):
            member_text = text.split(f"] {name}\n", 1)[1].split("\n[", 1)[0]
            self.assertIn("-- PROCESSLIST\nSHOW FULL PROCESSLIST;\n(empty)", member_text)
        self.queue.clear()
        partial["long queries"] = "0"
        partial["lock waits"] = ""
        self.tick(4)
        self.assertEqual(episode, self.observer.episode)
        text = episode.read_text()
        self.assertIn(": poll unknown", text)
        self.assertNotIn(": released", text)
        self.partial.clear()
        self.tick(5)
        self.assertIsNone(self.observer.episode)
        self.assertEqual(1, episode.read_text().count(": released"))

    def test_quiet_step_has_no_text_file(self):
        for second in range(8):
            self.tick(second)
        self.assertEqual([], list(self.observer.directory.glob("*.txt")))
        for member in self.stand.database_sql_backends:
            self.assertEqual(1, sum(name == member.service and sql == waits.PROBE for _, name, sql in self.sql))

    def test_directory_helper_owns_only_galera_and_has_a_bounded_startup_budget(self):
        for path in self.observer.directory.iterdir():
            path.unlink()
        self.observer.directory.rmdir()
        mkdir = Path.mkdir
        calls = []
        def permission(path, *args, **kwargs):
            if path == self.observer.directory:
                calls.append(True)
                if len(calls) == 1:
                    raise PermissionError("root-owned parent")
            return mkdir(path, *args, **kwargs)
        with patch.object(Path, "mkdir", permission), patch.object(waits.subprocess, "run") as run:
            self.observer._prepare()
        argv = run.call_args.args[0]
        self.assertIn(f"type=bind,source={self.observer.directory.parent},target=/logs", argv)
        self.assertIn('chown "$1:$2" /logs/galera', argv[-4])
        self.assertEqual(["galera", str(os.getuid()), str(os.getgid())], argv[-3:])
        self.assertEqual(waits.PREPARE_SECONDS, run.call_args.kwargs["timeout"])
        self.assertLess(waits.PREPARE_SECONDS + 2, waits.JOIN_SECONDS)
        self.assertEqual(2, len(calls))

    def test_directory_helper_timeout_removes_only_its_named_container(self):
        for path in self.observer.directory.iterdir():
            path.unlink()
        self.observer.directory.rmdir()
        mkdir = Path.mkdir
        calls = []
        def permission(path, *args, **kwargs):
            if path == self.observer.directory:
                calls.append(True)
                if len(calls) == 1:
                    raise PermissionError("root-owned parent")
            return mkdir(path, *args, **kwargs)
        with patch.object(Path, "mkdir", permission), patch.object(waits.subprocess, "run") as run:
            run.side_effect = [subprocess.TimeoutExpired("docker", waits.PREPARE_SECONDS), Mock(returncode=0)]
            with self.assertRaises(subprocess.TimeoutExpired):
                self.observer._prepare()
        start, cleanup = run.call_args_list
        name = start.args[0][start.args[0].index("--name") + 1]
        self.assertEqual(["docker", "rm", "-f", name], cleanup.args[0])
        self.assertEqual(2, cleanup.kwargs["timeout"])

    def test_existing_directory_retries_after_manifest_permission_error_and_keeps_archived_evidence(self):
        evidence = self.observer.directory / "diagnostics.txt"
        evidence.write_text("preserve this evidence")
        manifest = self.observer.directory / "run.json"
        mkdir = Path.mkdir
        write_text = Path.write_text
        existing_mkdirs = []
        denied_manifest_writes = []

        def existing_directory(path, *args, **kwargs):
            if path == self.observer.directory:
                existing_mkdirs.append(path.is_dir())
            return mkdir(path, *args, **kwargs)

        def deny_first_manifest_write(path, *args, **kwargs):
            if path == manifest and not denied_manifest_writes:
                denied_manifest_writes.append(path)
                raise PermissionError("root-owned galera")
            return write_text(path, *args, **kwargs)

        with patch.object(Path, "mkdir", existing_directory), \
                patch.object(Path, "write_text", deny_first_manifest_write), \
                patch.object(waits.subprocess, "run") as run:
            self.observer._prepare()

        archived_evidence = list(self.observer.directory.glob("archive/*/diagnostics.txt"))
        self.assertEqual([True, True], existing_mkdirs)
        self.assertEqual([manifest], denied_manifest_writes)
        self.assertEqual(1, run.call_count)
        self.assertEqual(["preserve this evidence"], [path.read_text() for path in archived_evidence])
        self.assertEqual(1, len(list(self.observer.directory.glob("archive/*/run.json"))))

    def test_mariadb_false_current_flow_is_a_quiet_poll(self):
        self.flow = {"db1": "false", "db2": "false", "db3": "false"}
        for second in range(4):
            self.tick(second)
        self.assertEqual([], list(self.observer.directory.glob("*.txt")))

    def test_three_second_query_captures_every_member_and_query_including_empty(self):
        self.long["db2"] = 1
        self.tick(0)
        text = self.episodes()[0].read_text()
        for name in ("db1", "db2", "db3"):
            self.assertIn(f"[2026-01-01T00:00:00.000Z] {name}", text)
        for _, sql in waits.DETAILS:
            self.assertIn(sql + ";\n(empty)", text)
        self.assertIn("TIME >= 3", waits.LONG_QUERIES)
        self.assertIn("ID <> CONNECTION_ID()", waits.LONG_QUERIES)
        self.assertIn("COMMAND IN ('Query','Execute')", waits.LONG_QUERIES)

    def test_lock_wait_row_opens_episode(self):
        self.lock_counts["db3"] = "1"
        self.locked["db3"] = "11\t11:42:3:4\t13\t13:42:3:4"
        self.tick(0)
        self.assertEqual(1, len(self.episodes()))
        text = self.episodes()[0].read_text()
        self.assertIn("SELECT * FROM information_schema.INNODB_LOCK_WAITS;\n"
                      "11\t11:42:3:4\t13\t13:42:3:4", text)
        self.assertNotIn(waits.WAITS + ";", text)

    def test_apply_queue_needs_three_consecutive_polls(self):
        self.queue["db1"] = 1
        self.tick(0)
        self.tick(1)
        self.assertEqual([], self.episodes())
        self.tick(2)
        self.assertEqual(1, len(self.episodes()))

    def test_two_queued_polls_and_then_release_never_open(self):
        self.queue["db1"] = 1
        self.tick(0)
        self.tick(1)
        self.queue.clear()
        self.tick(2)
        self.assertEqual([], self.episodes())

    def test_current_flow_needs_three_polls_on_the_same_member(self):
        self.flow["db1"] = "ON"
        self.tick(0)
        self.tick(1)
        self.flow = {"db2": "1"}
        self.tick(2)
        self.tick(3)
        self.assertEqual([], self.episodes())
        self.tick(4)
        self.assertEqual(1, len(self.episodes()))
        self.assertNotIn("paused", waits.PRESSURE)

    def test_poll_failure_breaks_pressure_streak(self):
        self.queue["db1"] = 1
        self.tick(0)
        self.tick(1)
        self.failed.add("db1")
        self.tick(2)
        self.failed.clear()
        self.tick(3)
        self.tick(4)
        self.assertEqual([], self.episodes())
        self.tick(5)
        self.assertEqual(1, len(self.episodes()))

    def test_repeats_every_two_seconds_then_captures_release_once(self):
        self.long["db1"] = 1
        for second in range(5):
            self.tick(second)
        self.long.clear()
        self.tick(5)
        self.tick(6)
        text = self.episodes()[0].read_text()
        self.assertEqual(4, text.count("snapshot "))
        self.assertEqual(1, text.count(": released"))
        self.assertEqual(3, text.count(waits.SETTINGS[1] + ";"))

    def test_caps_include_release_and_do_not_reopen_continuous_wait(self):
        self.long["db1"] = 1
        for second in range(0, 160, 2):
            self.tick(second)
        self.assertEqual(1, len(self.episodes()))
        self.assertEqual(29, self.observer.snapshots)
        self.long.clear()
        self.tick(161)
        self.assertEqual(30, self.observer.snapshots)
        self.assertEqual(30, self.episodes()[0].read_text().count("snapshot "))

    def test_step_caps_episodes_across_all_members(self):
        for index in range(8):
            self.long = {f"db{index % 3 + 1}": 1}
            self.tick(index * 3)
            self.long.clear()
            self.tick(index * 3 + 1)
        self.assertEqual(5, len(self.episodes()))

    def test_member_failure_preserves_others_and_unknown_is_not_release(self):
        self.long["db2"] = 1
        self.failed.add("db1")
        self.tick(0)
        text = self.episodes()[0].read_text()
        self.assertIn("member unavailable", text)
        self.assertIn("] db3", text)
        self.long.clear()
        self.tick(2)
        self.assertIsNotNone(self.observer.episode)
        self.assertNotIn(": released", self.episodes()[0].read_text())
        self.failed.clear()
        self.tick(3)
        self.assertIsNone(self.observer.episode)

    def test_partial_long_query_opens_episode_despite_transport_failure(self):
        self.assert_partial_poll_episode({
            "long queries": "1", "lock waits": "", "pressure": "",
            "error": "SQL deadline exceeded",
        })

    def test_partial_lock_wait_opens_episode_without_trusting_pressure(self):
        self.assert_partial_poll_episode({
            "long queries": "0", "lock waits": "1",
            "pressure": "wsrep_local_recv_queue\t1\nwsrep_flow_control_active\tOFF",
            "error": "SQL deadline exceeded",
        })

    def test_finished_transport_noise_does_not_spend_episode_budget(self):
        failure = self.pipe_failure("0\n", "unexpected EOF\n")
        for index in range(waits.MAX_EPISODES + 1):
            self.observer._exchange.side_effect = failure
            self.tick(index * 3)
            self.assertIsNone(self.observer.episode)
            self.assertEqual(0, self.observer.episodes)
            self.observer._exchange.side_effect = self.exchange
            self.tick(index * 3 + 1)
        diagnostic = (self.observer.directory / "diagnostics.txt").read_text()
        self.assertIn("lock waits: unexpected EOF", diagnostic)
        self.assertIn("SQL client EOF", diagnostic)
        self.long["db2"] = 1
        self.tick(20)
        self.assertEqual(1, len(self.episodes()))

    def test_finished_transport_noise_holds_episode_until_one_quiet_release(self):
        self.long["db2"] = 1
        self.tick(0)
        episode = self.observer.episode
        self.long.clear()
        self.observer._exchange.side_effect = self.pipe_failure("0\n", "unexpected EOF\n")
        self.tick(2)
        self.assertEqual(episode, self.observer.episode)
        self.assertIn(": poll unknown", episode.read_text())
        self.assertNotIn(": released", episode.read_text())
        self.observer._exchange.side_effect = self.exchange
        self.tick(3)
        self.tick(4)
        self.assertIsNone(self.observer.episode)
        self.assertEqual(1, episode.read_text().count(": released"))

    def test_finished_long_count_survives_diagnostic_and_later_transport_failure(self):
        self.observer._exchange.side_effect = self.pipe_failure("1\nunexpected EOF\n", "")
        self.tick(0)
        self.assertEqual(1, len(self.episodes()))
        self.assertIn("long queries: 1\nunexpected EOF",
                      (self.observer.directory / "diagnostics.txt").read_text())

    def test_finished_wait_count_survives_sql_error_and_transport_failure(self):
        self.queue["db1"] = 1
        self.tick(0)
        self.tick(1)
        self.assertEqual(2, self.observer.pressure["db1"])
        self.observer._exchange.side_effect = self.pipe_failure(
            "0\n", "1\nERROR 2013 lost connection\n")
        self.tick(2)
        self.assertEqual(1, len(self.episodes()))
        self.assertEqual(0, self.observer.pressure["db1"])
        text = self.episodes()[0].read_text()
        for name in ("db2", "db3"):
            member_text = text.split(f"] {name}\n", 1)[1].split("\n[", 1)[0]
            self.assertIn("-- PROCESSLIST\nSHOW FULL PROCESSLIST;\n(empty)", member_text)
        self.assertIn("lock waits: 1\nERROR 2013 lost connection",
                      (self.observer.directory / "diagnostics.txt").read_text())

    def test_unfinished_pipe_tail_is_not_a_wait_count(self):
        self.observer._exchange.side_effect = self.pipe_failure("0\n", "1")
        self.tick(0)
        self.assertEqual([], self.episodes())
        self.assertEqual(0, self.observer.episodes)

    def test_embedded_control_character_does_not_make_a_count_row(self):
        self.observer._exchange.side_effect = self.pipe_failure("0\n", "unexpected EOF\x1e1\n")
        self.tick(0)
        self.assertEqual([], self.episodes())

    def test_malformed_wait_reply_is_unknown_but_other_member_pressure_survives(self):
        self.lock_counts["db1"] = "unexpected EOF"
        self.assertIsNone(self.observer._poll())
        self.lock_counts["db1"] = "11\t12\t13"
        self.assertIsNone(self.observer._poll())
        diagnostic = (self.observer.directory / "diagnostics.txt").read_text()
        self.assertIn("lock waits: unexpected EOF", diagnostic)
        self.assertIn("lock waits: 11\t12\t13", diagnostic)
        self.queue["db2"] = 1
        self.tick(0)
        self.tick(1)
        self.assertEqual([], self.episodes())
        self.tick(2)
        self.assertEqual(1, len(self.episodes()))

    def test_count_with_noise_cannot_release_and_positive_count_is_still_evidence(self):
        self.long["db2"] = 1
        self.tick(0)
        episode = self.observer.episode
        self.long.clear()
        self.lock_counts["db1"] = "0\nunexpected EOF"
        self.tick(2)
        self.assertEqual(episode, self.observer.episode)
        self.assertIn(": poll unknown", episode.read_text())
        self.assertNotIn(": released", episode.read_text())
        self.lock_counts["db1"] = "1\nunexpected EOF"
        self.assertIs(self.observer._poll(), True)
        self.lock_counts["db1"] = ""
        self.assertIsNone(self.observer._poll())
        self.lock_counts.clear()
        self.tick(3)
        self.tick(4)
        self.assertEqual(1, episode.read_text().count(": released"))

    def test_missing_lock_table_is_visible_even_without_episode(self):
        original = self.read_sql
        def read(sql, member):
            if sql == waits.PROBE:
                return "41\t0\t1"
            return original(sql, member)
        self.read_sql = read
        self.tick(0)
        self.assertEqual([], self.episodes())
        self.assertIn("metadata probe", (self.observer.directory / "diagnostics.txt").read_text())

    def test_sql_error_does_not_hide_long_query_on_same_member(self):
        self.long["db1"] = 1
        self.lock_counts["db1"] = "ERROR 1109 unknown table"
        self.locked["db1"] = "ERROR 1109 unknown table"
        self.tick(0)
        self.assertEqual(1, len(self.episodes()))
        self.assertIn("ERROR 1109", self.episodes()[0].read_text())

    def test_lock_query_error_does_not_hide_three_poll_queue_pressure(self):
        self.lock_counts["db1"] = "ERROR 1109 unknown table"
        self.queue["db1"] = 1
        for second in range(2):
            self.tick(second)
            self.assertEqual([], self.episodes())
        self.tick(2)
        self.assertEqual(1, len(self.episodes()))
        self.assertIn("lock waits: ERROR 1109", (self.observer.directory / "diagnostics.txt").read_text())

    def test_lock_query_error_does_not_hide_three_poll_flow_pressure(self):
        self.lock_counts["db1"] = "ERROR 1109 unknown table"
        self.flow["db1"] = "TRUE"
        for second in range(2):
            self.tick(second)
            self.assertEqual([], self.episodes())
        self.tick(2)
        self.assertEqual(1, len(self.episodes()))

    def test_pressure_streaks_do_not_mix_members(self):
        self.queue["db1"] = 1
        self.tick(0)
        self.queue.clear()
        self.queue["db2"] = 1
        self.tick(1)
        self.queue.clear()
        self.queue["db3"] = 1
        self.tick(2)
        self.assertEqual([], self.episodes())

    def test_pressure_error_and_unknown_flow_break_the_streak(self):
        self.flow["db1"] = "TRUE"
        self.tick(0)
        self.pressure_errors["db1"] = "ERROR 1109 unknown table"
        self.tick(1)
        self.assertEqual([], self.episodes())
        self.pressure_errors.clear()
        self.tick(2)
        self.flow["db1"] = "UNKNOWN"
        self.tick(3)
        self.flow["db1"] = "TRUE"
        for second in (4, 5):
            self.tick(second)
            self.assertEqual([], self.episodes())
        self.tick(6)
        self.assertEqual(1, len(self.episodes()))

    def test_new_run_archives_evidence_and_manifest_lists_only_current_episodes(self):
        self.long["db1"] = 1
        self.tick(0)
        previous = self.episodes()[0].read_text()
        self.observer._prepare()
        fresh = waits.GaleraWaits(self.stand, self.observer.directory)
        fresh._prepare()
        self.assertEqual([], self.episodes())
        self.assertEqual([], json.loads((fresh.directory / "run.json").read_text())["episodes"])
        self.assertIn(previous, [path.read_text() for path in fresh.directory.glob("archive/*/20*.txt")])

    def test_run_stops_with_event_without_release_snapshot_or_real_wait(self):
        def stop_after_tick(_now):
            self.long["db1"] = 1
            waits.GaleraWaits.tick(self.observer, 0)
            self.observer.stop.set()
        with patch.object(self.observer, "tick", side_effect=stop_after_tick):
            thread = threading.Thread(target=self.observer.run)
            thread.start()
            thread.join(timeout=1)
        self.assertFalse(thread.is_alive())
        self.assertTrue(all(session.close.called for session in self.sessions.values()))
        self.assertNotIn(": released", self.episodes()[0].read_text())


class SessionTest(unittest.TestCase):
    def test_batch_sends_to_all_members_and_drains_healthy_member_beside_stalled_one(self):
        observer = waits.GaleraWaits(SimpleNamespace(database_sql_backends=()), Path("/unused"))
        writers = {}
        for name in ("stalled", "healthy"):
            request_read, request_write = os.pipe()
            response_read, response_write = os.pipe()
            for fd in (request_read, request_write, response_read, response_write):
                os.set_blocking(fd, False)
                self.addCleanup(os.close, fd)
            session = object.__new__(waits._Session)
            input_stream = os.fdopen(request_write, "wb", buffering=0, closefd=False)
            self.addCleanup(input_stream.close)
            output = os.fdopen(response_read, "rb", buffering=0, closefd=False)
            self.addCleanup(output.close)
            session.process = SimpleNamespace(stdin=input_stream, stdout=output)
            observer.sessions[name] = session
            writers[request_write] = (name, response_write)
        sent = []
        write = os.write
        def send(fd, data):
            name, response = writers[fd]
            sent.append(name)
            if name == "healthy":
                self.assertEqual(["stalled", "healthy"], sent)
                marker = observer.sessions[name].marker
                write(response, f"{marker}:0\n7\n{marker}:end\n".encode())
            return write(fd, data)
        # Poll real in-memory pipes with timeout zero; fake monotonic advances the deadline.
        selector = selectors.DefaultSelector()
        select = selector.select
        clock = iter(index / 100 for index in range(1000))
        with patch.object(waits.os, "write", side_effect=send), \
                patch.object(waits.time, "monotonic", side_effect=lambda: next(clock)), \
                patch.object(waits.selectors, "DefaultSelector", return_value=selector), \
                patch.object(selector, "select", side_effect=lambda timeout: select(0)):
            result = observer._exchange({name: [("query", "SELECT 7")] for name in observer.sessions})
        self.assertEqual("7", result["healthy"]["query"])
        self.assertEqual("SQL deadline exceeded", result["stalled"]["error"])

    def test_socket_transport_initializes_only_its_session_before_first_metadata_select(self):
        member = SimpleNamespace(service="db", container="example-db")
        with patch.object(waits.subprocess, "Popen") as popen, patch.object(waits.os, "set_blocking"):
            session = waits._Session(member)
        argv = popen.call_args.args[0]
        self.assertEqual(["docker", "exec", "-i", "-u", "mysql", "example-db", "mariadb"], argv[:7])
        self.assertIn("--init-command=SET SESSION wsrep_sync_wait=0, max_statement_time=0.7", argv)
        session.begin([("probe", waits.PROBE)])
        self.assertTrue(session.pending.decode().startswith(waits.PROBE + ";"))

    def test_interleaved_pipe_reads_preserve_empty_results_and_sql_errors(self):
        session = object.__new__(waits._Session)
        session.begin([("one", "SELECT 1"), ("empty", "SELECT 2"), ("bad", "SELECT 3")])
        session.process = SimpleNamespace(stdout=Mock(fileno=lambda: 1))
        marker = session.marker
        chunks = [f"{marker}:0\n1\n{marker}:1\n{marker}:2\nERR".encode(),
                  f"OR 1109 missing table\n{marker}:end\n".encode()]
        with patch.object(waits.os, "read", side_effect=chunks):
            session.receive()
            self.assertFalse(session.done)
            session.receive()
        self.assertTrue(session.done)
        self.assertEqual({"one": "1", "empty": "", "bad": "ERROR 1109 missing table"}, session.result())

    def test_cleanup_kills_only_observed_connection_and_bounds_client_waits(self):
        session = object.__new__(waits._Session)
        session.member = SimpleNamespace(container="example-db", service="db")
        session.connection_id = 42
        session.process = Mock()
        session.process.wait.side_effect = [subprocess.TimeoutExpired("docker", 0.2), 0]
        with patch.object(waits.subprocess, "run") as run:
            session.close()
        self.assertEqual("KILL CONNECTION 42", run.call_args.args[0][-1])
        self.assertEqual(0.7, run.call_args.kwargs["timeout"])
        session.process.kill.assert_called_once()

"""UTC log timestamps and their scenario consumers, without a cluster or external I/O."""

import os
from pathlib import Path
from tempfile import TemporaryDirectory
import time
from types import SimpleNamespace
import unittest
from datetime import datetime, timezone
from unittest.mock import patch

import control
import delivery
import scenarios


class LogTimeTest(unittest.TestCase):
    """Disk stamps have exactly three millisecond digits and an explicit UTC Z."""

    def test_canonical_stamp_preserves_the_utc_instant_and_milliseconds(self):
        moment = scenarios.log_time("2026-01-01T00:00:00.123Z")
        self.assertEqual(datetime(2026, 1, 1, 0, 0, 0, 123000, tzinfo=timezone.utc), moment)
        self.assertIs(timezone.utc, moment.tzinfo)
        self.assertEqual(1767225600.123, moment.timestamp())

    def test_host_timezone_does_not_change_the_instant(self):
        for zone in ("UTC0", "EST5EDT", "JST-9"):
            with self.subTest(zone=zone):
                try:
                    with patch.dict(os.environ, {"TZ": zone}):
                        time.tzset()
                        moment = scenarios.log_time("2026-01-01T00:00:00.123Z")
                        self.assertEqual(1767225600.123, moment.timestamp())
                        self.assertIs(timezone.utc, moment.tzinfo)
                finally:
                    time.tzset()

    def test_noncanonical_stamps_are_rejected(self):
        for stamp in ("2026-01-01 00:00:00.123", "2026-01-01T00:00:00.123",
                      "2026-01-01T00:00:00.123+00:00", "2026-01-01T01:00:00.123+01:00",
                      "2026-01-01T00:00:00Z", "2026-01-01T00:00:00.1Z",
                      "2026-01-01T00:00:00.12Z", "2026-01-01T00:00:00.1234Z",
                      "2026-01-01T00:00:00.123456Z", "2026-1-01T00:00:00.123Z",
                      "2026-01-01T00:00:00.123z", "2026-01-01T00:00:00.123Z\n"):
            with self.subTest(stamp=stamp), self.assertRaises(ValueError):
                scenarios.log_time(stamp)

    def test_impossible_calendar_date_is_rejected(self):
        with self.assertRaises(ValueError):
            scenarios.log_time("2026-02-30T00:00:00.123Z")

    def test_agent_start_prefix_preserves_each_matching_moment(self):
        text = ("[2026-10-24T16:10:11.123Z] [INFO] Agent 'hilos_i18n_library' started on worker #2\n"
                "[2026-10-24T16:10:11.456Z] Agent 'hilos_probe_fleet:0' started on worker #3\n"
                "[2026-10-24T16:10:12.123Z] Agent 'hilos_i18n_library' started on worker #4\n")
        self.assertEqual([datetime(2026, 10, 24, 16, 10, 11, 123000, tzinfo=timezone.utc),
                          datetime(2026, 10, 24, 16, 10, 12, 123000, tzinfo=timezone.utc)],
                         scenarios.started_on_worker_at(text, "hilos_i18n_library"))

    def test_placement_prefix_preserves_moments_and_destinations(self):
        text = ("[2026-10-24T16:10:11.123Z] [INFO] Placing agent 'hilos_probe_fleet:0' on node 's1'\n"
                "[2026-10-24T16:10:11.456Z] Placing agent 'hilos_probe_fleet:1' on node 's2'\n"
                "[2026-10-24T16:10:12.123Z] Placing agent 'hilos_probe_fleet:0' on node 'm2'\n")
        self.assertEqual([(datetime(2026, 10, 24, 16, 10, 11, 123000, tzinfo=timezone.utc), "s1"),
                          (datetime(2026, 10, 24, 16, 10, 12, 123000, tzinfo=timezone.utc), "m2")],
                         scenarios.placed_on_node_at(text, "hilos_probe_fleet:0"))

    def test_consensus_and_fence_moments_compare_with_placement_and_start(self):
        text = ("[2026-10-24T16:10:11.120Z] Consensus: won term 7 with 2 votes of the 3 masters\n"
                "[2026-10-24T16:10:11.121Z] Consensus: lost leadership held in term 7: quorum lost\n"
                "[2026-10-24T16:10:11.122Z] WARNING: Self-fence: quorum lost, stopping 1 placed agent(s)\n"
                "[2026-10-24T16:10:11.123Z] WARNING: Self-fence: placing leader 'm1' stopped leading, "
                "stopping 1 placed agent(s)\n"
                "[2026-10-24T16:10:11.124Z] Placing agent 'hilos_probe_fleet:0' on node 'm2'\n"
                "[2026-10-24T16:10:11.125Z] Agent 'hilos_probe_fleet:0' started on worker #2\n")
        won, lost, quorum, released = [scenarios.log_time(pattern.search(text).group(1)) for pattern in (
            scenarios.CONSENSUS_WON_TERM, scenarios.LEADERSHIP_LOST_ON_QUORUM,
            scenarios.QUORUM_FENCE_FIRED, scenarios.RELEASE_FENCE_FIRED)]
        placed, node = scenarios.placed_on_node_at(text, "hilos_probe_fleet:0")[0]
        started = scenarios.started_on_worker_at(text, "hilos_probe_fleet:0")[0]
        self.assertEqual("m2", node)
        self.assertTrue(won < lost < quorum < released < placed < started)
        self.assertEqual(0.002, (released - lost).total_seconds())
        self.assertEqual(0.003, (started - quorum).total_seconds())
        self.assertEqual(0.002, (started - released).total_seconds())


class RotationLifeTest(unittest.TestCase):
    """Holder replay spans rotation, but never revives an older daemon or worker."""

    def setUp(self):
        directory = TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.node = SimpleNamespace(log_file=self.root / "daemon.log")

    def write(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
        return path

    @staticmethod
    def lines(*events):
        return "".join(f"[2026-11-07T02:59:38.461Z] [INFO] {event}\n" for event in events)

    def workers(self):
        return delivery.active_workers(control.node_log(self.node, incarnation=True))

    def test_archive_start_and_current_tail_find_the_real_holder(self):
        self.write("archive/2026-11-07-03-00-04Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'hilos_logs' started on worker #8"))
        current = self.write("daemon.log", self.lines("Late ordinary event"))
        self.assertEqual({}, delivery.active_workers(control.node_log(self.node)))
        self.assertEqual({"hilos_logs": 8}, self.workers())
        self.assertEqual(current.read_text(), control.node_log(self.node, 0))
        stand = SimpleNamespace(members={"m2": self.node})
        controller = delivery.DeliveryController(stand, self.root, "rotation", "m1", 1)
        captured = object()
        with patch.object(controller, "stable_views"), \
                patch.object(control, "worker_process", return_value=captured) as worker, \
                patch.object(controller, "process_state", return_value={"state": "S"}):
            self.assertIs(captured, controller.locate("hilos_logs"))
        worker.assert_called_once_with(stand, "m2", 8)

    def test_stop_restart_and_reused_worker_index_forget_old_holders(self):
        self.write("archive/2026-11-07-02-00-00Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'old' started on worker #1"))
        self.write("archive/2026-11-07-03-00-04Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'hilos_logs' started on worker #8"))
        cases = [
            (("Daemon stopped",), {}),
            (("Daemon stopped after a failure",), {}),
            (("Daemon started with epoll", "Agent 'new' started on worker #2"), {"new": 2}),
            (("Worker #8 died hosting hilos_logs",), {}),
            (("Worker #8 started [type=default]", "Agent 'new' started on worker #8"), {"new": 8}),
            (("Agent 'hilos_logs' stopped on worker #8",), {}),
        ]
        for events, expected in cases:
            with self.subTest(events=events):
                self.write("daemon.log", self.lines(*events))
                self.assertEqual(expected, self.workers())

    def test_batch_order_not_line_timestamps_and_latest_start_in_one_file(self):
        self.write("archive/2026-11-07-03-00-04Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'new' started on worker #2"))
        self.write("archive/2026-11-07-02-00-00Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'old' started on worker #1"))
        self.write("daemon.log", self.lines("Late event"))
        self.assertEqual({"new": 2}, self.workers())
        self.write("daemon.log", self.lines("Daemon started with epoll", "Agent 'first' started on worker #1",
                                            "Daemon started with epoll", "Agent 'last' started on worker #3"))
        self.assertEqual({"last": 3}, self.workers())

    def test_pending_staging_batch_and_completed_copy_are_read_once(self):
        batch = "2026-11-07-03-00-04Z/daemon.log"
        text = self.lines("Daemon started with epoll", "Agent 'hilos_logs' started on worker #8")
        staged = self.write("staging/" + batch, text)
        self.write("daemon.log", self.lines("Agent 'hilos_logs' stopped on worker #8"))
        self.assertEqual({}, self.workers())
        self.write("archive/" + batch, text)
        self.assertEqual(1, control.node_log(self.node, incarnation=True).count("Daemon started with epoll"))
        staged.unlink()
        self.assertEqual({}, self.workers())

    def test_missing_start_and_other_node_history_do_not_create_a_holder(self):
        self.write("other/archive/2026-11-07-03-00-04Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'foreign' started on worker #1"))
        self.write("archive/.incoming-2026-11-07-03-00-04Z/daemon.log", self.lines(
            "Daemon started with epoll", "Agent 'unfinished' started on worker #2"))
        self.write("daemon.log", self.lines("Agent 'hilos_logs' started on worker #8"))
        self.assertEqual({}, self.workers())

    def test_byte_offsets_and_inode_marks_keep_the_single_file_contract(self):
        self.write("archive/2026-11-07-03-00-04Z/daemon.log", self.lines("Daemon started with epoll"))
        current = self.write("daemon.log", "first é\n")
        mark = control.node_log_mark(self.node)
        self.assertEqual(len("first é\n".encode()), control.node_log_size(self.node))
        with current.open("a", encoding="utf-8") as stream:
            stream.write("tail\n")
        self.assertEqual("tail\n", control.node_log(self.node, mark.size))
        self.assertEqual("tail\n", control.node_log_since(self.node, mark))
        current.rename(self.root / "staging-old.log")
        self.write("daemon.log", "replacement\n")
        self.assertEqual("replacement\n", control.node_log_since(self.node, mark))
        current.unlink()
        self.assertEqual("", control.node_log_since(self.node, mark))
        with self.assertRaises(ValueError):
            control.node_log(self.node, 1, incarnation=True)


if __name__ == "__main__":
    unittest.main()

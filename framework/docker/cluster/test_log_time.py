"""UTC log timestamps and their scenario consumers, without a cluster or external I/O."""

import os
import time
import unittest
from datetime import datetime, timezone
from unittest.mock import patch

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


if __name__ == "__main__":
    unittest.main()

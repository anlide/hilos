"""The public JSON reader uses the stand's TLS entry and preserves transport failures."""

import unittest
from types import SimpleNamespace
from unittest.mock import patch

import control
from stand import StandRefused


class EntryJsonTest(unittest.TestCase):
    def setUp(self):
        self.stand = SimpleNamespace(project="example", cli_container="example-cli",
                                     entry=SimpleNamespace(ip="192.0.2.10"))
        self.path = "/status-data/status.json"

    def test_reads_json_through_the_existing_cli_container_and_tls_entry(self):
        answer = control.Outcome(0, '{"components": []}\n', "")
        with patch.object(control, "_run", return_value=answer) as run:
            self.assertEqual(answer, control.entry_json(self.stand, self.path))
        run.assert_called_once_with([
            "docker", "exec", "example-cli", "curl", "--http1.1", "-k", "-f", "-sS",
            "--max-time", "5", "https://192.0.2.10/status-data/status.json",
        ])

    def test_http_and_transport_failures_are_not_parsed_as_json(self):
        with patch.object(control, "_run", return_value=control.Outcome(22, "<html>error</html>", "HTTP 404")):
            self.assertEqual(control.Outcome(22, "", "HTTP 404"), control.entry_json(self.stand, self.path))

    def test_successful_http_with_malformed_json_fails(self):
        with patch.object(control, "_run", return_value=control.Outcome(0, "<html>wrong location</html>", "")):
            answer = control.entry_json(self.stand, self.path)
        self.assertEqual(1, answer.code)
        self.assertEqual("", answer.out)
        self.assertIn("malformed JSON at /status-data/status.json", answer.err)

    def test_missing_entry_is_refused_before_network_io(self):
        self.stand.entry = None
        with patch.object(control, "_run") as run:
            with self.assertRaisesRegex(StandRefused, "has no browser entry"):
                control.entry_json(self.stand, self.path)
        run.assert_not_called()


if __name__ == "__main__":
    unittest.main()

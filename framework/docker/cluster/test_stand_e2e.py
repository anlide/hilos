"""The browser declaration names only the host capabilities its stand carries."""

import unittest

from stand import StandRefused, _e2e


class StandE2eTest(unittest.TestCase):
    def setUp(self):
        self.services = {"r": {"profiles": ["e2e"]}}
        self.entry = object()

    def test_capabilities_list_becomes_a_frozenset(self):
        result = _e2e("compose", "project", {
            "runner": "r", "capabilities": ["daemon", "delivery", "env-mismatch"],
        }, self.services, self.entry)
        self.assertEqual(frozenset(("daemon", "delivery", "env-mismatch")), result.capabilities)
        self.assertIsInstance(result.capabilities, frozenset)

    def test_missing_capabilities_is_empty(self):
        result = _e2e("compose", "project", {"runner": "r"}, self.services, self.entry)
        self.assertEqual(frozenset(), result.capabilities)

    def test_capabilities_must_be_a_list(self):
        with self.assertRaises(StandRefused) as refused:
            _e2e("compose", "project", {"runner": "r", "capabilities": "daemon"},
                 self.services, self.entry)
        self.assertEqual("compose: x-hilos-cluster.e2e.capabilities must be a list", str(refused.exception))

    def test_unknown_capability_is_refused(self):
        with self.assertRaises(StandRefused) as refused:
            _e2e("compose", "project", {"runner": "r", "capabilities": ["unknown"]},
                 self.services, self.entry)
        self.assertEqual("compose: x-hilos-cluster.e2e.capabilities names unknown, "
                         "not one of daemon, delivery, env-mismatch", str(refused.exception))


if __name__ == "__main__":
    unittest.main()

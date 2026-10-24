"""Retry evidence from the real scenario wrapper, without a cluster or external I/O."""

import io
import unittest
from contextlib import ExitStack, redirect_stdout
from unittest.mock import Mock, patch

import scenarios


class ScenarioRetriesTest(unittest.TestCase):
    """A successful retry reports once; failures keep the first cause."""

    def setUp(self):
        contexts = ExitStack()
        self.addCleanup(contexts.close)
        self.output = io.StringIO()
        contexts.enter_context(redirect_stdout(self.output))
        contexts.enter_context(patch.object(scenarios, "SCENARIO_RETRIES", 1))
        self.converge = contexts.enter_context(patch.object(scenarios, "wait_converge"))

    def unstable_lines(self):
        return [line for line in self.output.getvalue().splitlines() if line.startswith("hilos-unstable:")]

    def test_first_attempt_is_silent_and_preserves_detail(self):
        detail = object()
        fn = Mock(return_value=detail)
        self.assertIs(detail, scenarios.run_scenario("46 browser liveness across masters", fn))
        self.assertEqual("", self.output.getvalue())
        fn.assert_called_once_with()
        self.converge.assert_not_called()

    def test_success_after_timeout_reports_number_once(self):
        detail = object()
        fn = Mock(side_effect=[scenarios.ScenarioTimeout("first timeout"), detail])
        self.assertIs(detail, scenarios.run_scenario("46 browser liveness across masters", fn))
        self.assertEqual(["hilos-unstable: 1 (cluster/scenario-46)"], self.unstable_lines())
        self.assertEqual(2, fn.call_count)
        self.converge.assert_called_once_with(scenarios.ALL_NODES)

    def test_success_after_two_timeouts_still_reports_once(self):
        detail = object()
        fn = Mock(side_effect=[scenarios.ScenarioTimeout("first"), scenarios.ScenarioTimeout("second"), detail])
        with patch.object(scenarios, "SCENARIO_RETRIES", 2):
            self.assertIs(detail, scenarios.run_scenario("46 browser liveness across masters", fn))
        self.assertEqual(["hilos-unstable: 1 (cluster/scenario-46)"], self.unstable_lines())
        self.assertEqual(3, fn.call_count)
        self.assertEqual(2, self.converge.call_count)

    def test_exhaustion_raises_first_timeout(self):
        first = scenarios.ScenarioTimeout("original timeout")
        fn = Mock(side_effect=[first, scenarios.ScenarioTimeout("retry timeout")])
        with self.assertRaises(scenarios.ScenarioTimeout) as caught:
            scenarios.run_scenario("46 browser liveness across masters", fn)
        self.assertIs(first, caught.exception)
        self.assertEqual(2, fn.call_count)
        self.assertEqual([], self.unstable_lines())

    def test_hard_error_is_not_retried(self):
        first = AssertionError("hard invariant")
        fn = Mock(side_effect=first)
        with self.assertRaises(AssertionError) as caught:
            scenarios.run_scenario("46 browser liveness across masters", fn)
        self.assertIs(first, caught.exception)
        fn.assert_called_once_with()
        self.converge.assert_not_called()
        self.assertEqual([], self.unstable_lines())

    def test_hard_error_on_retry_preserves_first_timeout(self):
        first = scenarios.ScenarioTimeout("original timeout")
        hard = RuntimeError("retry precondition")
        fn = Mock(side_effect=[first, hard])
        with self.assertRaises(scenarios.ScenarioTimeout) as caught:
            scenarios.run_scenario("46 browser liveness across masters", fn)
        self.assertIs(first, caught.exception)
        self.assertIs(hard, caught.exception.__cause__)
        self.assertEqual(2, fn.call_count)
        self.assertEqual([], self.unstable_lines())

    def test_convergence_timeout_keeps_retry_and_reporting(self):
        self.converge.side_effect = scenarios.ScenarioTimeout("mesh still settling")
        fn = Mock(side_effect=[scenarios.ScenarioTimeout("original timeout"), "detail"])
        self.assertEqual("detail", scenarios.run_scenario("46 browser liveness across masters", fn))
        self.assertEqual(["hilos-unstable: 1 (cluster/scenario-46)"], self.unstable_lines())


if __name__ == "__main__":
    unittest.main()

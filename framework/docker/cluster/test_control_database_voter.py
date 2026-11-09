"""Database voters have their own controls; database cuts preserve client and proxy links."""

import unittest
from contextlib import ExitStack
from dataclasses import replace
from types import SimpleNamespace
from unittest.mock import call, patch

import cluster
import control
from stand import Database, DatabaseVoter, StandRefused


class DatabaseVoterControlTest(unittest.TestCase):
    def setUp(self):
        stack = ExitStack()
        self.addCleanup(stack.close)
        self.run = stack.enter_context(patch.object(control, "_run",
                                                   side_effect=AssertionError("unexpected subprocess")))
        self.compose = stack.enter_context(patch.object(control, "compose",
                                                       side_effect=AssertionError("unexpected compose")))
        self.stand = SimpleNamespace(
            database_members=(
                Database("db1", "stand-db1", "app", "secret", "app", ip="10.99.0.11"),
                Database("db2", "stand-db2", "app", "secret", "app", ip="10.99.0.12"),
            ),
            database_voters=(DatabaseVoter("garbd", "stand-garbd", "10.99.0.13"),),
            members={"m1": SimpleNamespace(container="stand-m1", ip="10.99.0.21")},
            database_proxy="stand-proxy",
            cli_container="stand-cli",
        )
        self.stand.database = self.stand.database_members[0]

    def allow_run(self, outcome=None):
        self.run.side_effect = None
        self.run.return_value = outcome or control.Outcome(0, "", "")

    @staticmethod
    def route(container, script):
        return call(["docker", "run", "--rm", "--network", f"container:{container}",
                     "--cap-add", "NET_ADMIN", control.NETNS_IMAGE, "sh", "-c", script])

    def test_data_commands_refuse_a_voter_without_docker(self):
        for command, args in [("db-sql", ("SELECT 1", "garbd")), ("db-log", ("garbd",)),
                              ("db-kill", ("garbd",)), ("db-start", ("garbd",))]:
            with self.subTest(command=command):
                with self.assertRaises(StandRefused) as refusal:
                    control.execute(self.stand, command, *args)
                self.assertEqual("garbd is a database voter: it holds no data and answers no SQL",
                                 str(refusal.exception))
                self.run.assert_not_called()
                self.compose.assert_not_called()

    def test_voter_kill_keeps_the_container(self):
        self.allow_run()
        self.assertEqual(control.Outcome(0, "cluster: killed database voter stand-garbd\n", ""),
                         control.execute(self.stand, "db-voter-kill", "garbd"))
        self.run.assert_called_once_with(["docker", "kill", "stand-garbd"])
        self.compose.assert_not_called()

    def test_voter_start_uses_its_compose_service(self):
        self.compose.side_effect = None
        self.compose.return_value = control.Outcome(0, "", "")
        self.assertEqual(control.Outcome(0, "cluster: started database voter stand-garbd\n", ""),
                         control.execute(self.stand, "db-voter-start", "garbd"))
        self.compose.assert_called_once_with(self.stand, "up", "-d", "garbd")
        self.run.assert_not_called()

    def test_voter_log_returns_both_streams_without_following(self):
        outcome = control.Outcome(0, "voter log\n", "")
        self.allow_run(outcome)
        self.assertEqual(outcome, control.execute(self.stand, "db-voter-log", "garbd"))
        self.run.assert_called_once_with(["docker", "logs", "stand-garbd"], merge_stderr=True)
        self.compose.assert_not_called()

    def test_failed_voter_commands_do_not_report_success(self):
        outcome = control.Outcome(1, "", "container unavailable\n")
        self.allow_run(outcome)
        self.compose.side_effect = None
        self.compose.return_value = outcome
        for command in ("db-voter-kill", "db-voter-start", "db-voter-log"):
            with self.subTest(command=command):
                self.assertEqual(outcome, control.execute(self.stand, command, "garbd"))

    def test_voter_commands_refuse_data_members_and_unknown_names_without_docker(self):
        for command in ("db-voter-kill", "db-voter-start", "db-voter-log"):
            for service in ("db1", "unknown"):
                with self.subTest(command=command, service=service):
                    with self.assertRaises(StandRefused) as refusal:
                        control.execute(self.stand, command, service)
                    self.assertEqual(f"unknown database voter '{service}' (expected one of: garbd)",
                                     str(refusal.exception))
                    self.run.assert_not_called()
                    self.compose.assert_not_called()

    def test_voter_commands_require_exactly_one_argument(self):
        for command in ("db-voter-kill", "db-voter-start", "db-voter-log"):
            for args in ((), ("garbd", "extra")):
                with self.subTest(command=command, args=args):
                    with self.assertRaises(StandRefused) as refusal:
                        control.execute(self.stand, command, *args)
                    self.assertEqual(f"usage: cluster {command} <voter>", str(refusal.exception))
                    self.run.assert_not_called()
                    self.compose.assert_not_called()

    def test_database_cut_routes_both_ways_only_between_database_servers(self):
        self.allow_run()
        self.assertEqual(control.Outcome(0, "cluster: cut database servers db1 off db2, garbd\n", ""),
                         control.execute(self.stand, "db-cut", "db1"))
        self.assertEqual([
            self.route("stand-db1", "ip route add blackhole 10.99.0.12/32 && "
                       "ip route add blackhole 10.99.0.13/32"),
            self.route("stand-db2", "ip route add blackhole 10.99.0.11/32"),
            self.route("stand-garbd", "ip route add blackhole 10.99.0.11/32"),
        ], self.run.call_args_list)
        self.compose.assert_not_called()

    def test_database_cut_accepts_a_mixed_island_and_preserves_its_internal_links(self):
        self.allow_run()
        self.assertEqual(control.Outcome(0, "cluster: cut database servers db1, garbd off db2\n", ""),
                         control.execute(self.stand, "db-cut", "db1", "garbd"))
        self.assertEqual([
            self.route("stand-db1", "ip route add blackhole 10.99.0.12/32"),
            self.route("stand-garbd", "ip route add blackhole 10.99.0.12/32"),
            self.route("stand-db2", "ip route add blackhole 10.99.0.11/32 && "
                       "ip route add blackhole 10.99.0.13/32"),
        ], self.run.call_args_list)
        self.compose.assert_not_called()

    def test_database_mend_lifts_both_sides_and_tolerates_absent_routes(self):
        self.allow_run()
        self.assertEqual(control.Outcome(0, "cluster: mended database servers garbd with db1, db2\n", ""),
                         control.execute(self.stand, "db-mend", "garbd"))
        self.assertEqual([
            self.route("stand-garbd", "ip route del blackhole 10.99.0.11/32 2>/dev/null; "
                       "ip route del blackhole 10.99.0.12/32 2>/dev/null; true"),
            self.route("stand-db1", "ip route del blackhole 10.99.0.13/32 2>/dev/null; true"),
            self.route("stand-db2", "ip route del blackhole 10.99.0.13/32 2>/dev/null; true"),
        ], self.run.call_args_list)
        self.compose.assert_not_called()

    def test_database_mend_visits_all_servers_after_a_failure_and_returns_the_first(self):
        self.run.side_effect = [control.Outcome(1, "", "first failure\n"),
                                control.Outcome(1, "", "second failure\n"),
                                control.Outcome(0, "", "")]
        self.assertEqual(control.Outcome(1, "", "first failure\ncluster: could not mend stand-garbd\n"),
                         control.execute(self.stand, "db-mend", "garbd"))
        self.assertEqual(["container:stand-garbd", "container:stand-db1", "container:stand-db2"],
                         [request.args[0][4] for request in self.run.call_args_list])
        self.compose.assert_not_called()

    def test_database_routes_refuse_empty_and_full_islands_without_docker(self):
        for command in ("db-cut", "db-mend"):
            for services in ((), ("db1", "db2", "garbd")):
                with self.subTest(command=command, services=services):
                    with self.assertRaisesRegex(StandRefused, f"usage: cluster {command} <server>.*outside"):
                        control.execute(self.stand, command, *services)
                    self.run.assert_not_called()
                    self.compose.assert_not_called()

    def test_database_routes_refuse_unknown_or_non_database_services_without_docker(self):
        for command in ("db-cut", "db-mend"):
            for service in ("unknown", "m1", "stand-proxy", "stand-cli"):
                with self.subTest(command=command, service=service):
                    with self.assertRaises(StandRefused) as refusal:
                        control.execute(self.stand, command, "db1", service)
                    self.assertEqual(f"unknown database server '{service}' (expected one of: db1 db2 garbd)",
                                     str(refusal.exception))
                    self.run.assert_not_called()
                    self.compose.assert_not_called()

    def test_database_routes_require_static_addresses_on_both_sides_before_docker(self):
        members = self.stand.database_members
        voters = self.stand.database_voters
        for command in ("db-cut", "db-mend"):
            for service in ("db1", "db2", "garbd"):
                with self.subTest(command=command, service=service):
                    self.stand.database_members = tuple(replace(member, ip=None) if member.service == service
                                                        else member for member in members)
                    self.stand.database_voters = tuple(replace(voter, ip=None) if voter.service == service
                                                       else voter for voter in voters)
                    with self.assertRaises(StandRefused) as refusal:
                        control.execute(self.stand, command, "db1")
                    self.assertEqual(f"database server {service} has no static IP address", str(refusal.exception))
                    self.run.assert_not_called()
                    self.compose.assert_not_called()

    def test_database_routes_work_on_data_members_without_voters(self):
        self.stand.database_voters = ()
        self.allow_run()
        self.assertEqual(0, control.execute(self.stand, "db-cut", "db1").code)
        self.assertEqual([
            self.route("stand-db1", "ip route add blackhole 10.99.0.12/32"),
            self.route("stand-db2", "ip route add blackhole 10.99.0.11/32"),
        ], self.run.call_args_list)
        self.compose.assert_not_called()

    def test_cluster_cli_accepts_every_database_command(self):
        for command in ("db-voter-kill", "db-voter-start", "db-voter-log", "db-cut", "db-mend"):
            with self.subTest(command=command):
                self.assertIn(command, cluster.COMMANDS)


if __name__ == "__main__":
    unittest.main()

"""The resolved Compose model separates data servers, replicas and Galera votes."""

import unittest
from pathlib import Path
from unittest.mock import patch

import stand
from stand import DatabaseVoter, StandRefused, stand_from_config


class StandDatabaseShapeTest(unittest.TestCase):
    def setUp(self):
        no_process = patch.object(stand.subprocess, "run", side_effect=AssertionError("unexpected subprocess"))
        no_process.start()
        self.addCleanup(no_process.stop)

    @staticmethod
    def config(data_members=0, replicas=0, *, clustered=True):
        services = {
            "cli": {},
            "master": {
                "container_name": "example-master",
                "environment": {"CLUSTER_ENABLED": "true", "CLUSTER_NODE_ID": "m1",
                                "CLUSTER_NODE_NAME": "Master", "CLUSTER_NODE_ROLE": "master"},
                "networks": {"nodes": {"ipv4_address": "192.0.2.10"}},
                "volumes": [{"type": "bind", "source": "/example/logs", "target": "/var/log/hilos"}],
            },
        }
        for index in range(1, data_members + 1):
            labels = {"hilos.database.member": "true"} if clustered else {}
            if index == 1:
                labels["hilos.role"] = "database"
            services[f"db{index}"] = {
                "labels": labels,
                "image": "mariadb:example",
                "environment": {"MYSQL_USER": "app", "MYSQL_PASSWORD": "example", "MYSQL_DATABASE": "app"},
                "networks": {"nodes": {"ipv4_address": f"192.0.2.{10 + index}"}},
            }
        for index in range(1, replicas + 1):
            services[f"replica{index}"] = {
                "labels": {"hilos.database.member": "true"},
                "image": "mariadb:example",
                "environment": {"MARIADB_MASTER_HOST": "db1"},
                "networks": {"nodes": {"ipv4_address": f"192.0.2.{20 + index}"}},
            }
        return {"name": "example", "services": services, "networks": {"nodes": {"name": "example-nodes"}},
                "x-hilos-cluster": {"cli": "cli", "scenarios": [1]}}

    @staticmethod
    def voter(config, service="vote1", ip="192.0.2.30"):
        spec = {"labels": {"hilos.database.voter": "true"}, "command": ["garbd"],
                "networks": {"nodes": {"ipv4_address": ip}}}
        config["services"][service] = spec
        return spec

    @staticmethod
    def read(config):
        return stand_from_config("example.yml", Path("/example/docker/docker-compose.cluster.yml"), config)

    def test_no_database_has_no_sql_backends_or_votes(self):
        parsed = self.read(self.config())
        self.assertIsNone(parsed.database)
        self.assertEqual((), parsed.database_members)
        self.assertEqual((), parsed.database_servers)
        self.assertEqual((), parsed.database_sql_backends)
        self.assertEqual((), parsed.database_voters)
        self.assertEqual(0, parsed.database_votes)

    def test_single_database_keeps_its_server_without_cluster_votes(self):
        parsed = self.read(self.config(data_members=1, clustered=False))
        self.assertEqual("192.0.2.11", parsed.database.ip)
        self.assertEqual((parsed.database,), parsed.database_servers)
        self.assertEqual((), parsed.database_members)
        self.assertEqual((), parsed.database_sql_backends)
        self.assertEqual((), parsed.database_voters)
        self.assertEqual(0, parsed.database_votes)

    def test_database_without_one_static_address_keeps_ip_none(self):
        for networks in [None, {}, {"nodes": None}, {"nodes": {"aliases": ["db"]}},
                         {"nodes": {"ipv4_address": "192.0.2.11"},
                          "other": {"ipv4_address": "198.51.100.11"}}]:
            with self.subTest(networks=networks):
                config = self.config(data_members=1, clustered=False)
                config["services"]["db1"]["networks"] = networks
                self.assertIsNone(self.read(config).database.ip)

    def test_primary_and_replica_have_one_sql_backend_and_one_vote_without_voters(self):
        parsed = self.read(self.config(data_members=1, replicas=1))
        self.assertEqual(("db1", "replica1"), tuple(member.service for member in parsed.database_members))
        self.assertEqual(("db1",), tuple(member.service for member in parsed.database_sql_backends))
        self.assertEqual(("replica1",), tuple(member.service for member in parsed.database_replicas))
        replica = parsed.database_replicas[0]
        self.assertEqual("db1", replica.replica_of)
        self.assertEqual("192.0.2.21", replica.ip)
        self.assertEqual(("app", "example", "app"), (replica.user, replica.password, replica.name))
        self.assertEqual((), parsed.database_voters)
        self.assertEqual(1, parsed.database_votes)

    def test_replica_without_static_address_keeps_ip_none(self):
        config = self.config(data_members=1, replicas=1)
        del config["services"]["replica1"]["networks"]
        self.assertIsNone(self.read(config).database_replicas[0].ip)

    def test_three_galera_members_are_three_sql_backends_and_votes(self):
        parsed = self.read(self.config(data_members=3))
        self.assertEqual(("db1", "db2", "db3"), tuple(member.service for member in parsed.database_sql_backends))
        self.assertEqual(("192.0.2.11", "192.0.2.12", "192.0.2.13"),
                         tuple(member.ip for member in parsed.database_members))
        self.assertEqual(parsed.database_members, parsed.database_sql_backends)
        self.assertEqual((), parsed.database_voters)
        self.assertEqual(3, parsed.database_votes)

    def test_two_data_members_and_arbitrator_keep_the_vote_out_of_sql(self):
        config = self.config(data_members=2)
        self.voter(config)["container_name"] = "example-arbitrator"
        parsed = self.read(config)
        self.assertEqual(("db1", "db2"), tuple(member.service for member in parsed.database_members))
        self.assertEqual(parsed.database_members, parsed.database_sql_backends)
        self.assertEqual(parsed.database_members, parsed.database_servers)
        self.assertEqual((DatabaseVoter("vote1", "example-arbitrator", "192.0.2.30"),), parsed.database_voters)
        self.assertEqual(3, parsed.database_votes)

    def test_no_voters_does_not_reject_an_even_data_member_count(self):
        parsed = self.read(self.config(data_members=2))
        self.assertEqual((), parsed.database_voters)
        self.assertEqual(2, parsed.database_votes)

    def test_voters_sort_by_service_and_default_the_container_name(self):
        config = self.config(data_members=2)
        for service, ip in [("vote3", "192.0.2.33"), ("vote1", "192.0.2.31"), ("vote2", "192.0.2.32")]:
            self.voter(config, service, ip)
        parsed = self.read(config)
        self.assertEqual((DatabaseVoter("vote1", "example-vote1-1", "192.0.2.31"),
                          DatabaseVoter("vote2", "example-vote2-1", "192.0.2.32"),
                          DatabaseVoter("vote3", "example-vote3-1", "192.0.2.33")), parsed.database_voters)
        self.assertEqual(5, parsed.database_votes)

    def test_arbitrator_program_is_found_in_entrypoint_command_and_shell_body(self):
        commands = [
            {"entrypoint": ["garbd"]},
            {"command": ["/usr/bin/garbd", "--address", "gcomm://db1,db2"]},
            {"entrypoint": ["/bin/sh", "-c"], "command": ["exec garbd --address 'gcomm://db1,db2'"]},
            {"entrypoint": ["/bin/sh", "-c", "exec /usr/bin/garbd --address gcomm://db1,db2"]},
            {"command": ["exec garbd --name 'unterminated"]},
        ]
        for command in commands:
            with self.subTest(command=command):
                config = self.config(data_members=2)
                spec = self.voter(config)
                del spec["command"]
                spec.update(command)
                self.assertEqual(3, self.read(config).database_votes)

    def test_arbitrator_cannot_carry_data_database_or_proxy_labels(self):
        for label, value in [("hilos.database.member", "true"), ("hilos.role", "database"),
                             ("hilos.database.proxy", "true")]:
            with self.subTest(label=label):
                config = self.config(data_members=2)
                self.voter(config)["labels"][label] = value
                with self.assertRaisesRegex(StandRefused, f"example.yml: service vote1 runs garbd but carries {label}"):
                    self.read(config)

    def test_database_role_on_arbitrator_is_refused_before_missing_credentials(self):
        config = self.config()
        self.voter(config)["labels"]["hilos.role"] = "database"
        with self.assertRaisesRegex(StandRefused, "service vote1 runs garbd but carries hilos.role"):
            self.read(config)

    def test_arbitrator_without_voter_label_is_refused_including_shell_command(self):
        for command in [["garbd"], ["/usr/bin/garbd"], ["sh", "-c", "exec garbd --address gcomm://db1,db2"]]:
            with self.subTest(command=command):
                config = self.config(data_members=2)
                spec = self.voter(config)
                spec["command"] = command
                spec["labels"] = {}
                with self.assertRaisesRegex(StandRefused, "service vote1 runs garbd without hilos.database.voter"):
                    self.read(config)

    def test_voter_label_requires_the_arbitrator_program(self):
        for command in [None, ["mariadbd"], ["garbd-helper"], ["/usr/bin/not-garbd"]]:
            with self.subTest(command=command):
                config = self.config(data_members=2)
                self.voter(config)["command"] = command
                with self.assertRaisesRegex(StandRefused, "service vote1 carries hilos.database.voter but does not run garbd"):
                    self.read(config)

    def test_voter_under_profile_is_refused(self):
        config = self.config(data_members=2)
        self.voter(config)["profiles"] = ["optional"]
        with self.assertRaisesRegex(StandRefused, "database voter vote1 is under a profile"):
            self.read(config)

    def test_voter_needs_exactly_one_static_address_on_the_node_network(self):
        for networks in [None, {}, {"nodes": None}, {"nodes": {"aliases": ["vote1"]}},
                         {"other": {"ipv4_address": "192.0.2.30"}},
                         {"nodes": {"ipv4_address": "192.0.2.30"},
                          "other": {"ipv4_address": "198.51.100.30"}}]:
            with self.subTest(networks=networks):
                config = self.config(data_members=2)
                self.voter(config)["networks"] = networks
                with self.assertRaisesRegex(StandRefused, "database voter vote1 must have one address on the node network"):
                    self.read(config)

    def test_voters_require_at_least_two_data_members(self):
        for count, clustered in [(0, True), (1, True), (1, False)]:
            with self.subTest(count=count, clustered=clustered):
                config = self.config(data_members=count, clustered=clustered)
                self.voter(config)
                with self.assertRaisesRegex(StandRefused, "database voters vote1 need at least two data members"):
                    self.read(config)

    def test_voters_cannot_accompany_replicas(self):
        config = self.config(data_members=2, replicas=1)
        self.voter(config)
        with self.assertRaisesRegex(StandRefused, "database voters vote1 cannot accompany database replicas"):
            self.read(config)

    def test_even_vote_total_is_refused(self):
        for count, extra_voter in [(3, False), (2, True)]:
            with self.subTest(count=count, extra_voter=extra_voter):
                config = self.config(data_members=count)
                self.voter(config)
                if extra_voter:
                    self.voter(config, "vote2", "192.0.2.31")
                with self.assertRaisesRegex(StandRefused, r"database voters vote1.*make an even number of database votes \(4\)"):
                    self.read(config)


if __name__ == "__main__":
    unittest.main()

"""Application writers keep their addressed carrier; log cleanup stays in its own bind."""

import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import call, patch

import control
from stand import Stand, StandRefused


class ControlOwnershipTest(unittest.TestCase):
    def test_application_commands_preserve_container_environment_cwd_argv_and_outcome(self):
        node = SimpleNamespace(container="example-m2-s2", ip="10.46.0.12")
        stand = SimpleNamespace(members={"m2": node}, cli_container="example-cli-s2",
                                cluster_directory=SimpleNamespace(path="/app/data/cluster"))
        stand.member = lambda node_id: Stand.member(stand, node_id)
        prefix = ["sh", "/hilos/framework/docker/test-writer-exec.sh", "/app", "--"]
        cases = [
            (lambda: control.inspect(stand, "m2"),
             ["docker", "exec", "-e", "HILOS_DAEMON_HOST=10.46.0.12", "example-cli-s2",
              *prefix, "php", "backend/Bootstrap/cli.php", "test:cluster:inspect"], None),
            (lambda: control.inspect_local(stand, "m2"),
             ["docker", "exec", "-e", "APP_ENV=test", "example-m2-s2",
              *prefix, "php", "backend/Bootstrap/cli.php", "test:cluster:inspect"], None),
            (lambda: control.client(stand, "m2", "test:backup:preflight", "two words", timeout=7.5),
             ["docker", "exec", "-e", "HILOS_DAEMON_HOST=10.46.0.12", "example-cli-s2",
              *prefix, "php", "backend/Bootstrap/cli.php", "test:backup:preflight", "two words"], 7.5),
            (lambda: control.cluster_directory_exec(stand, "m2", "sh", "-c", 'printf "%s" "$BACKUP_DIR"'),
             ["docker", "exec", "-w", "/app/data/cluster", "example-m2-s2",
              *prefix, "sh", "-c", 'printf "%s" "$BACKUP_DIR"'], None),
        ]
        for execute, argv, timeout in cases:
            for code in (0, 37):
                with self.subTest(argv=argv, code=code):
                    reply = SimpleNamespace(returncode=code, stdout="command output\n", stderr="command error\n")
                    with patch.object(control.subprocess, "run", return_value=reply) as run:
                        self.assertEqual(control.Outcome(code, "command output\n", "command error\n"), execute())
                    run.assert_called_once_with(argv, input=None, text=True, stdout=subprocess.PIPE,
                                                stderr=subprocess.PIPE, timeout=timeout)

    def test_log_cleanup_maps_members_and_stranger_to_the_explicit_log_root(self):
        with tempfile.TemporaryDirectory() as directory:
            demo = Path(directory) / "demo"
            root = demo / "data/logs-cluster"
            stand = SimpleNamespace(project="example", demo_dir=demo, cli_profiles=(), cli_service="example-cli",
                                    members={"m1": SimpleNamespace(id="m1", service="example-m1", log_dir=root / "m1"),
                                             "s1": SimpleNamespace(id="s1", service="example-s1", log_dir=root / "workers/s1")},
                                    stranger=SimpleNamespace(id="x1", service="example-x1", log_dir=root / "x1"))
            sources = [node.log_dir for node in control._logged_nodes(stand)]
            paths = ["/hilos-test-logs/m1", "/hilos-test-logs/workers/s1", "/hilos-test-logs/x1"]
            self.assertEqual(paths, control._test_log_directories(stand))
            with patch.object(control, "compose", side_effect=[control.Outcome(0, "example-cli\n", ""),
                                                               0, 29]) as compose:
                self.assertEqual(29, control.clear_test_logs(stand))
            self.assertEqual(call(stand, "--profile", "*", "ps", "--status", "running", "--services"),
                             compose.call_args_list[0])
            preparation = compose.call_args_list[1]
            self.assertEqual((stand, "run", "--build", "--rm", "--no-deps", "--entrypoint", "sh",
                              "example-cli", "-eu", "-c"), preparation.args[:10])
            self.assertIn('HILOS_TEST_WRITE_DIRS="$HILOS_TEST_WRITE_DIRS /hilos-test-logs"', preparation.args[10])
            self.assertIn("exec sh /hilos/framework/docker/test-writer-entrypoint.sh true", preparation.args[10])
            self.assertNotIn("find", preparation.args[10])
            self.assertEqual({"capture": False}, preparation.kwargs)
            cleanup = compose.call_args_list[2]
            self.assertEqual((stand, "run", "--build", "--rm", "--no-deps", "example-cli", "sh", "-c"),
                             cleanup.args[:8])
            self.assertEqual(("cluster-e2e-log-reset", *paths), cleanup.args[9:])
            self.assertEqual({"capture": False}, cleanup.kwargs)
            self.assertIn("! -name .gitkeep ! -name 'protected-mode.state.json*'", cleanup.args[8])
            self.assertIn('[ -L "$parent" ]', cleanup.args[8])
            self.assertEqual(sources, [node.log_dir for node in control._logged_nodes(stand)])
            with patch.object(control, "compose", return_value=control.Outcome(0, "example-x1\n", "")) as compose:
                with self.assertRaisesRegex(StandRefused, "needs stopped nodes"):
                    control.clear_test_logs(stand)
            self.assertEqual(1, compose.call_count)
            for replies in ([control.Outcome(17, "", "cannot list services")],
                            [control.Outcome(0, "", ""), 23]):
                with self.subTest(replies=replies):
                    with patch.object(control, "compose", side_effect=replies) as compose:
                        self.assertEqual(replies[-1].code if len(replies) == 1 else 23,
                                         control.clear_test_logs(stand))
                    self.assertEqual(len(replies), compose.call_count)

    def test_external_traversing_or_symlink_log_sources_are_refused_before_cleanup(self):
        with tempfile.TemporaryDirectory() as directory:
            demo = Path(directory) / "demo"
            root = demo / "data/logs-cluster"
            root.mkdir(parents=True)
            (root / "linked").symlink_to(root / "m1", target_is_directory=True)
            (root / "external").symlink_to(Path(directory) / "outside", target_is_directory=True)
            sources = [Path(directory) / "outside", demo / "data/other", demo, root,
                       root / "m1/../m2", root / "linked", root / "linked/child",
                       root / "external/child"]
            stand = SimpleNamespace(project="example", demo_dir=demo,
                                    members={"m1": SimpleNamespace(id="m1", service="example-m1", log_dir=root / "m1")})
            for source in sources:
                with self.subTest(source=source):
                    stand.stranger = SimpleNamespace(id="x1", service="example-x1", log_dir=source)
                    with patch.object(control, "compose") as compose, patch.object(control, "_run") as run:
                        with self.assertRaises(StandRefused):
                            control.clear_test_logs(stand)
                    compose.assert_not_called()
                    run.assert_not_called()
            stand.stranger = None
            moved = demo / "data/real-logs"
            root.rename(moved)
            root.symlink_to(moved, target_is_directory=True)
            with patch.object(control, "compose") as compose:
                with self.assertRaisesRegex(StandRefused, "unsafe log source"):
                    control.clear_test_logs(stand)
            compose.assert_not_called()


if __name__ == "__main__":
    unittest.main()

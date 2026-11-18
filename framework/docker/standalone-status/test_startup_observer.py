"""Startup native outcomes and host boundaries, using fake clocks/readers only."""

from contextlib import ExitStack
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch

import runner
import startup_observer as observer


class Clock:
    def __init__(self):
        self.now = 100.0

    def monotonic(self):
        self.now += 0.01
        return self.now

    def sleep(self, seconds):
        self.now += seconds


class StartupFixture(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.state = self.root / "state"
        self.state.mkdir()
        self.output = self.root / "results"
        self.clock = Clock()
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.stack.enter_context(patch.object(observer.time, "monotonic", self.clock.monotonic))
        self.stack.enter_context(patch.object(observer.time, "sleep", self.clock.sleep))
        self.stack.enter_context(patch.object(observer, "process", return_value={
            "pidInReaderNamespace": 42, "pidNamespace": "pid:[test]", "namespacePids": [42]}))
        self.child = Mock(pid=42, returncode=0)
        self.child.communicate.return_value = ("container-id\n", "native warning\n")
        self.waits = []

        def host_boundary(startup, filename):
            self.waits.append(filename)
            self.assertTrue((self.state / "startup-request.json").exists())
            if filename == "startup-host.json":
                self.assertTrue((self.state / "startup-outcome.json").exists())
                observer.write_json(self.state / filename, {"end": observer.stamp(), "closed": True})

        self.stack.enter_context(patch.object(observer.Startup, "wait_for", host_boundary))
        self.popen = self.stack.enter_context(patch.object(observer.subprocess, "Popen", side_effect=self.launch))
        self.startup = observer.Startup(self.state, self.output, "own-run", "exact-name", "exact-image")
        self.argv = ["docker", "run", "-d", "--rm", "--name", "exact-name", "exact-image"]
        self.failure = None

    def launch(self, argv, **kwargs):
        request = observer.load(self.state / "startup-request.json")
        self.assertEqual(self.argv, request["argv"])
        self.assertEqual(["startup-ready.json"], self.waits)
        self.assertEqual({"stdout": subprocess.PIPE, "stderr": subprocess.PIPE, "text": True}, kwargs)
        if self.failure:
            raise self.failure
        return self.child

    def artifact(self):
        result = observer.load(self.output / observer.ARTIFACT)
        self.assertEqual(["startup-ready.json", "startup-host.json"], self.waits)
        self.assertTrue(result["host"]["closed"])
        request = result["request"]
        self.assertEqual(("own-run", "exact-name", "exact-image", 30),
                         tuple(request[key] for key in ("runId", "name", "image", "timeout")))
        self.assertTrue(request["begin"]["utc"].endswith("+00:00"))
        self.assertLess(request["begin"]["monotonic"], result["outcome"]["end"]["monotonic"])
        self.popen.assert_called_once()
        return result


class StartupTest(StartupFixture):
    def test_success_preserves_native_output_and_one_mutating_startup(self):
        self.assertEqual("container-id", runner.docker(*self.argv[1:], observer=self.startup))
        self.child.communicate.assert_called_once_with(timeout=30)
        result = self.artifact()
        self.assertEqual(0, result["outcome"]["rc"])
        self.assertEqual("native warning\n", result["outcome"]["stderr"]["text"])
        self.assertEqual(42, result["client"]["pidInReaderNamespace"])

    def test_timeout_keeps_original_exception_and_partial_bytes_with_text_mode(self):
        error = subprocess.TimeoutExpired(self.argv, 30, output=b"partial\xff", stderr=b"waiting\xfe")
        self.child.communicate.side_effect = error
        with self.assertRaises(subprocess.TimeoutExpired) as raised:
            runner.docker(*self.argv[1:], observer=self.startup)
        self.assertIs(error, raised.exception)
        self.child.kill.assert_called_once()
        self.child.wait.assert_called_once()
        result = self.artifact()["outcome"]
        self.assertEqual("TimeoutExpired", result["exception"]["type"])
        self.assertEqual({"text": "partial\ufffd", "type": "bytes", "base64": "cGFydGlhbP8="}, result["stdout"])
        self.assertEqual("d2FpdGluZ/4=", result["stderr"]["base64"])
        self.child.communicate.assert_called_once_with(timeout=30)

    def test_nonzero_return_keeps_called_process_error_and_both_streams(self):
        self.child.returncode = 125
        with self.assertRaises(subprocess.CalledProcessError) as raised:
            runner.docker(*self.argv[1:], observer=self.startup)
        self.assertEqual((125, self.argv, "container-id\n", "native warning\n"),
                         (raised.exception.returncode, raised.exception.cmd,
                          raised.exception.output, raised.exception.stderr))
        self.assertEqual(125, self.artifact()["outcome"]["rc"])

    def test_launch_exception_is_native_and_missing_client_is_explicit(self):
        self.failure = FileNotFoundError("native docker launch failure")
        with self.assertRaises(FileNotFoundError) as raised:
            runner.docker(*self.argv[1:], observer=self.startup)
        self.assertIs(self.failure, raised.exception)
        result = self.artifact()
        self.assertEqual("unavailable", result["client"]["status"])
        self.assertEqual("FileNotFoundError", result["outcome"]["exception"]["type"])

    def test_diagnostic_failures_never_replace_success_or_native_failure(self):
        for method in ("write", "flush"):
            for error in (None, subprocess.TimeoutExpired(self.argv, 30, output=b"out\xff", stderr=b"err\xfe")):
                self.child.reset_mock()
                self.child.communicate.side_effect = error
                stderr = Mock()
                getattr(stderr, method).side_effect = BrokenPipeError("stderr unavailable")
                with self.subTest(method=method, error=error), \
                        patch.object(observer.sys, "stderr", stderr), \
                        patch.object(observer, "write_json", side_effect=PermissionError("denied")) as writes, \
                        patch.object(observer, "handoff", side_effect=PermissionError("denied")) as handoff, \
                        patch.object(observer.Startup, "wait_for", side_effect=OSError("host unavailable")) as waits, \
                        patch.object(observer.subprocess, "Popen", return_value=self.child) as popen:
                    if error is None:
                        self.assertEqual("container-id", self.startup.execute(self.argv))
                    else:
                        with self.assertRaises(subprocess.TimeoutExpired) as raised:
                            self.startup.execute(self.argv)
                        self.assertIs(error, raised.exception)
                        self.assertEqual((b"out\xff", b"err\xfe"), (error.output, error.stderr))
                        self.child.kill.assert_called_once()
                        self.child.wait.assert_called_once()
                    popen.assert_called_once_with(self.argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                    self.child.communicate.assert_called_once_with(timeout=30)
                    self.child.stdout.close.assert_called_once()
                    self.child.stderr.close.assert_called_once()
                    handoff.assert_called_once_with(self.state, self.output)
                    self.assertEqual([("startup-ready.json",), ("startup-host.json",)],
                                     [call.args for call in waits.call_args_list])
                    getattr(stderr, method).assert_called()
                    outcome, = [call.args[1] for call in writes.call_args_list
                                if call.args[0].name == "startup-outcome.json"]
                    self.assertIn("end", outcome)
                    if error is None:
                        self.assertEqual(0, outcome["rc"])
                        self.assertIsNone(outcome["exception"])
                        self.assertEqual("container-id\n", outcome["stdout"]["text"])
                        self.assertEqual("native warning\n", outcome["stderr"]["text"])
                    else:
                        self.assertEqual("TimeoutExpired", outcome["exception"]["type"])
                        self.assertIsNone(outcome["rc"])
                        self.assertEqual("b3V0/w==", outcome["stdout"]["base64"])
                        self.assertEqual("ZXJy/g==", outcome["stderr"]["base64"])

    def test_stderr_forwarding_failure_preserves_native_outcome_and_artifact(self):
        for returncode in (0, 125):
            self.waits.clear()
            self.popen.reset_mock()
            self.child.reset_mock()
            self.child.returncode = returncode
            stderr = Mock(write=Mock(side_effect=BrokenPipeError("stderr unavailable")))
            with self.subTest(returncode=returncode), patch.object(observer.sys, "stderr", stderr):
                if returncode:
                    with self.assertRaises(subprocess.CalledProcessError) as raised:
                        runner.docker(*self.argv[1:], observer=self.startup)
                    self.assertEqual((125, self.argv, "container-id\n", "native warning\n"),
                                     (raised.exception.returncode, raised.exception.cmd,
                                      raised.exception.output, raised.exception.stderr))
                else:
                    self.assertEqual("container-id", runner.docker(*self.argv[1:], observer=self.startup))
                outcome = self.artifact()["outcome"]
                self.assertEqual(returncode, outcome["rc"])
                self.assertEqual("container-id\n", outcome["stdout"]["text"])
                self.assertEqual("native warning\n", outcome["stderr"]["text"])
                if returncode:
                    self.assertEqual("CalledProcessError", outcome["exception"]["type"])
                else:
                    self.assertIsNone(outcome["exception"])
                self.child.communicate.assert_called_once_with(timeout=30)
                self.child.stdout.close.assert_called_once()
                self.child.stderr.close.assert_called_once()
                stderr.write.assert_any_call("native warning\n")



class CycleStartupTest(StartupFixture):
    def test_cycle_closes_startup_before_browser_and_never_enters_browser_on_failure(self):
        native_docker = runner.docker
        for failure in (None, subprocess.TimeoutExpired("docker", 30, output=b"partial", stderr=b"err"),
                        OSError("launch refused")):
            with self.subTest(failure=failure):
                self.waits.clear()
                self.popen.reset_mock()
                self.child.communicate.side_effect = failure
                self.argv = None
                browsers = []
                mutations = []

                def docker(*args, **kwargs):
                    if args[0] == "run":
                        mutations.append(args)
                        self.argv = ["docker", *args]
                        return native_docker(*args, **kwargs)
                    return "" if args[0] == "ps" else "pinned-id"

                def popen(argv, **kwargs):
                    if argv[1] == "run":
                        return self.launch(argv, **kwargs)
                    self.assertTrue(observer.load(self.output / observer.ARTIFACT)["host"]["closed"])
                    browsers.append(argv)
                    return Mock(returncode=0, poll=Mock(return_value=0))

                (self.state / "watchdog-result.json").write_text('{"error":null}')
                with patch.object(runner, "docker", side_effect=docker), \
                        patch.object(runner, "inspect", return_value={"Id": "pinned-id", "Created": "first"}), \
                        patch.object(runner, "Control") as control, \
                        patch.object(runner.subprocess, "run"), \
                        patch.object(runner.subprocess, "Popen", side_effect=popen), \
                        patch.object(runner.Path, "cwd", return_value=self.root), \
                        patch.dict(runner.os.environ, {"DOCKER_HOST": "unix:///test.sock", "STATUS_HARNESS_IMAGE": "exact-image"}):
                    control.return_value.recover.return_value = {}
                    # The fixture writes to cycle's actual output directory.
                    self.output = self.root / "tests/e2e/test-results/standalone-status"
                    if failure:
                        with self.assertRaises(type(failure)) as raised:
                            runner.cycle(self.state)
                        self.assertIs(failure, raised.exception)
                        self.assertEqual([], browsers)
                        control.return_value.recover.assert_not_called()
                    else:
                        runner.cycle(self.state)
                        self.assertEqual(1, len(browsers))
                self.assertEqual(1, len(mutations))
                self.child.communicate.assert_called_with(timeout=30)
                artifact = observer.load(self.output / observer.ARTIFACT)
                self.assertTrue(artifact["host"]["closed"])
                self.assertEqual(self.argv, artifact["request"]["argv"])
                self.assertEqual("exact-image", artifact["request"]["image"])


class HostObserverTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.state = Path(temporary.name)
        self.clock = Clock()

    def test_missing_and_denied_proc_fields_are_unavailable(self):
        with patch.object(observer.Path, "open", side_effect=PermissionError("denied")), \
                patch.object(observer.os, "readlink", side_effect=FileNotFoundError("missing PID")):
            facts = observer.process(42)
        self.assertEqual("unavailable", facts["pidNamespace"]["status"])
        for field in ("io", "stat", "cgroup", "wchan", "schedstat", "namespacePids", "cgroupMetrics"):
            self.assertEqual("unavailable", facts[field]["status"])

    def test_exact_argv_without_namespace_mapping_does_not_claim_a_host_pid(self):
        proc = self.state / "proc"
        target = proc / "123"
        target.mkdir(parents=True)
        (target / "comm").write_text("docker\n")
        (target / "cmdline").write_bytes(b"docker\0run\0exact\0")
        with patch.object(observer.time, "monotonic", self.clock.monotonic):
            facts = observer.host_snapshot({"argv": ["docker", "run", "exact"]}, {}, proc)
        self.assertEqual("unavailable", facts["clientHostPid"]["status"])
        self.assertEqual(123, facts["processes"][0]["pidInReaderNamespace"])

    def test_host_collection_is_closed_and_bounded_for_outcome_stop_and_deadline(self):
        for mode in ("outcome", "stop", "deadline", "event close timeout", "reader failure"):
            with self.subTest(mode=mode):
                for filename in ("startup-outcome.json", "startup-stop", "startup-host.json"):
                    (self.state / filename).unlink(missing_ok=True)
                observer.write_json(self.state / "startup-request.json", {"name": "exact-name", "argv": ["docker", "run"], "begin": observer.stamp()})
                if mode in ("outcome", "event close timeout", "reader failure"):
                    observer.write_json(self.state / "startup-outcome.json", {"rc": 0})
                if mode == "stop":
                    (self.state / "startup-stop").touch()
                events = Mock(returncode=-15)
                if mode == "event close timeout":
                    events.wait.side_effect = subprocess.TimeoutExpired("events", observer.READ_TIMEOUT)
                with patch.object(observer.time, "monotonic", self.clock.monotonic), \
                        patch.object(observer.time, "sleep", self.clock.sleep), \
                        patch.object(observer.signal, "signal"), \
                        patch.object(observer, "process", return_value={"pidNamespace": "pid:[host]"}), \
                        patch.object(observer, "host_snapshot", return_value={"clientHostPid": observer.unavailable("unknown")}) as snapshot, \
                        patch.object(observer, "docker_inspect", return_value={"rc": 0}) as inspect, \
                        patch.object(observer.subprocess, "Popen", return_value=events) as popen:
                    if mode == "reader failure":
                        snapshot.side_effect = PermissionError("host reader denied")
                    observer.observe(self.state, "unix:///test.sock")
                evidence = observer.load(self.state / "startup-host.json")
                self.assertIn("end", evidence)
                self.assertLessEqual(snapshot.call_count, 35)
                self.assertLessEqual(inspect.call_count, 35)
                self.assertIn("container=exact-name", popen.call_args.args[0])
                self.assertNotIn("run", popen.call_args.args[0])
                events.terminate.assert_called_once()
                if mode == "event close timeout":
                    events.kill.assert_called_once()
                    self.assertEqual(2, events.wait.call_count)
                    self.assertEqual("unavailable", evidence["eventCloseError"]["status"])
                else:
                    events.wait.assert_called_once_with(timeout=observer.READ_TIMEOUT)
                if mode == "reader failure":
                    self.assertEqual("unavailable", evidence["error"]["status"])

    def test_mapping_requires_both_exact_argv_and_namespace_facts(self):
        proc = self.state / "proc"
        target = proc / "123"
        (target / "ns").mkdir(parents=True)
        (target / "ns/pid").symlink_to("pid:[own]")
        (target / "comm").write_text("docker\n")
        (target / "cmdline").write_bytes(b"docker\0run\0exact\0")
        (target / "status").write_text("NSpid:\t123\t42\n")
        for argv, namespace, matches in ((["docker", "run", "exact"], "pid:[own]", True),
                                          (["docker", "run", "foreign"], "pid:[own]", False),
                                          (["docker", "run", "exact"], "pid:[foreign]", False)):
            with self.subTest(argv=argv, namespace=namespace), \
                    patch.object(observer.time, "monotonic", self.clock.monotonic):
                facts = observer.host_snapshot({"argv": argv}, {
                    "pidInReaderNamespace": 42, "pidNamespace": namespace}, proc)
            if matches:
                self.assertEqual(123, facts["clientHostPid"])
            else:
                self.assertEqual("unavailable", facts["clientHostPid"]["status"])

    def test_read_only_docker_timeout_keeps_partial_bytes(self):
        error = subprocess.TimeoutExpired("inspect", observer.READ_TIMEOUT, output=b"partial", stderr=b"denied")
        with patch.object(observer.subprocess, "run", side_effect=error) as execute:
            result = observer.docker_inspect("unix:///test.sock", "exact-name")
        self.assertEqual("TimeoutExpired", result["exception"])
        self.assertEqual("bytes", result["stdout"]["type"])
        self.assertEqual("denied", result["stderr"]["text"])
        self.assertEqual(observer.READ_TIMEOUT, execute.call_args.kwargs["timeout"])
        self.assertNotIn("run", execute.call_args.args[0])

    def test_waiting_for_an_absent_host_is_bounded_without_real_sleep(self):
        startup = observer.Startup(self.state, self.state, "run", "name", "image")
        with patch.object(observer.time, "monotonic", self.clock.monotonic), \
                patch.object(observer.time, "sleep", self.clock.sleep):
            startup.wait_for("missing")
        self.assertLess(self.clock.now, 101 + observer.FLUSH_TIMEOUT)


if __name__ == "__main__":
    unittest.main()

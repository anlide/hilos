"""Real browserless Playwright CLI integration, beside the existing runner units.

Like frontend/scripts/test/playwright-quarantine/run.mjs, only the Docker/stand
boundary is substituted. The runner hands its selection to the native entry in
one run; collection, IDs, reporters, the quarantine phase and the next
--last-failed read execute for real. Synthetic output never escapes this check.
"""

import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import runner


class NativeLastRunTest(unittest.TestCase):
    def test_one_native_run_leaves_the_state_the_next_selection_reads(self):
        here = Path(__file__).resolve().parent
        scripts = here.parents[1] / "frontend/scripts"
        entry = scripts / "playwright-run.mjs"
        reporter = scripts / "quarantine-plan-reporter.mjs"
        execute = subprocess.run
        with tempfile.TemporaryDirectory(prefix="hilos-status-cli-") as temporary:
            root = Path(temporary)
            e2e = root / "demo/tests/e2e"
            shutil.copytree(here / "fixtures", e2e / "tests")
            shutil.move(e2e / "tests/playwright.config.ts", e2e / "playwright.config.ts")
            (e2e / "package.json").write_text('{"private":true}')
            (e2e / "node_modules").symlink_to(here.parents[1] / "frontend/node_modules", target_is_directory=True)
            state = root / "control"
            state.mkdir()
            counter = root / "counter.jsonl"
            environment = {**os.environ, "HILOS_STATUS_FIXTURE_COUNTER": str(counter)}
            for key in ("HILOS_E2E_PLAN_FILE", "HILOS_E2E_PHASE", "HILOS_E2E_RESULT_FILE",
                        "HILOS_E2E_DEFER_REPORT", "PLAYWRIGHT_HTML_OUTPUT_DIR"):
                environment.pop(key, None)
            checked = execute(["node", str(here.parents[1] / "frontend/node_modules/typescript/bin/tsc"),
                               "--noEmit", "--target", "ES2022", "--module", "ESNext", "--moduleResolution", "Bundler",
                               "--strict", "--skipLibCheck", "--types", "node", "playwright.config.ts",
                               "tests/ordinary.spec.ts", "tests/status.spec.ts"], cwd=e2e,
                              env=environment, capture_output=True, text=True, timeout=90)
            self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)
            output = []

            def native(arguments, extra=None):
                result = execute(["node", str(entry), *arguments], cwd=e2e,
                                 env={**environment, **(extra or {})},
                                 capture_output=True, text=True, timeout=90)
                output.append(result.stdout + result.stderr)
                return result

            def docker_boundary(command, **_kwargs):
                if "node" not in command:
                    return subprocess.CompletedProcess(command, 0)
                extra = {}
                for index, arg in enumerate(command):
                    if arg == "-e":
                        key, value = command[index + 1].split("=", 1)
                        extra[key] = value
                return native(command[command.index("node") + 2:], extra)

            def invocation(failures, arguments=()):
                environment["HILOS_STATUS_FIXTURE_FAIL"] = failures
                counter.unlink(missing_ok=True)
                with patch.object(runner.subprocess, "run", side_effect=docker_boundary), \
                        patch.object(runner, "cycle") as cycle:
                    rc = runner.suite(state, ["--config=playwright.config.ts", *arguments])
                cycle.assert_not_called()
                events = [json.loads(line) for line in counter.read_text().splitlines()] if counter.exists() else []
                self.assertEqual(len(events), len({event["id"] for event in events}))
                return rc, events

            def next_selection(arguments=()):
                plan = root / "next-plan.json"
                result = native(["--config=playwright.config.ts", *arguments, "--last-failed", "--list",
                                 "--reporter=" + str(reporter)], {"HILOS_E2E_PLAN_FILE": str(plan)})
                self.assertEqual(0, result.returncode, output[-1])
                return {row["titlePath"][-1] for row in json.loads(plan.read_text())["tests"]}

            def last_run(arguments=()):
                directory = (arguments[1] if arguments[0] == "--output"
                             else arguments[0].split("=", 1)[1]) if arguments else "test-results/main"
                return json.loads((e2e / directory / ".last-run.json").read_text())

            try:
                for failures, expected in (("status", {"status"}), ("ordinary", {"ordinary"}),
                                           ("ordinary,status", {"ordinary", "status"})):
                    with self.subTest(failures=failures):
                        rc, events = invocation(failures)
                        self.assertEqual(1, rc, "\n".join(output))
                        self.assertEqual({"ordinary", "status", "quarantine"}, {event["name"] for event in events})
                        self.assertEqual("failed", last_run()["status"])
                        self.assertEqual({event["id"] for event in events if event["name"] in expected},
                                         set(last_run()["failedTests"]))
                        self.assertEqual(expected, next_selection())
                        print("native last-run " + json.dumps({"failure": failures, "state": last_run(),
                                                               "nextSelection": sorted(expected)}), flush=True)
                        rc, events = invocation("", ["--last-failed"])
                        self.assertEqual(0, rc)
                        self.assertEqual(expected, {event["name"] for event in events})
                        self.assertEqual({"status": "passed", "failedTests": []}, last_run())
                # A pointed invocation replaces an earlier failure natively.
                self.assertEqual(1, invocation("ordinary", ["ordinary.spec.ts"])[0])
                rc, events = invocation("status", ["status.spec.ts"])
                self.assertEqual(1, rc)
                self.assertEqual({"status", "quarantine"}, {event["name"] for event in events})
                self.assertEqual({"status"}, next_selection())
                rc, events = invocation("ordinary", ["ordinary.spec.ts"])
                self.assertEqual(1, rc)
                self.assertEqual(["ordinary"], [event["name"] for event in events])
                self.assertEqual({"ordinary"}, next_selection())
                rc, events = invocation("quarantine", ["--repeat-each=1"])
                self.assertEqual(1, rc)
                self.assertEqual({"ordinary", "status", "quarantine"}, {event["name"] for event in events})
                self.assertEqual({"quarantine"}, next_selection())
                # A quarantined failure never turns the main verdict or its state red.
                rc, events = invocation("quarantine")
                self.assertEqual(0, rc, "\n".join(output))
                self.assertEqual({"status": "passed", "failedTests": []}, last_run())
                # Native --last-failed selects nothing from a completed, empty failure list.
                empty_plan = root / "empty-plan.json"
                empty = native(["--config=playwright.config.ts", "--last-failed", "--list",
                                "--reporter=" + str(reporter)], {"HILOS_E2E_PLAN_FILE": str(empty_plan)})
                self.assertEqual(1, empty.returncode, output[-1])
                self.assertIn("No tests found", empty.stderr)
                self.assertFalse(empty_plan.exists())
                self.assertTrue(any('"status":"failed"' in text for text in output if "hilos-quarantine:" in text))
                for name in ("main", "quarantine/chromium"):
                    self.assertTrue((e2e / "playwright-report" / name / "index.html").exists())
                    self.assertTrue((e2e / "test-results" / name / ".last-run.json").exists())
                rc, events = invocation("quarantine", ["--grep=@quarantine:"])
                self.assertEqual(0, rc)
                self.assertEqual(["quarantine"], [event["name"] for event in events])
                self.assertEqual({"status": "passed", "failedTests": []}, last_run())
                for option in (["--output=custom-results"], ["--output", "separate-results"],
                               ["--output=" + str(e2e / "absolute-results")]):
                    rc, _events = invocation("status", option)
                    self.assertEqual(1, rc)
                    self.assertEqual({"status"}, next_selection(option))
                    rc, _events = invocation("", option)
                    self.assertEqual(0, rc)
                    self.assertEqual({"status": "passed", "failedTests": []}, last_run(option))
                rc, events = invocation("", ["--grep=no-original-test"])
                self.assertEqual(1, rc)
                self.assertEqual([], events)
                rc, events = invocation("", ["--grep=no-original-test", "--pass-with-no-tests"])
                self.assertEqual(0, rc)
                self.assertEqual([], events)
            except BaseException:
                print("\n".join("fixture | " + line for text in output for line in text.splitlines()))
                raise


class HostExitHandoffTest(unittest.TestCase):
    def test_shell_exit_preserves_native_rc_and_hands_off_before_state_cleanup(self):
        """The shell EXIT boundary is invisible to browser assertions; Docker and
        host readers are substituted because a stand gateway cannot run this shell.
        A FIFO orders fake observer readiness without clock sleeps.
        """
        here = Path(__file__).resolve().parent
        for rc, handoff_fails in ((0, False), (125, False), (124, True)):
            with self.subTest(rc=rc, handoff_fails=handoff_fails), \
                    tempfile.TemporaryDirectory(prefix="hilos-status-exit-") as temporary:
                root = Path(temporary)
                launcher = root / "framework/docker/standalone-status"
                launcher.mkdir(parents=True)
                shutil.copy(here / "run", launcher / "run")
                handshake = root / "ready.fifo"
                os.mkfifo(handshake)
                location = root / "state-location"
                source = repr(str(here / "startup_observer.py"))
                (launcher / "startup_observer.py").write_text(
                    "import importlib.util, os, sys\nfrom pathlib import Path\n"
                    f"spec = importlib.util.spec_from_file_location('diagnostics', {source})\n"
                    "module = importlib.util.module_from_spec(spec)\nspec.loader.exec_module(module)\n"
                    "state = Path(sys.argv[2])\n"
                    "if sys.argv[1] == 'observe':\n"
                    "    module.write_json(state / 'startup-host.json', {'closed': True})\n"
                    "    with open(os.environ['EXIT_HANDSHAKE'], 'w') as ready:\n"
                    "        ready.write('1')\n"
                    "elif os.environ['EXIT_HANDOFF_FAIL'] == '1':\n"
                    "    raise OSError('fixture handoff failure')\n"
                    "else:\n"
                    "    module.handoff(state, Path(sys.argv[3]))\n")
                (root / "docker.py").write_text(
                    f"#!{sys.executable}\n"
                    "import json, os, sys\nfrom pathlib import Path\nargs = sys.argv[1:]\n"
                    "if 'build' in args:\n"
                    "    with open(os.environ['EXIT_HANDSHAKE']) as ready:\n"
                    "        assert ready.read() == '1'\n"
                    "    image = Path(args[args.index('--iidfile') + 1])\n"
                    "    image.write_text('exact-image')\n"
                    "    Path(os.environ['EXIT_STATE_LOCATION']).write_text(str(image.parent))\n"
                    "else:\n"
                    "    state = Path(next(arg.split('=', 1)[1] for arg in args if arg.startswith('STATUS_CONTROL_DIR=')))\n"
                    "    for filename, value in (('manifest.json', {'runId': 'fixture-run'}),\n"
                    "                            ('startup-request.json', {'runId': 'fixture-run'}),\n"
                    "                            ('startup-outcome.json', {'rc': int(os.environ['EXIT_NATIVE_RC'])})):\n"
                    "        (state / filename).write_text(json.dumps(value))\n"
                    "    if os.environ['EXIT_NATIVE_RC'] == '0':\n"
                    "        (state / 'finished').touch()\n"
                    "    sys.exit(int(os.environ['EXIT_NATIVE_RC']))\n")
                endpoint = root / "docker.sock"
                with socket.socket(socket.AF_UNIX) as fake_socket:
                    fake_socket.bind(str(endpoint))
                    environment = {**os.environ, "EXIT_PYTHON": sys.executable,
                                   "EXIT_DOCKER": str(root / "docker.py"), "DOCKER_HOST": "unix://" + str(endpoint), "DOCKER_CONTEXT": "",
                                   "EXIT_HANDSHAKE": str(handshake), "EXIT_STATE_LOCATION": str(location),
                                   "EXIT_NATIVE_RC": str(rc), "EXIT_HANDOFF_FAIL": "1" if handoff_fails else "0"}
                    try:
                        result = subprocess.run([
                            "sh", "-c", 'docker() { "$EXIT_PYTHON" "$EXIT_DOCKER" "$@"; }; '
                            'php() { :; }; . "$0"', str(launcher / "run"), "e2e"], env=environment,
                            capture_output=True, text=True, timeout=30)
                        self.assertEqual(rc, result.returncode, result.stdout + result.stderr)
                        state = Path(location.read_text())
                        self.assertEqual(rc != 0, state.exists())
                        output = root / "demo/binance-btc-tracker/tests/e2e/test-results/standalone-status/startup/backstop-startup.json"
                        if handoff_fails:
                            self.assertIn("startup artifact handoff failed", result.stderr)
                        else:
                            artifact = json.loads(output.read_text())
                            self.assertEqual(rc, artifact["outcome"]["rc"])
                            self.assertTrue(artifact["host"]["closed"])
                    finally:
                        if location.exists():
                            shutil.rmtree(location.read_text(), ignore_errors=True)


if __name__ == "__main__":
    unittest.main(verbosity=2)

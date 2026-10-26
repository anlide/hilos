"""Real browserless Playwright CLI integration, beside the existing runner units.

Like frontend/scripts/test/playwright-quarantine/run.mjs, only the Docker/stand
boundary is substituted. Native collection, IDs, reporters, phases and subsequent
--last-failed reads execute for real. Synthetic output never escapes this check.
"""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import runner


class NativeLastRunTest(unittest.TestCase):
    def test_logical_invocation_native_state_and_next_selection(self):
        here = Path(__file__).resolve().parent
        entry = here.parents[1] / "frontend/scripts/playwright-run.mjs"
        reporter = here / "plan-reporter.mjs"
        execute = subprocess.run
        with tempfile.TemporaryDirectory(prefix="hilos-status-cli-") as temporary:
            root = Path(temporary)
            demo = root / "demo"
            e2e = demo / "tests/e2e"
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

            def docker_boundary(command, **kwargs):
                if "node" not in command:
                    return subprocess.CompletedProcess(command, 0)
                arguments = command[command.index("node") + 2:]
                arguments = [arg.replace("/status-suite/", str(state) + "/")
                             .replace("../../../../framework/docker/standalone-status/plan-reporter.mjs",
                                      str(reporter)) for arg in arguments]
                extra = {}
                for index, arg in enumerate(command):
                    if arg == "-e":
                        key, value = command[index + 1].split("=", 1)
                        extra[key] = value.replace("/status-suite/", str(state) + "/")
                return native(arguments, extra)

            def invocation(failures, arguments=()):
                environment["HILOS_STATUS_FIXTURE_FAIL"] = failures
                counter.unlink(missing_ok=True)
                with patch.object(runner.Path, "cwd", return_value=demo), \
                        patch.object(runner.subprocess, "run", side_effect=docker_boundary), \
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
                # A pointed Status invocation must replace the earlier ordinary failure.
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
                rc, events = invocation("quarantine")
                self.assertEqual(0, rc, "\n".join(output))
                self.assertEqual({"status": "passed", "failedTests": []}, last_run())
                self.assertEqual({"ordinary", "status", "quarantine"}, next_selection())
                self.assertTrue(any('"status":"failed"' in text for text in output if "hilos-quarantine:" in text))
                for name in ("main", "status-main", "quarantine/chromium"):
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
                self.assertEqual({"status": "failed", "failedTests": []}, last_run())
                rc, events = invocation("", ["--grep=no-original-test", "--pass-with-no-tests"])
                self.assertEqual(0, rc)
                self.assertEqual([], events)
                self.assertEqual({"status": "passed", "failedTests": []}, last_run())
            except BaseException:
                print("\n".join("fixture | " + line for text in output for line in text.splitlines()))
                raise


if __name__ == "__main__":
    unittest.main(verbosity=2)

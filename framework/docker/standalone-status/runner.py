"""Run-scoped, standalone Status loss fixture. No production control surface."""

import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import time
import uuid

SERVICE = "binance-btc-tracker-daemon-test"
NGINX = "binance-btc-tracker-nginx-test"
RUNNER = "binance-btc-tracker-e2e-runner"
PROJECT = "hilos-binance-btc-tracker-test"
ENTRY = "../../../../framework/frontend/scripts/playwright-run.mjs"
COMPOSE = ["compose", "-f", "docker/docker-compose.test.yml", "-f",
           "docker/docker-compose.e2e-status.yml"]
FAST_COMPOSE = [*COMPOSE, "-f", "docker/docker-compose.e2e-status-fast.yml"]
# A stopped daemon gets at most 90 real seconds, independently of Playwright.
STOP_LEASE = 90
CYCLE_LEASE = 600


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True, timeout=30).strip()


def inspect(container):
    return json.loads(docker("inspect", container))[0]


def write_json(path, value):
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(value))
    temporary.replace(path)


def validate_request(request, run_id, sequence):
    """No target, duration, command, path or Compose argument comes from a browser."""
    if not isinstance(request, dict) or set(request) != {"runId", "sequence", "operation"}:
        raise ValueError("Unexpected control fields")
    if (request["runId"] != run_id or type(request["sequence"]) is not int
            or request["sequence"] != sequence):
        raise ValueError("Foreign run or replayed request")
    if request["operation"] not in ("stop", "start", "inspect"):
        raise ValueError("Unsupported operation")
    return request["operation"]


class Control:
    """Pin the container lifetime, Compose owner, test environment and mounted tree."""

    def __init__(self, manifest, state, execute=docker, read=inspect):
        self.manifest = manifest
        self.state = state
        self.execute = execute
        self.read = read

    def checked(self, role):
        expected = self.manifest[role]
        current = self.read(expected["id"])
        labels = current["Config"]["Labels"]
        if (current["Id"] != expected["id"] or current["Created"] != expected["created"]
                or labels.get("com.docker.compose.project") != self.manifest["project"]
                or labels.get("com.docker.compose.service") != expected["service"]
                or current["HostConfig"]["RestartPolicy"]["Name"] not in ("no", "")):
            raise ValueError("Container lifetime or stand identity changed")
        if role == "daemon":
            if ("APP_ENV=test" not in current["Config"]["Env"]
                    or not any(m["Destination"] == "/app" and m["Source"] == self.manifest["demo"]
                               for m in current["Mounts"])):
                raise ValueError("Not this tree's test daemon")
        return current

    def evidence(self):
        result = {"at": int(time.time() * 1000), "runId": self.manifest["runId"]}
        for role in ("daemon", "nginx"):
            current = self.checked(role)
            labels = current["Config"]["Labels"]
            result[role] = {"id": current["Id"], "created": current["Created"],
                            "project": labels["com.docker.compose.project"],
                            "service": labels["com.docker.compose.service"],
                            "state": current["State"],
                            "restartCount": current["RestartCount"]}
            if role == "daemon":
                result[role]["environment"] = [value for value in current["Config"]["Env"]
                                                if value.startswith(("APP_ENV=", "STATUS_POLL_INTERVAL_SECONDS=",
                                                                     "STATUS_STALE_AFTER_SECONDS="))]
                result[role]["appMount"] = [mount for mount in current["Mounts"]
                                             if mount["Destination"] == "/app"]
        return result

    def operate(self, operation):
        current = self.checked("daemon")
        if operation == "stop":
            if not current["State"]["Running"]:
                raise ValueError("Daemon was already stopped")
            # Arm before touching PID 1, including a controller crash inside docker stop.
            write_json(self.state / "lease.json", {"until": time.monotonic() + STOP_LEASE})
            self.execute("stop", "--time", "5", current["Id"])
        elif operation == "start":
            self.execute("start", current["Id"])
        elif operation != "inspect":
            raise ValueError("Unsupported operation")
        evidence = self.evidence()
        if not evidence["nginx"]["state"]["Running"]:
            raise ValueError("The independent nginx is not running")
        if operation == "stop" and (evidence["daemon"]["state"]["Running"]
                                    or evidence["daemon"]["state"]["Pid"] != 0):
            raise ValueError("The whole daemon container did not stop")
        if operation == "start":
            if not evidence["daemon"]["state"]["Running"]:
                raise ValueError("Daemon did not restart")
            (self.state / "lease.json").unlink(missing_ok=True)
        return evidence

    def recover(self):
        """Idempotent finally/backstop, always against the originally pinned container."""
        if not self.checked("daemon")["State"]["Running"]:
            return self.operate("start")
        return self.evidence()


def watchdog(state):
    """A separate container survives controller/Playwright loss; it has no browser API."""
    try:
        watch_lease(state)
    except Exception as error:
        write_json(state / "watchdog-result.json", {"error": str(error)})
        raise
    else:
        write_json(state / "watchdog-result.json", {"error": None})


def watch_lease(state):
    """Finish normally, or recover and end the browser when its bounded lease expires."""
    manifest = json.loads((state / "manifest.json").read_text())
    control = Control(manifest, state)
    until = time.monotonic() + CYCLE_LEASE
    while not (state / "finished").exists():
        try:
            expired = time.monotonic() >= json.loads((state / "lease.json").read_text())["until"]
        except FileNotFoundError:
            expired = False
        if expired or time.monotonic() >= until:
            write_json(state / "backstop.json", {"firedAt": int(time.time() * 1000)})
            try:
                control.recover()
            finally:
                # This run's browser only. It has no production/stand service name.
                docker("rm", "-f", manifest["browserName"])
            return
        time.sleep(0.1)


def selected_args(args):
    """The native plan already applied these stateful selection options once."""
    result = []
    index = 0
    while index < len(args):
        arg = args[index]
        name = arg.split("=", 1)[0]
        if name in ("--test-list", "--test-list-invert", "--shard"):
            index += 1 if "=" in arg else 2
            continue
        if arg == "--last-failed":
            index += 1
            continue
        if name == "--only-changed":
            index += 1
            if "=" not in arg and index < len(args) and not args[index].startswith("-"):
                index += 1
            continue
        result.append(arg)
        index += 1
    return result


def suite(state, args):
    """Partition the real native selection, preserving the shared quarantine entry."""
    overlay = state / "suite.yml"
    overlay.write_text(json.dumps({"services": {RUNNER: {
        "volumes": [f"{state}:/status-suite"],
    }}}))

    def browser(arguments, environment=()):
        return subprocess.run(["docker", *COMPOSE, "-f", "docker/docker-compose.e2e-restart.yml",
                               "-f", str(overlay), "--profile", "e2e", "run", "--rm", "--no-deps",
                               *environment, RUNNER, "node", ENTRY, *arguments]).returncode

    # Listing/help/UI retain their native semantics and do not alter the stand.
    if any(arg.split("=", 1)[0] in ("--list", "--help", "-h", "--ui", "--ui-host", "--ui-port")
           for arg in args):
        return browser(args)
    rc = browser([*args, "--list", "--reporter=../../../../framework/docker/standalone-status/plan-reporter.mjs"],
                 ["-e", "HILOS_E2E_PLAN_FILE=/status-suite/plan.json"])
    plan = json.loads((state / "plan.json").read_text())
    if plan["version"] != 1:
        raise ValueError("Unexpected Playwright plan version")
    last_run = Path.cwd() / "tests/e2e" / plan["lastRunFile"]
    if rc:
        last_run.parent.mkdir(parents=True, exist_ok=True)
        write_json(last_run, {"status": "failed", "failedTests": []})
        return rc  # An empty original selection remains a native failure.
    status_files = {"status.spec.ts", "status-works.spec.ts", "a11y.spec.ts"}
    phases = [("main", [row for row in plan["tests"] if Path(row["file"]).name not in status_files]),
              ("status-main", [row for row in plan["tests"] if Path(row["file"]).name in status_files])]
    # Native IDs and native result files are run-private tooling state, not RT.
    # Start with unfinished main tests; replace each phase with its actual native
    # failures. Quarantine owns separate outputs and never changes main's verdict.
    failed = {row["id"] for row in plan["tests"] if row["flake"] is None}
    accelerated = False
    completed = False
    try:
        for name, tests in phases:
            if not tests:
                continue
            selection = state / (name + ".txt")
            selection.write_text("\n".join(row["selector"] for row in tests) + "\n")
            native = Path.cwd() / "tests/e2e/test-results" / name / ".last-run.json"
            native.unlink(missing_ok=True)
            if name == "status-main":
                accelerated = True
                subprocess.run(["docker", *FAST_COMPOSE, "--profile", "e2e", "up", "-d", "--no-deps",
                                "--force-recreate", SERVICE, NGINX], check=True)
            print(f"Status publication phase {name}: {len(tests)} selected tests", flush=True)
            result = browser([*selected_args(args), "--test-list=/status-suite/" + selection.name,
                              "--output=test-results/" + name],
                             ["-e", "PLAYWRIGHT_HTML_OUTPUT_DIR=playwright-report/" + name])
            main_ids = {row["id"] for row in tests if row["flake"] is None}
            if native.exists():
                outcome = json.loads(native.read_text())
                failed.difference_update(main_ids)
                # The native main file also owns tagged failures in an explicit
                # diagnostic --repeat-each run. Quarantine uses another file.
                failed.update(outcome["failedTests"])
                if outcome["status"] != "passed":
                    result = result or 1
            elif main_ids:
                raise RuntimeError("Playwright did not write its main last-run result")
            rc = rc or result
        if not args:
            # This retains the pinned lifetime, finally and independent backstop.
            cycle(state)
        completed = True
        return rc
    finally:
        try:
            if accelerated:
                subprocess.run(["docker", *COMPOSE, "--profile", "e2e", "up", "-d", "--no-deps",
                                "--force-recreate", SERVICE, NGINX], check=True)
        except BaseException:
            completed = False
            raise
        finally:
            last_run.parent.mkdir(parents=True, exist_ok=True)
            write_json(last_run, {"status": "passed" if completed and not rc else "failed",
                                  "failedTests": sorted(failed)})


def cycle(state):
    # Recreate nginx with its new upstream before photographing either lifetime.
    subprocess.run(["docker", *FAST_COMPOSE, "--profile", "e2e", "up", "-d", "--no-deps",
                    "--force-recreate", SERVICE, NGINX], check=True)
    suffix = os.environ.get("HILOS_STAND_SUFFIX", "")
    if suffix and not re.fullmatch(r"-s[1-9]", suffix):
        raise ValueError("Unsupported stand suffix")
    project = PROJECT + suffix
    compose = FAST_COMPOSE
    run_id = str(uuid.uuid4())
    manifest = {"runId": run_id, "project": project, "demo": str(Path.cwd()),
                "browserName": "hilos-status-browser-" + run_id}
    for role, service in (("daemon", SERVICE), ("nginx", NGINX)):
        container = inspect(docker(*compose, "ps", "-q", service))
        manifest[role] = {"id": container["Id"], "created": container["Created"], "service": service}
    control = Control(manifest, state)
    control.evidence()
    output = Path.cwd() / "tests/e2e/test-results/standalone-status"
    if output.exists():
        shutil.rmtree(output)
    write_json(state / "manifest.json", manifest)
    overlay = state / "browser.yml"
    overlay.write_text(json.dumps({"services": {RUNNER: {
        "volumes": [f"{state}:/status-control"],
        "environment": {"STATUS_CONTROL_DIR": "/status-control", "STATUS_RUN_ID": run_id},
    }}}))
    # Joining a service's PID namespace is itself a Compose dependency. Never
    # reconcile/recreate the already pinned daemon or its database from run.
    preparation = ["docker", *compose, "-f", "docker/docker-compose.e2e-restart.yml",
                   "-f", str(overlay), "--profile", "e2e", "run", "--rm", "--no-deps",
                   "--name", "hilos-status-prepare-" + run_id, RUNNER,
                   "node", ENTRY, "--config=playwright.status.config.ts",
                   "--output=test-results/standalone-status/prepare", "prepare.spec.ts"]
    try:
        subprocess.run(preparation, check=True, timeout=CYCLE_LEASE)
    finally:
        # A docker CLI timeout does not terminate its container by itself.
        remaining = docker("ps", "-aq", "--filter", "name=^/hilos-status-prepare-" + run_id + "$")
        if remaining:
            docker("rm", "-f", remaining)
    socket = os.environ["DOCKER_HOST"].removeprefix("unix://")
    repo = Path(__file__).resolve().parents[3]
    backstop_name = "hilos-status-backstop-" + run_id
    docker("run", "-d", "--rm", "--name", backstop_name,
           "--mount", f"type=bind,source={repo},target={repo},readonly",
           "--mount", f"type=bind,source={state},target={state}",
           "--mount", f"type=bind,source={socket},target={socket}",
           "--env", "DOCKER_HOST=" + os.environ["DOCKER_HOST"],
           os.environ["STATUS_HARNESS_IMAGE"], "python3", str(Path(__file__).resolve()),
           "watchdog", str(state))
    browser = None
    events = []
    try:
        browser = subprocess.Popen(["docker", *compose, "-f", str(overlay), "--profile", "e2e",
                                    "run", "--rm", "--no-deps", "--name", manifest["browserName"], RUNNER,
                                    "node", ENTRY, "--config=playwright.status.config.ts",
                                    "--output=test-results/standalone-status/loss", "loss.spec.ts"])
        sequence = 1
        deadline = time.monotonic() + CYCLE_LEASE
        while browser.poll() is None:
            if time.monotonic() >= deadline:
                raise TimeoutError("Standalone browser exceeded its bounded cycle")
            request = state / "request.json"
            if request.exists():
                payload = json.loads(request.read_text())
                request.unlink()
                try:
                    operation = validate_request(payload, run_id, sequence)
                    if (state / "backstop.json").exists():
                        raise ValueError("Independent recovery already fired")
                    result = control.operate(operation)
                    events.append({"operation": operation, **result})
                    write_json(state / "response.json", {"sequence": sequence, "result": result})
                    sequence += 1
                except Exception as error:
                    received = payload.get("sequence") if isinstance(payload, dict) else None
                    write_json(state / "response.json", {"sequence": received, "error": str(error)})
                    raise
            time.sleep(0.05)
        if browser.returncode:
            raise RuntimeError(f"Standalone browser failed: rc={browser.returncode}")
        if (state / "backstop.json").exists():
            raise RuntimeError("Independent recovery fired before completion")
    finally:
        recovered = False
        try:
            events.append({"operation": "finally", **control.recover()})
            recovered = True
            print("standalone-status evidence: " + json.dumps(events), flush=True)
        finally:
            if browser is not None and browser.poll() is None:
                docker("rm", "-f", manifest["browserName"])
                browser.wait(timeout=30)
            if recovered:
                (state / "finished").touch()
            else:
                # Give the independent process its own recovery attempt now.
                write_json(state / "lease.json", {"until": 0})
            # Join the backstop instead of orphaning a future restart.
            deadline = time.monotonic() + 90
            result = state / "watchdog-result.json"
            while not result.exists():
                if time.monotonic() >= deadline:
                    raise TimeoutError("Independent recovery did not finish")
                time.sleep(0.1)
            error = json.loads(result.read_text())["error"]
            # --rm may still be completing when its final result becomes visible.
            # Wait for removal rather than racing docker rm against auto-removal.
            while docker("ps", "-aq", "--filter", "name=^/" + backstop_name + "$"):
                if time.monotonic() >= deadline:
                    raise TimeoutError("Independent recovery container did not leave")
                time.sleep(0.1)
            if error:
                raise RuntimeError(f"Independent recovery failed: {error}")


def main():
    if sys.argv[1:] in (["unit"], ["cycle"]):
        import unittest
        unit_suite = unittest.defaultTestLoader.discover(str(Path(__file__).parent), "test_*.py")
        if not unittest.TextTestRunner(verbosity=2).run(unit_suite).wasSuccessful():
            return 1
        if sys.argv[1:] == ["unit"]:
            return 0
    if len(sys.argv) == 3 and sys.argv[1] == "watchdog":
        watchdog(Path(sys.argv[2]))
        return 0
    if sys.argv[1:] != ["cycle"] and sys.argv[1:2] != ["e2e"]:
        raise ValueError("usage: standalone-status/run unit|cycle|e2e [Playwright args]")
    # TERM is a failed run, but still executes the exact-container finally.
    def interrupted(signum, _frame):
        raise RuntimeError(f"Standalone fixture interrupted by signal {signum}")
    for signum in (signal.SIGTERM, signal.SIGINT):
        signal.signal(signum, interrupted)
    if sys.argv[1:2] == ["e2e"]:
        return suite(Path(os.environ["STATUS_CONTROL_DIR"]), sys.argv[2:])
    cycle(Path(os.environ["STATUS_CONTROL_DIR"]))
    return 0


if __name__ == "__main__":
    sys.exit(main())

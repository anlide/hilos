"""Bounded diagnostics for one backstop startup, with a separate host reader.

Handles belong to this controller invocation, not to product DB/runtime state.
The host reader never starts, stops or removes a product/fixture container.
"""

import base64
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time

STARTUP_TIMEOUT = 30
FLUSH_TIMEOUT = 3
POLL = 0.05
READ_TIMEOUT = 0.4
READ_LIMIT = 16384
ARTIFACT = "startup/backstop-startup.json"


def stamp():
    return {"utc": datetime.now(timezone.utc).isoformat(), "monotonic": time.monotonic()}


def unavailable(reason):
    return {"status": "unavailable", "reason": str(reason)}


def write_json(path, value):
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(value))
    temporary.replace(path)


def load(path):
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError) as error:
        return unavailable(error)


def read(path):
    try:
        with path.open("rb") as stream:
            value = stream.read(READ_LIMIT + 1)
        return {"text": value[:READ_LIMIT].decode("utf-8", "replace"),
                "truncated": len(value) > READ_LIMIT}
    except OSError as error:
        return unavailable(error)


def link(path):
    try:
        return os.readlink(path)
    except OSError as error:
        return unavailable(error)


def process(pid, proc=Path("/proc"), cgroup_root=Path("/sys/fs/cgroup")):
    directory = proc / str(pid)
    result = {"pidInReaderNamespace": pid, "pidNamespace": link(directory / "ns/pid")}
    for name in ("stat", "io", "cgroup", "wchan", "schedstat"):
        result[name] = read(directory / name)
    status = read(directory / "status")
    result["namespacePids"] = unavailable("NSpid is not readable")
    for line in status.get("text", "").splitlines():
        if line.startswith("NSpid:"):
            result["namespacePids"] = [int(value) for value in line.split()[1:]]
    result["cgroupMetrics"] = unavailable("No accessible cgroup v2 mapping")
    for line in result["cgroup"].get("text", "").splitlines():
        if line.startswith("0::"):
            directory = (cgroup_root / line[3:].lstrip("/")).resolve()
            if not directory.is_relative_to(cgroup_root.resolve()):
                result["cgroupMetrics"] = unavailable("Cgroup path is outside reader mount")
                break
            result["cgroupMetrics"] = {name: read(directory / name) for name in
                                       ("cpu.stat", "io.stat", "cpu.pressure", "io.pressure",
                                        "memory.pressure")}
    return result


def host_snapshot(request, client, proc=Path("/proc")):
    """Read only named engine processes and the exact startup argv, within a cap."""
    result = {"at": stamp(), "processes": [],
              "clientHostPid": unavailable("Exact argv and PID namespace mapping not observed"),
              "pressure": {name: read(proc / "pressure" / name) for name in ("cpu", "io", "memory")}}
    deadline = time.monotonic() + READ_TIMEOUT
    scanned = 0
    try:
        with os.scandir(proc) as entries:
            for entry in entries:
                if scanned >= 4096 or time.monotonic() >= deadline:
                    result["discovery"] = unavailable("Bounded process discovery cap reached")
                    break
                if not entry.name.isdigit():
                    continue
                scanned += 1
                directory = proc / entry.name
                name = read(directory / "comm").get("text", "").strip()
                if name not in ("dockerd", "containerd", "docker"):
                    continue
                if name == "docker":
                    argv = read(directory / "cmdline").get("text", "").rstrip("\0").split("\0")
                    if argv != request["argv"]:
                        continue
                facts = process(int(entry.name), proc)
                facts["name"] = name
                result["processes"].append(facts)
                if (name == "docker" and facts["pidNamespace"] == client.get("pidNamespace")
                        and isinstance(facts["namespacePids"], list)
                        and facts["namespacePids"][-1:] == [client.get("pidInReaderNamespace")]):
                    result["clientHostPid"] = int(entry.name)
    except OSError as error:
        result["discovery"] = unavailable(error)
    for name in ("dockerd", "containerd"):
        if not any(item["name"] == name for item in result["processes"]):
            result[name] = unavailable("Named process not visible in reader /proc")
    return result


def stream(value):
    if isinstance(value, bytes):
        return {"text": value.decode("utf-8", "replace"), "type": "bytes",
                "base64": base64.b64encode(value).decode("ascii")}
    return {"text": value, "type": "none" if value is None else "text"}


def handoff(state, output):
    """Run both before browser finally and from host EXIT before state removal."""
    request = state / "startup-request.json"
    if not request.exists():
        return
    host = state / "startup-host.json"
    if not host.exists() and (state / "startup-unavailable.json").exists():
        host = state / "startup-unavailable.json"
    artifact = {"request": load(request), "client": load(state / "startup-client.json"),
                "outcome": load(state / "startup-outcome.json"), "host": load(host)}
    destination = output / ARTIFACT
    if not destination.parent.exists():
        destination.parent.mkdir(parents=True, exist_ok=True)
        # The container writer and ordinary host reader have different UIDs.
        # Only this invocation's diagnostic directory is shared for atomic handoff.
        destination.parent.chmod(0o777)
    write_json(destination, artifact)


class Startup:
    def __init__(self, state, output, run_id, name, image):
        self.state = state
        self.output = output
        self.identity = {"runId": run_id, "name": name, "image": image}

    def diagnostic(self, operation):
        try:
            return operation()
        except Exception as error:
            # Diagnostics never replace a native startup result or exception.
            try:
                print("standalone-status startup observer: " + str(error), file=sys.stderr, flush=True)
            except Exception:
                pass

    def wait_for(self, filename):
        deadline = time.monotonic() + FLUSH_TIMEOUT
        while not (self.state / filename).exists():
            if time.monotonic() >= deadline or (self.state / "startup-unavailable.json").exists():
                return
            time.sleep(POLL)

    def execute(self, argv):
        begin = stamp()
        request = {**self.identity, "argv": argv, "timeout": STARTUP_TIMEOUT, "begin": begin,
                   "runner": self.diagnostic(lambda: process(os.getpid())) or unavailable("Runner PID facts unavailable")}
        self.diagnostic(lambda: write_json(self.state / "startup-request.json", request))
        self.diagnostic(lambda: self.wait_for("startup-ready.json"))
        child = None
        outcome = {"begin": stamp(), "rc": None, "exception": None,
                   "stdout": stream(None), "stderr": stream(None)}
        try:
            child = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            self.diagnostic(lambda: write_json(self.state / "startup-client.json", process(child.pid)))
            stdout, stderr = child.communicate(timeout=STARTUP_TIMEOUT)
            outcome.update(rc=child.returncode, stdout=stream(stdout), stderr=stream(stderr))
            if stderr:
                self.diagnostic(lambda: sys.stderr.write(stderr))
            if child.returncode:
                raise subprocess.CalledProcessError(child.returncode, argv, output=stdout, stderr=stderr)
            return stdout.strip()
        except BaseException as error:
            if child is not None:
                # Match check_output/run: kill the CLI, preserve the original partial bytes.
                self.diagnostic(child.kill)
                self.diagnostic(child.wait)
            outcome.update(exception={"type": type(error).__name__, "message": str(error)},
                           rc=getattr(error, "returncode", None),
                           stdout=stream(getattr(error, "output", None)),
                           stderr=stream(getattr(error, "stderr", None)))
            raise
        finally:
            outcome["end"] = stamp()
            self.diagnostic(lambda: write_json(self.state / "startup-outcome.json", outcome))
            self.diagnostic(lambda: self.wait_for("startup-host.json"))
            self.diagnostic(lambda: handoff(self.state, self.output))
            if child is not None:
                for pipe in (child.stdout, child.stderr):
                    if pipe is not None:
                        self.diagnostic(pipe.close)


def docker_inspect(endpoint, name):
    argv = ["docker", "--host", endpoint, "inspect", "--format",
            '{{json .Id}} {{json .Name}} {{json .Created}} {{json .State}} {{json .Image}}', name]
    begin = stamp()
    try:
        response = subprocess.run(argv, capture_output=True, text=True, timeout=READ_TIMEOUT)
        return {"begin": begin, "end": stamp(), "argv": argv, "rc": response.returncode,
                "stdout": stream(response.stdout), "stderr": stream(response.stderr)}
    except (OSError, subprocess.SubprocessError) as error:
        return {"begin": begin, "end": stamp(), "argv": argv, "exception": type(error).__name__,
                "reason": str(error), "stdout": stream(getattr(error, "output", None)),
                "stderr": stream(getattr(error, "stderr", None))}


def observe(state, endpoint):
    stopped = False

    def stop(_signum, _frame):
        nonlocal stopped
        stopped = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    while not (state / "startup-request.json").exists():
        if stopped or (state / "startup-stop").exists():
            return
        time.sleep(POLL)
    request = load(state / "startup-request.json")
    evidence = {"begin": stamp(), "reader": process(os.getpid()), "samples": [], "docker": [],
                "clientHostPidMeaning": "PID in host shell reader namespace; see namespace facts",
                "events": unavailable("Event reader not started")}
    events = None
    event_output = state / "startup-events.txt"
    event_error = state / "startup-events-error.txt"
    try:
        # Exact-name filtering starts before the one mutating client is released.
        with event_output.open("w+") as stdout, event_error.open("w+") as stderr:
            try:
                events = subprocess.Popen(["docker", "--host", endpoint, "events", "--filter",
                                           "type=container", "--filter", "container=" + request["name"],
                                           "--since", request["begin"]["utc"], "--format", "{{json .}}"], stdout=stdout, stderr=stderr)
            except OSError as error:
                evidence["events"] = unavailable(error)
            deadline = time.monotonic() + STARTUP_TIMEOUT + FLUSH_TIMEOUT
            evidence["samples"].append(host_snapshot(request, {}))
            evidence["docker"].append(docker_inspect(endpoint, request["name"]))
            write_json(state / "startup-ready.json", {"at": stamp()})
            next_sample = time.monotonic()
            while not stopped and not (state / "startup-stop").exists():
                if (state / "startup-outcome.json").exists() or time.monotonic() >= deadline:
                    break
                if (event_output.stat().st_size > READ_LIMIT or event_error.stat().st_size > READ_LIMIT):
                    evidence["eventOutputLimit"] = unavailable("Bounded event output cap reached")
                    break
                if time.monotonic() >= next_sample:
                    evidence["samples"].append(host_snapshot(request, load(state / "startup-client.json")))
                    evidence["docker"].append(docker_inspect(endpoint, request["name"]))
                    next_sample = time.monotonic() + 1
                time.sleep(POLL)
            evidence["closeReason"] = ("native outcome" if (state / "startup-outcome.json").exists()
                                       else "host stop" if stopped or (state / "startup-stop").exists()
                                       else "event output cap" if "eventOutputLimit" in evidence
                                       else "observer deadline")
            if not stopped:
                evidence["samples"].append(host_snapshot(request, load(state / "startup-client.json")))
                evidence["docker"].append(docker_inspect(endpoint, request["name"]))
    except Exception as error:
        evidence["error"] = unavailable(error)
    finally:
        if events is not None:
            try:
                events.terminate()
                try:
                    events.wait(timeout=READ_TIMEOUT)
                except subprocess.TimeoutExpired:
                    events.kill()
                    events.wait(timeout=READ_TIMEOUT)
            except (OSError, subprocess.SubprocessError) as error:
                evidence["eventCloseError"] = unavailable(error)
            evidence["events"] = {"rc": events.returncode, "stdout": read(event_output),
                                  "stderr": read(event_error)}
        evidence["end"] = stamp()
        write_json(state / "startup-host.json", evidence)


if __name__ == "__main__":
    state = Path(sys.argv[2])
    if sys.argv[1] == "observe":
        observe(state, sys.argv[3])
    elif sys.argv[1] == "handoff":
        handoff(state, Path(sys.argv[3]))

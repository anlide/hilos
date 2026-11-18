"""Best-effort Galera wait evidence for one browser step; never an e2e verdict."""

import json
import os
import selectors
import subprocess
import threading
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path


POLL_SECONDS = 1
SNAPSHOT_SECONDS = 2
MAX_SNAPSHOTS = 30
MAX_EPISODES = 5
SQL_DEADLINE = 0.8
PREPARE_SECONDS = 20
JOIN_SECONDS = 30
PROBE = ("SELECT CONNECTION_ID(), "
         "EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='information_schema' "
         "AND TABLE_NAME='INNODB_LOCKS'), "
         "EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='information_schema' "
         "AND TABLE_NAME='INNODB_LOCK_WAITS')")
LONG_QUERIES = ("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() "
                "AND COMMAND IN ('Query','Execute') AND INFO IS NOT NULL AND TIME >= 3")
WAITS = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS"
PRESSURE = ("SHOW GLOBAL STATUS WHERE Variable_name IN "
            "('wsrep_local_recv_queue','wsrep_flow_control_active')")
DETAILS = (
    ("PROCESSLIST", "SHOW FULL PROCESSLIST"),
    ("INNODB_TRX", "SELECT * FROM information_schema.INNODB_TRX"),
    ("INNODB_LOCK_WAITS", "SELECT * FROM information_schema.INNODB_LOCK_WAITS"),
    ("INNODB_LOCKS", "SELECT * FROM information_schema.INNODB_LOCKS"),
    ("INNODB STATUS", "SHOW ENGINE INNODB STATUS"),
    ("wsrep", "SHOW GLOBAL STATUS LIKE 'wsrep_%'"),
)
SETTINGS = ("timeouts and provider options", "SHOW VARIABLES WHERE Variable_name LIKE '%timeout%' "
            "OR Variable_name = 'wsrep_provider_options'")


def utc():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def warn(error):
    print(f"cluster e2e: galera waits: {error}", flush=True)


class _Session:
    """One socket-authenticated client, framed SQL and nonblocking pipe drainage."""

    def __init__(self, member):
        self.member = member
        self.connection_id = None
        self.probe = None
        self.process = subprocess.Popen(
            ["docker", "exec", "-i", "-u", "mysql", member.container, "mariadb", "-N", "-B",
             "--unbuffered", "--force", "--skip-reconnect", "--connect-timeout=2",
             "--init-command=SET SESSION wsrep_sync_wait=0, max_statement_time=0.7"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, bufsize=0)
        os.set_blocking(self.process.stdin.fileno(), False)
        os.set_blocking(self.process.stdout.fileno(), False)

    def begin(self, queries):
        self.marker = "galera_" + uuid.uuid4().hex
        self.queries = queries
        self.rows = {name: [] for name, _ in queries}
        self.current = None
        self.buffer = b""
        self.done = False
        self.error = ""
        statements = []
        for index, (_, sql) in enumerate(queries):
            if index == 0 and sql == PROBE:
                # The metadata probe itself must be the first SELECT in a new session.
                self.current = queries[0][0]
            else:
                statements.append(f"SELECT '{self.marker}:{index}';")
            statements.append(sql + ";")
        statements.append(f"SELECT '{self.marker}:end';")
        self.pending = ("\n".join(statements) + "\n").encode()

    def receive(self):
        chunk = os.read(self.process.stdout.fileno(), 65536)
        if not chunk:
            raise OSError("SQL client EOF")
        self.buffer += chunk
        while b"\n" in self.buffer:
            line, self.buffer = self.buffer.split(b"\n", 1)
            line = line.decode(errors="replace").rstrip("\r")
            if line == f"{self.marker}:end":
                self.done = True
            elif line.startswith(self.marker + ":"):
                self.current = self.queries[int(line.split(":")[-1])][0]
            elif self.current is not None:
                self.rows[self.current].append(line)
            elif line:
                self.error += line + "\n"

    def result(self):
        return {name: "\n".join(rows) for name, rows in self.rows.items()}

    def close(self):
        # EOF normally ends the remote client. If SQL is stuck, kill only the
        # connection id this session read, before terminating the local Docker CLI.
        self.process.stdin.close()
        try:
            self.process.wait(timeout=0.2)
        except subprocess.TimeoutExpired:
            if self.connection_id is not None:
                try:
                    subprocess.run(
                        ["docker", "exec", "-u", "mysql", self.member.container, "mariadb",
                         "--connect-timeout=1", "--init-command=SET SESSION wsrep_sync_wait=0",
                         "-e", f"KILL CONNECTION {self.connection_id}"],
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=0.7)
                except (OSError, subprocess.TimeoutExpired) as error:
                    warn(f"{self.member.service}: own connection cleanup: {error}")
            else:
                warn(f"{self.member.service}: remote client exit unconfirmed (no connection id)")
            self.process.kill()
            try:
                self.process.wait(timeout=0.2)
            except subprocess.TimeoutExpired:
                warn(f"{self.member.service}: Docker client did not exit")
        self.process.stdout.close()


class GaleraWaits:
    """Poll all members, then capture bounded all-member episodes on a wait."""

    def __init__(self, stand, directory):
        self.stand = stand
        self.directory = Path(directory)
        self.stop = threading.Event()
        self.sessions = {}
        self.pressure = {member.service: 0 for member in stand.database_sql_backends}
        self.probes = {}
        self.episodes = 0
        self.episode = None
        self.snapshots = 0
        self.last_snapshot = 0
        self.prepared = False
        self.manifest = {"started": utc(), "episodes": []}

    def _prepare(self):
        self.directory.parent.mkdir(parents=True, exist_ok=True)
        if self.directory.is_symlink():
            raise OSError("galera output must not be a symlink")
        try:
            self._prepare_contents()
        except PermissionError:
            self._repair_directory_permissions()
            self._prepare_contents()

        self.prepared = True

    def _prepare_contents(self):
        self.directory.mkdir(exist_ok=True)
        previous = [path for path in self.directory.iterdir() if path.name != "archive"]
        if previous:
            archive = self.directory / "archive" / uuid.uuid4().hex
            if archive.parent.is_symlink():
                raise OSError("galera archive must not be a symlink")
            archive.mkdir(parents=True)
            for path in previous:
                path.rename(archive / path.name)
        self._manifest()

    def _repair_directory_permissions(self):
        if self.directory.is_symlink():
            raise OSError("galera output must not be a symlink")
        # A fresh stand can create the logs parent as root. Give only this
        # observer's directory to the harness uid; leave node logs untouched.
        helper = "galera-waits-" + uuid.uuid4().hex
        try:
            subprocess.run(
                ["docker", "run", "--rm", "--name", helper, "--network", "none", "--user", "0",
                 "--mount", f"type=bind,source={self.directory.parent},target=/logs",
                 "--entrypoint", "sh", self.stand.database_sql_backends[0].image,
                 "-c", 'test ! -L /logs/galera && mkdir -p /logs/galera && '
                 'chown "$1:$2" /logs/galera', "galera", str(os.getuid()), str(os.getgid())],
                check=True, stdout=subprocess.DEVNULL, timeout=PREPARE_SECONDS)
        except subprocess.TimeoutExpired:
            try:
                subprocess.run(["docker", "rm", "-f", helper], timeout=2,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            except (OSError, subprocess.TimeoutExpired) as error:
                warn(f"directory helper cleanup: {error}")
            raise

    def _manifest(self):
        (self.directory / "run.json").write_text(json.dumps(self.manifest, indent=2) + "\n")

    def _diagnostic(self, member, message):
        warn(f"{member}: {message}")
        with (self.directory / "diagnostics.txt").open("a", encoding="utf-8") as output:
            output.write(f"[{utc()}] {member}\n{message}\n")

    def _exchange(self, requests):
        """Queue every member before collecting; a single deadline bounds the batch."""
        results = {}
        with selectors.DefaultSelector() as selector:
            for name, queries in requests.items():
                session = self.sessions[name]
                session.begin(queries)
                try:
                    # Attempt every send before draining any member's response.
                    count = os.write(session.process.stdin.fileno(), session.pending)
                    session.pending = session.pending[count:]
                except BlockingIOError:
                    pass
                except OSError as error:
                    results[name] = {"error": str(error)}
                    continue
                if session.pending:
                    selector.register(session.process.stdin, selectors.EVENT_WRITE, (name, True))
                selector.register(session.process.stdout, selectors.EVENT_READ, (name, False))
            deadline = time.monotonic() + SQL_DEADLINE
            pending = set(requests) - results.keys()
            while pending and not self.stop.is_set() and time.monotonic() < deadline:
                for key, _ in selector.select(min(0.05, max(0, deadline - time.monotonic()))):
                    name, writing = key.data
                    session = self.sessions[name]
                    if name not in pending:
                        continue
                    try:
                        if writing:
                            count = os.write(key.fd, session.pending)
                            session.pending = session.pending[count:]
                            if not session.pending:
                                selector.unregister(key.fileobj)
                        else:
                            session.receive()
                            if session.done:
                                results[name] = session.result()
                                if session.error:
                                    results[name]["error"] = session.error
                                pending.remove(name)
                    except BlockingIOError:
                        continue
                    except (OSError, ValueError) as error:
                        results[name] = {**session.result(), "error": str(error)}
                        pending.remove(name)
            for name in pending:
                results[name] = {**self.sessions[name].result(),
                                 "error": "stopped" if self.stop.is_set() else "SQL deadline exceeded"}
        return results

    def _batch(self, queries):
        results = self._exchange({name: queries for name in self.sessions})
        for name, rows in results.items():
            if "error" in rows:
                if rows["error"] != "stopped":
                    self._diagnostic(name, rows["error"])
                self.sessions[name].close()
                del self.sessions[name]
        return results

    def _connect(self):
        for member in self.stand.database_sql_backends:
            if member.service not in self.sessions:
                try:
                    self.sessions[member.service] = _Session(member)
                except OSError as error:
                    self._diagnostic(member.service, str(error))
        fresh = {name: [("probe", PROBE)] for name, session in self.sessions.items()
                 if session.probe is None}
        for name, rows in self._exchange(fresh).items():
            session = self.sessions[name]
            session.probe = rows
            self.probes[name] = rows
            if rows.get("error") == "stopped":
                session.close()
                del self.sessions[name]
                continue
            try:
                connection, locks, waits = rows["probe"].split("\t")
                session.connection_id = int(connection)
                if locks != "1" or waits != "1":
                    self._diagnostic(name, f"metadata probe: {rows}")
            except (KeyError, ValueError):
                self._diagnostic(name, f"metadata probe failed: {rows}")
                session.close()
                del self.sessions[name]

    def _poll(self):
        self._connect()
        rows = self._batch([("long queries", LONG_QUERIES), ("lock waits", WAITS),
                            ("pressure", PRESSURE)])
        if self.stop.is_set():
            return None
        active, complete = False, len(rows) == len(self.pressure)
        for name, result in rows.items():
            for label in ("long queries", "lock waits"):
                value = result.get(label, "ERROR missing response")
                lines = value.split("\n")
                # Only finished unsigned COUNT rows are evidence; merged stderr
                # may follow a genuine positive without invalidating that row.
                counts = [line for line in lines if line.isascii() and line.isdecimal()]
                active |= any(int(count) > 0 for count in counts)
                if len(lines) != 1 or len(counts) != 1:
                    complete = False
                    self._diagnostic(name, f"invalid {label}: {value}")
            if "error" in result:
                self.pressure[name] = 0
                complete = False
                self._diagnostic(name, f"poll failed: {result}")
                continue
            try:
                pressure = result.get("pressure", "ERROR missing response")
                if "ERROR " in pressure:
                    raise ValueError(f"pressure query failed: {pressure}")
                status = dict(line.split("\t", 1) for line in pressure.splitlines())
                queued = float(status["wsrep_local_recv_queue"]) > 0
                flow_value = status["wsrep_flow_control_active"].upper()
                if flow_value not in ("ON", "YES", "TRUE", "1", "OFF", "NO", "FALSE", "0"):
                    raise ValueError(f"unknown current flow control: {flow_value}")
                flow = flow_value in ("ON", "YES", "TRUE", "1")
                self.pressure[name] = self.pressure[name] + 1 if queued or flow else 0
                active |= self.pressure[name] >= 3
            except (ValueError, KeyError) as error:
                self.pressure[name] = 0
                complete = False
                self._diagnostic(name, str(error))
        for name in self.pressure.keys() - rows.keys():
            self.pressure[name] = 0
        return True if active else (False if complete else None)

    def _capture(self, reason, first=False):
        queries = list(DETAILS) + ([SETTINGS] if first else [])
        rows = self._batch(queries)
        with self.episode.open("a", encoding="utf-8") as output:
            output.write(f"snapshot {self.snapshots + 1}: {reason}\n")
            for member in self.stand.database_sql_backends:
                name = member.service
                output.write(f"[{utc()}] {name}\n")
                if first:
                    output.write(f"metadata probe: {self.probes.get(name, 'unavailable')}\n")
                result = rows.get(name, {"error": "SQL session unavailable"})
                for label, sql in queries:
                    output.write(f"-- {label}\n{sql};\n{result.get(label, '(unavailable)') or '(empty)'}\n")
                if "error" in result:
                    output.write(f"ERROR: {result['error']}\n")
        self.snapshots += 1

    def tick(self, now):
        active = self._poll()
        if self.stop.is_set():
            return
        if self.episode is None:
            if active is not True or self.episodes >= MAX_EPISODES:
                return
            self.episodes += 1
            self.snapshots = 0
            self.episode = self.directory / (utc() + ".txt")
            self.manifest["episodes"].append(self.episode.name)
            self._manifest()
            self._capture("wait threshold", first=True)
            self.last_snapshot = now
        elif active is False:
            self._capture("released")
            self.episode = None
        elif now - self.last_snapshot >= SNAPSHOT_SECONDS and self.snapshots < MAX_SNAPSHOTS - 1:
            self._capture("waiting" if active else "poll unknown")
            self.last_snapshot = now
        # Keep a capped episode open until observed release; reserve its final slot.

    def run(self):
        try:
            self._prepare()
            while not self.stop.is_set():
                started = time.monotonic()
                self.tick(started)
                # Discard only failed transports, so the next session repeats its probe.
                for name, session in list(self.sessions.items()):
                    if session.error or not session.done:
                        session.close()
                        del self.sessions[name]
                self.stop.wait(max(0, POLL_SECONDS - (time.monotonic() - started)))
        except Exception as error:
            warn(error)
        finally:
            for session in self.sessions.values():
                try:
                    session.close()
                except Exception as error:
                    warn(error)
            if self.prepared:
                try:
                    self.manifest["finished"] = utc()
                    self._manifest()
                except OSError as error:
                    warn(error)

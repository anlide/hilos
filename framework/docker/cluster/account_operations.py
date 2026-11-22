"""Shared live cluster helpers for account operation scenarios."""

import json
import os
import re
import subprocess
import sys
import threading
import time
import uuid
from contextlib import ExitStack
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from pathlib import Path

import control


HOLDER = "hilos_sessions_library"
BACKUP = "hilos_backup"
PROBE = "test:account:operation-probe"
RECOVERY = f"{HOLDER}/hilos_account_erasure_recovery"
# Mirrors CommandChannelWindows::CHANNEL_HELD_SECONDS, the outer command deadline.
CALLER_DEADLINE = 30


@dataclass(frozen=True)
class ArchiveAddress:
    node: str
    root: str
    environment: str


class StandUnusable(RuntimeError):
    """Account cleanup could not establish a safe stand for another scenario."""

    def __init__(self, diagnosis, original=None):
        self.original = original
        super().__init__(diagnosis)


class BackupFailed(AssertionError):
    """The exact error sidecar has been published."""


SCHEDULE = "hilos_backup_daily_full"
CREATE = "test:backup:run-schedule"
RESTORE_REQUEST = "backup:restore-request"
BACKUP_ID = r"[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}"
BACKUP_SCOPE = r"full|schema-seed|schema-only"
BATCH_NAME = r"\d{4}(?:-\d{2}){5}Z"
BATCH_TIME_FORMAT = "%Y-%m-%d-%H-%M-%SZ"
BACKUP_LOG = f"agent-{BACKUP}.log"
BACKUP_ERROR = f"agent-{BACKUP}.error.log"
PRUNE_LOG = "agent-hilos_log_store.log"
BACKUP_STREAMS = ("daemon.log", BACKUP_LOG, BACKUP_ERROR)
JOURNAL_STREAMS = (*BACKUP_STREAMS, PRUNE_LOG)
TAKEOUT_MARKER = ".hilos-taken.json"
BACKUP_WORKER_START = re.compile(r"Agent started on worker \[workerIndex=(\d+)\]")
BACKUP_WORKER_STOP = re.compile(
    r"Agent stopped(?: \([^)]*\))? (?:on worker|during worker cleanup) \[workerIndex=\d+\]")
ARCHIVE_PATH_GUARD = '''
safe_full() {
    test "$(realpath -m -- "$1/full")" = "$1/full"
}
'''


@dataclass
class CreateAttempt:
    address: ArchiveAddress
    owner: tuple
    earliest_id: str
    inventory: set
    marks: dict
    answer: control.Outcome | None = None
    terminal: bool = False
    sidecar_only: bool = False
    publication: tuple | None = None
    digest: str | None = None


@dataclass
class StagedArchive:
    address: ArchiveAddress
    token: str
    published: bool = False


@dataclass
class RestoreAttempt:
    node: str
    backup_id: str
    marks: dict
    answer: control.Outcome | None = None
    windows: dict = field(default_factory=dict)
    opening: bool = False
    settled: bool = False
    owner: tuple | None = None


@dataclass
class BackupJournal:
    path: Path
    first_batch: str = ""
    live: "JournalSegment | None" = None
    batches: dict = field(default_factory=dict)
    absent_batches: set = field(default_factory=set)
    entries: list = field(default_factory=list)
    origin: int = 0
    fresh: int = 0


@dataclass
class JournalInventory:
    present: dict
    absent: set


@dataclass(frozen=True)
class JournalFile:
    path: Path
    identity: tuple
    size: int
    modified_ns: int


@dataclass(frozen=True)
class JournalSegment:
    file: JournalFile
    content: bytes


def log_cursor(path):
    try:
        stat = path.stat()
        return control.LogMark(stat.st_ino, stat.st_size)
    except FileNotFoundError:
        return control.LogMark(None, 0)


def log_tail(path, mark):
    try:
        with path.open("rb") as stream:
            stat = os.fstat(stream.fileno())
            assert mark.inode in (None, stat.st_ino) and stat.st_size >= mark.size, f"log cursor lost: {path.name}"
            stream.seek(mark.size)
            return stream.read().decode("utf-8", errors="replace").splitlines()
    except FileNotFoundError:
        assert mark.inode is None, f"log cursor lost: {path.name}"
        return []


def journal_segment(path, mark):
    try:
        with path.open("rb") as stream:
            stat = os.fstat(stream.fileno())
            assert mark.inode in (None, stat.st_ino) and stat.st_size >= mark.size, f"log cursor lost: {path.name}"
            stream.seek(mark.size)
            content = stream.read()
            assert not content or content.endswith(b"\n"), f"incomplete log entry in {path.name}"
            return control.LogMark(stat.st_ino, stream.tell()), list(log_entries(
                content.decode("utf-8", errors="replace").splitlines()))
    except FileNotFoundError:
        assert mark.inode is None, f"log cursor lost: {path.name}"
        return mark, []


def archive_members(directory):
    try:
        before = directory.stat()
        entries = {entry.name for entry in directory.iterdir()}
        after = directory.stat()
    except FileNotFoundError:
        return set(), False
    stable = (before.st_dev, before.st_ino, before.st_mtime_ns, before.st_ctime_ns) == (
        after.st_dev, after.st_ino, after.st_mtime_ns, after.st_ctime_ns)
    # The marker remains through file deletion. The last thing a successful
    # prune leaves before rmdir is an empty directory.
    complete = stable and TAKEOUT_MARKER not in entries and any(name.endswith(".log") for name in entries)
    return entries, complete


def rotated_logs(path, first_batch):
    # Follow the carrier in order, including the unpublished destination of a
    # cross-device copy. Listing archive before .incoming can miss both sides.
    batches = {}
    root = path.parent
    vanished = set()
    for entry in root.glob(f"staging/*/{path.name}"):
        name = entry.parent.name
        if name >= first_batch and re.fullmatch(BATCH_NAME, name):
            candidate = journal_file(entry)
            if candidate is None:
                vanished.add(name)
            else:
                batches[name] = candidate
    for incoming in root.glob("archive/.incoming-*"):
        name = incoming.name.removeprefix(".incoming-")
        diagnosis = f"log batch in transit: {path.name}"
        assert re.fullmatch(BATCH_NAME, name) and name < first_batch, diagnosis
        try:
            datetime.strptime(name, BATCH_TIME_FORMAT)
        except ValueError as error:
            raise AssertionError(diagnosis) from error
    absent = set()
    for directory in root.glob("archive/*"):
        name = directory.name
        if name < first_batch or not re.fullmatch(BATCH_NAME, name):
            continue
        entries, complete = archive_members(directory)
        if path.name not in entries:
            if complete and journal_file(root / "staging" / name) is None:
                absent.add(name)
            continue
        candidate = journal_file(directory / path.name)
        assert candidate is not None, f"missing journal batch {name}: {path.name}"
        previous = batches.get(name)
        if previous is not None:
            assert candidate.identity == previous.identity, f"ambiguous log batch {name}: {path.name}"
            assert candidate.size >= previous.size, f"journal segment shortened: {path.name}"
        batches[name] = candidate
        vanished.discard(name)
    assert not vanished, f"lost journal batch: {path.name}"
    return JournalInventory(batches, absent)


def journal_file(path, stream=None):
    try:
        stat = os.fstat(stream.fileno()) if stream is not None else path.stat()
    except FileNotFoundError:
        return None
    return JournalFile(path, (stat.st_dev, stat.st_ino), stat.st_size, stat.st_mtime_ns)


def same_journal_file(first, second):
    return (first.identity, first.size, first.modified_ns) == (second.identity, second.size, second.modified_ns)


def carried_to_archive(source, destination):
    return (source.path.parent.parent.name == "staging" and
            destination.path.parent.parent.name == "archive")


def read_backup_segment(files, candidate, moved_to=None):
    """Keep the inode open through confirmation, including after a carrier rename."""
    try:
        stream = files.enter_context(candidate.path.open("rb"))
    except FileNotFoundError as error:
        if moved_to is None:
            raise AssertionError(f"missing journal segment: {candidate.path.name}") from error
        try:
            stream = files.enter_context(moved_to.open("rb"))
        except FileNotFoundError as missing:
            raise AssertionError(f"missing journal segment: {candidate.path.name}") from missing
    observed = journal_file(Path(stream.name), stream)
    assert observed.identity == candidate.identity, f"journal segment replaced: {candidate.path.name}"
    assert observed.size >= candidate.size, f"journal segment shortened: {candidate.path.name}"
    content = stream.read()
    assert len(content) == observed.size and (not content or content.endswith(b"\n")), \
        f"incomplete or unstable journal entry in {candidate.path.name}"
    return JournalSegment(observed, content), stream


def confirm_journal_read(journal, inventory, batches, live, opened, files):
    path = journal.path
    latest = rotated_logs(path, journal.first_batch)
    diagnosis = f"log cursor lost: {path.name} inventory changed during journal read"
    assert not latest.present.keys() - inventory.present.keys(), diagnosis
    absent = journal.absent_batches | inventory.absent | latest.absent
    assert not absent.intersection(batches), diagnosis
    for name in inventory.present:
        expected = batches[name].file
        actual = latest.present.get(name)
        if actual is None:
            prior = journal.batches.get(name)
            assert prior is not None and same_journal_file(prior.file, expected), diagnosis
            continue
        content = batches[name].content
        if not same_journal_file(expected, actual):
            assert carried_to_archive(expected, actual), diagnosis
            copied, stream = read_backup_segment(files, actual)
            assert copied.content == content and same_journal_file(copied.file, actual), diagnosis
            opened.append((copied, stream))
        batches[name] = JournalSegment(actual, content)
    current = journal_file(path)
    assert (live is None and current is None or
            live is not None and current is not None and same_journal_file(live.file, current)), diagnosis
    for segment, stream in opened:
        assert same_journal_file(segment.file, journal_file(segment.file.path, stream)), \
            f"{path.name} journal segment changed after reading"
    return absent


def read_backup_journal(journal, *, stopped=False):
    """Advance the byte boundary only after confirming the whole segment chain."""
    path = journal.path
    inventory = rotated_logs(path, journal.first_batch)
    batches = journal.batches.copy()
    added = sorted(inventory.present.keys() - batches.keys())
    assert not added or not batches or added[0] > max(batches), \
        f"log cursor lost: late journal batch {added[0]} for {path.name}"
    with ExitStack() as files:
        opened = []
        for name, candidate in inventory.present.items():
            previous = batches.get(name)
            if previous and same_journal_file(previous.file, candidate):
                continue
            alternate = path.parent / "archive" / name / path.name
            segment, stream = read_backup_segment(files, candidate, alternate)
            if previous is not None:
                # Only a confirmed complete staging file can witness an
                # archive copy. Matching a live prefix does not prove its EOF.
                assert carried_to_archive(previous.file, segment.file) and segment.content == previous.content, \
                    f"journal batch {name} changed: {path.name}"
            batches[name] = segment
            opened.append((segment, stream))

        candidate = journal_file(path)
        live = None
        if candidate is not None:
            live, stream = read_backup_segment(files, candidate)
            opened.append((live, stream))
        absent = confirm_journal_read(journal, inventory, batches, live, opened, files)

    previous = journal.live
    chain = [batches[name] for name in added]
    chain.extend([live] if live is not None else [])
    moved = None
    if previous is not None:
        diagnosis = f"log cursor lost: {path.name} has no continuation of its previous inode and bytes"
        assert chain, diagnosis
        first = chain[0]
        source = inventory.present[added[0]] if added else first.file
        same_inode = source.identity == previous.file.identity
        archived_copy = stopped and added and first.file.path.parent.parent.name == "archive"
        assert (same_inode and first.content.startswith(previous.content) or
                archived_copy and first.content == previous.content), diagnosis
        if stopped:
            assert added and first.content == previous.content, diagnosis
            moved = added[0]
        unread = [first.content[len(previous.content):], *(part.content for part in chain[1:])]
    else:
        unread = [part.content for part in chain]

    journal.entries.extend(entry for content in unread
                           for entry in log_entries(content.decode("utf-8", errors="replace").splitlines()))
    journal.live, journal.batches, journal.absent_batches = live, batches, absent
    return moved


def log_entries(lines):
    for line in lines:
        match = re.fullmatch(r"\[([^\]]+)\] (?:\[[A-Z]+\] )?(.+)", line)
        if match:
            yield match[1], match[2]


def worker_birth(process):
    # The harness and stand are on the same Linux Docker host. Convert the
    # worker's /proc start ticks into the clock used by daemon timestamps.
    return time.time() - time.clock_gettime(time.CLOCK_BOOTTIME) + int(process.start_ticks) / os.sysconf("SC_CLK_TCK")


def payload(frame):
    """Read the typed assignment address without rebuilding its saved envelope."""
    return frame["signal"]["data"]["data"]


class AccountOperationRun:
    """The stand and handles for one live account operation journey."""

    def __init__(self, scenarios, label):
        self.s = scenarios
        self.stand = scenarios.STAND
        self.entry = scenarios.MASTERS[0]
        self.nodes = list(scenarios.ALL_NODES)
        self.label = label
        self.callers = []
        self.probes = {}
        self.pauses = []
        self.killed = None
        self.killed_container = None
        self.backup_id = None
        self.archive_addresses = {}
        self.create_attempt = None
        self.staged_archive = None
        self.writers_stopped = False
        self.restore_attempt = None
        self.backup_journal = {}
        self.backup_containers = {}
        self.backup_addresses = {}

    def sql(self, statement):
        answer = control.db_sql(self.stand, statement)
        assert answer.code == 0, f"{self.label} SQL oracle failed: {answer.err or answer.out}"
        return answer.out.strip()

    def fixture_sql(self, statement):
        self.backup_admission()
        return self.sql(statement)

    def command(self, node, *args):
        answer = control.client(self.stand, node, *map(str, args), timeout=CALLER_DEADLINE)
        assert answer.code == 0, f"{node} {args}: {answer.code}: {answer.err or answer.out}"
        return answer.out.strip()

    def wait(self, predicate, description, nodes=None, timeout=None):
        return self.s.wait_until(predicate, timeout or self.s.CONVERGE_TIMEOUT,
                                 description, nodes=nodes or self.nodes)

    def placed(self, agent):
        views = self.wait(lambda v: self.s.placement_row(v, agent).get("state") == "started",
                          f"{agent} really started")
        return self.s.placement_row(views, agent)

    def process(self, agent, placement=None):
        row = self.placed(agent) if placement is None else placement
        node = row["nodeId"]
        starts = [int(index) for name, index in self.s.AGENT_STARTED_ON_WORKER.findall(self.s.node_log(node))
                  if name == agent]
        assert starts, f"{agent} has no worker start on {node}: {row}"
        return control.worker_process(self.stand, node, starts[-1])

    def place(self, agent):
        self.command(self.entry, "test:cluster:agent:place", *agent.split(":", 1))
        return self.process(agent)

    def incarnation(self, agent, placement=None, *, journal=None):
        """Distinguish successive instances even when they share a worker."""
        if agent == BACKUP:
            row = self.placed(agent) if placement is None else placement
            node = row["nodeId"]
            daemon = self.read_backup_stream(node, "daemon.log") if journal is None else journal
            boot, boot_stamp = self.daemon_boot(node, daemon, daemon.origin)
            pattern = re.compile(rf"Agent '{BACKUP}' (started|stopped) on worker #(\d+)\b")
            current = daemon.entries[boot:]
            lifecycle = [(position, stamp, line, match) for position, (stamp, line) in enumerate(current)
                         if (match := pattern.match(line))]
            assert lifecycle and lifecycle[-1][3][1] == "started", f"no current {BACKUP} worker start on {node}"
            position, stamp, line, match = lifecycle[-1]
            index = int(match[2])
            worker = re.compile(rf"Worker #{index} (started|stopped) \[type=\w+\]")
            events = [(i, at, event[1]) for i, (at, text) in enumerate(current) if (event := worker.fullmatch(text))]
            assert events and events[-1][2] == "started" and events[-1][0] < position, \
                f"{BACKUP} start is outside its worker lifecycle on {node}"
            if journal is not None:
                publisher = self.backup_journal[node, BACKUP_LOG]
                for at, event in reversed(publisher.entries[publisher.origin:]):
                    if at < boot_stamp:
                        break
                    start = BACKUP_WORKER_START.fullmatch(event)
                    if start or BACKUP_WORKER_STOP.fullmatch(event) or event in (
                            f"Agent started '{BACKUP}'", f"Agent stopped '{BACKUP}'"):
                        # The worker logs a start before the daemon acknowledges it.
                        assert at < stamp or at == stamp and start and int(start[1]) == index, \
                            f"backup holder changed before mutation on {node}"
                        break
            process = control.worker_process(self.stand, node, index)
            assert worker_birth(process) <= self.s.log_time(events[-1][1]).timestamp() + .001, \
                f"{BACKUP} start predates the live worker on {node}"
            return process, (boot + position, f"[{stamp}] {line}")
        process = self.process(agent, placement)
        pattern = re.compile(rf"Agent '{re.escape(agent)}' started on worker #{process.index}\b")
        starts = [line for line in self.s.node_log(process.node).splitlines() if pattern.search(line)]
        assert starts, f"no lifecycle start for {agent} on {process}"
        return process, starts[-1]

    def forget_stopped(self, agent, incarnation):
        """Forget handles locally only after the caller has proved stop or replacement."""
        if self.probes.get(agent) == incarnation:
            self.probes.pop(agent)
        self.pauses = [handle for handle in self.pauses if handle[:2] != (agent, incarnation)]

    def probe(self, agent, action, node=None, **options):
        if node is None:
            node = self.placed(agent)["nodeId"]
        if action == "arm":
            self.probes[agent] = self.incarnation(agent)
        # Neither a replay argument nor an inspect reply may enter command diagnostics.
        description = f"{node} {agent}: operation probe {action}"
        try:
            answer = control.client(self.stand, node, PROBE, agent, action,
                                    *(f"--{key.replace('_', '-')}={value}" for key, value in options.items()),
                                    timeout=CALLER_DEADLINE)
            if answer.code != 0:
                failure = f"exit {answer.code}"
            else:
                state = json.loads(answer.out)
                if isinstance(state, dict):
                    return state
                failure = "invalid response"
        except subprocess.TimeoutExpired:
            failure = "timed out"
        except (OSError, ValueError):
            failure = "could not read response"
        raise AssertionError(f"{description}: {failure}")

    def held(self, agent, node=None):
        node = node or self.placed(agent)["nodeId"]
        found = []

        def captured(_):
            state = self.probe(agent, "inspect", node)
            if state["held"] is None:
                return False
            assert not state["armed"] and state["matched"] >= 1, f"{agent} returned an inconsistent probe state"
            found.append(state["held"])
            return True

        try:
            self.wait(captured, f"{agent} captured its production frame")
        except self.s.ScenarioTimeout as failure:
            # An armed probe which never saw its frame is a missing behavior, rather than
            # fleet convergence. Do not retry the entire destructive journey for it.
            raise AssertionError(f"{agent} on {node} never captured its frame") from failure
        return found[0]

    def replay(self, frame, copies=1):
        return self.probe(frame["agentId"], "replay", frame_json=json.dumps(frame, separators=(",", ":")),
                          copies=copies)

    def backup_logs(self, nodes):
        """Mark requests at an exact position in each admitted journal."""
        marks = {}
        for node in nodes:
            for name in BACKUP_STREAMS:
                journal = self.read_backup_stream(node, name)
                marks[node, name] = journal, len(journal.entries)
        return marks

    def begin_backup_observation(self, previous_logs=None):
        """Anchor the journals at the boot of the observed container roster."""
        try:
            containers = {node: self.running_container(node) for node in self.s.ALL_NODES}
            if previous_logs is None:
                self.backup_journal = {}
            else:
                assert all(state[0] != self.backup_containers[node][0] for node, state in containers.items()), \
                    "cleanup UP did not establish a new container roster"
            self.backup_containers = containers
            self.backup_addresses = {node: self.archive_address(node) for node in self.s.ALL_NODES}
            for node in containers:
                startup = None
                boot = None
                # Batch names bound the search by the container's start
                # time. After UP, keep the previous bound until the stopped
                # ends have been matched to the new startup batch.
                first_batch = containers[node][2].strftime(BATCH_TIME_FORMAT)
                for name in JOURNAL_STREAMS:
                    key = node, name
                    if previous_logs is None:
                        path = self.stand.node(node).log_dir / name
                        self.backup_journal[key] = BackupJournal(path, first_batch)
                    journal = self.backup_journal[key]
                    try:
                        moved = read_backup_journal(journal, stopped=previous_logs is not None)
                        if name == "daemon.log":
                            if previous_logs is not None:
                                startup = moved
                                assert startup is not None, "missing the stopped daemon segment"
                            origin = 0 if previous_logs is None else previous_logs[key]
                            boot_position, boot = self.daemon_boot(node, journal, origin, first=True)
                            journal.origin = boot_position
                        elif previous_logs is not None:
                            assert moved == startup or moved is None and startup not in journal.batches, \
                                "streams disagree on the stopped roster's startup batch"
                            journal.origin = previous_logs[key]
                        else:
                            journal.origin = next((i for i, (stamp, _) in enumerate(journal.entries)
                                                   if stamp >= boot), len(journal.entries))
                        assert all(stamp >= boot for stamp, _ in journal.entries[journal.origin:]), \
                            "log entries cross the established daemon boot"
                        journal.first_batch = first_batch
                        journal.fresh = len(journal.entries) if previous_logs is None else journal.origin
                    except Exception as error:
                        if previous_logs is not None:
                            raise StandUnusable(f"cleanup UP journal history is unconfirmed for {name} on {node}: {error}") from error
                        raise
            if previous_logs is None:
                self.backup_admission()
        except StandUnusable:
            raise
        except Exception as error:
            raise StandUnusable(f"cannot establish backup observation: {error}") from error

    def running_container(self, node):
        state = control.container_state(self.stand, node)
        fields = state.out.split()
        assert state.code == 0 and len(fields) == 3 and fields[1] in ("true", "false"), \
            f"cannot identify the container on {node}"
        container, running, started_at = fields
        if node == self.killed:
            assert container == self.killed_container, f"cannot identify the stopped member {node}"
        else:
            assert running == "true", f"unexpected stopped member {node}"
        return container, running == "true", datetime.fromisoformat(started_at)

    def daemon_boot(self, node, journal, origin=0, *, first=False):
        boots = [(i, stamp) for i, (stamp, line) in enumerate(journal.entries[origin:], origin)
                 if line == "Daemon started with epoll"]
        assert boots, f"missing daemon history on {node}"
        # The logger stamps milliseconds while Docker reports sub-millisecond time.
        started_at = self.backup_containers[node][2]
        current = [boot for boot in boots if self.s.log_time(boot[1]) + timedelta(milliseconds=1) > started_at]
        assert current and current[-1] == boots[-1], f"daemon boot does not belong to the container on {node}"
        return current[0] if first else current[-1]

    def read_backup_stream(self, node, name):
        journal = self.backup_journal[node, name]
        try:
            read_backup_journal(journal)
        except (AssertionError, OSError) as error:
            raise StandUnusable(f"unconfirmed backup journal on {node}: {error}") from error
        return journal

    def journal_entries(self, node, name):
        journal = self.read_backup_stream(node, name)
        return [(i >= journal.fresh, stamp, line)
                for i, (stamp, line) in enumerate(journal.entries[journal.origin:], journal.origin)]

    def request_entries(self, node, name, marks):
        journal = self.read_backup_stream(node, name)
        marked, position = marks[node, name]
        if journal is not marked or not journal.origin <= position <= len(journal.entries):
            raise StandUnusable(f"request journal mark was lost on {node}: {name}")
        return journal.entries[position:]

    def check_pruned_batches(self, node):
        journal = self.read_backup_stream(node, PRUNE_LOG)
        for _, line in journal.entries[journal.origin:]:
            match = re.fullmatch(rf"Log cleanup: removed batch archive/({BATCH_NAME})/, taken at .*", line)
            if match and match[1] >= journal.first_batch:
                missing = [name for name in JOURNAL_STREAMS
                           if match[1] not in self.backup_journal[node, name].batches
                           and match[1] not in self.backup_journal[node, name].absent_batches]
                if missing:
                    raise StandUnusable(f"backup journal history removed before it was read on {node}: "
                                        f"{match[1]} ({', '.join(missing)})")

    def check_backup_journal(self, owner, restore_bounds, allow_create_stop):
        """Reconcile starts, pending work and terminal publications on every node."""
        create = self.create_attempt
        restore = self.restore_attempt
        destinations = list(self.archive_addresses.values())
        if self.staged_archive:
            destinations.append(self.staged_archive.address)
        assert all(self.backup_addresses[address.node] == address for address in destinations), \
            "archive address changed since observation began"
        targets = {(address.root, address.environment) for address in destinations}
        parked = set()
        answered = set()
        foreign_terminal = False
        for node in self.s.ALL_NODES:
            address = self.backup_addresses[node]
            same_directory = (address.root, address.environment) in targets
            active_create = {}
            completed_create = {}
            worker_start = None
            own_create = False
            pending = None
            own_restore = False
            restore_active = None

            def confirm_create_release(fresh):
                # Both BackupAgent::startBackup and ::admitRestore require the child slot
                # that finishRun releases after refreshing the index and applying retention.
                fresh_foreign = False
                for publication, completed_fresh in completed_create.items():
                    own, _ = active_create.pop(publication)
                    fresh_foreign |= not own and (fresh or completed_fresh)
                completed_create.clear()
                return fresh_foreign

            def change_publisher(start):
                nonlocal worker_start
                if create and create.terminal:
                    for publication in completed_create:
                        if active_create[publication][0]:
                            del active_create[publication]
                completed_create.clear()
                worker_start = start

            daemon = self.journal_entries(node, "daemon.log")
            journal = self.backup_journal[node, "daemon.log"]
            self.daemon_boot(node, journal, journal.origin)
            boots = [stamp for _, stamp, line in daemon if line == "Daemon started with epoll"]
            generation = None
            for _, _, line in daemon:
                match = re.fullmatch(rf"Command channel: parked (?:{CREATE}|{RESTORE_REQUEST}) #(\S+) for its agent", line)
                if match:
                    parked.add(match[1])
            for position, (fresh, stamp, line) in enumerate(self.journal_entries(node, BACKUP_LOG)):
                boot = next((boot for boot in reversed(boots) if boot <= stamp), None)
                assert boot is not None, f"backup publisher has no daemon generation on {node}"
                if generation != boot:
                    change_publisher(None)
                    generation = boot
                if line in (f"Agent started '{BACKUP}'", f"Agent stopped '{BACKUP}'") or BACKUP_WORKER_STOP.fullmatch(line):
                    change_publisher(None)
                elif BACKUP_WORKER_START.fullmatch(line):
                    change_publisher(position)
                elif match := re.fullmatch(r"Command channel: answered #(\S+)(?: .*)?", line):
                    answered.add(match[1])
                elif match := re.fullmatch(rf"Backup busy; queued pending create \(scope=({BACKUP_SCOPE})\)", line):
                    pending = match[1], False
                elif match := re.fullmatch(rf"Running pending backup create \(scope=({BACKUP_SCOPE})\)", line):
                    pending = match[1], True
                elif line == "Backup is disabled; ignoring create request" and pending and pending[1]:
                    pending = None
                elif match := re.fullmatch(rf"Backup started: ({BACKUP_ID}) \(scope=({BACKUP_SCOPE})\)", line):
                    backup_id, scope = match.groups()
                    publication = backup_id, scope
                    if pending == (scope, True):
                        pending = None
                    own = (create is not None and create.address.node == node and scope == "full"
                           and create.publication == (stamp, backup_id))
                    if own and own_create:
                        raise StandUnusable(f"ambiguous CREATE publication {backup_id} on {node}")
                    if same_directory and backup_id == self.backup_id and scope == "full" and not own:
                        raise StandUnusable(f"foreign CREATE collides with owned archive {backup_id} on {node}")
                    if completed_create:
                        foreign_terminal |= confirm_create_release(fresh)
                    if active_create:
                        raise StandUnusable(f"active foreign CREATE on {node}: prior writer has no release")
                    own_create |= own
                    active_create[publication] = own, worker_start
                elif match := re.fullmatch(rf"Backup ({BACKUP_ID}) completed in \d+s", line):
                    backup_id = match[1]
                    matching = [publication for publication in active_create if publication[0] == backup_id]
                    if len(matching) != 1:
                        raise StandUnusable(f"CREATE completion without its start on {node}: {backup_id}")
                    publication, = matching
                    own, publisher = active_create[publication]
                    if publisher == worker_start and (publisher is not None or own):
                        completed_create[publication] = completed_create.get(publication, False) or fresh
                elif match := re.fullmatch(
                        rf"Restore accepted: ({BACKUP_ID}) \(scope=({BACKUP_SCOPE})\); requesting protected mode", line):
                    if completed_create:
                        foreign_terminal |= confirm_create_release(fresh)
                    if any(not own for own, _ in active_create.values()):
                        raise StandUnusable(f"active foreign CREATE on {node}: RESTORE cannot prove its release")
                    admitted_at = int(round(self.s.log_time(stamp).timestamp() * 1000))
                    own = (restore_bounds is not None and node == restore.node and match.groups() == (restore.backup_id, "full")
                           and admitted_at == restore_bounds[0])
                    if restore_active is not None or (own and own_restore):
                        raise StandUnusable(f"ambiguous RESTORE admission on {node}: {match[1]}")
                    own_restore |= own
                    restore_active = match.groups()
                    if fresh and not own:
                        raise StandUnusable(f"foreign RESTORE admission on {node}: {match[1]}")
                elif match := re.fullmatch(rf"Restore started: ({BACKUP_ID}) \(scope=({BACKUP_SCOPE})\)", line):
                    if restore_active != match.groups():
                        raise StandUnusable(f"RESTORE start without its admission on {node}: {match[1]}")
                elif match := re.fullmatch(rf"Restore ({BACKUP_ID}) completed", line):
                    if restore_active is None or restore_active[0] != match[1]:
                        raise StandUnusable(f"RESTORE completion without its admission on {node}: {match[1]}")
                    restore_active = None
            if pending:
                raise StandUnusable(f"pending foreign CREATE on {node} (scope={pending[0]})")
            if restore_active and not own_restore:
                raise StandUnusable(f"unfinished foreign or unverified RESTORE on {node}: {restore_active[0]}")
            if restore_bounds is not None and node == restore.node and not own_restore:
                raise StandUnusable(f"RESTORE admission missing from observed journal on {node}")
            for fresh, stamp, line in self.journal_entries(node, BACKUP_ERROR):
                if match := re.fullmatch(rf"Backup ({BACKUP_ID}) (?:refused|failed): .*", line):
                    own = (create is not None and create.address.node == node and (
                        active_create.get((match[1], "full"), (False, None))[0] or
                        create.sidecar_only and create.publication == (stamp, match[1])))
                    if fresh and not own:
                        raise StandUnusable(f"unverified foreign CREATE failure on {node}: {match[1]}")
            for publication, (own, _) in active_create.items():
                backup_id, _ = publication
                if not own:
                    raise StandUnusable(f"active foreign CREATE on {node}: {backup_id}")
                if publication in completed_create and create.terminal:
                    continue
                if owner != create.owner:
                    raise StandUnusable(f"active CREATE publisher changed on {node}")
                if not allow_create_stop:
                    raise StandUnusable(f"owned CREATE on {node} is still publishing: {backup_id}")
            self.check_pruned_batches(node)
        if parked - answered:
            raise StandUnusable("backup command parked without a witnessed handler outcome")
        return foreign_terminal

    def backup_admission(self, *, allow_restore=False, allow_stopped=False, allow_create_stop=False,
                         check_pairs=False, expected_owner=None):
        """Read the writer evidence at a mutation boundary, including mounted RT and identity."""
        try:
            expected = set(self.s.ALL_NODES)
            assert expected == set(self.stand.members) == set(self.backup_containers), "backup roster is unconfirmed"
            nodes = []
            for node in self.s.ALL_NODES:
                state = self.running_container(node)
                assert state[0] == self.backup_containers[node][0], f"backup container changed on {node}"
                assert state[2] == self.backup_containers[node][2] or node == self.killed, \
                    f"backup container restarted unexpectedly on {node}"
                if state[1]:
                    self.backup_containers[node] = state
                if state[1]:
                    nodes.append(node)
            assert set(nodes) == expected or (allow_stopped and expected - set(nodes) == {self.killed}), \
                "backup roster is incomplete"
            views = self.s.inspect_all(nodes)
            leader, = self.s.leaders(views)
            online = {row["nodeId"] for row in views[leader]["nodes"] if row.get("online")}
            assert online == set(nodes) and all(views.get(node) for node in nodes), "backup roster is unconfirmed"
            holder = self.s.placement_row(views, BACKUP)
            assert holder.get("state") == "started" and holder.get("nodeId") in nodes, "backup holder is unconfirmed"
            owner = self.incarnation(BACKUP, holder)
            assert owner[0].container == self.backup_containers[holder["nodeId"]][0], "backup holder container changed"
            assert expected_owner is None or owner == expected_owner, "backup holder changed before mutation"
            rows = self.restore_rows(nodes)
            attempt = self.restore_attempt
            bounds = None
            if self.create_attempt is not None and self.backup_id is None:
                try:
                    self.create_evidence()
                except AssertionError:
                    if not self.create_attempt.terminal:
                        raise
                assert self.backup_id is not None or self.create_attempt.terminal, "CREATE publication could not be attributed"
            if attempt is not None:
                _, bounds, _, closed = self.restore_window_evidence(attempt)
                if attempt.settled and (closed or self.restore_result(attempt) == "unsafe"):
                    raise StandUnusable("settled RESTORE left the database or rehydrate unverified")
                if bounds is not None and not attempt.settled:
                    assert owner == attempt.owner, "RESTORE publisher changed before settling its window"
                    if not allow_restore:
                        raise StandUnusable("owned RESTORE has not settled")
            for node, row in rows.items():
                if row["phase"] == "inactive":
                    continue
                if not allow_restore or bounds is None or attempt.settled:
                    raise StandUnusable(f"another protected-mode window on {node}")
                self.check_restore_window(attempt, node, row, bounds)
            if self.check_backup_journal(owner, bounds, allow_create_stop) and check_pairs:
                self.check_archive_pairs()
                self.check_backup_journal(owner, bounds, allow_create_stop)
            if (expected_owner is not None or bounds is not None and not attempt.settled
                    or self.create_attempt is not None and not self.create_attempt.terminal):
                daemon = self.backup_journal[holder["nodeId"], "daemon.log"]
                assert self.incarnation(BACKUP, holder, journal=daemon) == owner, "backup holder changed before mutation"
            return rows
        except StandUnusable:
            raise
        except Exception as error:
            raise StandUnusable(f"backup cleanup admission is unconfirmed: {error}") from error

    def command_interval(self, node, marks, command):
        """Find the single parked request and the synchronous handler that answered it."""
        parked = []
        for peer in self.s.ALL_NODES:
            for _, line in self.request_entries(peer, "daemon.log", marks):
                match = re.fullmatch(rf"Command channel: parked {re.escape(command)} #(\S+) for its agent", line)
                if match:
                    parked.append((peer, match[1]))
        if not parked:
            return None
        if len(parked) != 1 or parked[0][0] != node:
            raise StandUnusable(f"ambiguous {command} command-channel requests")
        key = re.escape(parked[0][1])
        lines = self.agent_log(node, marks)
        took = [i for i, (_, line) in enumerate(lines) if re.fullmatch(
            rf"Command channel: took {re.escape(command)} #{key}", line)]
        answered = [i for i, (_, line) in enumerate(lines) if re.fullmatch(
            rf"Command channel: answered #{key}(?: .*)?", line)]
        if not took or not answered:
            return None
        assert len(took) == len(answered) == 1 and took[0] < answered[0], f"ambiguous {command} handler"
        return lines[took[0]:answered[0] + 1]

    def agent_log(self, node, marks, errors=False):
        return self.request_entries(node, BACKUP_ERROR if errors else BACKUP_LOG, marks)

    def archive_inventory(self, address):
        script = ARCHIVE_PATH_GUARD + """set -eu
safe_full "$1"
date -u +%Y-%m-%d_%H-%M-%S
for file in "$1/full/"*; do
    if [ -e "$file" ] || [ -L "$file" ]; then basename -- "$file"; fi
done
"""
        answer = control.compose(self.stand, "exec", "-T", self.stand.member(address.node).service,
                                 "sh", "-c", script, "full-archive-inventory", address.root)
        assert answer.code == 0, f"cannot inventory full archives on {address.node}: {answer.err}"
        now, *names = answer.out.splitlines()
        assert re.fullmatch(BACKUP_ID, now), "cannot establish the backup name clock"
        suffix = re.escape(f"-{address.environment}-full")
        for name in names:
            match = re.fullmatch(rf"({BACKUP_ID}){suffix}\.(?:json|tar\.gz)", name)
            assert match is None or match[1] < now, f"FULL archive name can collide with CREATE: {name}"
        return now, set(names)

    def create_evidence(self):
        attempt = self.create_attempt
        node = attempt.address.node
        answer = attempt.answer
        refusal = answer.err.strip() if answer is not None and answer.code != 0 else ""
        if refusal.startswith("Refused: Backup busy:"):
            raise StandUnusable("another backup writer refused CREATE", AssertionError(refusal))
        if refusal.startswith("Refused: Unknown backup schedule entry: "):
            attempt.terminal = True
            return None
        started = None
        if answer is not None and answer.code == 0:
            started = re.fullmatch(rf"Started scheduled backup ({BACKUP_ID}) \(scope=full\)", answer.out.strip())
        if not started and refusal != "Refused: Backup did not start":
            original = (answer.err or answer.out).strip() if answer is not None else ""
            raise StandUnusable("CREATE ownership is unconfirmed by the CLI",
                                AssertionError(original) if original else None)

        interval = self.command_interval(node, attempt.marks, CREATE)
        if interval is None:
            return None
        assert self.incarnation(BACKUP) == attempt.owner, "CREATE publisher changed"
        starts = [(stamp, match[1]) for stamp, line in interval
                  if (match := re.fullmatch(rf"Backup started: ({BACKUP_ID}) \(scope=full\)", line))]
        began, ended = (self.s.log_time(interval[i][0]) for i in (0, -1))
        errors = []
        misconfigured = False
        for stamp, line in self.agent_log(node, attempt.marks, errors=True):
            if not began <= self.s.log_time(stamp) <= ended:
                continue
            match = re.fullmatch(rf"Backup ({BACKUP_ID}) (?:refused: .*|failed: failed to spawn child:.*)", line)
            if match:
                errors.append((stamp, match[1]))
            misconfigured |= line.startswith("Cannot start backup: missing configuration (")
        assert len(starts) + len(errors) <= 1, "multiple publications inside the CREATE handler"
        if any(line.startswith("Restore engaged (") for _, line in interval):
            raise StandUnusable("another restore is pending or running", AssertionError(refusal) if refusal else None)
        if not starts and not errors:
            disabled = any(line == "Backup is disabled; ignoring create request" for _, line in interval)
            if refusal and (disabled or misconfigured):
                attempt.terminal = True
                raise AssertionError(refusal)
            return None

        publication = (starts or errors)[0]
        _, backup_id = publication
        pattern = rf"Backup started: {backup_id} \(scope=full\)"
        for peer in self.s.ALL_NODES:
            address = self.backup_addresses[peer]
            if (address.root, address.environment) != (attempt.address.root, attempt.address.environment):
                continue
            count = sum(bool(re.fullmatch(pattern, line)) for _, line in self.agent_log(peer, attempt.marks))
            assert count == int(peer == node and bool(starts)), "repeated FULL publication with the same name"
        assert backup_id >= attempt.earliest_id, "CREATE used a name older than its inventory"
        base = f"{backup_id}-{attempt.address.environment}-full"
        assert not {base + ".json", base + ".tar.gz"} & attempt.inventory, "CREATE used an existing archive name"
        if started:
            assert starts and started[1] == backup_id, "CREATE reply disagrees with the published archive"
        else:
            assert errors, "CREATE refusal disagrees with the published archive"
        if errors:
            try:
                full_archive(self, attempt.address, backup_id)
            except BackupFailed:
                attempt.terminal = attempt.sidecar_only = True
            else:
                return None  # Stderr can reach the journal before publication finishes.
        self.backup_id = backup_id
        self.archive_addresses[node] = attempt.address
        attempt.publication = publication
        return backup_id

    def capture_full_archive(self):
        owner = self.incarnation(BACKUP)
        node = owner[0].node
        address = self.archive_address(node)
        pause = self.pause(f"{BACKUP}/{SCHEDULE}", owner=owner)
        clock, inventory = self.archive_inventory(address)
        marks = self.backup_logs(self.s.ALL_NODES)
        self.backup_admission(expected_owner=owner)
        for peer, archive in self.backup_addresses.items():
            if (archive.root, archive.environment) != (address.root, address.environment):
                continue
            journal = self.backup_journal[peer, BACKUP_LOG]
            recent = [match[1] for _, line in journal.entries[journal.origin:]
                      if (match := re.fullmatch(rf"Backup started: ({BACKUP_ID}) \(scope=full\)", line))]
            assert all(backup_id < clock for backup_id in recent), "a recent FULL writer can reuse the CREATE name"
        attempt = self.create_attempt = CreateAttempt(address, owner, clock, inventory, marks)
        try:
            attempt.answer = run_backup_command(self, node, CREATE, SCHEDULE)
        except (OSError, subprocess.TimeoutExpired) as failure:
            raise StandUnusable("CREATE ownership is unconfirmed by the CLI", failure) from failure

        if attempt.answer.code != 0 and attempt.answer.err.strip().startswith("Refused: Unknown backup schedule entry: "):
            raise AssertionError(attempt.answer.err.strip())

        def finished(_):
            backup_id = self.create_evidence()
            if backup_id is None:
                return False
            metadata = full_archive(self, address, backup_id)
            completed = f"Backup {backup_id} completed in "
            attempt.terminal = metadata is not None and any(
                line.startswith(completed) for _, line in self.agent_log(node, marks))
            if attempt.terminal:
                attempt.digest = metadata["sha256"]
            return attempt.terminal

        try:
            self.s.wait_until(finished, self.s.CONVERGE_TIMEOUT, "the exact CREATE and its full archive pair", nodes=[])
        except self.s.ScenarioTimeout as failure:
            answer = attempt.answer
            raise AssertionError(f"no terminal owned FULL publication on {node}: {answer and (answer.err or answer.out)}") from failure
        except BackupFailed:
            attempt.terminal = True
            raise
        self.backup_admission(check_pairs=True, expected_owner=owner)
        verified = backup_command(self, node, "backup:verify", self.backup_id, "--scope=full")
        assert "checked 1: ok 1, mismatch 0, skipped 0, unverified 0" in verified, verified
        metadata = full_archive(self, address, self.backup_id)
        assert metadata["verifyOutcome"] == "ok" and metadata["verifiedAt"], metadata
        assert metadata["sha256"] == attempt.digest, "CREATE digest changed during verification"
        self.cancel_pause(pause)
        return self.backup_id, node

    def archive_address(self, node):
        answer = control.compose(self.stand, "exec", "-T", self.stand.member(node).service,
                                 "printenv", "BACKUP_DIR", "APP_ENV")
        fields = answer.out.splitlines()
        assert (answer.code == 0 and len(fields) == 2 and re.fullmatch(r"/app/data(?:/[A-Za-z0-9._-]+)+", fields[0])
                and not {".", ".."}.intersection(fields[0].split("/"))
                and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", fields[1])), \
            f"could not read the shared archive address on {node}"
        return ArchiveAddress(node, *fields)

    def stage_archive_at_restore_holder(self, node):
        source = next(iter(self.archive_addresses.values()))
        destination = self.archive_address(node)
        assert source.environment == destination.environment and source.root != destination.root, \
            "archive addresses did not identify distinct directories in the same environment"
        staged = self.staged_archive = StagedArchive(destination, uuid.uuid4().hex)
        script = ARCHIVE_PATH_GUARD + """set -eu
safe_full "$1"
safe_full "$2"
mkdir -p -- "$2/full"
tmp="$2/full/.stage-$4"
mkdir -- "$tmp"
printf '%s\n' "$4" > "$tmp/owner"
for ext in tar.gz json; do
    original="$1/full/$3.$ext"
    test -f "$original" && test ! -L "$original" || exit 1
    cp -- "$original" "$tmp/$ext"
    cmp -s -- "$original" "$tmp/$ext"
    ln -T -- "$tmp/$ext" "$2/full/$3.$ext"
done
"""
        answer = control.compose(self.stand, "exec", "-T", self.stand.member(node).service,
                                 *control.WRITER_EXEC, "sh", "-c", script, "stage-full-archive",
                                 source.root, destination.root, f"{self.backup_id}-{source.environment}-full", staged.token)
        assert answer.code == 0, f"could not stage FULL archive {self.backup_id} on {node}: {answer.err}"
        staged.published = True  # Both links succeeded before any product metadata rewrite.
        metadata = full_archive(self, destination, self.backup_id)
        assert metadata is not None and metadata["verifyOutcome"] == "ok", metadata
        if self.create_attempt and metadata["sha256"] != self.create_attempt.digest:
            raise StandUnusable(f"staged archive changed before reindex on {node}: {self.backup_id}")
        owner = self.incarnation(BACKUP)
        assert owner[0].node == node, "backup holder moved during stage"
        created_at = metadata["createdAt"]
        # Restamp the same instant through the live agent to synchronously rescan the exact pair.
        self.backup_admission(check_pairs=True, expected_owner=owner)
        indexed = self.command(node, "test:backup:age", self.backup_id, f"--at={created_at}", "--scope=full")
        assert indexed == f"Aged backup {self.backup_id} createdAt={created_at}", indexed
        assert full_archive(self, destination, self.backup_id)["sha256"] == metadata["sha256"]

    def full_roster(self):
        self.return_node()
        expected = set(self.s.ALL_NODES)
        assert expected == set(self.stand.members), "configured and expected restore rosters differ"
        views = self.s.wait_converge(self.s.ALL_NODES)
        leader, = self.s.leaders(views)
        online = {row["nodeId"] for row in views[leader]["nodes"] if row.get("online")}
        assert online == expected and all(views.get(node) for node in expected), "unexpected or unavailable restore participant"
        return self.restore_rows(self.s.ALL_NODES)

    def restore_rows(self, nodes):
        rows = {}
        for node in nodes:
            row = self.inspect_restore(node)
            assert row.get("rtMounted") is True, f"protected-mode RT is not mounted on {node}"
            assert row.get("phase") in ("inactive", "activating", "active", "verifying", "deactivating"), \
                f"protected-mode phase is unconfirmed on {node}"
            rows[node] = row
        return rows

    def require_inactive(self, rows):
        taken = [node for node, row in rows.items() if row["phase"] != "inactive"]
        if taken:
            raise StandUnusable(f"another protected-mode window on {', '.join(taken)}")

    def restore_full_archive(self):
        self.return_node()
        self.backup_admission()
        node = self.placed(BACKUP)["nodeId"]
        assert node in self.s.ALL_NODES, f"unverified backup holder {node}"
        if node not in self.archive_addresses:
            self.stage_archive_at_restore_holder(node)
        assert not self.probes and not self.pauses, "existing test controls address pre-freeze agents"
        owner = self.incarnation(BACKUP)
        assert owner[0].node == node, "backup holder moved before restore"
        started_at = int(self.sql("SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3)) * 1000)"))
        marks = self.backup_logs(self.s.ALL_NODES)
        self.backup_admission(expected_owner=owner)
        attempt = self.restore_attempt = RestoreAttempt(node, self.backup_id, marks, owner=owner)
        lost = None
        try:
            attempt.answer = run_backup_command(self, node, "backup:restore", self.backup_id, "--scope=full", "--yes")
        except subprocess.TimeoutExpired as failure:
            captured = failure.stdout or ""
            if isinstance(captured, bytes):
                captured = captured.decode("utf-8", errors="replace")
            # Only complete lines survived the timeout of the caller.
            attempt.answer = control.Outcome(1, captured.rpartition("\n")[0], "")
            lost = failure
        except OSError as failure:
            lost = failure

        outcome = self.restore_result(attempt)
        if outcome == "unknown" and lost is not None:
            raise StandUnusable("RESTORE ownership is unconfirmed by the CLI", lost) from lost
        if outcome in ("refused", "busy", "unknown"):
            self.restore_window_evidence(attempt)
            reason = attempt.answer.err or attempt.answer.out
            attempt.settled = True
            raise AssertionError(f"restore of {self.backup_id} refused on {node}: {reason}")
        if outcome in ("untouched", "unsafe"):
            self.restore_window_evidence(attempt)
            raise AssertionError(f"restore of {self.backup_id} failed on {node}: {attempt.answer.err or attempt.answer.out}")
        try:
            self.reopen_restore(require_verifying=True)
        except StandUnusable as failure:
            if lost is not None:
                raise StandUnusable(str(failure), lost) from failure
            raise
        except (AssertionError, OSError) as failure:
            if lost is not None:
                raise lost from failure
            raise

        return started_at

    def inspect_restore(self, node):
        return json.loads(self.command(node, "protected-mode:inspect"))

    def restore_result(self, attempt):
        """The CLI has separate refusal, lost monitor, and terminal outcome messages."""
        answer = attempt.answer
        if answer is None:
            return "unknown"
        output = answer.out.splitlines()
        accepted = f"Restore accepted: {attempt.backup_id} (scope=full); the node is entering protected mode"
        if accepted in output:
            if "The database was not touched" in output:
                return "untouched"
            if any(line.startswith(("The database may be left partially replaced", "The system stays closed to everyone,"))
                   for line in output):
                return "unsafe"
            if answer.code == 0 and "Restore completed" in output and any(
                    line.startswith("The system is open to pass holders only;") for line in output):
                return "success"
            return "accepted"
        if answer.code != 0 and answer.err.strip().startswith("Refused: Backup subsystem busy:"):
            return "busy"
        if answer.code != 0 and (answer.err.strip().startswith("Refused: ") or any(
                line.startswith(("Error: archive failed verification (", "Error: No stored backup [",
                                 "Error: Backup id [", "Error: APP_ENV does not name a known environment",
                                 "Error: the declared personal-data verdicts are not usable:",
                                 "Error: restoring is destructive;")) for line in output)):
            return "refused"
        return "unknown"

    def restore_window_evidence(self, attempt):
        """Corroborate the caller's admission against the exact handler and window."""
        outcome = self.restore_result(attempt)
        reason = (attempt.answer.err or attempt.answer.out).strip() if attempt.answer is not None else ""
        if outcome in ("busy", "unknown"):
            diagnosis = ("another backup writer refused RESTORE" if outcome == "busy"
                         else "RESTORE ownership is unconfirmed by the CLI")
            raise StandUnusable(diagnosis, AssertionError(reason) if reason else None)
        admission = []
        ready = []
        completed = False
        closed = False
        backup_id = re.escape(attempt.backup_id)
        expected = f"Restore accepted: {attempt.backup_id} (scope=full); requesting protected mode"
        for node in self.s.ALL_NODES:
            for stamp, line in self.agent_log(node, attempt.marks):
                if line.startswith("Restore accepted: "):
                    admission.append((node, stamp, line))
                if node == attempt.node:
                    if line == f"Restore started: {attempt.backup_id} (scope=full)":
                        ready.append(stamp)
                    if line == f"Restore {attempt.backup_id} completed":
                        completed = True
            if node == attempt.node:
                for stamp, line in self.agent_log(node, attempt.marks, errors=True):
                    if re.match(rf"Restore {backup_id} failed: failed to spawn child:", line):
                        ready.append(stamp)
                    if line.startswith(f"Restore {attempt.backup_id} left the node closed - "):
                        closed = True
        if outcome == "refused":
            if admission:
                raise StandUnusable("restore admission belongs to another request", AssertionError(reason))
            return False, None, False, False
        interval = self.command_interval(attempt.node, attempt.marks, RESTORE_REQUEST)
        if (len(admission) != 1 or (admission[0][0], admission[0][2]) != (attempt.node, expected)
                or interval is None or (admission[0][1], expected) not in interval):
            raise StandUnusable("restore admission is missing, ambiguous or outside its command handler")
        if len(ready) > 1:
            raise StandUnusable("restore ready phase was repeated")
        before = int(round(self.s.log_time(admission[0][1]).timestamp() * 1000))
        after = int(round(self.s.log_time(ready[0]).timestamp() * 1000)) if ready else None
        assert after is None or before <= after, "restore became ready before admission"
        bounds = before, after
        return True, bounds, completed, closed

    def check_restore_window(self, attempt, node, row, bounds):
        started = row.get("startedAt")
        identity = (row.get("operation"), row.get("initiatorAgentType"),
                    row.get("initiatorAgentIndex"), row.get("initiatorNodeId"))
        if (identity != ("restore", BACKUP, None, attempt.node)
                or type(started) is not int or not bounds[0] <= started <= (bounds[1] or int(time.time() * 1000))):
            raise StandUnusable(f"protected window on {node} belongs to another restore")
        # Each node stamps its local entry into the same protected window.
        if started != attempt.windows.setdefault(node, started):
            raise StandUnusable(f"protected window changed on {node}")

    def reopen_restore(self, require_verifying=False, *, wait=True):
        attempt = self.restore_attempt
        if attempt is None:
            return
        if attempt.settled:
            self.restore_window_evidence(attempt)
            return
        outcome = self.restore_result(attempt)
        rows = {}

        def ready_to_open(_):
            rows.clear()
            rows.update(self.restore_rows(self.s.ALL_NODES))
            occupied = {node: row for node, row in rows.items() if row["phase"] != "inactive"}
            admitted, bounds, completed, closed = self.restore_window_evidence(attempt)
            if outcome == "unsafe" or closed:
                raise AssertionError("restore left the database or rehydrate unverified")
            if not occupied:
                if (outcome == "success" or completed) and not attempt.opening:
                    raise StandUnusable("the completed restore window was opened outside this scenario")
                return not require_verifying and (admitted and outcome == "untouched" or attempt.opening)
            for node, row in occupied.items():
                if not admitted:
                    raise StandUnusable(f"another protected-mode window on {node}")
                self.check_restore_window(attempt, node, row, bounds)
            terminal = completed or (outcome == "untouched" and not require_verifying)
            return (bounds[1] is not None and len(occupied) == len(rows)
                    and all(row["phase"] == "verifying" for row in occupied.values())
                    and terminal)

        if wait:
            self.s.wait_until(ready_to_open, self.s.CONVERGE_TIMEOUT,
                              f"the confirmed usable restore window for {attempt.backup_id}", nodes=[])
        else:
            assert ready_to_open({}), f"restore {attempt.backup_id} has no confirmed terminal window"
        if rows[attempt.node]["phase"] != "inactive":
            checked = rows.copy()
            current = self.backup_admission(allow_restore=True)
            if any((row["phase"], row.get("startedAt"), row.get("operation"), row.get("initiatorAgentType"),
                    row.get("initiatorAgentIndex"), row.get("initiatorNodeId")) != (
                        checked[node]["phase"], checked[node]["startedAt"], "restore", BACKUP, None, attempt.node)
                   for node, row in current.items()):
                raise StandUnusable("protected window changed before opening")
            attempt.opening = True
            self.command(attempt.node, "protected-mode:open")
            self.s.wait_protected_mode(lambda row: row.get("rtMounted") is True and row.get("phase") == "inactive",
                                       "only this restore reopened", nodes=self.s.ALL_NODES)
        attempt.settled = True

    def pause(self, rule=RECOVERY, *, owner=None):
        agent = rule.split("/", 1)[0]
        incarnation = owner or self.incarnation(agent)
        node = incarnation[0].node
        if agent == BACKUP:
            directory = self.stand.node(node).log_dir
            worker_logs = {path: log_cursor(path) for path in directory.glob("worker-*.log")
                           if path.stem.rsplit("-", 1)[-1] == str(incarnation[0].index)}
        try:
            answer = self.command(node, "test:cron:pause", rule)
        except (OSError, subprocess.TimeoutExpired, AssertionError) as error:
            if agent != BACKUP or "Refused: " in str(error):
                raise
            matches = [match for path, mark in worker_logs.items()
                       for match in re.findall(rf"Cron schedule pause {re.escape(rule)}: pause (\S+)$",
                                               "\n".join(log_tail(path, mark)), re.M)]
            if len(matches) != 1 or self.incarnation(agent) != incarnation:
                raise AssertionError(f"cannot confirm the pause for {rule}") from error
            pause_id = matches[0]
        else:
            match = re.fullmatch(rf"Paused {re.escape(rule)}: pause (\S+)", answer)
            assert match, f"missing exact pause id for {rule}: {answer}"
            pause_id = match[1]
        pause = (agent, incarnation, pause_id)
        self.pauses.append(pause)
        return pause

    def cancel_pause(self, handle):
        agent, incarnation, pause = handle
        if self.incarnation(agent) == incarnation:
            self.command(incarnation[0].node, "test:cron:cancel-pause", pause)
        self.pauses.remove(handle)

    def fire(self):
        answer = self.command(self.placed(HOLDER)["nodeId"], "test:cron:fire", RECOVERY)
        assert re.fullmatch(r"Fired .+: ran at epoch ms \d+", answer), answer

    def request(self, root):
        now = int(time.time() * 1000)
        # A future standing request prevents the ordinary deletion sweep opening it first.
        return int(self.fixture_sql("INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at) "
                                    f"VALUES ({root}, {now}, {now + 86400000}); SELECT LAST_INSERT_ID()"))

    def start_caller(self, node, command, *args):
        """Start a CLI caller while the scenario controls the operation's delivery."""
        self.backup_admission()
        result = []

        def call():
            try:
                result.append(control.client(self.stand, node, command, *map(str, args),
                                             timeout=CALLER_DEADLINE))
            except BaseException as error:
                result.append(error)

        thread = threading.Thread(target=call)
        self.callers.append((thread, command, result))
        thread.start()

    def assignment(self, operation, key):
        value = self.sql("SELECT id FROM hilos_account_operation_assignment "
                         f"WHERE operation_id = {operation} AND assignment_key = '{key}'")
        assert value.isdigit(), f"no unique assignment {key}: {value}"
        return int(value)

    def result(self, assignment):
        # Keep these bytes, including id, JSON and original commit moment, for duplicate proof.
        return self.sql("SELECT id, owner_agent_id, payload_json, created_at FROM hilos_account_operation_result "
                        f"WHERE assignment_id = {assignment}")

    def worker_marks(self, node):
        return {path: control.LogMark(path.stat().st_ino, path.stat().st_size)
                for path in self.stand.node(node).log_dir.glob("worker-*.log")}

    def worker_since(self, node, marks):
        parts = []
        for path in self.stand.node(node).log_dir.glob("worker-*.log"):
            try:
                with path.open("rb") as stream:
                    mark = marks.get(path)
                    if mark is not None and path.stat().st_ino == mark.inode:
                        stream.seek(mark.size)
                    parts.append(stream.read().decode("utf-8", errors="replace"))
            except FileNotFoundError:
                continue
        return "\n".join(parts)

    def collect_callers(self):
        for thread, _, _ in self.callers:
            thread.join(CALLER_DEADLINE)
        for thread, command, result in self.callers:
            assert not thread.is_alive(), f"{command} caller outlived its command deadline"
            assert len(result) == 1 and not isinstance(result[0], BaseException), result
            # An unfinished or disconnected command is not the operation oracle.
            assert result[0].code in (0, 1, 6), result[0]

    def clean_probes(self):
        for agent, incarnation in list(self.probes.items()):
            views = self.s.inspect_all(self.nodes)
            if self.s.placement_row(views, agent).get("state") != "started":
                self.probes.pop(agent)
                continue
            if self.incarnation(agent) != incarnation:
                self.probes.pop(agent)
                continue  # Even a replacement on the same worker must not inherit the handle.
            self.probe(agent, "clear", incarnation[0].node)

    def clean_pauses(self):
        for handle in self.pauses[:]:
            self.cancel_pause(handle)

    def return_node(self):
        if self.killed is None:
            return
        node = self.killed
        try:
            assert node in self.s.ALL_NODES, f"unknown stopped member {node}"
            rows = self.backup_admission(allow_stopped=True)
            if node not in rows:
                assert control.start(self.stand, node).code == 0, f"could not return stopped member {node}"
            self.s.wait_converge(self.s.ALL_NODES)
            self.backup_admission()
        except StandUnusable:
            raise
        except Exception as error:
            raise StandUnusable(f"cannot confirm the returned roster: {error}") from error
        self.killed = self.killed_container = None
        self.nodes = list(self.s.ALL_NODES)

    def check_archive_pairs(self):
        """After another publication, confirm any verified pair we still claim."""
        attempt = self.create_attempt
        if attempt is None or attempt.digest is None:
            return
        addresses = list(self.archive_addresses.values())
        if self.staged_archive and self.staged_archive.published:
            addresses.append(self.staged_archive.address)
        for address in addresses:
            pair = full_archive(self, address, self.backup_id)
            if pair is None or pair["sha256"] != attempt.digest:
                raise StandUnusable(f"owned archive changed after another CREATE on {address.node}: {self.backup_id}")

    def remove_archives(self):
        assert self.restore_attempt is None or self.restore_attempt.settled or self.writers_stopped, \
            "restore can still write the archive"
        assert self.create_attempt is None or self.create_attempt.terminal or self.writers_stopped, \
            "CREATE can still publish the archive"
        first = None
        addresses = [(address, None) for address in self.archive_addresses.values()]
        if self.staged_archive is not None:
            addresses.append((self.staged_archive.address, self.staged_archive))
        for address, staged in addresses:
            try:
                if not self.writers_stopped:
                    self.backup_admission(check_pairs=True)
                sidecar_only = staged is None and self.create_attempt and self.create_attempt.sidecar_only
                full_archive(self, address, self.backup_id, remove=True, staged=staged, sidecar_only=bool(sidecar_only))
                if staged is None:
                    del self.archive_addresses[address.node]
                else:
                    self.staged_archive = None
            except StandUnusable:
                raise
            except Exception as error:
                if first is None:
                    first = error
                print(f"    {self.label} archive cleanup on {address.node}: {error}", flush=True)
        if first is not None:
            raise first

    def stop_writers(self):
        self.backup_admission(allow_restore=True, allow_create_stop=True, check_pairs=True)
        try:
            assert control.down(self.stand, volumes=False) == 0, "could not stop account and backup writers"
        except Exception as error:
            raise StandUnusable(str(error)) from error
        self.writers_stopped = True
        self.probes.clear()
        self.pauses.clear()
        self.killed = self.killed_container = None
        self.nodes = list(self.s.ALL_NODES)

    def bring_back_stand(self):
        assert control.up(self.stand, f"{sys.argv[0]} {self.stand.compose}") == 0, "could not restart the cluster stand"
        self.require_inactive(self.full_roster())
        self.wait(self.s.fleet_started, f"the original mesh and fleet after {self.label} cleanup")
        self.writers_stopped = False

    def restart(self):
        self.stop_writers()
        try:
            self.bring_back_stand()
        except Exception as error:
            raise StandUnusable(str(error)) from error

    def clean_fixtures(self, failed):
        """Remove SQL fixtures, or run product erasure on a successful merge."""
        raise NotImplementedError

    def fixture_users(self, names, known):
        ids = {int(user) for user in self.sql(f"SELECT id FROM hilos_user WHERE name IN ({names})").splitlines()}
        if known:
            archived = ",".join(map(str, sorted(known)))
            reused = self.sql(f"SELECT id FROM hilos_user WHERE id IN ({archived}) AND name NOT IN ({names})")
            # Deleted people can still have operation or merge rows addressed by their original IDs.
            ids.update(set(known) - {int(user) for user in reused.splitlines()})
        return ",".join(map(str, sorted(ids)))

    def schema(self):
        return self.sql("SELECT table_name, column_name, column_type FROM information_schema.columns "
                        "WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position")

    def recover(self, after_up):
        """Stop the writers before fallback SQL. A persisted, unverified restore stays closed."""
        attempt = self.restore_attempt
        self.return_node()
        self.backup_admission(allow_restore=True, allow_create_stop=True)
        problem = None
        try:
            self.reopen_restore(wait=attempt is not None and self.restore_result(attempt) == "untouched")
            self.backup_admission(allow_create_stop=True)
        except StandUnusable:
            raise
        except Exception as error:
            problem = error

        # There is no safe schema baseline while an import may still be running.
        schema = None
        if problem is None:
            try:
                schema = self.schema()
            except Exception as error:
                problem = error
        self.stop_writers()
        self.remove_archives()
        if problem is not None:
            raise AssertionError(f"stopped writers, preserved unverified DB and freeze: {problem}") from problem
        assert schema and control.start_database(self.stand) == 0, "could not start the isolated database for cleanup"

        def schema_ready(_):
            try:
                current = self.schema()
            except AssertionError:
                return False
            assert current == schema, "database schema changed across the writer stop"
            return True

        self.s.wait_until(schema_ready, self.s.CONVERGE_TIMEOUT, "the retained schema before stopped-writer cleanup", nodes=[])
        self.clean_fixtures(True)
        previous_logs = None
        if after_up:
            previous_logs = {key: len(self.read_backup_stream(*key).entries) for key in self.backup_journal}
        self.bring_back_stand()
        if previous_logs is not None:
            # The old request evidence was used before the writer barrier and all
            # owned files are gone. Every request on the new roster is foreign.
            assert not self.archive_addresses and self.staged_archive is None
            self.create_attempt = self.restore_attempt = self.backup_id = None
            self.begin_backup_observation(previous_logs)
        for _, action in after_up:
            self.backup_admission()
            action()

    def cleanup(self, failed, *, before_restart=(), product_fixtures_first=False, archive_first=False,
                writers_finished=False):
        """Finish the normal journey, or recover the exclusive stand behind a writer barrier."""
        original = sys.exception() if failed else None
        if isinstance(original, StandUnusable):
            raise original

        if not failed:
            try:
                self.backup_admission(allow_restore=archive_first, allow_stopped=writers_finished)
                if archive_first:
                    self.reopen_restore()
                    self.backup_admission()
                    self.remove_archives()
                if product_fixtures_first:
                    self.clean_fixtures(False)
                else:
                    self.collect_callers()
                self.clean_probes()
                if writers_finished:
                    self.backup_admission(allow_stopped=True)
                    self.clean_fixtures(False)
                if not product_fixtures_first and not writers_finished:
                    # The unfinished journeys still have accepted work after the CLI callers exit.
                    try:
                        self.recover(before_restart)
                    except Exception as error:
                        raise StandUnusable(f"{self.label} recovery failed: {error}") from error
                    return
                self.clean_pauses()
                self.return_node()
                if product_fixtures_first:
                    self.reopen_restore()
                    self.backup_admission()
                if not archive_first:
                    self.remove_archives()
                for _, action in before_restart:
                    self.backup_admission()
                    action()
                if product_fixtures_first:
                    self.collect_callers()
                    print("    merge 61 cleanup: B and N erased by product, A absent, archive removed, no stand restart", flush=True)
                else:
                    self.restart()
                return
            except StandUnusable:
                raise
            except Exception as error:
                original = error
                if self.writers_stopped:
                    raise StandUnusable(f"{self.label} recovery failed after writer stop: {error}", error) from error

        try:
            self.recover(before_restart)
        except StandUnusable as error:
            raise StandUnusable(str(error), original or error.original) from (original or error)
        except Exception as error:
            raise StandUnusable(f"{self.label} recovery failed: {error}", original) from (original or error)
        finally:
            if self.callers:
                self.s.restore_stand(original is not None, f"{self.label} callers", self.collect_callers)
        if original is not None and not failed:
            raise original


def run_backup_command(run, node, *args):
    """Keep archive preflight and the hot monitor inside the actual holder's environment."""
    answer = control.compose(run.stand, "exec", "-T", run.stand.member(node).service,
                             *control.CLI_ENTRY, *args)
    print(f"    {run.label} {args[0]} on {node}: {answer.out.strip()}", flush=True)
    return answer


def backup_command(run, node, *args):
    answer = run_backup_command(run, node, *args)
    assert answer.code == 0, f"backup on {node} {args}: {answer.code}: {answer.err or answer.out}"
    return answer.out.strip()


def full_archive(run, address, backup_id, remove=False, staged=None, sidecar_only=False):
    """Read an exact pair or remove files whose publication the run proved."""
    assert re.fullmatch(BACKUP_ID, backup_id), backup_id
    base = f"{address.root}/full/{backup_id}-{address.environment}-full"
    script = ARCHIVE_PATH_GUARD + '''set -eu
safe_full "$1"
base="$2"
mode="$3"
tmp="$1/full/.stage-${4:-}"
if [ "$mode" = read ]; then
    test ! -L "$base.json" && test ! -L "$base.tar.gz"
    if [ -f "$base.json" ]; then
        if [ -f "$base.tar.gz" ]; then printf 'present\n'; else printf 'missing\n'; fi
        cat "$base.json"
    fi
    exit 0
fi
witness=false
if [ -n "${4:-}" ] && [ ! -L "$tmp" ] && [ -f "$tmp/owner" ] && [ "$(cat "$tmp/owner")" = "$4" ]; then
    witness=true
fi
for ext in json tar.gz; do
    if [ "$mode" = owned ] || { [ "$mode" = sidecar ] && [ "$ext" = json ]; } ||
       { "$witness" && [ "$tmp/$ext" -ef "$base.$ext" ]; }; then
        test ! -L "$base.$ext"
        rm -f -- "$base.$ext"
    fi
done
if "$witness"; then
    rm -f -- "$tmp/json" "$tmp/tar.gz" "$tmp/owner"
    rmdir -- "$tmp"
fi
'''
    mode = "read"
    if remove:
        mode = "witness" if staged and not staged.published else "sidecar" if sidecar_only else "owned"
    command = ("sh", "-c", script, f"{run.label}-full-archive", address.root, base, mode, staged.token if staged else "")
    if not remove:
        answer = control.compose(run.stand, "exec", "-T", run.stand.member(address.node).service, *command)
    elif run.writers_stopped:
        answer = control.compose(run.stand, "run", "--rm", "--no-deps", "-T", run.stand.cli_service,
                                 *command)
    else:
        answer = control.compose(run.stand, "exec", "-T", run.stand.cli_service, *control.WRITER_EXEC, *command)
    assert answer.code == 0, f"archive {backup_id} on {address.node}: {answer.err or answer.out}"
    if remove or not answer.out.strip():
        return None
    tar, raw = answer.out.split("\n", 1)
    metadata = json.loads(raw)
    assert metadata["id"] == backup_id and metadata["env"] == address.environment, metadata
    assert metadata["scope"] == "full", metadata
    if metadata["status"] == "error":
        raise BackupFailed(f"full backup {backup_id} failed on {address.node}: {metadata['failureReason']}")
    assert metadata["status"] == "success", metadata
    if tar == "missing":
        return None
    assert tar == "present", "invalid archive pair observation"
    assert isinstance(metadata["createdAt"], str) and metadata["createdAt"].endswith("Z"), metadata
    assert metadata["sizeBytes"] > 0 and metadata["dumpBytes"] > 0, metadata
    assert metadata["failureReason"] is None, metadata
    assert re.fullmatch(r"[0-9a-f]{64}", metadata["sha256"]), metadata
    assert metadata["connections"], metadata
    for connection in metadata["connections"]:
        assert isinstance(connection["index"], int) and connection["database"], connection
        assert isinstance(connection["migrationIndex"], int), connection
    return metadata

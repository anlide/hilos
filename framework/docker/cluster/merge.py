"""Live merge recovery on the Binance cluster stand.

SQL fixtures precede lazy reads. The SQL oracle is independent of the probe and
caller replies. The captured handover and business snapshots stay in memory.
"""

import json
import re
import signal
import time
import uuid
from dataclasses import dataclass

import control
from account_operations import HOLDER, AccountOperationRun, journal_segment, log_entries, payload


FIXTURE_PASSWORD_HASH = "$2y$10$abcdefghijklmnopqrstuuCj7fyLDJytXL.TfOiOeiv0fQvSOK1/2"
TRANSFER_TABLES = {
    "identities": "hilos_identity",
    "passkeyCredentials": "hilos_passkey_credential",
    "secondFactors": "hilos_second_factor",
    "secondFactorBackupCodes": "hilos_second_factor_backup_code",
}
PERSON_TABLES = (
    *TRANSFER_TABLES.values(), "hilos_access_log", "hilos_second_factor_trust", "hilos_second_factor_setting",
)
ARCHIVED_DUTIES = ("prepare_survivor", "prepare_loser", "give", "sessions")
RESUMED_DUTIES = ("receive", "survivor_sessions", "cleanup")
SOURCE_SUFFIXES = ("first", "second")
SURVIVOR_SUFFIX = "survivor"
MARKER_SUFFIX = "post-archive"
LOSER_WAIT_DAYS = 21
SURVIVOR_WAIT_DAYS = 14


@dataclass
class SenderLifecycle:
    node: str
    worker: int
    worker_started: tuple[str, int]
    started: tuple[str, int]
    stopped: tuple[str, int] | None = None


class MergeRun(AccountOperationRun):
    """One pair, its untouched neighbour, and one exact FULL archive."""

    def __init__(self, scenarios):
        super().__init__(scenarios, "merge 61")
        self.prefix = f"cluster-merge-{uuid.uuid4().hex}"
        self.users = []
        self.loser = self.survivor = self.neighbour = None
        self.operation = None
        self.duties = {}

    def fixture_name(self, suffix):
        return f"{self.prefix}-{suffix}"

    def person(self, suffix):
        user = int(self.fixture_sql(f"INSERT INTO hilos_user (name, admin) VALUES ('{self.fixture_name(suffix)}', 0); "
                            "SELECT LAST_INSERT_ID()"))
        if suffix != MARKER_SUFFIX:
            self.users.append(user)
        return user

    def seed(self, suffix, source):
        user = self.person(suffix)
        now = int(time.time() * 1000)
        email = f"{self.prefix}-{suffix}@example.test"
        days = LOSER_WAIT_DAYS if source else SURVIVOR_WAIT_DAYS
        self.fixture_sql("INSERT INTO hilos_identity "
                 "(user_id, type, identifier, secret, verified, password_changed_at, created_at, updated_at) "
                 f"VALUES ({user}, 'password', '{email}', '{FIXTURE_PASSWORD_HASH}', 1, {now}, {now}, {now}); "
                 "INSERT INTO hilos_second_factor "
                 "(user_id, label, secret, last_used_step, confirmed_at, last_used_at, created_at) "
                 f"VALUES ({user}, 'Authenticator', 'totp-fixture', 23, {now}, {now}, {now}); "
                 "INSERT INTO hilos_second_factor_backup_code (user_id, code, used_at, created_at) "
                 f"VALUES ({user}, 'unusedfixture', NULL, {now}); "
                 "INSERT INTO hilos_second_factor_setting (user_id, reset_wait_days, updated_at) "
                 f"VALUES ({user}, {days}, {now})")
        if source:
            self.fixture_sql("INSERT INTO hilos_identity (user_id, type, identifier, verified, created_at, updated_at) "
                     f"VALUES ({user}, 'passkey', '{self.prefix}-{suffix}-key', 1, {now}, {now}); "
                     "INSERT INTO hilos_passkey_credential "
                     "(identity_id, user_id, credential_id, public_key, algorithm, sign_count, transports, "
                     "user_handle, label, last_used_at, created_at) "
                     f"""VALUES (LAST_INSERT_ID(), {user}, '{self.prefix}-{suffix}-credential', 'key-fixture',
                                 -7, 17, '["usb","internal"]', UNHEX('66697874757265'), 'Key', {now}, {now});
                     INSERT INTO hilos_second_factor (user_id, label, secret, confirmed_at, created_at)
                     VALUES ({user}, 'Unconfirmed', 'pending-fixture', NULL, {now});
                     INSERT INTO hilos_second_factor_backup_code (user_id, code, used_at, created_at)
                     VALUES ({user}, 'usedfixture', {now}, {now});
                     INSERT INTO hilos_access_log (user_id, event, ip_address, occurred_at)
                     VALUES ({user}, 'sign_in', '192.0.2.61', {now})""")
        else:
            self.fixture_sql("INSERT INTO hilos_second_factor_trust (session_id, user_id, trusted_until, created_at, granted_at) "
                     f"VALUES ({user}, {user}, {now + 86400000}, {now}, {now})")
        return user

    def prepare(self):
        self.s.wait_converge(self.nodes)
        self.wait(self.s.fleet_started, "the original fleet before merge")
        for agent in (HOLDER, "hilos_users_library", "hilos_backup"):
            self.placed(agent)
        self.s.wait_protected_mode(lambda row: row.get("phase") == "inactive",
                                   "every node open before merge", nodes=self.nodes)
        self.begin_backup_observation()
        self.pause()
        # Seed both source candidates before observing either one's placement.
        # The unused candidate is the untouched neighbour and has its own protection.
        candidates = [self.seed(suffix, source=True) for suffix in SOURCE_SUFFIXES]
        self.survivor = self.seed(SURVIVOR_SUFFIX, source=False)
        holder = self.incarnation(HOLDER)
        placements = {user: self.place(f"hilos_user:{user}") for user in candidates}
        suitable = [user for user in candidates if placements[user] != holder[0]]
        assert suitable, f"no prepared source on a different worker from H {holder[0]}: {placements}"
        self.loser = suitable[0]
        self.neighbour = next(user for user in candidates if user != self.loser)
        source = self.incarnation(f"hilos_user:{self.loser}")
        assert source[0] == placements[self.loser] and self.incarnation(HOLDER) == holder
        self.place(f"hilos_user:{self.survivor}")
        print(f"    merge 61 placement: A={self.loser} on {source[0].node} worker #{source[0].index}; "
              f"H on {holder[0].node} worker #{holder[0].index}; B={self.survivor}; N={self.neighbour}", flush=True)
        return source, self.incarnation(f"hilos_user:{self.survivor}"), holder

    def person_snapshot(self, user):
        return (
            self.sql(f"SELECT * FROM hilos_user WHERE id = {user}"),
            *(self.sql(f"SELECT * FROM {table} WHERE user_id = {user} ORDER BY 1") for table in PERSON_TABLES),
        )

    def pair(self):
        return f"kind = 'merge' AND root_user_id = {self.survivor} AND loser_user_id = {self.loser}"

    def open_at_give(self, agent):
        self.probe(agent, "arm", frame_kind="handover")
        self.probe(HOLDER, "arm", frame_kind="ask", stage="go")
        self.start_caller(self.entry, "account:merge", self.survivor, self.loser,
                          "--password=survivor", "--second-factor=both")
        lost_give = payload(self.held(HOLDER))
        operation = self.sql(f"SELECT id FROM hilos_account_operation WHERE {self.pair()}")
        assert operation.isdigit(), "the held give requires exactly one durable merge"
        self.operation = int(operation)
        self.duties = {key: self.assignment(self.operation, key) for key in (*ARCHIVED_DUTIES, *RESUMED_DUTIES)}
        give = self.duties["give"]
        receive = self.duties["receive"]
        assert (lost_give["operationId"], lost_give["assignmentId"], lost_give["stage"]) == (self.operation, give, "go")
        assert not self.result(give), "the withheld give had already committed"
        self.probe(HOLDER, "discard")
        # Arm the successor before firing the ordinary pass, with the first ask lost.
        self.probe(HOLDER, "arm", frame_kind="ask", assignment_id=receive)
        self.fire()

        handover = self.held(agent)
        held_receive = self.held(HOLDER)
        for frame in (handover, held_receive):
            part = payload(frame)
            assert (part["operationId"], part["assignmentId"], part["stage"]) == (self.operation, receive, "go")
        self.wait(lambda _: bool(self.result(self.duties["sessions"])), "holder sessions committed at the partial merge")
        return handover, held_receive

    def snapshot(self):
        operation = self.operation
        rows = self.sql("SELECT r.assignment_id, r.id, r.owner_agent_id, r.payload_json, r.created_at "
                        "FROM hilos_account_operation_result r JOIN hilos_account_operation_assignment a "
                        f"ON a.id = r.assignment_id WHERE a.operation_id = {operation} ORDER BY r.assignment_id")
        return {
            "operation": self.sql(f"SELECT * FROM hilos_account_operation WHERE id = {operation}"),
            "assignments": self.sql("SELECT * FROM hilos_account_operation_assignment "
                                    f"WHERE operation_id = {operation} ORDER BY id"),
            "results": {int(line.split("\t", 1)[0]): line for line in rows.splitlines()},
            "copy": self.sql("SELECT c.assignment_id, c.owner_agent_id, c.created_at, SHA2(c.payload_json, 256) "
                             "FROM hilos_account_merge_copy c JOIN hilos_account_operation_assignment a "
                             f"ON a.id = c.assignment_id WHERE a.operation_id = {operation} ORDER BY c.assignment_id"),
            "fences": self.sql("SELECT collection_key, row_id, operation_id, assignment_id, owner_user_id, revision, deleted "
                               f"FROM hilos_account_merge_fence WHERE operation_id = {operation} ORDER BY collection_key, row_id"),
            "tombstone": self.sql(f"SELECT * FROM hilos_user_merge WHERE user_id = {self.loser}"),
            "loser": self.person_snapshot(self.loser),
            "survivor": self.person_snapshot(self.survivor),
        }

    def source_rows(self):
        rows = {}
        for key, table in TRANSFER_TABLES.items():
            confirmed = " AND confirmed_at IS NOT NULL" if key == "secondFactors" else ""
            rows[key] = self.sql(f"SELECT id FROM {table} WHERE user_id = {self.loser}{confirmed} ORDER BY id").splitlines()
        rows["access_log"] = self.sql(f"SELECT id FROM hilos_access_log WHERE user_id = {self.loser}").splitlines()
        assert [len(ids) for ids in rows.values()] == [2, 1, 1, 2, 1], "incomplete loser fixture"
        return rows

    def assert_transferred(self, source_rows, snapshot):
        expected_fences = set()
        for key, table in TRANSFER_TABLES.items():
            for row in source_rows[key]:
                expected_fences.add((key, row, str(self.operation), str(self.duties["give"]), str(self.survivor), "1", "0"))
            assert not self.sql(f"SELECT id FROM {table} WHERE user_id = {self.loser}"), f"{table} kept a loser row"
            ids = ",".join(source_rows[key])
            assert self.sql(f"SELECT id FROM {table} WHERE user_id = {self.survivor} AND id IN ({ids}) ORDER BY id").splitlines() \
                == source_rows[key], f"{table} did not keep the source ids"
        fences = {tuple(line.split("\t")) for line in snapshot["fences"].splitlines()}
        assert fences == expected_fences, "transfer fences differ from the original ids at revision one"
        access_log = source_rows["access_log"][0]
        assert self.sql(f"SELECT user_id FROM hilos_access_log WHERE id = {access_log}") == str(self.survivor)
        assert self.sql(f"SELECT survivor_user_id FROM hilos_user_merge WHERE user_id = {self.loser}") == str(self.survivor)

    def backup_code_spends(self, user):
        rows = self.sql("SELECT id, used_at IS NOT NULL FROM hilos_second_factor_backup_code "
                        f"WHERE user_id = {user} ORDER BY id")
        return dict(line.split("\t") for line in rows.splitlines())

    def protection(self, password, code_spends):
        user = self.survivor
        assert self.sql("SELECT id, secret FROM hilos_identity "
                        f"WHERE user_id = {user} AND type = 'password'") == password, "survivor password changed"
        assert self.sql(f"SELECT reset_wait_days FROM hilos_second_factor_setting WHERE user_id = {user}") \
            == str(max(LOSER_WAIT_DAYS, SURVIVOR_WAIT_DAYS)), "reset wait did not take the greater value"
        assert self.sql(f"SELECT COUNT(*) FROM hilos_second_factor_trust WHERE user_id = {user}") == "0"
        factors = self.sql(f"SELECT id, confirmed_at IS NOT NULL FROM hilos_second_factor WHERE user_id = {user} ORDER BY id")
        assert [line.split("\t")[1] for line in factors.splitlines()] == ["1", "1"], "both confirmed factors must survive"
        assert self.backup_code_spends(user) == code_spends, "backup code spend changed for an original id"
        return factors

    def sleep_survivor(self, agent, incarnation):
        assert self.incarnation(agent) == incarnation, "the original survivor must still be live"
        node = incarnation[0].node
        idle_mark = self.s.node_log_mark(node)
        worker_marks = self.worker_marks(node)
        self.probe(agent, "expire-idle", node)

        def slept(views):
            return (not self.s.placement_row(views, agent)
                    and re.search(rf"Agent {re.escape(agent)} stopped \(idle for \d+s\)", self.worker_since(node, worker_marks))
                    and self.s.stopped_on_worker_at(self.s.node_log_since(node, idle_mark), agent))

        self.wait(slept, "the original survivor really stopped idle before its receipt")
        assert not self.result(self.duties["receive"])

    def kill_sender(self, agent, incarnation):
        process = incarnation[0]
        assert self.incarnation(agent) == incarnation, "the original sender must still hold its frame"
        mark = self.s.node_log_mark(process.node)
        killed = control.signal_worker_process(self.stand, process, signal.SIGKILL)
        assert killed.code == 0 and json.loads(killed.out) is not None, "the captured sender worker was not killed"

        def died(_):
            log = self.s.node_log_since(process.node, mark)
            return any(int(index) == process.index and agent in agents.split(", ")
                       for index, agents in self.s.WORKER_DIED_HOSTING.findall(log))

        self.wait(died, "the master reported the sender lost with its captured worker")
        self.forget_stopped(agent, incarnation)

    def recover_with_fresh_handover(self, sender, recipient, holder):
        assert self.incarnation(HOLDER) == holder, "the holder did not survive the sender fault"
        receive = self.duties["receive"]
        self.probe(HOLDER, "discard")
        self.probe(HOLDER, "arm", frame_kind="ask", operation_id=self.operation, assignment_id=receive)
        views = self.wait(lambda views: not self.s.placement_row(views, sender),
                          "the lost sender is absent from placement")
        assert not self.s.placement_row(views, recipient) and not self.result(receive), (
            "the survivor woke before sender recovery")
        # Startup replays give before a probe can arm on the new sender. Place
        # only A with recovery paused, so its single startup give answer is
        # the only source of an ordinary receive ask, held by the probe at H.
        self.command(self.entry, "test:cluster:agent:place", *sender.split(":", 1))
        try:
            self.s.wait_until(lambda _: bool(self.result(receive)), self.s.CONVERGE_TIMEOUT,
                              "survivor receipt while the ordinary receive ask is withheld", nodes=[])
        except self.s.ScenarioTimeout as failure:
            raise AssertionError("the replacement's fresh handover did not wake the survivor") from failure
        assert self.incarnation(HOLDER) == holder, "a replacement holder could have sent an unguarded receive ask"
        gate = self.probe(HOLDER, "inspect")
        assert gate["armed"] or gate["held"] is not None, "the receive gate cleared before the receipt"
        self.probe(HOLDER, "clear")
        self.probes.pop(HOLDER)
        self.fire()

    def replay_finished_handover(self, recipient, handover, receipt):
        receive = self.duties["receive"]
        # The original pass's receive ask was held and discarded. New recovery
        # passes and resumed owners use new journal receipts. On this stand the
        # worker joins the input receipt and stamps it on the synchronous reply.
        assert type(receipt) is int and receipt > 0, "the captured handover has no journal receipt"
        self.probe(recipient, "arm", frame_kind="answer", operation_id=self.operation, assignment_id=receive)
        self.replay(handover, copies=1)
        frame = self.held(recipient)
        assert frame["signal"]["data"].get("receipt") == receipt, (
            "captured an answer outside the original handover's journal receipt")
        reply = payload(frame)
        assert (reply["operationId"], reply["assignmentId"], reply["stage"], reply["answer"]) == (
            self.operation, receive, "go", "done"), "the stale handover did not return the saved receipt"
        self.probe(recipient, "clear")
        self.probes.pop(recipient)

    def sender_stopped(self, agent, marks, views):
        if self.s.placement_row(views, agent):
            return False
        for node, mark in marks.items():
            log = self.s.node_log_since(node, mark)
            starts = self.s.started_on_worker_at(log, agent)
            stops = self.s.stopped_on_worker_at(log, agent)
            if starts and stops and stops[-1] >= starts[-1]:
                return True
        return False

    def restore_replayed_at(self):
        attempt = self.restore_attempt
        replay = re.compile(rf"Restore {re.escape(attempt.backup_id)} replayed in \d+s; re-reading state")
        stamps = [stamp for stamp, line in self.agent_log(attempt.node, attempt.marks) if replay.fullmatch(line)]
        assert attempt.settled and len(stamps) == 1, "no unique completed FULL import in the addressed restore journal"
        self.s.log_time(stamps[0])
        return stamps[0]

    def restored_sender_lifecycles(self, agent, node, mark, replayed_at):
        path = control.node_log_path(self.stand.node(node))
        _, since_mark = journal_segment(path, mark)
        freezes = [stamp for stamp, line in since_mark if line.startswith("Protected mode: froze this node for hilos_backup,")]
        if len(freezes) != 1 or freezes[0] >= replayed_at:
            return None
        _, entries = journal_segment(path, control.LogMark(mark.inode, 0))
        workers = {}
        instances = []
        lifecycle = re.compile(rf"Agent '{re.escape(agent)}' (started|stopped) on worker #(\d+)")
        for position, (stamp, line) in enumerate(entries):
            event = stamp, position
            if match := re.fullmatch(r"Worker #(\d+) (started|stopped) \[type=\w+\]", line):
                worker = int(match[1])
                if match[2] == "started":
                    workers[worker] = event
                else:
                    workers.pop(worker, None)
            if stamp <= replayed_at or not (match := lifecycle.fullmatch(line)):
                continue
            worker = int(match[2])
            generation = workers.get(worker)
            if match[1] == "started":
                if generation is None:
                    return None
                instances.append(SenderLifecycle(node, worker, generation, event))
            elif instances:
                instance = instances[-1]
                if instance.stopped is None and (instance.worker, instance.worker_started) == (worker, generation):
                    instance.stopped = event
        return instances, workers

    def restored_sender_stopped(self, agent, marks, replayed_at, views):
        placement = self.s.placement_row(views, agent)
        if placement and placement.get("state") != "started":
            return False
        lifecycles = []
        workers = {}
        for node, mark in marks.items():
            observed = self.restored_sender_lifecycles(agent, node, mark, replayed_at)
            if observed is None:
                return False
            instances, workers[node] = observed
            lifecycles.extend(instances)
        lifecycles.sort(key=lambda instance: instance.started[0])
        if not lifecycles or lifecycles[0].stopped is None:
            return False
        restored = lifecycles[0]
        if any(instance.node != restored.node and instance.started[0] == restored.started[0]
               for instance in lifecycles[1:]):
            return False
        running = [instance for instance in lifecycles if instance.stopped is None]
        successor = None
        if placement:
            if len(running) != 1:
                return False
            current, = running
            if current.node != placement["nodeId"]:
                return False
            start_stamp = current.started[0]
            stop_stamp = restored.stopped[0]
            if start_stamp < stop_stamp:
                return False
            if start_stamp == stop_stamp and (current.node != restored.node or current.started <= restored.stopped):
                return False
            process, start_line = self.incarnation(agent, placement)
            if (process.index != current.worker or list(log_entries([start_line])) != [
                    (start_stamp, f"Agent '{agent}' started on worker #{current.worker}")]):
                return False
            # Re-read after resolving the PID so index reuse cannot bind an old
            # start report to the replacement process in that slot.
            if self.restored_sender_lifecycles(agent, current.node, marks[current.node], replayed_at) != (
                    [instance for instance in lifecycles if instance.node == current.node], workers[current.node]):
                return False
            if workers[current.node].get(current.worker) != current.worker_started:
                return False
            successor = (process, start_stamp)
        elif running:
            return False
        print(f"    merge 61 restored sender lifecycle: FULL replayed at {replayed_at}; "
              f"A on {restored.node} worker #{restored.worker} (worker started {restored.worker_started[0]}), "
              f"started {restored.started[0]}, stopped {restored.stopped[0]}; successor {successor}", flush=True)
        return True

    def completed(self):
        return self.sql("SELECT outcome = 'completed' AND finished_at IS NOT NULL FROM hilos_account_operation "
                        f"WHERE id = {self.operation} AND {self.pair()}") == "1"

    def assert_completed(self):
        assert self.sql(f"SELECT id FROM hilos_account_operation WHERE {self.pair()}") == str(self.operation)
        observed = self.command(self.entry, "account:merge", f"--operation={self.operation}")
        assert f"Operation {self.operation}, pair {self.survivor} {self.loser}," in observed
        assert f"Reply (ok): merged user #{self.loser} into #{self.survivor} (" in observed

    def clean_fixtures(self, failed):
        if failed:
            self.clean_sql_fixtures()
            return
        # Replay warmed A and B. Warm N after restore before starting its erasure too.
        self.place(f"hilos_user:{self.neighbour}")
        roots = (self.survivor, self.neighbour)
        requests = []
        for user in roots:
            requests.append(self.request(user))
        for user in roots:
            self.start_caller(self.entry, "test:account:force-purge", user)
        ids = ",".join(map(str, self.users))
        request_ids = ",".join(map(str, requests))

        def erased(_):
            return (self.sql("SELECT COUNT(*) FROM hilos_account_operation WHERE kind = 'erasure' "
                             f"AND request_id IN ({request_ids}) AND outcome = 'completed' AND finished_at IS NOT NULL") == "2"
                    and self.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id IN ({ids})") == "0")

        try:
            self.s.wait_until(erased, self.s.CONVERGE_TIMEOUT, "product erasure completed for B's circle and N", nodes=[])
        except self.s.ScenarioTimeout:
            status = self.sql("SELECT request_id, stage, outcome FROM hilos_account_operation "
                              f"WHERE kind = 'erasure' AND request_id IN ({request_ids}) ORDER BY request_id")
            print(f"    merge 61 cleanup requests {request_ids}, durable states {status.splitlines()}", flush=True)
            raise

    def clean_sql_fixtures(self):
        # The fixture INSERT may have committed before its id reply was lost.
        # The post-archive marker's numeric ID can belong to another row after FULL.
        names = ", ".join(f"'{self.fixture_name(suffix)}'"
                          for suffix in (*SOURCE_SUFFIXES, SURVIVOR_SUFFIX, MARKER_SUFFIX))
        ids = self.fixture_users(names, self.users)
        if ids:
            self.sql("START TRANSACTION; "
                     "DELETE f FROM hilos_account_merge_fence f JOIN hilos_account_operation o ON o.id = f.operation_id "
                     f"WHERE o.root_user_id IN ({ids}) OR o.loser_user_id IN ({ids}); "
                     f"DELETE FROM hilos_account_operation WHERE root_user_id IN ({ids}) OR loser_user_id IN ({ids}); "
                     f"DELETE FROM hilos_account_deletion WHERE user_id IN ({ids}); "
                     + "".join(f"DELETE FROM {table} WHERE user_id IN ({ids}); "
                               for table in ("hilos_passkey_credential", "hilos_identity", *PERSON_TABLES[2:]))
                     + f"DELETE FROM hilos_user_merge WHERE user_id IN ({ids}) OR survivor_user_id IN ({ids}); "
                     f"DELETE FROM hilos_user WHERE id IN ({ids}); COMMIT")
            assert self.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id IN ({ids})") == "0"

    def cleanup(self, failed):
        super().cleanup(failed, product_fixtures_first=True)


def scenario_61_merge_survives_sender_death_and_full_restore(s):
    """One committed give survives sender death, then the same open checkpoint survives FULL import."""
    started = time.monotonic()
    run = MergeRun(s)
    failed = False
    phase = "prepare"
    try:
        source, target, holder = run.prepare()
        sender, recipient = f"hilos_user:{run.loser}", f"hilos_user:{run.survivor}"
        neighbour = run.person_snapshot(run.neighbour)
        source_rows = run.source_rows()
        source_spends = run.backup_code_spends(run.loser)
        survivor_spends = run.backup_code_spends(run.survivor)
        assert list(source_spends) == source_rows["secondFactorBackupCodes"]
        assert sorted(source_spends.values()) == ["0", "1"] and list(survivor_spends.values()) == ["0"]
        code_spends = source_spends | survivor_spends
        password = run.sql(f"SELECT id, secret FROM hilos_identity WHERE user_id = {run.survivor} AND type = 'password'")
        assert password and run.sql(
            f"SELECT COUNT(*) FROM hilos_second_factor_trust WHERE user_id = {run.survivor}") == "1"

        phase = "give checkpoint"
        handover, receive_ask = run.open_at_give(sender)
        replay_receipt = handover["signal"]["data"].get("receipt")
        assert replay_receipt == receive_ask["signal"]["data"].get("receipt"), \
            "the held ordinary receive and handover did not come from the same recovery pass"
        archived = run.snapshot()
        archived_ids = {run.duties[key] for key in ARCHIVED_DUTIES}
        resumed_ids = {run.duties[key] for key in RESUMED_DUTIES}
        assert set(archived["results"]) == archived_ids, "checkpoint must contain only both preparations, give and sessions"
        assert len(archived["assignments"].splitlines()) == len(run.duties)
        copy = archived["copy"].split("\t")
        assert len(copy) == 4 and copy[0] == str(run.duties["give"]) and copy[1] == sender
        assert re.fullmatch(r"[0-9a-f]{64}", copy[3]), "the give must have one saved copy"
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {run.operation}") == "NULL"
        run.assert_transferred(source_rows, archived)
        assert run.backup_code_spends(run.survivor) == code_spends, "give changed the spend of an original backup code"
        print(f"    merge 61 checkpoint: operation {run.operation}, both preparations, give and sessions committed, "
              "handover and receive ask held, receipt absent", flush=True)

        phase = "archive"
        run.capture_full_archive()
        assert run.snapshot() == archived, "unfiltered partial SQL changed during FULL capture/verify"
        assert run.incarnation(HOLDER) == holder
        assert run.probe(HOLDER, "inspect")["held"] == receive_ask, "the receive ask escaped during capture"
        assert run.probe(sender, "inspect")["held"] == handover, "the handover escaped during capture"

        phase = "idle and sender death"
        run.sleep_survivor(recipient, target)
        marks = {node: s.node_log_mark(node) for node in run.nodes}
        run.kill_sender(sender, source)
        run.recover_with_fresh_handover(sender, recipient, holder)
        run.wait(lambda _: run.completed(), "the original merge completed after sender startup and the ordinary pass")

        def restarted_and_cleaned(views):
            return run.sender_stopped(sender, marks, views) and any(
                s.started_on_worker_at(s.node_log_since(node, mark), recipient) for node, mark in marks.items())

        run.wait(restarted_and_cleaned, "a new sender incarnation finished and stopped, and the survivor woke")
        live = run.snapshot()
        assert live["results"].keys() == archived_ids | resumed_ids and not live["copy"]
        assert all(live["results"][key] == saved for key, saved in archived["results"].items())
        assert live["assignments"] == archived["assignments"] and live["fences"] == archived["fences"]
        run.assert_transferred(source_rows, live)
        live_protection = run.protection(password, code_spends)
        assert run.person_snapshot(run.neighbour) == neighbour
        run.assert_completed()
        print("    merge 61 live recovery: fresh sender handover woke the survivor with ordinary receive asks withheld, "
              "archived results and revision-one fences unchanged and copy removed", flush=True)

        phase = "full import and resume"
        marker = run.fixture_name(MARKER_SUFFIX)
        marker_id = run.person(MARKER_SUFFIX)
        assert run.sql(f"SELECT id FROM hilos_user WHERE name = '{marker}'") == str(marker_id)
        run.clean_pauses()
        run.pauses.clear()
        run.clean_probes()
        run.probes.clear()
        restore_marks = {node: s.node_log_mark(node) for node in run.nodes}
        restore_started_at = run.restore_full_archive()
        replayed_at = run.restore_replayed_at()
        run.wait(lambda _: run.completed(), "ordinary startup and recovery completed the restored merge")
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE name = '{marker}'") == "0", \
            "FULL import kept the post-archive marker"
        restored = run.snapshot()
        assert restored["results"].keys() == archived_ids | resumed_ids and not restored["copy"]
        assert restored["assignments"] == archived["assignments"] and restored["fences"] == archived["fences"]
        assert restored["tombstone"] == archived["tombstone"]
        assert all(restored["results"][key] == saved for key, saved in archived["results"].items())
        for key in resumed_ids:
            result = restored["results"][key]
            assert result != live["results"][key] and int(result.rsplit("\t", 1)[1]) >= restore_started_at, \
                f"assignment {key} was not rebuilt after restore"
        run.assert_transferred(source_rows, restored)
        assert run.protection(password, code_spends) == live_protection, "restored protection differs from live recovery"
        assert run.person_snapshot(run.neighbour) == neighbour
        run.assert_completed()
        print("    merge 61 FULL import: marker gone, archived results byte-identical, receipt, survivor sessions and cleanup "
              "rebuilt, same protection and unchanged neighbour", flush=True)

        phase = "finished replay"
        # Recovery can place A again after its restored cleanup stops it. Require
        # that stop even if the leader already has a later, fully started A.
        run.wait(lambda views: run.restored_sender_stopped(sender, restore_marks, replayed_at, views),
                 "restored sender finished and stopped before replay placement")
        sender_worker = run.place(sender)
        recipient_worker = run.place(recipient)
        replay_source = run.incarnation(sender)
        replay_target = run.incarnation(recipient)
        assert replay_source[0] == sender_worker and replay_target[0] == recipient_worker, (
            "replay placement changed before sending")
        route = "local" if sender_worker.node == recipient_worker.node else "cross-node"
        print(f"    merge 61 replay placement ({route}): "
              f"A on {sender_worker.node} worker #{sender_worker.index} "
              f"(pid={sender_worker.pid}, start ticks={sender_worker.start_ticks}); "
              f"B on {recipient_worker.node} worker #{recipient_worker.index} "
              f"(pid={recipient_worker.pid}, start ticks={recipient_worker.start_ticks})", flush=True)
        run.replay_finished_handover(recipient, handover, replay_receipt)
        assert run.incarnation(sender) == replay_source and run.incarnation(recipient) == replay_target, (
            "replay placement changed before the observed answer")
        assert run.snapshot() == restored, "old handover changed the completed operation or its effects"
        assert run.person_snapshot(run.neighbour) == neighbour
        print(f"    merge 61 replay ({route}): observed one saved receipt answer, SQL and neighbour unchanged", flush=True)
        return "same merge completed after sender death and FULL import, stale handover answered and fixtures erased by product"
    except BaseException:
        failed = True
        print(f"    merge 61 failed during {phase}", flush=True)
        raise
    finally:
        try:
            run.cleanup(failed)
        finally:
            print(f"    merge 61 elapsed including cleanup: {time.monotonic() - started:.1f}s", flush=True)

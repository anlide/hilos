"""Live erasure journeys, driven by the existing cluster runner and command channel.

SQL is deliberately external: unique fixtures precede lazy reads, and persisted facts
are an oracle independent of the probe and of the force-purge caller's answer. Product
handlers retain every business write. The final restart discards fixture process copies.
"""

import json
import re
import time
import uuid

import control
from account_operations import HOLDER, AccountOperationRun, payload


EMAIL_ENABLED = "notifications.channel.email.enabled"
MARKER_SUFFIX = "after-archive"


class ErasureRun(AccountOperationRun):
    """Fixtures for one live erasure journey."""

    def __init__(self, scenarios):
        super().__init__(scenarios, "erasure")
        self.names = []
        self.archived_users = set()
        self.email_snapshot = None
        self.prefix = f"cluster-erasure-{uuid.uuid4().hex}"

    def prepare(self):
        self.s.wait_converge(self.nodes)
        self.wait(self.s.fleet_started, "the original fleet before erasure")
        # AUTH is a live sessions holder and personal owner, BACKUP a live registered owner.
        for agent in (HOLDER, "hilos_users_library", "hilos_backup", "hilos_settings_library"):
            self.placed(agent)
        self.begin_backup_observation()
        self.pause()

    def person(self, suffix):
        name = f"{self.prefix}-{suffix}"
        self.names.append(name)
        user = int(self.fixture_sql(f"INSERT INTO hilos_user (name, admin) VALUES ('{name}', 0); SELECT LAST_INSERT_ID()"))
        if suffix != MARKER_SUFFIX:
            self.archived_users.add(user)
        now = int(time.time() * 1000)
        self.fixture_sql("INSERT INTO hilos_identity (user_id, type, identifier, verified, created_at, updated_at) "
                 f"VALUES ({user}, 'password', '{self.prefix}-{suffix}@example.test', 1, {now}, {now}); "
                 "INSERT INTO hilos_user_rename (user_id, old_name, new_name, renamed_at) "
                 f"VALUES ({user}, 'Before', '{self.prefix}-{suffix}', {now})")
        return user

    def delivery(self, root, channel, routing_key=0):
        now = int(time.time() * 1000)
        notification = int(self.fixture_sql("INSERT INTO hilos_notification (user_id, type, title, created_at) "
                                    f"VALUES ({root}, 'cluster.erasure', 'Erasure fixture', {now}); "
                                    "SELECT LAST_INSERT_ID()"))
        return int(self.fixture_sql("INSERT INTO hilos_notification_delivery "
                            "(notification_id, channel, routing_key, status, created_at, updated_at) "
                            f"VALUES ({notification}, '{channel}', {routing_key}, 'pending', {now}, {now}); "
                            "SELECT LAST_INSERT_ID()"))

    def disable_email(self):
        self.email_snapshot = self.command(self.entry, "test:settings:snapshot", EMAIL_ENABLED)
        json.loads(self.email_snapshot)
        self.backup_admission()
        self.command(self.entry, "settings:set", EMAIL_ENABLED, "false")
        assert self.sql(f"SELECT value FROM hilos_setting WHERE `key` = '{EMAIL_ENABLED}'") in ("0", "false")

    def force(self, root):
        self.start_caller(self.entry, "test:account:force-purge", root)

    def operation(self, root):
        found = []

        def opened(_):
            value = self.sql(f"SELECT id FROM hilos_account_operation WHERE root_user_id = {root} AND kind = 'erasure'")
            if value:
                found.append(int(value))
            return bool(value)

        self.wait(opened, f"one durable erasure of {root}")
        return found[0]

    def personal_key(self, prefix, user):
        return f"{prefix}:personal:hilos_user:{user}:{user}"

    def business(self, user):
        return self.sql(f"SELECT * FROM hilos_user WHERE id = {user}") + "\n" + self.sql(
            f"SELECT * FROM hilos_identity WHERE user_id = {user} ORDER BY id") + "\n" + self.sql(
            f"SELECT * FROM hilos_user_verification WHERE user_id = {user} ORDER BY id")

    def open_at_personal(self, root):
        """Hold the personal ask of a live root, before its part runs."""
        agent = f"hilos_user:{root}"
        self.place(agent)
        self.probe(HOLDER, "arm", frame_kind="ask")
        self.force(root)
        first = self.held(HOLDER)
        operation = self.operation(root)
        start = payload(first)["assignmentId"]
        assert payload(first)["operationId"] == operation
        assert start == self.assignment(operation, self.personal_key("start", root))
        assert not self.result(start), "the held start ask already has a result"
        personal = self.assignment(operation, self.personal_key("expect", root))
        self.probe(HOLDER, "discard")
        # Requeue the exact suppressed envelope only after arming its successor. This avoids
        # a race between two CLI calls and a fast participant; nothing synthesizes an ask.
        self.probe(HOLDER, "arm", frame_kind="ask", assignment_id=personal)
        self.replay(first)
        held = self.held(HOLDER)
        assert payload(held)["assignmentId"] == personal
        assert self.result(start), "ordinary wake/startup did not commit the start"
        self.process(agent)
        return operation, personal, held

    def clean_fixtures(self, failed):
        if not self.names:
            return
        names = ", ".join(f"'{name}'" for name in self.names)
        ids = self.fixture_users(names, self.archived_users)
        if not ids:
            return
        if not self.writers_stopped:
            self.backup_admission(allow_stopped=True)
        self.sql("START TRANSACTION; "
                 "DELETE d FROM hilos_notification_delivery d JOIN hilos_notification n ON n.id = d.notification_id "
                 f"WHERE n.user_id IN ({ids}); "
                 f"DELETE FROM hilos_account_operation WHERE root_user_id IN ({ids}); "
                 f"DELETE FROM hilos_account_deletion WHERE user_id IN ({ids}); "
                 f"DELETE FROM hilos_user_verification WHERE user_id IN ({ids}); "
                 f"DELETE FROM hilos_identity WHERE user_id IN ({ids}); "
                 f"DELETE FROM hilos_notification WHERE user_id IN ({ids}); "
                 f"DELETE FROM hilos_user_rename WHERE user_id IN ({ids}); "
                 f"DELETE FROM hilos_user_merge WHERE user_id IN ({ids}); "
                 f"DELETE FROM hilos_user WHERE id IN ({ids}); COMMIT")
        assert self.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id IN ({ids})") == "0"

    def restore_email(self):
        if self.email_snapshot is not None:
            self.command(self.entry, "test:settings:restore", self.email_snapshot)
            assert json.loads(self.command(self.entry, "test:settings:snapshot", EMAIL_ENABLED)) == json.loads(self.email_snapshot)

    def cleanup(self, failed, *, archive_first=False, writers_finished=False):
        email = (("email setting", self.restore_email),) if self.email_snapshot is not None else ()
        super().cleanup(failed, before_restart=email,
                        archive_first=archive_first, writers_finished=writers_finished)


def scenario_58_erasure_frames_and_owner_recovery(s):
    """One circle loses frames and its personal host while the holder stays alive."""
    started = time.monotonic()
    run = ErasureRun(s)
    failed = False
    try:
        run.prepare()
        run.pause(f"{HOLDER}/hilos_account_deletion_sweep")
        run.disable_email()
        # Seed both candidate circles completely before warming either personal owner.
        # Selection uses one bounded observation of real placement, never a host selector.
        candidates = []
        mail = "hilos_mail:1"
        for suffix in ("a", "b"):
            candidate = run.person(f"root-{suffix}")
            child = run.person(f"folded-{suffix}")
            run.fixture_sql("INSERT INTO hilos_user_merge (user_id, survivor_user_id, merged_at) "
                    f"VALUES ({child}, {candidate}, {int(time.time() * 1000)})")
            request_id = run.request(candidate)
            now = int(time.time() * 1000)
            verification_id = int(run.fixture_sql("INSERT INTO hilos_user_verification "
                "(user_id, type, identifier, expires_at, created_at) "
                f"VALUES ({candidate}, 'password_reset', '{run.prefix}-{suffix}-code@example.test', "
                f"{now + 86400000}, {now}); SELECT LAST_INSERT_ID()"))
            # Key zero belongs to shard one for every positive production pool width.
            delivery_id = run.delivery(candidate, "email", routing_key=0)
            candidates.append((candidate, child, request_id, verification_id, delivery_id))
        neighbour = run.person("neighbour")
        run.request(neighbour)
        holder_before = run.incarnation(HOLDER)
        library = "hilos_users_library"
        library_before = run.incarnation(library)
        placements = {row[0]: run.place(f"hilos_user:{row[0]}") for row in candidates}
        suitable = [row for row in candidates if placements[row[0]].node != holder_before[0].node]
        assert suitable, f"no prepared root away from H {holder_before}: {placements}"
        root, folded, request, verification, delivery = suitable[0]
        agent = f"hilos_user:{root}"
        owner_before = run.incarnation(agent)
        assert owner_before[0] == placements[root]
        assert owner_before[0] != library_before[0], (
            "simultaneous root/library probes require different workers", owner_before, library_before)
        run.place(mail)
        untouched = [user for user in run.users if user not in (root, folded)]

        def untouched_snapshot():
            return {user: (
                run.business(user),
                run.sql(f"SELECT * FROM hilos_user_rename WHERE user_id = {user} ORDER BY id"),
                run.sql("SELECT * FROM hilos_user_merge "
                        f"WHERE user_id = {user} OR survivor_user_id = {user} ORDER BY user_id, survivor_user_id"),
                run.sql(f"SELECT * FROM hilos_account_deletion WHERE user_id = {user} ORDER BY id"),
                run.sql(f"SELECT * FROM hilos_notification WHERE user_id = {user} ORDER BY id"),
                run.sql("SELECT d.* FROM hilos_notification_delivery d JOIN hilos_notification n "
                        f"ON n.id = d.notification_id WHERE n.user_id = {user} ORDER BY d.id"),
            ) for user in untouched}

        neighbour_before = untouched_snapshot()
        print(f"    erasure 58 placements: {placements}; chosen root={root}, folded={folded}", flush=True)
        marks = {node: s.node_log_mark(node) for node in s.ALL_NODES}
        run.probe(HOLDER, "arm", frame_kind="ask")
        run.force(root)
        lost_ask = run.held(HOLDER)
        operation = run.operation(root)
        start = run.assignment(operation, run.personal_key("start", root))
        assert payload(lost_ask)["assignmentId"] == start and not run.result(start)
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        run.probe(HOLDER, "discard")
        run.probe(agent, "arm", frame_kind="answer", assignment_id=start)
        run.fire()  # Ordinary recovery replaces the lost ask, without a new purge request.
        start_answer = run.held(agent)
        assert run.result(start), "the recovery pass did not commit the lost start"
        personal = run.assignment(operation, run.personal_key("expect", root))
        library_part = run.assignment(operation, f"expect:users_library:{library}:{root}")
        mail_part = run.assignment(operation, f"expect:delivery:email:{mail}:{root}")
        run.probe(agent, "discard")
        run.probe(agent, "arm", frame_kind="answer", assignment_id=personal)
        run.probe(HOLDER, "arm", frame_kind="ask", assignment_id=library_part)
        run.replay(start_answer)
        library_ask = run.held(HOLDER)
        personal_answer = run.held(agent)
        assert payload(library_ask)["assignmentId"] == library_part
        assert payload(personal_answer)["assignmentId"] == personal
        assert set(json.loads(run.sql(f"SELECT circle_user_ids FROM hilos_account_operation WHERE id = {operation}"))) == {root, folded}
        # The root's held personal answer occupies a different worker from this library.
        # H and the library may share a worker: discard H's frame before arming the library.
        run.probe(HOLDER, "discard")
        run.probe(library, "arm", frame_kind="answer", assignment_id=library_part)
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user_verification WHERE id = {verification}") == "1"
        run.replay(library_ask)
        library_answer = run.held(library)
        library_result = run.result(library_part)
        assert library_result
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user_verification WHERE id = {verification}") == "0"
        run.probe(library, "release")
        run.probe(library, "arm", frame_kind="answer", assignment_id=library_part)
        run.replay(library_ask)  # One duplicate ask from the original, still live H.
        duplicate_answer = run.held(library)
        assert payload(duplicate_answer) == payload(library_answer)
        assert run.result(library_part) == library_result
        run.probe(library, "discard")
        personal_result = run.result(personal)
        assert personal_result and run.sql(f"SELECT COUNT(*) FROM hilos_identity WHERE user_id = {root}") == "0"
        folded_final = run.assignment(operation, run.personal_key("final", folded))
        run.wait(lambda _: bool(run.result(folded_final)), "the folded final committed at its real owner")
        folded_result = run.result(folded_final)
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id = {folded}") == "0"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id = {root}") == "1"
        assert not run.result(mail_part), "the prerequisite is already finished"
        assert run.sql(f"SELECT status FROM hilos_notification_delivery WHERE id = {delivery}") == "pending"
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        assert untouched_snapshot() == neighbour_before

        # All these frames carry the recovery receipt, unchanged by replay. Its only
        # remaining ordinary handover is the root's held personal answer. Replaying the
        # duplicate ask consumed one unstamped handover and produced the discarded answer:
        # those two cancel. Thus one outstanding handover also proves that the released
        # original answer and every other original frame have finished their receipt joins.
        receipt = library_answer["signal"]["data"]["receipt"]
        assert isinstance(receipt, int) and receipt > 0
        assert all(frame["signal"]["data"]["receipt"] == receipt for frame in (
            start_answer, personal_answer, library_ask, duplicate_answer))
        assert lost_ask["signal"]["data"]["receipt"] != receipt
        assert run.probe(agent, "inspect")["held"] == personal_answer
        journal = "`" + (run.stand.database.name + "-change-log").replace("`", "``") + "`"

        def receipt_snapshot():
            row = run.sql(f"SELECT id, created_at, open_handovers FROM {journal}.hilos_change_log_receipt "
                          f"WHERE id = {receipt}").split("\t")
            assert len(row) == 3 and row[0] == str(receipt) and row[1].isdigit(), row
            return int(row[0]), int(row[1]), int(row[2])

        run.wait(lambda _: receipt_snapshot()[2] == 1,
                 "the recovery receipt has only the held personal handover left")
        receipt_before = receipt_snapshot()  # Fresh boundary, after the original answer joined H.
        assert receipt_before[2] == 1
        business_before = {user: run.business(user) for user in (root, folded)}
        run.replay(library_answer)  # One duplicate answer from the still live participant.
        # The control ack can overtake an RT-blocked frame. Only the receiver's join
        # after its handler consumes this extra, unstamped answer and reaches zero.
        run.wait(lambda _: receipt_snapshot() == (*receipt_before[:2], 0),
                 "H finished handling the duplicate answer under its exact recovery receipt")
        receipt_after = receipt_snapshot()
        assert receipt_after == (*receipt_before[:2], 0)
        assert run.result(library_part) == library_result and run.result(personal) == personal_result
        assert run.result(folded_final) == folded_result
        assert {user: run.business(user) for user in (root, folded)} == business_before
        assert not run.result(mail_part)
        assert run.sql(f"SELECT status FROM hilos_notification_delivery WHERE id = {delivery}") == "pending"
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        assert untouched_snapshot() == neighbour_before
        print(f"    erasure 58 duplicate answer receiver receipt: {receipt_before} -> {receipt_after}; "
              f"library result unchanged={library_result!r}; personal/folded/business/neighbours exact", flush=True)
        # These saved full envelopes prove a real owner/H exchange across their actual nodes.
        assert lost_ask["agentId"] == library_ask["agentId"] == HOLDER
        assert personal_answer["agentId"] == agent
        assert run.incarnation(HOLDER) == holder_before
        assert run.incarnation(agent) == owner_before
        assert run.incarnation(library) == library_before
        other = next(node for node in s.SLAVES if node != owner_before[0].node)
        move_mark = s.node_log_mark(other)
        print(f"    erasure 58 checkpoint: operation={operation}, personal={personal_result!r}, "
              f"mail debt open; owner={owner_before}, holder={holder_before}; "
              f"duplicate ask={json.dumps(library_ask)}, answer={json.dumps(library_answer)}", flush=True)
        run.killed = owner_before[0].node
        run.killed_container = owner_before[0].container
        s.ctl("kill", run.killed)
        run.nodes = [node for node in s.ALL_NODES if node != run.killed]
        gone = control.signal_worker_process(run.stand, owner_before[0])
        assert gone.code == 0 and gone.out.strip() == "null", gone
        # A dead source's held answer is lost. Never release or replay it on a successor.
        for source, incarnation in list(run.probes.items()):
            if incarnation[0].node == run.killed:
                run.forget_stopped(source, incarnation)
        if run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL":
            run.fire()  # At most the second pass, BEFORE waiting for the new personal start.
        run.wait(lambda _: any(name == agent for name, _ in s.AGENT_STARTED_ON_WORKER.findall(
            s.node_log_since(other, move_mark))), "the root started on the surviving slave",
            timeout=s.FAILOVER_TIMEOUT)
        starts = [int(index) for name, index in s.AGENT_STARTED_ON_WORKER.findall(
            s.node_log_since(other, move_mark)) if name == agent]
        owner_after = control.worker_process(run.stand, other, starts[-1])
        assert owner_after != owner_before[0] and owner_after.node == other
        # The new owner may already have finalized and stopped; placement is not its proof.
        run.wait(lambda _: run.sql("SELECT outcome = 'completed' AND finished_at IS NOT NULL "
                                  f"FROM hilos_account_operation WHERE id = {operation}") == "1",
                 "the original erasure completed after owner death and ordinary recovery")
        assert run.incarnation(HOLDER) == holder_before
        assert not s.stopped_on_worker_at(s.node_log_since(holder_before[0].node, marks[holder_before[0].node]), HOLDER)
        assert run.result(personal) == personal_result and run.result(library_part) == library_result
        assert run.result(folded_final) == folded_result
        mail_result = run.result(mail_part)
        assert mail_result and mail in mail_result
        assert run.sql(f"SELECT completed_at IS NOT NULL FROM hilos_account_deletion WHERE id = {request}") == "1"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id IN ({root},{folded})") == "0"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_notification_delivery WHERE id = {delivery}") == "0"
        assert run.sql("SELECT COUNT(*) FROM hilos_notification_delivery d JOIN hilos_notification n "
                       f"ON n.id = d.notification_id WHERE n.user_id = {root}") == "0"
        before_stale = run.sql("SELECT r.* FROM hilos_account_operation_result r "
                               "JOIN hilos_account_operation_assignment a ON a.id = r.assignment_id "
                               f"WHERE a.operation_id = {operation} ORDER BY r.id")
        # A library on the killed host may have been replaced. All its old handles were
        # forgotten at the kill; this is a fresh terminal observation on the current source.
        stale_source = run.incarnation(library)
        run.probe(library, "arm", frame_kind="answer")
        run.replay(library_ask)  # Its original source H stayed alive, including across the kill.
        stale_answer = run.held(library)  # Observed handling, beyond the CLI send acknowledgement.
        assert run.incarnation(library) == stale_source
        assert payload(stale_answer)["assignmentId"] == library_part
        assert run.sql("SELECT r.* FROM hilos_account_operation_result r "
                       "JOIN hilos_account_operation_assignment a ON a.id = r.assignment_id "
                       f"WHERE a.operation_id = {operation} ORDER BY r.id") == before_stale
        assert run.result(library_part) == library_result and run.result(mail_part) == mail_result
        assert untouched_snapshot() == neighbour_before
        print(f"    erasure 58 completed: owner {owner_before[0]} -> {owner_after}; H unchanged; "
              f"stale handling={json.dumps(stale_answer)}", flush=True)
        return "one circle: lost ask/recovery, live duplicates, committed personal answer lost with one host, moved owner, stale handling, disabled email"
    except BaseException:
        failed = True
        raise
    finally:
        try:
            run.cleanup(failed, writers_finished=True)
        finally:
            print(f"    erasure 58 elapsed including cleanup: {time.monotonic() - started:.1f}s", flush=True)


def scenario_59_erasure_waits_and_restrict(s):
    """Two honest unfinished operations: no delivery owner, and a late FK refusal."""
    started = time.monotonic()
    run = ErasureRun(s)
    failed = False
    try:
        run.prepare()
        missing = run.person("missing-owner")
        run.request(missing)
        run.delivery(missing, "unregistered")
        run.place(f"hilos_user:{missing}")
        run.force(missing)
        missing_op = run.operation(missing)
        wait_key = f"wait:delivery:unregistered:{missing}"
        run.wait(lambda _: run.sql("SELECT COUNT(*) FROM hilos_account_operation_assignment "
                                  f"WHERE operation_id = {missing_op} AND assignment_key = '{wait_key}'") == "1",
                 "the ownerless channel is a durable census wait")
        wait_assignment = run.assignment(missing_op, wait_key)

        def assert_wait():
            assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {missing_op}") == "NULL"
            assert not run.result(wait_assignment)
            assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id = {missing}") == "1"

        assert_wait()
        run.fire()  # One ordinary recovery pass leaves the missing-owner debt open.
        assert_wait()
        root = run.person("restrict")
        request = run.request(root)
        operation, personal, personal_ask = run.open_at_personal(root)
        final = run.assignment(operation, run.personal_key("final", root))
        run.probe(HOLDER, "discard")
        run.probe(HOLDER, "arm", frame_kind="ask", assignment_id=final)
        run.replay(personal_ask)
        final_ask = run.held(HOLDER)
        assert payload(final_ask)["assignmentId"] == final and run.result(personal)
        capture = run.assignment(operation, run.personal_key("capture", root))
        capture_before = run.result(capture)
        rename_before = run.sql(f"SELECT * FROM hilos_user_rename WHERE user_id = {root}")
        request_before = run.sql(f"SELECT * FROM hilos_account_deletion WHERE id = {request}")
        now = int(time.time() * 1000)
        run.fixture_sql("INSERT INTO hilos_identity (user_id, type, identifier, created_at, updated_at) "
                f"VALUES ({root}, 'password', '{run.prefix}-late@example.test', {now}, {now})")
        person_before = run.business(root)
        run.probe(HOLDER, "release")
        run.wait(lambda _: run.sql(f"SELECT final_refusals IS NOT NULL FROM hilos_account_operation WHERE id = {operation}") == "1",
                 "the real final holder received its safe RESTRICT refusal")
        refusals = json.loads(run.sql(f"SELECT final_refusals FROM hilos_account_operation WHERE id = {operation}"))
        assert refusals == {str(root): "hilos_identity"}, refusals
        assert not run.result(final) and run.result(capture) == capture_before
        assert run.business(root) == person_before and run.sql(f"SELECT * FROM hilos_user_rename WHERE user_id = {root}") == rename_before
        assert run.sql(f"SELECT * FROM hilos_account_deletion WHERE id = {request}") == request_before
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        assert_wait()
        return "two operations remain open: ownerless delivery after one recovery pass; late identity RESTRICT with safe reason and rolled-back final"
    except BaseException:
        failed = True
        raise
    finally:
        try:
            run.cleanup(failed)
        finally:
            print(f"    erasure 59 elapsed including cleanup: {time.monotonic() - started:.1f}s", flush=True)


def scenario_60_partial_erasure_survives_full_restore(s):
    """A real FULL import retains committed parts and resumes the archived unfinished circle."""
    started = time.monotonic()
    run = ErasureRun(s)
    failed = False
    operation = None
    try:
        run.prepare()
        run.pause(f"{HOLDER}/hilos_account_deletion_sweep")
        s.wait_protected_mode(lambda row: row.get("phase") == "inactive",
                              "every node open before the partial archive", nodes=run.nodes)
        holder_before = run.incarnation(HOLDER)
        root = run.person("restore-root")
        folded = run.person("restore-folded")
        neighbour = run.person("restore-neighbour")
        request = run.request(root)
        neighbour_request = run.request(neighbour)
        run.fixture_sql("INSERT INTO hilos_user_merge (user_id, survivor_user_id, merged_at) "
                f"VALUES ({folded}, {root}, {int(time.time() * 1000)})")

        def neighbour_snapshot():
            return (run.business(neighbour),
                    run.sql(f"SELECT * FROM hilos_user_rename WHERE user_id = {neighbour} ORDER BY id"),
                    run.sql(f"SELECT * FROM hilos_account_deletion WHERE id = {neighbour_request}"))

        neighbour_before = neighbour_snapshot()
        operation, personal, personal_ask = run.open_at_personal(root)
        agent = f"hilos_user:{root}"
        root_before = run.incarnation(agent)
        final = run.assignment(operation, run.personal_key("final", root))
        folded_final = run.assignment(operation, run.personal_key("final", folded))
        capture = run.assignment(operation, run.personal_key("capture", root))
        folded_capture = run.assignment(operation, run.personal_key("capture", folded))

        def missing():
            # A personal final disposes only its own capture, retaining the assignment.
            # Every other missing result remains a real unfinished obligation.
            rows = run.sql("SELECT a.id FROM hilos_account_operation_assignment a "
                           "LEFT JOIN hilos_account_operation_result r ON r.assignment_id = a.id "
                           f"WHERE a.operation_id = {operation} AND r.id IS NULL "
                           f"AND NOT ((a.id = {capture} AND EXISTS (SELECT 1 FROM "
                           f"hilos_account_operation_result WHERE assignment_id = {final})) "
                           f"OR (a.id = {folded_capture} AND EXISTS (SELECT 1 FROM "
                           f"hilos_account_operation_result WHERE assignment_id = {folded_final}))) ORDER BY a.id")
            return {int(value) for value in rows.splitlines()}

        def snapshot():
            return {
                "operation": run.sql(f"SELECT * FROM hilos_account_operation WHERE id = {operation}"),
                "assignments": run.sql("SELECT * FROM hilos_account_operation_assignment "
                                       f"WHERE operation_id = {operation} ORDER BY id"),
                "results": run.sql("SELECT r.assignment_id, r.id, r.owner_agent_id, r.payload_json, r.created_at "
                                   "FROM hilos_account_operation_result r "
                                   "JOIN hilos_account_operation_assignment a ON a.id = r.assignment_id "
                                   f"WHERE a.operation_id = {operation} ORDER BY r.assignment_id"),
            }

        # No arbitrary quiet period: every duty which can currently commit has its result.
        run.wait(lambda _: missing() == {personal, final} and bool(run.result(folded_final)),
                 "all other current duties, including the folded final, have committed")
        assert run.sql(f"SELECT started_at IS NOT NULL FROM hilos_account_deletion WHERE id = {request}") == "1"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id = {root}") == "1"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id = {folded}") == "0"
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        circle = json.loads(run.sql(f"SELECT circle_user_ids FROM hilos_account_operation WHERE id = {operation}"))
        assert set(circle) == {root, folded}, circle
        archived = snapshot()
        assert run.result(capture) and not run.result(folded_capture)
        # Keep the complete checkpoint above; only the terminal preservation oracle
        # excludes personal captures, whose addresses the finals must erase.
        archived_results = {int(line.split("\t", 1)[0]): line for line in archived["results"].splitlines()
                            if int(line.split("\t", 1)[0]) not in (capture, folded_capture)}
        assert archived_results and personal not in archived_results and final not in archived_results
        folded_result = run.result(folded_final)
        backup_id, backup_node = run.capture_full_archive()
        captured = snapshot()
        assert captured == archived, f"partial SQL changed during FULL capture/verify: {archived!r} -> {captured!r}"
        print("    erasure 60 archived SQL: " + json.dumps(archived, sort_keys=True), flush=True)
        print(f"    erasure 60 checkpoint: operation={operation}, folded final committed, "
              f"missing={sorted(missing())}; full={backup_id} on {backup_node}, digest verified", flush=True)

        # Capture/verify must leave the original live root and the same held P ask.
        assert run.incarnation(agent) == root_before, "the original root stopped before its idle proof"
        assert run.incarnation(HOLDER) == holder_before
        assert run.probe(HOLDER, "inspect")["held"] == personal_ask
        assert missing() == {personal, final} and not run.result(personal) and not run.result(final)
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        idle_mark = s.node_log_mark(root_before[0].node)
        worker_marks = run.worker_marks(root_before[0].node)
        run.probe(agent, "expire-idle", root_before[0].node)

        def slept(views):
            log = run.worker_since(root_before[0].node, worker_marks)
            return (not s.placement_row(views, agent)
                    and re.search(rf"Agent {re.escape(agent)} stopped \(idle for \d+s\)", log)
                    and s.stopped_on_worker_at(s.node_log_since(root_before[0].node, idle_mark), agent))

        run.wait(slept, "the original root really stopped idle with personal work still absent")
        run.forget_stopped(agent, root_before)
        assert not run.result(personal) and not run.result(final)
        assert run.sql(f"SELECT finished_at FROM hilos_account_operation WHERE id = {operation}") == "NULL"
        assert run.incarnation(HOLDER) == holder_before
        assert run.probe(HOLDER, "inspect")["held"] == personal_ask
        restart_marks = {node: s.node_log_mark(node) for node in run.nodes}
        run.command(holder_before[0].node, "agent:restart", HOLDER)

        def replaced(_):
            stops = s.stopped_on_worker_at(s.node_log_since(holder_before[0].node,
                                                           restart_marks[holder_before[0].node]), HOLDER)
            starts = [at for node in run.nodes for at in s.started_on_worker_at(
                s.node_log_since(node, restart_marks[node]), HOLDER)]
            return stops and starts and min(starts) >= min(stops)

        run.wait(replaced, "the one holder restart stopped the old instance and started its replacement")
        run.forget_stopped(HOLDER, holder_before)
        holder_after = run.incarnation(HOLDER)
        assert holder_after != holder_before, "a worker PID alone cannot prove holder replacement"
        print(f"    erasure 60 lifecycle: root idle={s.node_log_since(root_before[0].node, idle_mark)}; "
              f"holder {holder_before} -> {holder_after}", flush=True)
        # No successor probe or pause: due, recovery and owner startup finish this same intent.
        def completed(_):
            return run.sql("SELECT outcome = 'completed' AND finished_at IS NOT NULL "
                           f"FROM hilos_account_operation WHERE id = {operation}") == "1"

        run.wait(completed, "holder startup and ordinary owner resume completed the original operation")
        completed_before_restore = snapshot()
        assert completed_before_restore != archived and not missing()
        assert not run.result(capture) and not run.result(folded_capture)
        assert run.sql(f"SELECT completed_at IS NOT NULL FROM hilos_account_deletion WHERE id = {request}") == "1"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id IN ({root},{folded})") == "0"
        assert run.result(folded_final) == folded_result
        later_results = {assignment: run.result(assignment) for assignment in (personal, final)}
        marker = f"{run.prefix}-{MARKER_SUFFIX}"
        marker_id = run.person(MARKER_SUFFIX)
        assert run.sql(f"SELECT id FROM hilos_user WHERE name = '{marker}'") == str(marker_id)
        assert neighbour_snapshot() == neighbour_before
        run.collect_callers()
        restore_started_at = run.restore_full_archive()
        run.wait(completed, "ordinary startup/resume completed the archived circle without another purge")

        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE name = '{marker}'") == "0", "FULL import kept a post-archive row"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user WHERE id IN ({root},{folded})") == "0"
        assert run.sql(f"SELECT COUNT(*) FROM hilos_user_merge WHERE user_id = {folded}") == "0"
        assert not missing() and snapshot()["assignments"] == archived["assignments"]
        assert run.sql(f"SELECT completed_at >= {restore_started_at} FROM hilos_account_deletion WHERE id = {request}") == "1"
        assert not run.result(capture) and not run.result(folded_capture)
        after = snapshot()
        after_results = {int(line.split("\t", 1)[0]): line for line in after["results"].splitlines()}
        assert set(after_results) == set(archived_results) | {personal, final}, after_results
        for assignment, saved in archived_results.items():
            assert after_results[assignment] == saved, f"archived result {assignment} changed"
        for assignment in (personal, final):
            result = run.result(assignment)
            assert result and result != later_results[assignment], f"part {assignment} was not rebuilt"
            assert int(result.rsplit("\t", 1)[1]) >= restore_started_at, result
        assert run.result(folded_final) == folded_result, "the archived folded final ran again"
        assert neighbour_snapshot() == neighbour_before
        print(f"    erasure 60 import/resume: marker {marker} gone; archived non-capture results unchanged; "
              f"{personal}/{final} rebuilt after restore, captures disposed, folded absent, neighbour unchanged", flush=True)
        print("    erasure 60 resumed SQL: " + json.dumps(after, sort_keys=True), flush=True)
        return "one verified FULL archive of a stable partial circle; real hot import, immutable archived non-capture results and ordinary resume completed it"
    except BaseException:
        failed = True
        if operation is not None:
            s.restore_stand(True, "erasure SQL evidence",
                            lambda: print("    erasure 60 failed SQL: " + json.dumps(snapshot(), sort_keys=True), flush=True))
        raise
    finally:
        try:
            run.cleanup(failed, archive_first=True, writers_finished=True)
        finally:
            print(f"    erasure 60 elapsed including cleanup: {time.monotonic() - started:.1f}s", flush=True)

"""Failure paths for the account operation cluster harness without a live stand."""

import json
import subprocess
import tempfile
import traceback
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import ANY, Mock, call, patch

import account_operations
import control
import erasure
import scenarios
from account_operations import AccountOperationRun, ArchiveAddress, RestoreAttempt, StandUnusable
from erasure import EMAIL_ENABLED, ErasureRun
from merge import MergeRun


REAL_INCARNATION = AccountOperationRun.incarnation


class AccountOperationRunTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.logs = {node: Path(directory.name) / node for node in ("m1", "s1")}
        for path in self.logs.values():
            path.mkdir()
        self.stand = SimpleNamespace(compose="cluster.yml", cli_service="cli", members=self.logs,
                                     node=lambda node: SimpleNamespace(log_dir=self.logs[node],
                                                                       log_file=self.logs[node] / "daemon.log"),
                                     member=lambda node: SimpleNamespace(service=node))
        self.containers = {node: f"{node}-container true" for node in self.logs}
        self.holder = "s1"
        self.requests = 0
        self.addCleanup(patch.stopall)
        patch.object(control, "container_state", side_effect=self.container_state).start()
        patch.object(AccountOperationRun, "archive_address", side_effect=self.address).start()
        patch.object(AccountOperationRun, "incarnation", side_effect=self.incarnation).start()
        row = {"phase": "inactive", "rtMounted": True}
        views = {"m1": {"nodes": [{"nodeId": node, "online": True} for node in self.logs]}, "s1": {"online": True}}
        runner = SimpleNamespace(STAND=self.stand, MASTERS=["m1"], ALL_NODES=["m1", "s1"], CONVERGE_TIMEOUT=60,
                                 restore_stand=scenarios.restore_stand, ScenarioTimeout=scenarios.ScenarioTimeout,
                                 wait_until=self.wait_until, wait_protected_mode=Mock(), log_time=scenarios.log_time,
                                 wait_converge=Mock(return_value=views), leaders=lambda views: [next(iter(views))], fleet_started=Mock(),
                                 inspect_all=self.views, placement_row=self.placement)
        self.run = AccountOperationRun(runner, "test")
        self.run.inspect_restore = Mock(return_value=row)
        self.observe(self.run)

    def container_state(self, stand, node):
        return control.Outcome(0, self.containers[node] + " 2026-01-01T00:00:00.000000000Z", "")

    def views(self, nodes):
        views = {node: {"online": True} for node in nodes}
        views[nodes[0]]["nodes"] = [{"nodeId": node, "online": True} for node in nodes]
        return views

    def placement(self, views, agent):
        return {"nodeId": self.holder, "state": "started"}

    def incarnation(self, agent, placement=None, *, journal=None):
        node = self.holder if placement is None else placement["nodeId"]
        container = self.containers[node].split()[0]
        return control.WorkerProcess(node, container, 1, 100, "birth"), "instance"

    def address(self, node):
        return ArchiveAddress(node, f"/app/data/backup/{node}", "dev")

    def observe(self, run):
        for node in self.logs:
            self.log(node, "daemon", "Daemon started with epoll")
        run.begin_backup_observation()

    def restart_logs(self, *_):
        for node, directory in self.logs.items():
            batch = directory / "staging" / "2026-01-01-00-00-01Z"
            batch.mkdir(parents=True, exist_ok=True)
            for path in directory.glob("*.log"):
                path.rename(batch / path.name)
            self.containers[node] = f"{node}-new-container true"
            self.log(node, "daemon", "Daemon started with epoll", seconds=1)
        return 0

    def wait_until(self, predicate, _timeout, description, nodes=None):
        self.assertTrue(predicate({}), description)

    def test_replay_refusal_and_timeout_hide_the_envelope_and_both_streams(self):
        frame = {"agentId": "hilos_user:1", "private": "fixture-private-handover"}
        private = frame["private"]
        timeout = subprocess.TimeoutExpired(["--frame-json=" + private], 30, output=private, stderr=private)
        responses = (("exit 1", {"return_value": control.Outcome(1, private, private)}),
                     ("timed out", {"side_effect": timeout}),
                     ("could not read response", {"return_value": control.Outcome(0, private, "")}))
        with patch.object(self.run, "placed", return_value={"nodeId": "m1"}):
            for reason, response in responses:
                with self.subTest(reason=reason), patch.object(control, "client", **response):
                    with self.assertRaisesRegex(AssertionError, f"m1 hilos_user:1: operation probe replay: {reason}") as error:
                        self.run.replay(frame)
                    self.assertIsNone(error.exception.__context__)
                    self.assertNotIn(private, "".join(traceback.format_exception(error.exception)))

    backup_id = "2026-01-01_00-00-00"

    def set_archive(self, run, node="s1"):
        run.backup_id = self.backup_id
        address = ArchiveAddress(node, f"/app/data/backup/{node}", "dev")
        run.archive_addresses[node] = address
        return address

    def rotate(self, node, name, seconds):
        live = self.logs[node] / f"{name}.log"
        batch = live.parent / "staging" / f"2026-01-01-00-00-{seconds:02d}Z"
        batch.mkdir(parents=True, exist_ok=True)
        archived = batch / live.name
        live.rename(archived)
        return archived

    def log(self, node, name, line, seconds=0, milliseconds=0):
        with (self.logs[node] / f"{name}.log").open("a") as stream:
            stream.write(f"[2026-01-01T00:00:{seconds:02d}.{milliseconds:03d}Z] {line}\n")

    def interval(self, command, body=(), node="s1", seconds=0):
        agent = "agent-hilos_backup"
        self.requests += 1
        request = f"request-{self.requests}"
        self.log(node, "daemon", f"[INFO] Command channel: parked {command} #{request} for its agent", seconds=seconds)
        self.log(node, agent, f"Command channel: took {command} #{request}", seconds=seconds, milliseconds=1)
        for line in body:
            self.log(node, agent, line, seconds=seconds, milliseconds=2)
        self.log(node, agent, f"Command channel: answered #{request}", seconds=seconds, milliseconds=3)

    def create_setup(self):
        self.run.create_attempt = None
        self.run.backup_id = None
        self.run.archive_addresses.clear()
        self.run.pause = Mock(return_value="own-pause")
        self.run.cancel_pause = Mock()
        self.run.archive_inventory = Mock(return_value=(self.backup_id, set()))
        self.observe(self.run)

    def test_create_matches_its_reply_to_the_exact_publication_beside_cron(self):
        self.create_setup()
        backup_id = self.backup_id

        def launch(*_):
            self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]")
            self.log("s1", "agent-hilos_backup", "Backup started: 2025-12-31_23-59-59 (scope=full)")
            self.log("s1", "agent-hilos_backup", "Backup 2025-12-31_23-59-59 completed in 0s")
            self.interval(account_operations.CREATE, [f"Backup started: {backup_id} (scope=full)"])
            self.rotate("s1", "daemon", 1)
            self.rotate("s1", "agent-hilos_backup", 1)
            self.log("s1", "agent-hilos_backup", f"Backup {backup_id} completed in 1s", seconds=1)
            return control.Outcome(0, f"Started scheduled backup {backup_id} (scope=full)", "")

        with (patch.object(account_operations, "run_backup_command", side_effect=launch),
              patch.object(account_operations, "backup_command", return_value="checked 1: ok 1, mismatch 0, skipped 0, unverified 0") as verify,
              patch.object(account_operations, "full_archive",
                           return_value={"verifyOutcome": "ok", "verifiedAt": True, "sha256": "a" * 64}) as archive):
            self.assertEqual(self.run.capture_full_archive(), (backup_id, "s1"))
        address = self.run.archive_addresses["s1"]
        self.assertEqual(archive.call_args_list, [call(self.run, address, backup_id)] * 3)
        verify.assert_called_once_with(self.run, "s1", "backup:verify", backup_id, "--scope=full")
        self.run.cancel_pause.assert_called_once_with("own-pause")

    def test_create_refuses_foreign_stdout_duplicates_and_collisions(self):
        other = "2026-01-01_00-00-01"
        for detail in ("foreign stdout", "duplicate", "conflicting reply", "occupied"):
            with self.subTest(detail=detail):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                self.create_setup()
                if detail == "occupied":
                    self.run.archive_inventory.return_value = (self.backup_id, {f"{self.backup_id}-dev-full.json"})

                def launch(*_):
                    started = f"Backup started: {self.backup_id} (scope=full)"
                    self.interval(account_operations.CREATE, [] if detail == "foreign stdout" else [started])
                    if detail == "duplicate":
                        self.log("s1", "agent-hilos_backup", started)
                    named = other if detail in ("foreign stdout", "conflicting reply") else self.backup_id
                    return control.Outcome(0, f"Started scheduled backup {named} (scope=full)", "")

                with (patch.object(account_operations, "run_backup_command", side_effect=launch),
                      patch.object(account_operations, "full_archive") as archive,
                      self.assertRaises(AssertionError)):
                    self.run.capture_full_archive()
                archive.assert_not_called()
                self.assertIsNone(self.run.backup_id)
                self.assertFalse(self.run.archive_addresses)

    def test_create_full_reply_loss_and_busy_preserve_the_other_writer(self):
        for result, diagnosis in (
                (OSError("lost CREATE reply"), "CREATE ownership is unconfirmed"),
                (control.Outcome(1, "", "The daemon did not answer test:backup:run-schedule within 5s"),
                 "CREATE ownership is unconfirmed"),
                (control.Outcome(1, "", "Refused: Backup busy: 2025-12-31_23-59-59"),
                 "another backup writer refused CREATE"),
                (control.Outcome(1, "", "Refused: Backup did not start"), "another restore is pending or running")):
            with self.subTest(result=result):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                self.create_setup()

                def launch(*_):
                    if diagnosis.startswith("CREATE ownership"):
                        self.interval(account_operations.CREATE, [f"Backup started: {self.backup_id} (scope=full)"])
                        self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 1s")
                    if diagnosis == "another restore is pending or running":
                        self.interval(account_operations.CREATE, [
                            f"Restore engaged ({self.backup_id}); skipping new full request"])
                    if isinstance(result, OSError):
                        raise result
                    return result

                original = str(result) if isinstance(result, OSError) else result.err
                self.assert_backup_stops_matrix(self.run.capture_full_archive, launch, original, diagnosis,
                                                (account_operations.CREATE, account_operations.SCHEDULE))
                self.assertIsNone(self.run.backup_id)
                self.assertFalse(self.run.archive_addresses)

    def test_own_terminal_create_error_claims_only_its_exact_published_sidecar(self):
        self.create_setup()

        def launch(*_):
            self.interval(account_operations.CREATE)
            self.log("s1", "agent-hilos_backup.error", f"Backup {self.backup_id} refused: no space", milliseconds=2)
            return control.Outcome(1, "", "Refused: Backup did not start")

        meta = {"id": self.backup_id, "env": "dev", "scope": "full", "status": "error", "failureReason": "no space"}
        with (patch.object(account_operations, "run_backup_command", side_effect=launch),
              patch.object(control, "compose", return_value=control.Outcome(0, "missing\n" + json.dumps(meta), "")),
              self.assertRaisesRegex(AssertionError, "FULL|full backup .*no space")):
            self.run.capture_full_archive()
        self.assertTrue(self.run.create_attempt.terminal)
        self.assertEqual(self.run.backup_id, self.backup_id)
        self.assertEqual(list(self.run.archive_addresses), ["s1"])
        with patch.object(control, "compose", return_value=control.Outcome(0, "", "")) as remove:
            self.run.remove_archives()
        self.assertEqual(remove.call_args.args[-2], "sidecar")


    def test_successful_sidecar_alone_does_not_count_as_a_pair(self):
        address = self.set_archive(self.run)
        meta = {"id": self.backup_id, "env": "dev", "scope": "full", "status": "success"}
        with patch.object(control, "compose", return_value=control.Outcome(0, "missing\n" + json.dumps(meta), "")):
            self.assertIsNone(account_operations.full_archive(self.run, address, self.backup_id))

    def restore_window(self, phase="verifying", started=1020):
        return {"phase": phase, "rtMounted": True, "operation": "restore", "initiatorAgentType": "hilos_backup",
                "initiatorAgentIndex": None, "initiatorNodeId": "s1",
                "startedAt": int(scenarios.log_time("2026-01-01T00:00:01.000Z").timestamp() * 1000) + started - 1000}

    def restore_logs(self, *, ready=True, completed=True):
        self.interval(account_operations.RESTORE_REQUEST, [
            f"Restore accepted: {self.backup_id} (scope=full); requesting protected mode"])
        if ready:
            self.log("s1", "agent-hilos_backup", f"Restore started: {self.backup_id} (scope=full)", seconds=2)
        if completed:
            self.log("s1", "agent-hilos_backup", f"Restore {self.backup_id} completed", seconds=3)

    def restore_acceptance(self):
        return f"Restore accepted: {self.backup_id} (scope=full); the node is entering protected mode"

    def restore_setup(self):
        self.set_archive(self.run)
        self.run.restore_attempt = None
        for path in self.logs["s1"].glob("*.log"):
            path.unlink()
        self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": True}
        self.observe(self.run)
        self.run.placed = Mock(return_value={"nodeId": "s1"})
        self.run.sql = Mock(return_value="1000")
        self.run.command = Mock()

    def test_restore_recovers_lost_monitor_after_its_exact_cli_acceptance(self):
        acceptance = self.restore_acceptance()
        for response in (control.Outcome(1, f"{acceptance}\nError: the daemon stopped answering", ""),
                         subprocess.TimeoutExpired("backup:restore", 30, output=f"{acceptance}\npartial".encode())):
            with self.subTest(response=response):
                self.restore_setup()
                self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": True}
                self.run.s.wait_protected_mode.reset_mock()

                def restore(*_):
                    self.restore_logs()
                    self.run.inspect_restore.side_effect = lambda node: self.restore_window(
                        started=1020 if node == "s1" else 1050)
                    if isinstance(response, subprocess.TimeoutExpired):
                        raise response
                    return response

                with patch.object(account_operations, "run_backup_command", side_effect=restore):
                    self.assertEqual(self.run.restore_full_archive(), 1000)
                self.run.command.assert_called_once_with("s1", "protected-mode:open")
                self.run.s.wait_protected_mode.assert_called_once()
                self.assertEqual(self.run.s.wait_protected_mode.call_args.kwargs["nodes"], ["m1", "s1"])
                self.assertTrue(self.run.restore_attempt.settled)
                self.run.inspect_restore.reset_mock(side_effect=True)

    def test_restore_full_reply_loss_and_busy_preserve_the_other_writer(self):
        for busy in (False, True):
            with self.subTest(busy=busy):
                self.restore_setup()
                self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": True}
                original = f"Refused: Backup subsystem busy: {self.backup_id}" if busy else "lost RESTORE reply"

                def restore(*_):
                    if busy:
                        return control.Outcome(1, "", original)
                    self.restore_logs()
                    self.run.inspect_restore.return_value = self.restore_window()
                    raise OSError(original)

                diagnosis = "another backup writer refused RESTORE" if busy else "RESTORE ownership is unconfirmed"
                self.assert_backup_stops_matrix(self.run.restore_full_archive, restore, original, diagnosis,
                                                ("backup:restore", self.backup_id, "--scope=full", "--yes"))

    def test_same_full_browser_admission_blocks_cleanup_even_while_pending(self):
        for phase in ("verifying", "pending before freeze", "during recovery"):
            with self.subTest(phase=phase):
                self.restore_setup()
                self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": True}
                refusal = "Refused: Restore refused by the environment guard"

                def browser_admission():
                    self.log("s1", "agent-hilos_backup",
                             f"Restore accepted: {self.backup_id} (scope=full); requesting protected mode",
                             milliseconds=4)
                    if phase == "verifying":
                        self.run.inspect_restore.return_value = self.restore_window()
                    return "schema"

                def restore(*_):
                    self.interval(account_operations.RESTORE_REQUEST)
                    if phase != "during recovery":
                        browser_admission()
                    return control.Outcome(1, "", refusal)

                self.run.schema = Mock(side_effect=browser_admission)
                self.assert_backup_stops_matrix(self.run.restore_full_archive, restore, refusal,
                                                "restore admission belongs to another request",
                                                ("backup:restore", self.backup_id, "--scope=full", "--yes"))
                self.assertEqual(self.run.schema.call_count, int(phase == "during recovery"))

    def test_restore_clean_refusal_and_admitted_terminal_without_freeze(self):
        self.restore_setup()
        self.run.schema = Mock(return_value="schema")
        self.run.clean_fixtures = Mock()
        with patch.object(account_operations, "run_backup_command",
                          return_value=control.Outcome(1, "", "Refused: Restore refused by the environment guard")):
            with self.assertRaisesRegex(AssertionError, "Restore refused by the environment guard"):
                try:
                    self.run.restore_full_archive()
                finally:
                    with (patch.object(control, "down", return_value=0) as down,
                          patch.object(control, "start_database", return_value=0),
                          patch.object(control, "up", return_value=0) as up,
                          patch.object(account_operations, "full_archive")):
                        self.run.cleanup(True)
        down.assert_called_once_with(self.run.stand, volumes=False)
        up.assert_called_once_with(self.run.stand, ANY)
        self.run.clean_fixtures.assert_called_once_with(True)
        self.run.restore_attempt = RestoreAttempt("s1", self.backup_id, self.run.backup_logs(self.run.s.ALL_NODES),
                                                  control.Outcome(1, f"Restore accepted: {self.backup_id} (scope=full); "
                                                                  "the node is entering protected mode\n"
                                                                  "Error: restore failed: freeze refused\n"
                                                                  "The database was not touched", ""))
        self.restore_logs(ready=False, completed=False)
        self.run.reopen_restore(wait=False)
        self.assertTrue(self.run.restore_attempt.settled)
        self.run.command.assert_not_called()

    def test_restore_refuses_stale_duplicate_and_replaced_window(self):
        for case in ("stale", "duplicate", "replacement", "outside admission"):
            with self.subTest(case=case):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                self.run.restore_attempt = None
                self.run.command = Mock()
                self.run.placed = Mock(return_value={"nodeId": "s1"})
                self.run.inspect_restore.reset_mock(side_effect=True)
                self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": True}
                self.observe(self.run)
                self.run.inspect_restore.return_value = self.restore_window()
                if case == "stale":
                    self.restore_logs()
                self.run.restore_attempt = RestoreAttempt("s1", self.backup_id, self.run.backup_logs(self.run.s.ALL_NODES),
                                                          control.Outcome(1, self.restore_acceptance(), ""),
                                                          owner=self.incarnation("hilos_backup"))
                if case != "stale":
                    self.restore_logs()
                if case == "duplicate":
                    self.restore_logs()
                if case == "replacement":
                    self.run.inspect_restore.side_effect = [self.restore_window()] * 2 + [self.restore_window(started=1030)] * 2
                if case == "outside admission":
                    self.run.inspect_restore.return_value = self.restore_window(started=2020)
                diagnosis = {
                    "stale": "restore admission is missing",
                    "duplicate": "ambiguous backup:restore-request command-channel requests",
                    "replacement": "window changed",
                    "outside admission": "belongs to another restore",
                }[case]
                with self.assertRaisesRegex(StandUnusable, diagnosis):
                    self.run.reopen_restore(wait=False)
                self.run.command.assert_not_called()

    def test_late_create_cannot_be_unlinked_without_a_successful_writer_stop(self):
        address = self.set_archive(self.run)
        self.run.create_attempt = account_operations.CreateAttempt(
            address, ("same publisher",), self.backup_id, set(), {})
        self.run.schema = Mock(return_value="original schema")
        self.run.clean_fixtures = Mock()

        with (patch.object(account_operations, "full_archive") as archive,
              patch.object(control, "down", return_value=1) as stop,
              patch.object(control, "up") as up):
            with self.assertRaisesRegex(AssertionError, "CREATE can still publish"):
                self.run.remove_archives()
            with self.assertRaisesRegex(StandUnusable, "could not stop account and backup writers"):
                self.run.cleanup(True)
        stop.assert_called_once_with(self.run.stand, volumes=False)
        archive.assert_not_called()
        up.assert_not_called()
        self.run.clean_fixtures.assert_not_called()
        self.assertEqual(self.run.archive_addresses, {"s1": address})

    def test_active_or_dirty_restore_stops_before_unlink_and_preserves_db(self):
        for dirty in (False, True):
            with self.subTest(dirty=dirty):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                self.set_archive(self.run)
                self.run.restore_attempt = None
                self.run.writers_stopped = False
                self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": True}
                self.observe(self.run)
                attempt = self.run.restore_attempt = RestoreAttempt(
                    "s1", self.backup_id, self.run.backup_logs(self.run.s.ALL_NODES),
                    control.Outcome(1, self.restore_acceptance(), ""), owner=self.incarnation("hilos_backup"))
                self.restore_logs(completed=False)
                if dirty:
                    attempt.answer = control.Outcome(1, f"Restore accepted: {self.backup_id} (scope=full); "
                                                     "the node is entering protected mode\n"
                                                     "The database may be left partially replaced", "")
                self.run.inspect_restore.return_value = self.restore_window("active" if not dirty else "verifying")
                self.run.command = Mock()
                self.run.sql = Mock()
                events = Mock()
                with (patch.object(control, "down", side_effect=events.down),
                      patch.object(control, "up", side_effect=events.up),
                      patch.object(control, "start_database", side_effect=events.db),
                      patch.object(account_operations, "full_archive", side_effect=events.archive)):
                    events.down.return_value = 0
                    with self.assertRaisesRegex(StandUnusable, "preserved unverified DB and freeze"):
                        self.run.cleanup(True)
                self.assertEqual(events.mock_calls, [call.down(self.run.stand, volumes=False),
                                                    call.archive(self.run, ArchiveAddress("s1", "/app/data/backup/s1", "dev"),
                                                                 self.backup_id, remove=True, staged=None, sidecar_only=False)])
                self.run.command.assert_not_called()
                self.run.sql.assert_not_called()

    def test_stage_collision_or_lost_partial_copy_leaves_only_witness_cleanup(self):
        self.set_archive(self.run, "m1")
        self.run.archive_address = Mock(return_value=ArchiveAddress("s1", "/app/data/backup/s1", "dev"))
        self.run.command = Mock()
        for refusal in (control.Outcome(1, "", "exclusive link already exists"), OSError("lost stage reply")):
            with self.subTest(refusal=refusal):
                response = [refusal, control.Outcome(0, "", "")]
                with patch.object(control, "compose", side_effect=response) as compose:
                    with self.assertRaises((AssertionError, OSError)):
                        self.run.stage_archive_at_restore_holder("s1")
                    stage = self.run.staged_archive
                    self.assertFalse(stage.published)
                    self.assertEqual(compose.call_args.args[-4:],
                                     ("/app/data/backup/m1", "/app/data/backup/s1",
                                      f"{self.backup_id}-dev-full", stage.token))
                    self.assertIn('ln -T -- "$tmp/$ext"', compose.call_args.args[-6])
                    account_operations.full_archive(self.run, stage.address, self.backup_id, remove=True, staged=stage)
                    self.assertEqual(compose.call_args.args[-2:], ("witness", stage.token))
                    self.assertIn('[ "$tmp/$ext" -ef "$base.$ext" ]', compose.call_args.args[-6])
                self.run.command.assert_not_called()

    def test_stage_reads_the_shared_pair_and_refreshes_the_exact_holder_index(self):
        self.set_archive(self.run, "m1")
        destination = ArchiveAddress("s1", "/app/data/backup/s1", "dev")
        self.run.archive_address = Mock(return_value=destination)
        metadata = {"createdAt": "2026-01-01T00:00:00.000Z", "verifyOutcome": "ok", "sha256": "digest"}
        events = Mock()
        events.publish.return_value = control.Outcome(0, "", "")
        events.read.return_value = metadata
        events.holder.side_effect = self.incarnation
        events.age.return_value = f"Aged backup {self.backup_id} createdAt={metadata['createdAt']}"
        with (patch.object(control, "compose", side_effect=events.publish),
              patch.object(account_operations, "full_archive", side_effect=events.read),
              patch.object(self.run, "incarnation", side_effect=events.holder),
              patch.object(self.run, "command", side_effect=events.age)):
            self.run.stage_archive_at_restore_holder("s1")
        publish = events.mock_calls[0]
        self.assertEqual(publish.args[:4], (self.run.stand, "exec", "-T", "s1"))
        self.assertEqual(publish.args[4:4 + len(control.WRITER_EXEC)], tuple(control.WRITER_EXEC))
        self.assertEqual(publish.args[-4:-1], ("/app/data/backup/m1", destination.root, f"{self.backup_id}-dev-full"))
        self.assertEqual(events.mock_calls[1:], [
            call.read(self.run, destination, self.backup_id),
            call.holder("hilos_backup"), call.holder("hilos_backup", {"nodeId": "s1", "state": "started"}),
            call.holder("hilos_backup", {"nodeId": "s1", "state": "started"},
                        journal=self.run.backup_journal["s1", "daemon.log"]),
            call.age("s1", "test:backup:age", self.backup_id, f"--at={metadata['createdAt']}", "--scope=full"),
            call.read(self.run, destination, self.backup_id),
        ])
        self.assertTrue(self.run.staged_archive.published)

    def test_foreign_window_stops_recovery_without_stop_open_sql_or_unlink(self):
        self.set_archive(self.run)
        self.run.inspect_restore.side_effect = [
            {"rtMounted": True, "phase": "inactive"},
            {"rtMounted": True, "phase": "active", "operation": "maintenance"},
        ]
        self.run.command = Mock()
        self.run.sql = Mock()
        with (patch.object(control, "down") as stop,
              patch.object(account_operations, "full_archive") as archive,
              self.assertRaisesRegex(StandUnusable, "another protected-mode window on s1")):
            self.run.cleanup(True)
        stop.assert_not_called()
        archive.assert_not_called()
        self.run.command.assert_not_called()
        self.run.sql.assert_not_called()

    def test_stopped_source_returns_before_fresh_holder_stage_and_restore(self):
        self.set_archive(self.run, "m1")
        self.run.killed, self.run.killed_container = "m1", "m1-container"
        self.containers["m1"] = "m1-container false"
        self.run.sql = Mock(return_value="1000")
        events = Mock()
        events.placement.return_value = {"nodeId": "s1"}
        events.restore.return_value = control.Outcome(0, self.restore_acceptance(), "")

        def start(stand, node):
            events.start(stand, node)
            batch = self.logs[node] / "staging" / "2026-01-01-00-01-00Z"
            batch.mkdir(parents=True)
            (self.logs[node] / "daemon.log").rename(batch / "daemon.log")
            self.log(node, "daemon", "Daemon started with epoll", seconds=1)
            self.containers[node] = "m1-container true"
            return control.Outcome(0, "", "")

        with (patch.object(control, "start", side_effect=start),
              patch.object(self.run, "placed", side_effect=events.placement),
              patch.object(self.run, "stage_archive_at_restore_holder", side_effect=events.stage),
              patch.object(self.run, "reopen_restore", side_effect=events.reopen),
              patch.object(account_operations, "run_backup_command", side_effect=events.restore)):
            self.assertEqual(self.run.restore_full_archive(), 1000)
        self.assertEqual(events.mock_calls, [
            call.start(self.run.stand, "m1"), call.placement("hilos_backup"), call.stage("s1"),
            call.restore(self.run, "s1", "backup:restore", self.backup_id, "--scope=full", "--yes"),
            call.reopen(require_verifying=True),
        ])
        self.assertEqual(self.run.s.wait_converge.call_args_list, [call(["m1", "s1"])])
        self.assertIsNone(self.run.killed)

    def test_restore_checks_mounted_full_roster_before_staging(self):
        self.set_archive(self.run, "m1")
        for fault in ("rt", "missing", "holder", "start"):
            with self.subTest(fault=fault):
                self.run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": fault != "rt"}
                self.run.placed = Mock(return_value={"nodeId": "unknown"})
                views = self.views if fault != "missing" else lambda _: {"m1": {}, "s1": None}
                if fault == "start":
                    self.run.killed, self.run.killed_container = "m1", "m1-container"
                    self.containers["m1"] = "m1-container false"
                with (patch.object(self.run, "stage_archive_at_restore_holder") as stage,
                      patch.object(account_operations, "run_backup_command") as restore,
                      patch.object(self.run.s, "inspect_all", side_effect=views),
                      patch.object(control, "start", return_value=control.Outcome(1, "", "did not start")),
                      self.assertRaises((AssertionError, StandUnusable))):
                    self.run.restore_full_archive()
                stage.assert_not_called()
                restore.assert_not_called()
        self.assertEqual(self.run.killed, "m1")

    def test_product_purge_timeout_needs_stop_before_unlink_and_sql(self):
        for down_code in (0, 1):
            with self.subTest(down_code=down_code):
                run = MergeRun(self.run.s)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                self.set_archive(run)
                events = Mock()
                run.schema = Mock(return_value="same schema")
                run.clean_fixtures = events.fixtures
                timeout = scenarios.ScenarioTimeout("product purge still writing")

                def fixtures(failed):
                    if not failed:
                        raise timeout

                events.fixtures.side_effect = fixtures
                events.down.return_value = down_code
                events.database.return_value = 0
                events.up.return_value = 1
                with (patch.object(control, "down", side_effect=events.down),
                      patch.object(control, "start_database", side_effect=events.database),
                      patch.object(control, "up", side_effect=events.up),
                      patch.object(account_operations, "full_archive", side_effect=events.archive)):
                    with self.assertRaises(StandUnusable) as error:
                        run.cleanup(False)
                self.assertIs(error.exception.original, timeout)
                expected = [call.fixtures(False), call.down(run.stand, volumes=False)]
                if down_code == 0:
                    expected += [call.archive(run, ArchiveAddress("s1", "/app/data/backup/s1", "dev"),
                                              self.backup_id, remove=True, staged=None, sidecar_only=False),
                                 call.database(run.stand), call.fixtures(True), call.up(run.stand, ANY)]
                self.assertEqual(events.mock_calls, expected)

    def test_erasure_60_preserves_window_archive_and_fixture_order(self):
        run = ErasureRun(self.run.s)
        run.email_snapshot = '{}'
        self.set_archive(run)
        actions = Mock()
        for method in ("reopen_restore", "collect_callers", "clean_probes", "clean_fixtures", "clean_pauses",
                       "return_node", "restore_email", "restart", "backup_admission"):
            setattr(run, method, getattr(actions, method))
        with patch.object(account_operations, "full_archive", side_effect=actions.archive):
            run.cleanup(False, archive_first=True, writers_finished=True)
        self.assertEqual(actions.mock_calls, [
            call.backup_admission(allow_restore=True, allow_stopped=True),
            call.reopen_restore(), call.backup_admission(), call.backup_admission(check_pairs=True),
            call.archive(run, ArchiveAddress("s1", "/app/data/backup/s1", "dev"), self.backup_id, remove=True, staged=None, sidecar_only=False),
            call.collect_callers(), call.clean_probes(), call.backup_admission(allow_stopped=True), call.clean_fixtures(False),
            call.clean_pauses(), call.return_node(), call.backup_admission(), call.restore_email(), call.restart(),
        ])

    def test_erasure_58_and_59_cleanup_have_no_archive_path(self):
        for finished in (True, False):
            with self.subTest(finished=finished):
                run = ErasureRun(self.run.s)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                run.schema = Mock(return_value="same schema")
                run.email_snapshot = json.dumps({EMAIL_ENABLED: True})
                events = Mock()
                run.clean_fixtures = events.fixtures
                events.down.return_value = events.database.return_value = 0
                events.up.return_value = 0
                if not finished:
                    self.log("s1", "agent-hilos_backup", "Command channel: answered #old")
                    inventory = account_operations.rotated_logs

                    def carry_startup_batch(path, first_batch):
                        batches = inventory(path, first_batch)
                        if path == self.logs["s1"] / "daemon.log":
                            staging = path.parent / "staging" / "2026-01-01-00-00-01Z"
                            if staging.exists():
                                archive = path.parent / "archive"
                                archive.mkdir()
                                staging.rename(archive / staging.name)
                        return batches

                    events.up.side_effect = self.restart_logs
                    self.enterContext(patch.object(account_operations, "rotated_logs", side_effect=carry_startup_batch))
                events.command.side_effect = ["", run.email_snapshot]
                with (patch.object(control, "down", side_effect=events.down),
                      patch.object(control, "start_database", side_effect=events.database),
                      patch.object(control, "up", side_effect=events.up),
                      patch.object(run, "command", side_effect=events.command),
                      patch.object(account_operations, "full_archive", side_effect=events.archive)):
                    run.cleanup(False, writers_finished=finished)
                stop = call.down(run.stand, volumes=False)
                up = call.up(run.stand, ANY)
                email = [call.command(run.entry, "test:settings:restore", run.email_snapshot),
                         call.command(run.entry, "test:settings:snapshot", EMAIL_ENABLED)]
                self.assertEqual(events.mock_calls, [call.fixtures(False), *email, stop, up] if finished else
                                 [stop, call.database(run.stand), call.fixtures(True), up, *email])

    def test_recovery_checks_new_writers_before_restoring_email(self):
        foreign = (
            (f"Backup started: {self.backup_id} (scope=full)", "active foreign CREATE", "up"),
            (f"Restore accepted: {self.backup_id} (scope=full); requesting protected mode", "foreign RESTORE admission", "fleet"),
            (f"Backup started: {self.backup_id} (scope=full)",
             "cleanup UP journal history is unconfirmed", "rotation"),
        )
        for line, diagnosis, timing in foreign:
            with self.subTest(timing=timing):
                for node, directory in self.logs.items():
                    self.containers[node] = f"{node}-container true"
                    for path in directory.glob("*.log"):
                        path.unlink()
                    for path in directory.glob("staging/*/*.log"):
                        path.unlink()
                run = ErasureRun(self.run.s)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                run.email_snapshot = json.dumps({EMAIL_ENABLED: True})
                run.schema = Mock(return_value="same schema")
                effects = Mock()
                run.clean_fixtures = effects.fixtures
                effects.down.return_value = effects.database.return_value = 0

                def up(*_):
                    self.restart_logs()
                    if timing != "fleet":
                        self.log("s1", "agent-hilos_backup", line, seconds=2)
                    return 0

                def fleet_started(_):
                    if timing == "fleet":
                        self.log("s1", "agent-hilos_backup", line, seconds=2)
                    return True

                rotated_logs = account_operations.rotated_logs
                rotated = False

                def rotate_after_inventory(path, first_batch):
                    nonlocal rotated
                    paths = rotated_logs(path, first_batch)
                    if (timing == "rotation" and not rotated and path == self.logs["s1"] / "agent-hilos_backup.log"
                            and self.containers["s1"] == "s1-new-container true"):
                        rotated = True
                        batch = path.parent / "staging" / "2026-01-01-00-00-03Z"
                        batch.mkdir()
                        for journal in path.parent.glob("*.log"):
                            journal.rename(batch / journal.name)
                        self.interval("backup:restore-status", seconds=3)
                    return paths

                effects.up.side_effect = up

                def operation():
                    try:
                        raise scenarios.ScenarioTimeout("the primary scenario failure")
                    finally:
                        run.cleanup(True)

                with (patch.object(run.s, "fleet_started", side_effect=fleet_started),
                      patch.object(account_operations, "rotated_logs", side_effect=rotate_after_inventory)):
                    self.assert_cleanup_veto(run, operation, diagnosis, effects, [
                        call.down(self.stand, volumes=False), call.database(self.stand), call.fixtures(True),
                        call.up(self.stand, ANY),
                    ], original="the primary scenario failure")
                self.assertEqual(rotated, timing == "rotation")

    def owned_create(self, run, *, terminal=True, seconds=0):
        address = self.address("s1")
        run.create_attempt = account_operations.CreateAttempt(
            address, self.incarnation("hilos_backup"), self.backup_id, set(), run.backup_logs(run.s.ALL_NODES),
            control.Outcome(0, f"Started scheduled backup {self.backup_id} (scope=full)", ""))
        self.interval(account_operations.CREATE, [f"Backup started: {self.backup_id} (scope=full)"], seconds=seconds)
        if terminal:
            self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 1s", seconds=seconds + 1)
            run.create_attempt.terminal = True
            run.create_attempt.digest = "a" * 64
        self.assertEqual(run.create_evidence(), self.backup_id)
        return address

    def owned_restore(self, run, *, ready=True, completed=True):
        run.restore_attempt = RestoreAttempt("s1", self.backup_id, run.backup_logs(run.s.ALL_NODES),
                                             control.Outcome(1, self.restore_acceptance(), ""),
                                             owner=self.incarnation("hilos_backup"))
        self.restore_logs(ready=ready, completed=completed)

    def assert_cleanup_veto(self, run, operation, diagnosis, effects, expected=(), original=None):
        first = Mock(side_effect=operation)
        following = Mock()
        stand = SimpleNamespace(project="test", masters=["m1"], slaves=["s1"], members=["m1", "s1"])
        with (patch.object(control, "start", side_effect=effects.start),
              patch.object(control, "down", side_effect=effects.down),
              patch.object(control, "start_database", side_effect=effects.database),
              patch.object(control, "up", side_effect=effects.up),
              patch.object(account_operations, "full_archive", side_effect=effects.archive),
              patch.object(run, "command", side_effect=effects.command),
              patch.object(scenarios, "bind"), patch.object(scenarios, "wait_converge") as converge,
              patch.object(scenarios, "SCENARIOS", [scenarios.Scenario("61 first", first, scenarios.Need()),
                                                   scenarios.Scenario("60 next", following, scenarios.Need())]),
              patch("builtins.print") as output):
            self.assertEqual(scenarios.run_matrix(stand, [61, 60]), 1)
        self.assertEqual(effects.mock_calls, list(expected))
        first.assert_called_once()
        following.assert_not_called()
        converge.assert_called_once()
        messages = [entry.args[0] for entry in output.call_args_list]
        self.assertTrue(any(message.startswith("  FATAL: stand unusable: ") and diagnosis in message
                            for message in messages), messages)
        self.assertFalse(any("RETRY" in message for message in messages), messages)
        if original is not None:
            self.assertIn(f"  FAIL: {original}", messages)

    def test_observation_includes_open_work_before_the_scenario_cursor(self):
        cases = (
            ("agent-hilos_backup", f"Backup started: {self.backup_id} (scope=schema-only)", "active foreign CREATE"),
            ("agent-hilos_backup", f"Restore accepted: {self.backup_id} (scope=schema-seed); requesting protected mode",
             "unfinished foreign or unverified RESTORE"),
            ("daemon", f"Command channel: parked {account_operations.CREATE} #old for its agent",
             "parked without a witnessed handler outcome"),
        )
        for name, line, diagnosis in cases:
            with self.subTest(line=line):
                self.observe(self.run)
                self.log("s1", name, line)
                self.assert_cleanup_veto(self.run, self.run.begin_backup_observation, diagnosis, Mock())
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()

    def test_cleanup_vetoes_foreign_work_outside_the_own_attempt_cursors(self):
        other = "2026-01-01_00-00-02"
        cases = (
            ("early refusal before CREATE", f"Backup started: {other} (scope=full)", "active foreign CREATE"),
            ("58 pending before return", "Backup busy; queued pending create (scope=schema-seed)", "pending foreign CREATE"),
            ("59 before down", f"Backup started: {other} (scope=schema-only)", "active foreign CREATE"),
            ("capture before RESTORE same archive",
             f"Restore accepted: {self.backup_id} (scope=full); requesting protected mode", "foreign RESTORE admission"),
            ("capture before RESTORE other archive",
             f"Restore accepted: {other} (scope=schema-seed); requesting protected mode", "foreign RESTORE admission"),
            ("clean RESTORE refusal", f"Backup started: {other} (scope=full)", "active foreign CREATE"),
            ("foreign terminal RESTORE",
             f"Restore accepted: {other} (scope=full); requesting protected mode", "foreign RESTORE admission"),
        )
        for phase, event, diagnosis in cases:
            with self.subTest(phase=phase):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                run = AccountOperationRun(self.run.s, phase)
                run.inspect_restore = self.run.inspect_restore
                self.containers["m1"] = "m1-container true"
                self.observe(run)
                self.containers["m1"] = "m1-container false" if phase.startswith("58") else "m1-container true"
                if phase.startswith("58"):
                    run.killed, run.killed_container = "m1", "m1-container"
                if phase.startswith("capture"):
                    self.owned_create(run)
                if phase == "clean RESTORE refusal":
                    run.restore_attempt = RestoreAttempt(
                        "s1", self.backup_id, run.backup_logs(run.s.ALL_NODES),
                        control.Outcome(1, "", "Refused: Restore refused by the environment guard"), settled=True)

                def foreign_work():
                    self.log("s1", "agent-hilos_backup", event, seconds=4)
                    if phase == "foreign terminal RESTORE":
                        self.log("s1", "agent-hilos_backup", f"Restore {other} completed", seconds=5)
                    return "schema"

                effects = Mock()
                run.clean_fixtures = effects.fixtures
                run.sql = effects.sql
                run.schema = foreign_work if phase == "clean RESTORE refusal" else Mock(return_value="schema")
                if phase != "clean RESTORE refusal":
                    foreign_work()

                def operation():
                    if phase.startswith(("58", "59")):
                        run.cleanup(False, writers_finished=phase.startswith("58"))
                    else:
                        try:
                            raise scenarios.ScenarioTimeout(phase)
                        finally:
                            run.cleanup(True)

                original = None if phase.startswith(("58", "59")) else phase
                self.assert_cleanup_veto(run, operation, diagnosis, effects, original=original)

    def test_live_cleanup_rechecks_before_open_unlink_product_and_restart(self):
        for boundary in ("open", "60 unlink", "61 product", "61 unlink", "58 return", "58 restart"):
            with self.subTest(boundary=boundary):
                self.containers["m1"] = "m1-container true"
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                run = AccountOperationRun(self.run.s, boundary)
                run.inspect_restore = Mock(return_value={"phase": "inactive", "rtMounted": True})
                self.observe(run)
                effects = Mock()
                run.clean_fixtures = effects.fixtures
                run.sql = effects.sql
                if not boundary.startswith("58"):
                    self.owned_create(run)

                def new_create(*_):
                    self.log("s1", "agent-hilos_backup", "Running pending backup create (scope=schema-only)", seconds=4)
                    self.log("s1", "agent-hilos_backup", "Backup started: 2026-01-01_00-00-04 (scope=schema-only)", seconds=4)

                if boundary == "open":
                    self.owned_restore(run)
                    run.inspect_restore.return_value = self.restore_window()
                    new_create()
                    operation = lambda: run.reopen_restore(wait=False)
                    expected = ()
                elif boundary == "60 unlink":
                    run.reopen_restore = new_create
                    operation = lambda: run.cleanup(False, archive_first=True, writers_finished=True)
                    expected = ()
                elif boundary.startswith("61"):
                    if boundary == "61 product":
                        new_create()
                    else:
                        effects.fixtures.side_effect = new_create
                    operation = lambda: run.cleanup(False, product_fixtures_first=True)
                    expected = (call.fixtures(False),) if boundary == "61 unlink" else ()
                else:
                    if boundary == "58 return":
                        run.killed, run.killed_container = "m1", "m1-container"
                        self.containers["m1"] = "m1-container false"

                        def returned(*_):
                            self.containers["m1"] = "m1-container true"
                            new_create()
                            return control.Outcome(0, "", "")

                        effects.start.side_effect = returned
                    effects.email.side_effect = new_create
                    operation = lambda: run.cleanup(False, writers_finished=True, before_restart=(("email", effects.email),))
                    expected = (call.fixtures(False), call.start(self.stand, "m1") if boundary == "58 return" else call.email())
                self.assert_cleanup_veto(run, operation, "active foreign CREATE", effects, expected)

    def test_incomplete_roster_never_allows_recovery_to_stop_writers(self):
        for fault in ("unmounted", "unavailable", "replaced"):
            with self.subTest(fault=fault):
                run = AccountOperationRun(self.run.s, fault)
                run.inspect_restore = Mock(return_value={"phase": "inactive", "rtMounted": True})
                self.containers["m1"] = "m1-container true"
                self.observe(run)
                if fault == "unmounted":
                    run.inspect_restore.return_value = {"phase": "inactive", "rtMounted": False}
                if fault == "replaced":
                    run.killed, run.killed_container = "m1", "m1-container"
                    self.containers["m1"] = "replacement false"
                views = self.views if fault != "unavailable" else lambda _: {"m1": {"nodes": []}, "s1": None}
                effects = Mock()
                run.clean_fixtures = effects.fixtures
                run.schema = effects.schema
                with patch.object(run.s, "inspect_all", side_effect=views):
                    self.assert_cleanup_veto(run, lambda: run.cleanup(True), "admission is unconfirmed", effects)

    def test_recovery_refuses_a_lost_backup_journal_cursor(self):
        live = self.logs["s1"] / "daemon.log"
        live.rename(live.with_suffix(".discarded"))
        self.log("s1", "daemon", "Daemon started with epoll", seconds=1)
        effects = Mock()
        self.run.clean_fixtures = effects.fixtures
        self.run.schema = effects.schema
        self.assert_cleanup_veto(self.run, lambda: self.run.cleanup(True), "log cursor lost", effects)

    def test_missing_middle_batch_cannot_hide_a_writer_after_a_proven_prune(self):
        name = "agent-hilos_backup"
        self.log("s1", name, "Agent started on worker [workerIndex=1]")
        self.run.backup_admission()
        self.rotate("s1", name, 2)
        self.log("s1", name, f"Backup started: {self.backup_id} (scope=full)", seconds=3)
        missing = self.rotate("s1", name, 4)
        missing.unlink()
        self.log("s1", "agent-hilos_log_store",
                 f"Log cleanup: removed batch archive/{missing.parent.name}/, taken at 2026-01-01 00:00:05",
                 seconds=5)
        self.log("s1", name, "Command channel: answered #status", seconds=5)
        effects = Mock()
        self.run.schema = effects.schema
        self.assert_cleanup_veto(self.run, lambda: self.run.cleanup(True), "removed", effects)

    def test_pruned_batch_requires_coverage_of_each_stream(self):
        name = "agent-hilos_backup"
        for coverage in ("missing backup", "missing error", "all bytes read", "absent streams proved"):
            with (self.subTest(coverage=coverage), tempfile.TemporaryDirectory() as directory,
                  patch.dict(self.logs, {"s1": Path(directory)})):
                run = AccountOperationRun(self.run.s, coverage)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                self.log("s1", name, "Agent started on worker [workerIndex=1]")
                run.backup_admission()
                self.rotate("s1", name, 2)
                self.log("s1", "daemon", "The daemon is still running", seconds=3)
                batch = self.rotate("s1", "daemon", 4).parent
                if coverage == "missing backup":
                    self.log("s1", name, f"Backup started: {self.backup_id} (scope=full)", seconds=3)
                    self.rotate("s1", name, 4)
                if coverage == "missing error":
                    self.log("s1", f"{name}.error", f"Backup {self.backup_id} failed: child exit", seconds=3)
                    self.rotate("s1", f"{name}.error", 4)
                if coverage == "all bytes read":
                    for stream in (name, f"{name}.error", "agent-hilos_log_store"):
                        self.log("s1", stream, "Ordinary status", seconds=3)
                        self.rotate("s1", stream, 4)
                archive = batch.parent.parent / "archive" / batch.name
                archive.parent.mkdir()
                batch.rename(archive)
                self.log("s1", name, "Command channel: answered #status", seconds=5)
                if not coverage.startswith("missing"):
                    run.backup_admission()
                (archive / ".hilos-taken.json").write_text('{"takenAt": 1767225604}')

                def prune():
                    for path in archive.iterdir():
                        path.unlink()
                    archive.rmdir()
                    self.log("s1", "agent-hilos_log_store",
                             f"Log cleanup: removed batch archive/{archive.name}/, taken at 2026-01-01 00:00:05",
                             seconds=5)

                if coverage.startswith("missing"):
                    entries = run.journal_entries
                    effects = Mock()
                    run.sql = effects.sql
                    run.clean_fixtures = effects.fixtures

                    def prune_after_daemon(node, stream):
                        result = entries(node, stream)
                        if node == "s1" and stream == "daemon.log":
                            self.assertIn(archive.name, run.backup_journal[node, stream].batches)
                            prune()
                        return result

                    def operation():
                        try:
                            raise scenarios.ScenarioTimeout("the primary scenario failure")
                        finally:
                            run.cleanup(True)

                    with patch.object(run, "journal_entries", side_effect=prune_after_daemon):
                        self.assert_cleanup_veto(run, operation, "removed", effects,
                                                 original="the primary scenario failure")
                else:
                    prune()
                    with patch.object(control, "down", return_value=0) as down:
                        run.stop_writers()
                    down.assert_called_once_with(run.stand, volumes=False)

    def test_late_append_to_rotated_writer_journal_blocks_recovery(self):
        name = "agent-hilos_backup"
        self.log("s1", name, "Agent started on worker [workerIndex=1]")
        self.run.backup_admission()
        live = self.logs["s1"] / f"{name}.log"
        read = account_operations.read_backup_segment
        with live.open("a") as writer:
            archived = self.rotate("s1", name, 2)
            self.log("s1", name, "Command channel: answered #status", seconds=3)

            def append_after_eof(files, candidate, *args):
                result = read(files, candidate, *args)
                if candidate.path == archived and writer.tell() == live_size:
                    writer.write(f"[2026-01-01T00:00:01.000Z] Backup started: {self.backup_id} (scope=full)\n")
                    writer.flush()
                return result

            live_size = writer.tell()
            effects = Mock()
            self.run.schema = effects.schema
            with patch.object(account_operations, "read_backup_segment", side_effect=append_after_eof):
                self.assert_cleanup_veto(self.run, lambda: self.run.cleanup(True), "journal", effects)
            self.assertGreater(writer.tell(), live_size)

    def test_intermediate_batch_must_be_read_before_stopping_writers(self):
        name = "agent-hilos_backup"
        for middle in ("clean", "writer", "lost after inventory", "shortened after inventory", "shortened during carry"):
            with (self.subTest(middle=middle), tempfile.TemporaryDirectory() as directory,
                  patch.dict(self.logs, {"s1": Path(directory)})):
                run = AccountOperationRun(self.run.s, middle)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                self.log("s1", name, "Agent started on worker [workerIndex=1]")
                run.backup_admission()
                self.rotate("s1", name, 2)
                self.log("s1", name, "Command channel: answered #middle", seconds=3)
                if middle != "clean":
                    self.log("s1", name, f"Backup started: {self.backup_id} (scope=full)", seconds=3)
                batch = self.rotate("s1", name, 4)
                self.log("s1", name, "Command channel: answered #status", seconds=5)
                read = account_operations.read_backup_segment
                stat = account_operations.journal_file

                def shorten(path):
                    before = path.stat()
                    first_line = path.read_bytes().splitlines(keepends=True)[0]
                    path.write_bytes(first_line)
                    self.assertEqual(before.st_ino, path.stat().st_ino)

                def after_inventory(files, candidate, *args):
                    if candidate.path == batch:
                        if middle == "lost after inventory":
                            batch.unlink()
                        elif middle == "shortened after inventory":
                            shorten(batch)
                    return read(files, candidate, *args)

                def move_after_staging_stat(path, *args):
                    candidate = stat(path, *args)
                    if middle == "shortened during carry" and path == batch:
                        archive = batch.parents[2] / "archive" / batch.parent.name
                        archive.parent.mkdir()
                        batch.parent.rename(archive)
                        shorten(archive / batch.name)
                    return candidate

                with (patch.object(account_operations, "read_backup_segment", side_effect=after_inventory),
                      patch.object(account_operations, "journal_file", side_effect=move_after_staging_stat)):
                    if middle == "clean":
                        with patch.object(control, "down", return_value=0) as down:
                            run.stop_writers()
                        down.assert_called_once_with(run.stand, volumes=False)
                    else:
                        diagnosis = {"writer": "active foreign CREATE", "lost after inventory": "missing",
                                     "shortened after inventory": "shortened", "shortened during carry": "shortened"}[middle]
                        self.assert_cleanup_veto(run, run.stop_writers, diagnosis, Mock())
                        journal = run.backup_journal["s1", account_operations.BACKUP_LOG]
                        if middle.startswith("shortened"):
                            self.assertEqual([line for _, line in journal.entries], ["Agent started on worker [workerIndex=1]"])
                            self.assertNotIn(batch.parent.name, journal.batches)

    def test_exact_restore_command_evidence_survives_daemon_and_agent_rotation(self):
        self.restore_setup()
        self.owned_restore(self.run)
        self.rotate("s1", "daemon", 4)
        self.rotate("s1", "agent-hilos_backup", 4)
        accepted, bounds, completed, closed = self.run.restore_window_evidence(self.run.restore_attempt)
        self.assertTrue(accepted and completed)
        self.assertIsNotNone(bounds)
        self.assertFalse(closed)
        self.run.inspect_restore.side_effect = lambda node: self.restore_window(
            started=1020 if node == "s1" else 1050)
        self.run.reopen_restore(require_verifying=True)
        self.run.command.assert_called_once_with("s1", "protected-mode:open")

    def test_exact_restore_reply_cannot_open_when_its_daemon_batch_is_lost(self):
        self.restore_setup()
        self.owned_restore(self.run)
        self.rotate("s1", "daemon", 4).unlink()
        self.log("s1", "daemon", "Protected mode still verifying", seconds=5)
        self.run.inspect_restore.side_effect = lambda node: self.restore_window(
            started=1020 if node == "s1" else 1050)
        with self.assertRaisesRegex(StandUnusable, "log cursor lost"):
            self.run.reopen_restore(require_verifying=True)
        self.run.command.assert_not_called()

    def test_prepare_keeps_open_work_before_a_second_daemon_boot_in_the_same_container(self):
        self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=1)
        self.log("s1", "agent-hilos_backup", f"Backup started: {self.backup_id} (scope=full)", seconds=2)
        self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 1s", seconds=3)
        self.log("s1", "daemon", "Daemon started with epoll", seconds=4)
        self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=5)
        self.log("s1", "agent-hilos_backup", "Command channel: answered #status", seconds=6)
        with self.assertRaisesRegex(StandUnusable, "active foreign CREATE"):
            self.run.begin_backup_observation()

    def test_current_holder_can_be_proved_from_the_retained_daemon_boot_and_start(self):
        self.log("s1", "daemon", "Worker #1 started [type=regular]")
        self.log("s1", "daemon", "Agent 'hilos_backup' started on worker #1", seconds=1)
        self.rotate("s1", "daemon", 2)
        self.log("s1", "daemon", "The daemon is still running", seconds=3)
        run = self.run
        process, _ = self.incarnation("hilos_backup")
        real = REAL_INCARNATION.__get__(run)
        epoch = scenarios.log_time("2026-01-01T00:00:00.000Z").timestamp()
        with (patch.object(run, "incarnation", real),
              patch.object(control, "worker_process", return_value=process) as worker,
              patch.object(account_operations, "worker_birth", return_value=epoch) as birth):
            run.begin_backup_observation()
            self.assertEqual(set(run.backup_admission()), self.logs.keys())
            worker.assert_called_with(self.stand, "s1", 1)
            birth.return_value = epoch + 2
            with self.assertRaisesRegex(StandUnusable, "predates the live worker"):
                run.backup_admission()
            self.log("s1", "daemon", "Worker #1 stopped [type=regular]", seconds=4)
            self.log("s1", "daemon", "Worker #1 started [type=regular]", seconds=4)
            with self.assertRaisesRegex(StandUnusable, "outside its worker lifecycle"):
                run.backup_admission()

    def test_addressed_backup_commands_recheck_the_publisher_in_the_admission_journal(self):
        for command in ("create", "verify", "reindex", "restore"):
            for replaced in (False, True):
                with (self.subTest(command=command, replaced=replaced), tempfile.TemporaryDirectory() as directory,
                      patch.dict(self.logs, {"s1": Path(directory)})):
                    run = AccountOperationRun(self.run.s, command)
                    run.inspect_restore = self.run.inspect_restore
                    self.observe(run)
                    self.log("s1", "daemon", "Worker #1 started [type=regular]")
                    self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]")
                    self.log("s1", "daemon", "Agent 'hilos_backup' started on worker #1")
                    process, _ = self.incarnation("hilos_backup")
                    rows = run.restore_rows
                    reads = 0
                    run.placed = Mock(return_value=self.placement(None, None))
                    run.pause = Mock(return_value="own-pause")
                    run.cancel_pause = Mock()
                    run.archive_inventory = Mock(return_value=(self.backup_id, set()))
                    metadata = {"createdAt": "2026-01-01T00:00:00.000Z", "verifyOutcome": "ok",
                                "verifiedAt": True, "sha256": "a" * 64}
                    effects = Mock()
                    effects.age.return_value = f"Aged backup {self.backup_id} createdAt={metadata['createdAt']}"
                    effects.publish.return_value = control.Outcome(0, "", "")
                    effects.verify.return_value = "checked 1: ok 1, mismatch 0, skipped 0, unverified 0"

                    def restore_rows(nodes):
                        nonlocal reads
                        result = rows(nodes)
                        reads += 1
                        admission = 2 if command in ("verify", "restore") else 1
                        if replaced and reads == admission:
                            self.log("s1", "agent-hilos_backup", "Agent stopped on worker [workerIndex=1]", seconds=2)
                            self.log("s1", "daemon", "Agent 'hilos_backup' stopped on worker #1", seconds=2)
                            self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=3)
                            self.log("s1", "daemon", "Agent 'hilos_backup' started on worker #1", seconds=3)
                        return result

                    def launch(_, node, action, *_args):
                        if action == account_operations.CREATE:
                            self.interval(action, [f"Backup started: {self.backup_id} (scope=full)"], seconds=1)
                            self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 1s", seconds=1)
                            return control.Outcome(0, f"Started scheduled backup {self.backup_id} (scope=full)", "")
                        self.interval(account_operations.RESTORE_REQUEST, seconds=1)
                        return control.Outcome(1, "", "Refused: Restore refused by the environment guard")

                    effects.launch.side_effect = launch
                    run.restore_rows = restore_rows
                    run.sql = Mock(return_value="1767225600000")
                    with (patch.object(run, "incarnation", REAL_INCARNATION.__get__(run)),
                          patch.object(control, "worker_process", return_value=process),
                          patch.object(account_operations, "worker_birth", return_value=scenarios.log_time(
                              "2026-01-01T00:00:00.000Z").timestamp()),
                          patch.object(control, "compose", side_effect=effects.publish),
                          patch.object(run, "command", side_effect=effects.age),
                          patch.object(account_operations, "full_archive", return_value=metadata),
                          patch.object(account_operations, "run_backup_command", side_effect=effects.launch),
                          patch.object(account_operations, "backup_command", side_effect=effects.verify)):
                        if command == "reindex":
                            self.set_archive(run, "m1")
                            operation = lambda: run.stage_archive_at_restore_holder("s1")
                        elif command == "restore":
                            self.set_archive(run)
                            operation = run.restore_full_archive
                        else:
                            operation = run.capture_full_archive
                        if replaced:
                            with self.assertRaisesRegex(StandUnusable, "holder changed before mutation"):
                                operation()
                            effects.age.assert_not_called()
                            effects.verify.assert_not_called()
                            self.assertEqual(effects.launch.call_count, int(command == "verify"))
                        elif command == "restore":
                            with self.assertRaisesRegex(AssertionError, "Restore refused by the environment guard"):
                                operation()
                            effects.launch.assert_called_once_with(
                                run, "s1", "backup:restore", self.backup_id, "--scope=full", "--yes")
                        else:
                            operation()
                            if command == "reindex":
                                effects.age.assert_called_once_with(
                                    "s1", "test:backup:age", self.backup_id, f"--at={metadata['createdAt']}", "--scope=full")
                            else:
                                effects.launch.assert_called_once_with(
                                    run, "s1", account_operations.CREATE, account_operations.SCHEDULE)
                                effects.verify.assert_called_once_with(run, "s1", "backup:verify", self.backup_id, "--scope=full")

    def test_publisher_checks_worker_events_and_same_millisecond_daemon_starts(self):
        for evidence in ("worker before daemon acknowledgement", "same millisecond"):
            with (self.subTest(evidence=evidence), tempfile.TemporaryDirectory() as directory,
                  patch.dict(self.logs, {"s1": Path(directory)})):
                run = AccountOperationRun(self.run.s, evidence)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                self.log("s1", "daemon", "Worker #1 started [type=regular]")
                self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]")
                self.log("s1", "daemon", "Agent 'hilos_backup' started on worker #1")
                process, _ = self.incarnation("hilos_backup")
                row = self.placement(None, None)
                with (patch.object(run, "incarnation", REAL_INCARNATION.__get__(run)),
                      patch.object(control, "worker_process", return_value=process),
                      patch.object(account_operations, "worker_birth", return_value=scenarios.log_time(
                          "2026-01-01T00:00:00.000Z").timestamp())):
                    owner = run.incarnation("hilos_backup", row)
                    rows = run.restore_rows

                    def replace_after_initial_check(nodes):
                        result = rows(nodes)
                        if evidence == "same millisecond":
                            self.log("s1", "daemon", "Agent 'hilos_backup' stopped on worker #1")
                            self.log("s1", "daemon", "Agent 'hilos_backup' started on worker #1")
                        else:
                            self.log("s1", "agent-hilos_backup", "Agent stopped on worker [workerIndex=1]", seconds=1)
                            self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=1)
                        return result

                    with (patch.object(run, "restore_rows", side_effect=replace_after_initial_check),
                          self.assertRaisesRegex(StandUnusable, "holder changed before mutation")):
                        run.backup_admission(expected_owner=owner)

    def test_confirmed_live_boundary_distinguishes_append_torn_shrink_and_rewrite(self):
        name = "agent-hilos_backup.error"
        path = self.logs["s1"] / f"{name}.log"
        baseline = b"[2026-01-01T00:00:00.000Z] lower boundary\n"
        for change in ("append", "torn", "shrink", "rewrite"):
            with self.subTest(change=change):
                path.write_bytes(b"")
                journal = account_operations.BackupJournal(path)
                account_operations.read_backup_journal(journal)
                self.assertEqual(journal.entries, [])
                path.write_bytes(baseline)
                account_operations.read_backup_journal(journal)
                if change == "append":
                    self.log("s1", name, "one append", seconds=1)
                    for _ in range(2):
                        account_operations.read_backup_journal(journal)
                    self.assertEqual([line for _, line in journal.entries], ["lower boundary", "one append"])
                else:
                    path.write_bytes({"torn": baseline + b"tail", "shrink": b"",
                                      "rewrite": baseline.replace(b"lower", b"other")}[change])
                    with self.assertRaises(AssertionError):
                        account_operations.read_backup_journal(journal)
                    self.assertEqual([line for _, line in journal.entries], ["lower boundary"])

    def test_journal_carry_preserves_a_confirmed_segment_and_refuses_an_unproved_copy(self):
        name = "agent-hilos_backup.error"
        for carry in ("rename during open", "copy during confirmation", "confirmed copy", "unconfirmed copy"):
            with self.subTest(carry=carry), tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / f"{name}.log"
                path.write_bytes(b"[2026-01-01T00:00:00.000Z] first\n")
                journal = account_operations.BackupJournal(path)
                account_operations.read_backup_journal(journal)
                staging = path.parent / "staging" / "2026-01-01-00-00-02Z" / path.name
                archive = path.parent / "archive" / staging.parent.name / path.name
                staging.parent.mkdir(parents=True)
                archive.parent.mkdir(parents=True)
                path.rename(staging)
                path.write_bytes(b"[2026-01-01T00:00:02.000Z] second\n")
                if carry in ("rename during open", "copy during confirmation"):
                    read = account_operations.read_backup_segment

                    def after_inventory(files, candidate, *args):
                        if candidate.path == staging and carry == "rename during open":
                            staging.rename(archive)
                        segment = read(files, candidate, *args)
                        if candidate.path == staging and carry == "copy during confirmation":
                            archive.write_bytes(staging.read_bytes())
                            staging.unlink()
                        return segment

                    with patch.object(account_operations, "read_backup_segment", side_effect=after_inventory):
                        account_operations.read_backup_journal(journal)
                else:
                    if carry == "confirmed copy":
                        account_operations.read_backup_journal(journal)
                    archive.write_bytes(staging.read_bytes())
                    staging.unlink()
                    if carry == "unconfirmed copy":
                        with self.assertRaisesRegex(AssertionError, "no continuation"):
                            account_operations.read_backup_journal(journal)
                        continue
                    account_operations.read_backup_journal(journal)
                archive.unlink()  # A closed, confirmed batch can disappear after its bytes were kept.
                account_operations.read_backup_journal(journal)
                self.assertEqual([line for _, line in journal.entries], ["first", "second"])

    def test_incoming_publication_cannot_hide_the_first_batch_of_an_empty_stream(self):
        name = "agent-hilos_backup.error"
        path = self.logs["s1"] / f"{name}.log"
        rotated = account_operations.rotated_logs
        inventory_path = path.parent / "archive" / "2026-01-01-00-00-02Z"
        incoming = inventory_path.with_name(f".incoming-{inventory_path.name}")
        glob = Path.glob

        def during_inventory(journal_path, first_batch):
            items = rotated(journal_path, first_batch)
            if journal_path == path and not incoming.exists() and not inventory_path.exists():
                incoming.mkdir(parents=True)
                (incoming / path.name).write_text("[2026-01-01T00:00:01.000Z] Backup failed during copy\n")
            return items

        def publish_after_archive_listing(root, pattern):
            found = list(glob(root, pattern))
            if root == path.parent and pattern == "archive/*" and incoming.exists():
                incoming.rename(inventory_path)
            return iter(found)

        with (patch.object(account_operations, "rotated_logs", side_effect=during_inventory),
              patch.object(Path, "glob", publish_after_archive_listing)):
            self.assert_cleanup_veto(self.run, lambda: self.run.cleanup(True), "log batch in transit", Mock())
        self.assertTrue(incoming.is_dir())

    def test_prepare_uses_only_incoming_batches_in_range_of_the_current_boot(self):
        for batch in ("2026-01-01-00-00-01Z", "2026-01-01-00-00-02Z", "2026-00-01-00-00-01Z", "unknown"):
            with (self.subTest(batch=batch), tempfile.TemporaryDirectory() as directory,
                  patch.dict(self.logs, {"s1": Path(directory)})):
                run = AccountOperationRun(self.run.s, "prepare fixture")
                run.inspect_restore = self.run.inspect_restore
                self.log("s1", "daemon", "Daemon started with epoll", seconds=2, milliseconds=500)
                self.rotate("s1", "daemon", 3)
                self.log("s1", "daemon", "The daemon is still running", seconds=3)
                self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=3)
                incoming = self.logs["s1"] / "archive" / f".incoming-{batch}"
                incoming.mkdir(parents=True)
                effects = Mock()
                run.sql = effects.sql

                def container_state(stand, node):
                    if node == "s1":
                        return control.Outcome(0, "s1-container true 2026-01-01T00:00:02.100000000Z", "")
                    return self.container_state(stand, node)

                def operation():
                    run.begin_backup_observation()
                    run.fixture_sql("INSERT fixture")

                with patch.object(control, "container_state", side_effect=container_state):
                    if batch == "2026-01-01-00-00-01Z":
                        operation()
                        effects.sql.assert_called_once_with("INSERT fixture")
                    else:
                        self.assert_cleanup_veto(run, operation, "log batch in transit", effects)

    def test_post_up_distinguishes_stopped_copy_and_current_rollover_from_partial_startup(self):
        for startup in ("exact copy and fresh rollover", "partial", "old startup still in transit"):
            with self.subTest(startup=startup):
                for node, directory in self.logs.items():
                    for path in directory.glob("*.log"):
                        path.unlink()
                    for path in directory.glob("*/*/*.log"):
                        path.unlink()
                    self.containers[node] = f"{node}-container true"
                run = AccountOperationRun(self.run.s, startup)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                self.log("s1", "agent-hilos_backup", "Command channel: answered #old")
                stopped = {key: len(run.read_backup_stream(*key).entries) for key in run.backup_journal}
                self.restart_logs()
                name = "agent-hilos_backup"
                old = self.logs["s1"] / "staging" / "2026-01-01-00-00-01Z" / f"{name}.log"
                if startup == "old startup still in transit":
                    incoming = self.logs["s1"] / "archive" / f".incoming-{old.parent.name}"
                    incoming.mkdir(parents=True)
                    old.rename(incoming / old.name)
                    daemon = self.logs["s1"] / "daemon.log"
                    daemon.unlink()
                    self.log("s1", "daemon", "Daemon started with epoll", seconds=2)

                    def container_state(stand, node):
                        if node == "s1":
                            return control.Outcome(0, self.containers[node] + " 2026-01-01T00:00:02Z", "")
                        return self.container_state(stand, node)

                    with (patch.object(control, "container_state", side_effect=container_state),
                          self.assertRaisesRegex(StandUnusable, "cleanup UP.*log batch in transit")):
                        run.begin_backup_observation(stopped)
                    continue
                if startup == "partial":
                    old.rename(self.logs["s1"] / old.name)
                    with self.assertRaisesRegex(StandUnusable, "cleanup UP journal history is unconfirmed"):
                        run.begin_backup_observation(stopped)
                    continue
                copy = self.logs["s1"] / "archive" / old.parent.name / old.name
                copy.parent.mkdir(parents=True)
                copy.parent.with_name(".incoming-2025-12-31-23-59-59Z").mkdir()
                copy.write_bytes(old.read_bytes())
                old.unlink()
                self.log("s1", name, "Command channel: answered #fresh", seconds=2)
                self.rotate("s1", name, 3)
                self.rotate("s1", "daemon", 3)
                run.begin_backup_observation(stopped)
                self.assertEqual(set(run.backup_admission()), self.logs.keys())
                self.assertEqual([line for _, _, line in run.journal_entries("s1", account_operations.BACKUP_LOG)],
                                 ["Command channel: answered #fresh"])

    def test_fresh_writer_after_email_snapshot_vetoes_settings_set(self):
        run = ErasureRun(self.run.s)
        run.inspect_restore = self.run.inspect_restore
        self.observe(run)
        effects = Mock()

        def snapshot(_, command, *_args):
            self.assertEqual(command, "test:settings:snapshot")
            self.log("s1", "agent-hilos_backup", f"Backup started: {self.backup_id} (scope=full)")
            return json.dumps({EMAIL_ENABLED: True})

        effects.command.side_effect = snapshot
        run.sql = Mock(return_value="false")
        self.assert_cleanup_veto(run, run.disable_email, "active foreign CREATE", effects,
                                 [call.command(run.entry, "test:settings:snapshot", EMAIL_ENABLED)])
        run.sql.assert_not_called()
        (self.logs["s1"] / account_operations.BACKUP_LOG).unlink()
        self.observe(run)
        run.command = Mock(side_effect=[json.dumps({EMAIL_ENABLED: True}), ""])
        run.disable_email()
        self.assertEqual(run.command.call_args_list, [call(run.entry, "test:settings:snapshot", EMAIL_ENABLED),
                                                     call(run.entry, "settings:set", EMAIL_ENABLED, "false")])

    def test_live_merge_start_checks_admission_before_launching_its_caller(self):
        run = MergeRun(self.run.s)
        run.inspect_restore = self.run.inspect_restore
        self.observe(run)
        invocation = (run.entry, "account:merge", 12, 11, "--password=survivor", "--second-factor=both")
        with patch.object(account_operations.threading, "Thread") as thread:
            run.start_caller(*invocation)
            thread.return_value.start.assert_called_once_with()
            self.log("s1", "agent-hilos_backup", f"Backup started: {self.backup_id} (scope=full)")
            with self.assertRaisesRegex(StandUnusable, "active foreign CREATE"):
                run.start_caller(*invocation)
            thread.assert_called_once()

    def test_erasure_59_admits_the_late_identity_after_reading_the_request(self):
        for evidence in ("active writer", "lost cursor", "clean"):
            with self.subTest(evidence=evidence):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                run = ErasureRun(self.run.s)
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                run.prepare = Mock()
                run.person = Mock(side_effect=[11, 12])
                run.request = Mock(side_effect=[16, 17])
                run.delivery = run.place = run.force = run.fire = Mock()
                run.operation = Mock(return_value=20)
                run.assignment = Mock(side_effect=[21, 22, 23])
                run.open_at_personal = Mock(return_value=(24, 25, {}))
                run.probe = run.replay = Mock()
                run.held = Mock(return_value={"signal": {"data": {"data": {"assignmentId": 22}}}})
                run.result = lambda assignment: "data" if assignment in (23, 25) else ""
                run.business = Mock(return_value=("unchanged",))
                effects = Mock()
                request_reads = 0

                def sql(statement):
                    nonlocal request_reads
                    if statement.startswith("INSERT"):
                        return effects.sql(statement)
                    if statement.startswith("SELECT * FROM hilos_account_deletion"):
                        if request_reads == 0:
                            if evidence == "active writer":
                                self.log("s1", "agent-hilos_backup", f"Backup started: {self.backup_id} (scope=full)")
                            if evidence == "lost cursor":
                                live = self.logs["s1"] / "daemon.log"
                                live.rename(live.with_suffix(".lost"))
                        request_reads += 1
                        return "standing request"
                    if "SELECT final_refusals FROM" in statement:
                        return '{"12":"hilos_identity"}'
                    return "NULL" if statement.startswith("SELECT finished_at") else "1"

                run.sql = sql
                with patch.object(erasure, "ErasureRun", return_value=run):
                    operation = lambda: erasure.scenario_59_erasure_waits_and_restrict(run.s)
                    if evidence == "clean":
                        with patch.object(run, "cleanup") as cleanup:
                            self.assertIn("late identity RESTRICT", operation())
                        effects.sql.assert_called_once()
                        self.assertIn("INSERT INTO hilos_identity", effects.sql.call_args.args[0])
                        cleanup.assert_called_once_with(False)
                    else:
                        diagnosis = "active foreign CREATE" if evidence == "active writer" else "log cursor lost"
                        self.assert_cleanup_veto(run, operation, diagnosis, effects)

    def test_capture_and_verify_veto_an_independent_writer_at_the_last_read(self):
        for boundary in ("capture", "verify"):
            with self.subTest(boundary=boundary):
                for path in self.logs["m1"].glob("*.log"):
                    path.unlink()
                self.create_setup()
                writer = "Backup started: 2026-01-01_00-00-01 (scope=full)"

                def inventory(_):
                    if boundary == "capture":
                        self.log("m1", "agent-hilos_backup", writer, seconds=1)
                    return self.backup_id, set()

                def launch(*_):
                    self.interval(account_operations.CREATE, [f"Backup started: {self.backup_id} (scope=full)"])
                    self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 0s")
                    return control.Outcome(0, f"Started scheduled backup {self.backup_id} (scope=full)", "")

                def pair(*_):
                    self.log("m1", "agent-hilos_backup", writer, seconds=1)
                    return {"sha256": "a" * 64}

                self.run.archive_inventory.side_effect = inventory
                with (patch.object(account_operations, "run_backup_command", side_effect=launch) as create,
                      patch.object(account_operations, "full_archive", side_effect=pair) as archive,
                      patch.object(account_operations, "backup_command") as verify):
                    with self.assertRaisesRegex(StandUnusable, "active foreign CREATE on m1"):
                        self.run.capture_full_archive()
                verify.assert_not_called()
                self.run.cancel_pause.assert_not_called()
                self.assertEqual(create.call_count, int(boundary == "verify"))
                self.assertEqual(archive.call_count, create.call_count)

    def test_stage_vetoes_reindex_after_links_when_a_writer_appears(self):
        self.set_archive(self.run, "m1")

        def pair(*_):
            self.log("m1", "agent-hilos_backup", f"Restore accepted: {self.backup_id} (scope=full); requesting protected mode")
            return {"createdAt": "2026-01-01T00:00:00.000Z", "verifyOutcome": "ok", "sha256": "digest"}

        with (patch.object(control, "compose", return_value=control.Outcome(0, "", "")) as publish,
              patch.object(account_operations, "full_archive", side_effect=pair),
              patch.object(self.run, "command") as command):
            with self.assertRaisesRegex(StandUnusable, "foreign RESTORE admission on m1"):
                self.run.stage_archive_at_restore_holder("s1")
        publish.assert_called_once()
        self.assertTrue(self.run.staged_archive.published)
        command.assert_not_called()

    def test_recovery_rechecks_rotation_when_the_observed_stream_was_empty(self):
        name = "agent-hilos_backup.error"
        live = self.logs["s1"] / f"{name}.log"
        self.log("s1", name, f"Backup {self.backup_id} failed: child exit", seconds=1)
        inventory = account_operations.rotated_logs
        batch = live.parent / "staging" / "2026-01-01-00-00-02Z"

        def rotate_after_inventory(path, first_batch):
            rotated = inventory(path, first_batch)
            if path == live and not batch.exists():
                batch.mkdir(parents=True)
                live.rename(batch / live.name)
                self.log("s1", name, "Backup index read failed", seconds=2)
            return rotated

        effects = Mock()
        self.run.clean_fixtures = effects.fixtures
        self.run.sql = effects.sql
        with patch.object(account_operations, "rotated_logs", side_effect=rotate_after_inventory):
            self.assert_cleanup_veto(self.run, lambda: self.run.cleanup(True), "log cursor lost", effects)
        self.assertTrue((batch / live.name).is_file())

    def test_child_exit_alone_cannot_admit_writer_stop_with_or_without_an_owned_pair(self):
        other = "2026-01-01_00-00-04"
        cases = ((False, "none"), (True, "none"), (True, "replacement"), (False, "same"), (True, "same"))
        for has_archive, status_worker in cases:
            with self.subTest(has_archive=has_archive, status_worker=status_worker):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                run = AccountOperationRun(self.run.s, "foreign CREATE finalizing")
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                if has_archive:
                    self.owned_create(run)
                self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=3)
                self.log("s1", "agent-hilos_backup", f"Backup started: {other} (scope=full)", seconds=4)
                self.log("s1", "agent-hilos_backup", f"Backup {other} completed in 1s", seconds=5)
                self.log("s1", "agent-hilos_backup", f"Backup {other} completed in 2s", seconds=5, milliseconds=1)
                if status_worker == "replacement":
                    self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=6)
                if status_worker == "same":
                    # The worker can answer RESTORE status after the failed index sync
                    # and before the next tick resumes this CREATE.
                    self.log("s1", "worker-1.error", "[ERROR] hilos_backup tick: syncToScan failed", seconds=6)
                if status_worker != "none":
                    self.log("s1", "agent-hilos_backup", "Command channel: took backup:restore-status #status", seconds=7)
                    self.log("s1", "agent-hilos_backup", "Command channel: answered #status", seconds=7, milliseconds=1)
                effects = Mock()
                effects.sql.return_value = "same schema"
                run.sql = effects.sql
                run.clean_fixtures = effects.fixtures
                effects.archive.return_value = {"sha256": "a" * 64}
                effects.down.return_value = effects.database.return_value = 0
                effects.up.return_value = 1

                def operation():
                    try:
                        raise AssertionError("the primary scenario failure")
                    finally:
                        run.cleanup(True)

                self.assert_cleanup_veto(run, operation, "active foreign CREATE", effects,
                                         original="the primary scenario failure")

    def test_later_create_proves_release_and_checks_the_owned_pair(self):
        for result in ("unchanged", "collision", "changed", "replacement worker"):
            with self.subTest(result=result):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                run = AccountOperationRun(self.run.s, "foreign terminal")
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                other = self.backup_id if result == "collision" else "2026-01-01_00-00-04"
                self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=3)
                self.log("s1", "agent-hilos_backup", f"Backup started: {other} (scope=full)", seconds=4)
                self.log("s1", "agent-hilos_backup", f"Backup {other} completed in 1s", seconds=5)
                if result == "replacement worker":
                    self.log("s1", "agent-hilos_backup", "Agent stopped on worker [workerIndex=1]", seconds=5)
                    self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]", seconds=5)
                own = self.owned_create(run, seconds=6)
                with patch.object(account_operations, "full_archive",
                                  return_value={"sha256": ("b" if result == "changed" else "a") * 64}) as archive:
                    diagnoses = {"collision": "collides with owned archive", "changed": "owned archive changed",
                                 "replacement worker": "active foreign CREATE"}
                    if result in diagnoses:
                        diagnosis = diagnoses[result]
                        with self.assertRaisesRegex(StandUnusable, diagnosis):
                            run.remove_archives()
                        self.assertEqual(archive.call_args_list,
                                         [call(run, own, self.backup_id)] if result == "changed" else [])
                    else:
                        run.remove_archives()
                        self.assertEqual(archive.call_args_list, [
                            call(run, own, self.backup_id),
                            call(run, own, self.backup_id, remove=True, staged=None, sidecar_only=False),
                        ])

    def test_prior_create_and_restore_allow_clean_observation(self):
        self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]")
        self.log("s1", "agent-hilos_backup", f"Backup started: {self.backup_id} (scope=full)", seconds=1)
        self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 1s", seconds=2)
        self.interval(account_operations.RESTORE_REQUEST, [
            f"Restore accepted: {self.backup_id} (scope=full); requesting protected mode"], seconds=3)
        self.log("s1", "agent-hilos_backup", f"Restore started: {self.backup_id} (scope=full)", seconds=4)
        self.log("s1", "agent-hilos_backup", f"Restore {self.backup_id} completed", seconds=5)
        self.run.begin_backup_observation()
        self.assertEqual(set(self.run.backup_admission()), set(self.logs))

    def test_same_second_schema_then_owned_full_releases_repeated_completion(self):
        self.log("s1", "agent-hilos_backup", "Agent started on worker [workerIndex=1]")
        self.log("s1", "agent-hilos_backup", f"Backup started: {self.backup_id} (scope=schema-only)", milliseconds=1)
        for ms in (2, 3):
            self.log("s1", "agent-hilos_backup", f"Backup {self.backup_id} completed in 0s", milliseconds=ms)
        address = self.owned_create(self.run)
        with patch.object(account_operations, "full_archive", return_value={"sha256": "a" * 64}) as archive:
            self.run.remove_archives()
        self.assertEqual(archive.call_args_list, [call(self.run, address, self.backup_id),
                         call(self.run, address, self.backup_id, remove=True, staged=None, sidecar_only=False)])

    def test_pending_owned_restore_needs_a_window_at_or_after_admission(self):
        for started in (None, 0, 1020):
            with self.subTest(started=started):
                for path in self.logs["s1"].glob("*.log"):
                    path.unlink()
                run = AccountOperationRun(self.run.s, "pending RESTORE")
                run.inspect_restore = Mock(return_value={"phase": "inactive", "rtMounted": True})
                self.observe(run)
                self.owned_create(run)
                self.owned_restore(run, ready=False, completed=False)
                window = self.restore_window("active", started=started or 0)
                if started is None:
                    window.pop("startedAt")
                run.inspect_restore.return_value = window
                effects = Mock()
                run.schema = effects.schema
                run.clean_fixtures = effects.fixtures
                effects.down.return_value = 0
                with (patch.object(control, "down", side_effect=effects.down),
                      patch.object(control, "up", side_effect=effects.up),
                      patch.object(control, "start_database", side_effect=effects.database),
                      patch.object(account_operations, "full_archive", side_effect=effects.archive)):
                    diagnosis = "preserved unverified DB and freeze" if started == 1020 else "belongs to another restore"
                    with self.assertRaisesRegex(StandUnusable, diagnosis):
                        run.cleanup(True)
                expected = [
                    call.down(self.stand, volumes=False),
                    call.archive(run, self.address("s1"), self.backup_id, remove=True, staged=None, sidecar_only=False),
                ] if started == 1020 else []
                self.assertEqual(effects.mock_calls, expected)

    def test_active_create_stop_unlink_then_schema_and_isolated_cleanup(self):
        address = self.owned_create(self.run, terminal=False)
        with self.assertRaisesRegex(StandUnusable, "owned CREATE .* is still publishing"):
            self.run.backup_admission()
        events = Mock()
        self.run.schema = events.schema
        self.run.clean_fixtures = events.fixtures
        events.schema.return_value = "same schema"
        events.down.return_value = events.database.return_value = events.up.return_value = 0
        with (patch.object(control, "down", side_effect=events.down),
              patch.object(control, "start_database", side_effect=events.database),
              patch.object(control, "up", side_effect=events.up),
              patch.object(account_operations, "full_archive", side_effect=events.archive)):
            self.run.cleanup(True)
        self.assertEqual(events.mock_calls, [
            call.schema(), call.down(self.stand, volumes=False),
            call.archive(self.run, address, self.backup_id, remove=True, staged=None, sidecar_only=False),
            call.database(self.stand), call.schema(), call.fixtures(True), call.up(self.stand, ANY),
        ])

    def test_stopped_cleanup_refuses_a_schema_change_before_sql_and_full_up(self):
        own = self.owned_create(self.run)
        effects = Mock()
        self.run.schema = effects.schema
        self.run.clean_fixtures = effects.fixtures
        effects.schema.side_effect = ["live schema", "isolated schema"]
        effects.down.return_value = effects.database.return_value = 0

        def operation():
            try:
                raise AssertionError("the primary scenario failure")
            finally:
                self.run.cleanup(True)

        self.assert_cleanup_veto(self.run, operation, "database schema changed", effects, [
            call.schema(), call.down(self.stand, volumes=False),
            call.archive(self.run, own, self.backup_id, remove=True, staged=None, sidecar_only=False),
            call.database(self.stand), call.schema(),
        ], original="the primary scenario failure")

    def test_61_cleanup_orders_product_controls_archive_and_callers_without_restart(self):
        run = MergeRun(self.run.s)
        run.inspect_restore = self.run.inspect_restore
        self.observe(run)
        own = self.owned_create(run)
        effects = Mock()
        run.clean_fixtures = effects.fixtures
        run.clean_probes = effects.probes
        run.clean_pauses = effects.pauses
        run.collect_callers = effects.callers
        with (patch.object(account_operations, "full_archive", side_effect=effects.archive),
              patch.object(control, "down", side_effect=effects.down),
              patch.object(control, "up", side_effect=effects.up)):
            run.cleanup(False)
        self.assertEqual(effects.mock_calls, [
            call.fixtures(False), call.probes(), call.pauses(),
            call.archive(run, own, self.backup_id, remove=True, staged=None, sidecar_only=False),
            call.callers(),
        ])

    def test_unlink_argv_uses_the_writer_wrapper_only_for_live_exec(self):
        address = self.set_archive(self.run)
        with patch.object(control, "compose", return_value=control.Outcome(0, "", "")) as compose:
            self.run.remove_archives()
            self.set_archive(self.run)
            self.run.writers_stopped = True
            self.run.remove_archives()
        live, stopped = (entry.args for entry in compose.call_args_list)
        command = live[4 + len(control.WRITER_EXEC):]
        self.assertEqual(live[:4 + len(control.WRITER_EXEC)],
                         (self.stand, "exec", "-T", "cli", *control.WRITER_EXEC))
        self.assertEqual(stopped, (self.stand, "run", "--rm", "--no-deps", "-T", "cli", *command))
        self.assertEqual(command[:2], ("sh", "-c"))
        self.assertEqual(command[-4:], (address.root, f"{address.root}/full/{self.backup_id}-dev-full", "owned", ""))

    def assert_backup_stops_matrix(self, operation, reply, original, diagnosis, command):
        def scenario():
            failed = False
            try:
                return operation()
            except Exception:
                failed = True
                raise
            finally:
                self.run.cleanup(failed)

        self.run.clean_fixtures = Mock()
        self.run.command = Mock()
        operation_run = Mock(side_effect=scenario)
        following = Mock()
        stand = SimpleNamespace(project="test", masters=["m1"], slaves=["s1"], members=["m1", "s1"])
        with (patch.object(account_operations, "run_backup_command", side_effect=reply) as launch,
              patch.object(account_operations, "backup_command") as verify,
              patch.object(account_operations, "full_archive") as archive,
              patch.object(control, "down") as down, patch.object(control, "up") as up,
              patch.object(control, "start_database") as database, patch.object(control, "db_sql") as sql,
              patch.object(scenarios, "bind"), patch.object(scenarios, "wait_converge") as converge,
              patch.object(scenarios, "SCENARIOS", [scenarios.Scenario("61 first", operation_run, scenarios.Need()),
                                                   scenarios.Scenario("60 next", following, scenarios.Need())]),
              patch("builtins.print") as output):
            self.assertEqual(scenarios.run_matrix(stand, [61, 60]), 1)

        launch.assert_called_once_with(self.run, "s1", *command)
        for forbidden in (verify, archive, down, up, database, sql, self.run.clean_fixtures, self.run.command, following):
            forbidden.assert_not_called()
        if command[0] == "backup:restore":
            self.run.sql.assert_called_once_with("SELECT FLOOR(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3)) * 1000)")
        operation_run.assert_called_once()
        converge.assert_called_once()
        messages = [entry.args[0] for entry in output.call_args_list]
        self.assertTrue(any(message.startswith("  FAIL: ") and original in message for message in messages), messages)
        self.assertTrue(any(message.startswith(f"  FATAL: stand unusable: {diagnosis}") for message in messages), messages)

    def test_unusable_stand_reports_original_and_stops_the_matrix_and_retry(self):
        fault = scenarios.ScenarioTimeout("original timeout")
        first = Mock(side_effect=StandUnusable("writer stop failed", fault))
        next_scenario = Mock()
        stand = SimpleNamespace(project="test", masters=["m1"], slaves=["s1"], members=["m1", "s1"])
        with (patch.object(scenarios, "bind"), patch.object(scenarios, "wait_converge") as converge,
              patch.object(scenarios, "SCENARIOS", [scenarios.Scenario("60 first", first, scenarios.Need()),
                                                   scenarios.Scenario("61 next", next_scenario, scenarios.Need())]),
              patch("builtins.print") as output):
            self.assertEqual(scenarios.run_matrix(stand, [60, 61]), 1)
        first.assert_called_once()
        next_scenario.assert_not_called()
        converge.assert_called_once()
        self.assertIn(call("  FAIL: original timeout"), output.call_args_list)
        self.assertIn(call("  FATAL: stand unusable: writer stop failed"), output.call_args_list)

    def test_replay_rejects_late_ordinary_answer_even_with_identical_payload(self):
        run = MergeRun(self.run.s)
        run.operation = 23
        run.duties["receive"] = 27
        run.probes["hilos_user:2"] = object()
        handover = {"signal": {"data": {"receipt": 53}}}
        reply = {"operationId": 23, "assignmentId": 27, "stage": "go", "answer": "done"}
        original = {"signal": {"data": {"receipt": 53, "data": reply}}}
        ordinary = {"signal": {"data": {"receipt": 59, "data": reply}}}
        for frame in (ordinary, original):
            with (self.subTest(receipt=frame["signal"]["data"]["receipt"]),
                  patch.object(run, "probe") as probe, patch.object(run, "replay") as replay,
                  patch.object(run, "held", return_value=frame) as held):
                arm = call("hilos_user:2", "arm", frame_kind="answer", operation_id=23, assignment_id=27)
                if frame is ordinary:
                    with self.assertRaisesRegex(AssertionError, "outside the original handover's journal receipt"):
                        run.replay_finished_handover("hilos_user:2", handover, 53)
                    self.assertEqual(probe.call_args_list, [arm])
                else:
                    run.replay_finished_handover("hilos_user:2", handover, 53)
                    self.assertEqual(probe.call_args_list, [arm, call("hilos_user:2", "clear")])
                replay.assert_called_once_with(handover, copies=1)
                held.assert_called_once_with("hilos_user:2")

    def restored_sender_fixture(self, *, fresh_worker=False):
        run = MergeRun(SimpleNamespace(**vars(self.run.s)))
        run.inspect_restore = self.run.inspect_restore
        self.observe(run)
        sender = "hilos_user:1"
        for node in self.logs:
            self.log(node, "daemon", "Worker #2 started [type=regular]")
            if node != "s1" or not fresh_worker:
                self.log(node, "daemon", "Worker #3 started [type=regular]")
        marks = {node: control.node_log_mark(self.stand.node(node)) for node in self.logs}
        run.restore_attempt = RestoreAttempt("s1", self.backup_id, run.backup_logs(self.logs), settled=True)
        self.log("m1", "daemon", f"Agent '{sender}' started on worker #2", seconds=1)
        self.log("m1", "daemon", f"Agent '{sender}' stopped on worker #2", seconds=2)
        for node in self.logs:
            self.log(node, "daemon", "Protected mode: froze this node for hilos_backup, stopped 5 agent(s) "
                     "over 1 pass(es), kept 0 waiting replay(s) for the lift", seconds=3)
        self.log("s1", "agent-hilos_backup", f"Restore {self.backup_id} replayed in 1s; re-reading state", seconds=4)
        if fresh_worker:
            self.log("s1", "daemon", "Worker #3 started [type=regular]", seconds=5)
        self.log("s1", "daemon", f"Agent '{sender}' started on worker #3", seconds=5)
        return run, sender, marks, run.restore_replayed_at()

    def test_replay_rejects_pre_import_stop_with_an_unstopped_restored_sender(self):
        run, sender, marks, replayed_at = self.restored_sender_fixture()

        self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {}))
        self.log("s1", "daemon", f"Agent '{sender}' stopped on worker #2", seconds=6)
        self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {}))
        self.log("s1", "daemon", "Worker #3 stopped [type=regular]", seconds=7)
        self.log("s1", "daemon", "Worker #3 started [type=regular]", seconds=8)
        self.log("s1", "daemon", f"Agent '{sender}' stopped on worker #3", seconds=9)
        self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {}))

    def test_replay_placement_requires_restored_stop_and_matches_any_successor(self):
        run, sender, marks, replayed_at = self.restored_sender_fixture()
        self.log("s1", "daemon", f"Agent '{sender}' stopped on worker #3", seconds=6)
        self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {}))
        run.s.placement_row = lambda views, _: views
        self.assertTrue(run.restored_sender_stopped(sender, marks, replayed_at, {}))

        self.log("m1", "daemon", f"Agent '{sender}' started on worker #2", seconds=7)
        placement = {"nodeId": "m1", "state": "started"}
        start_line = f"[2026-01-01T00:00:07.000Z] Agent '{sender}' started on worker #2"
        process = control.WorkerProcess("m1", "m1-container", 2, 100, "later")
        with patch.object(run, "incarnation", return_value=(process, start_line)):
            self.assertTrue(run.restored_sender_stopped(sender, marks, replayed_at, placement))
            self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {**placement, "nodeId": "s1"}))
            self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {}))
            self.assertFalse(run.sender_stopped(sender, marks, placement))

    def test_equal_millisecond_successor_requires_order_in_the_same_journal(self):
        for order in ("stop first", "start first", "other node", "reused worker"):
            with self.subTest(order=order):
                for path in self.logs.values():
                    (path / "daemon.log").unlink()
                backup_log = self.logs["s1"] / "agent-hilos_backup.log"
                backup_log.unlink(missing_ok=True)
                run, sender, marks, replayed_at = self.restored_sender_fixture(fresh_worker=order == "reused worker")
                run.s.placement_row = lambda views, _: views
                node = "m1" if order == "other node" else "s1"
                stopped = f"Agent '{sender}' stopped on worker #3"
                started = f"Agent '{sender}' started on worker #2"
                if order == "start first":
                    self.log(node, "daemon", started, seconds=6)
                if order == "reused worker":
                    self.log("s1", "daemon", "Worker #3 stopped [type=regular]", seconds=5)
                    self.log("s1", "daemon", "Worker #3 started [type=regular]", seconds=5)
                self.log("s1", "daemon", stopped, seconds=6)
                if order == "reused worker":
                    self.assertFalse(run.restored_sender_stopped(sender, marks, replayed_at, {}))
                if order != "start first":
                    self.log(node, "daemon", started, seconds=6)
                placement = {"nodeId": node, "state": "started"}
                process = control.WorkerProcess(node, f"{node}-container", 2, 100, "later")
                start_line = f"[2026-01-01T00:00:06.000Z] {started}"
                with patch.object(run, "incarnation", return_value=(process, start_line)):
                    self.assertEqual(run.restored_sender_stopped(sender, marks, replayed_at, placement),
                                     order == "stop first")

    def test_red_merge_cleanup_recovers_a_fixture_with_a_lost_insert_reply(self):
        run = MergeRun(self.run.s)
        run.users = [4]
        with patch.object(run, "sql", side_effect=["9", "", "", "0"]) as sql:
            run.clean_fixtures(True)
        names = ", ".join(f"'{run.prefix}-{suffix}'" for suffix in ("first", "second", "survivor", "post-archive"))
        lookup, reused_ids, delete, verify = sql.call_args_list
        self.assertEqual(lookup, call(f"SELECT id FROM hilos_user WHERE name IN ({names})"))
        self.assertEqual(reused_ids, call(f"SELECT id FROM hilos_user WHERE id IN (4) AND name NOT IN ({names})"))
        self.assertTrue(delete.args[0].startswith("START TRANSACTION; "))
        self.assertTrue(delete.args[0].endswith("DELETE FROM hilos_user WHERE id IN (4,9); COMMIT"))
        self.assertEqual(verify, call("SELECT COUNT(*) FROM hilos_user WHERE id IN (4,9)"))

    def test_red_merge_and_erasure_cleanup_ignore_a_reused_marker_id(self):
        for run, marker in ((MergeRun(self.run.s), "post-archive"), (ErasureRun(self.run.s), "after-archive")):
            with self.subTest(marker=marker):
                run.inspect_restore = self.run.inspect_restore
                self.observe(run)
                if isinstance(run, MergeRun):
                    run.users = [4, 5, 6]
                    marker_inserts = ["9"]
                else:
                    run.archived_users = {4, 5, 6}
                    marker_inserts = ["9", ""]
                with patch.object(run, "sql", side_effect=[*marker_inserts, "4\n5\n6", "", "", "0"]) as sql:
                    self.assertEqual(run.person(marker), 9)
                    run.writers_stopped = True
                    run.clean_fixtures(True)
                self.assertIn(f"'{run.prefix}-{marker}'", sql.call_args_list[len(marker_inserts)].args[0])
                self.assertIn("DELETE FROM hilos_user WHERE id IN (4,5,6)", sql.call_args_list[-2].args[0])
                self.assertNotIn("(4,5,6,9)", sql.call_args_list[-2].args[0])

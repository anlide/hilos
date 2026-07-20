<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupPruner;
use Hilos\Backup\BackupRetentionPolicy;
use Hilos\Backup\BackupScanResult;
use Hilos\Backup\BackupSchedule;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Process;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;
use Throwable;

/**
 * BackupAgent - the monopoly backup subsystem agent (framework-owned, concrete).
 *
 * Projects activate it by registering it in Hilos::AGENTS under
 * {@see HilosAgentType::HILOS_BACKUP}; it needs no subclass because all behavior
 * is driven by env and the backup catalog.
 *
 * It owns two paths. The read path (HIL-269): on start it scans the backup storage tree
 * and rebuilds the runtime backup index (files=truth, RT=index). The create path (HIL-270,
 * this class as supervisor): {@see startBackup()} spawns the short-lived `backup:run` child
 * over {@see Process} — never blocking the daemon loop — and {@see onTick()} polls it,
 * enforces the timeout, and finalizes the run. Being a singleton monopoly agent is the
 * concurrency lock itself: only one child runs at a time, and a second create request while
 * one is in flight is skipped.
 *
 * It also owns backup scheduling (HIL-273). The schedule ({@see BackupSchedule}) is loaded on
 * start; its agent-mechanism entries become cron rules this agent evaluates in {@see onTick()},
 * and its daemon-mechanism entries fire named DAEMON/CRON signals handled by
 * {@see onSignalCron()}. Both, plus the list action (HIL-333) and the manual CLI backup, funnel
 * through the single guarded {@see startBackup()}; this class owns the launch path and the lock.
 */
final class BackupAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_BACKUP;

    /**
     * Page → agent routes for the list-page actions (HIL-333). All three are singleton
     * signals (the agent is monopolistic), so each maps straight to its payload DTO.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::BACKUP_AGENT_CREATE => BackupCreateSignalData::class,
        HilosSignalConstants::BACKUP_AGENT_DELETE => BackupDeleteSignalData::class,
        HilosSignalConstants::BACKUP_AGENT_SET_KEEP => BackupSetKeepSignalData::class,
    ];

    /** Child interpreter; matches the worker spine's binary ({@see \Hilos\Socket\Server\WorkerServer}). */
    private const string PHP_BINARY = 'php';

    /** The in-flight backup child, or null when idle (the single-flight lock). */
    private ?Process $childProcess = null;

    /** Id of the in-flight backup, or null when idle. */
    private ?string $currentBackupId = null;

    /** Scope of the in-flight backup, or null when idle. */
    private ?BackupScope $currentScope = null;

    /** Monotonic-ish start time (microtime) of the in-flight backup; 0 when idle. */
    private float $startedAt = 0.0;

    /** Timeout budget (seconds) of the in-flight backup, captured at spawn time. */
    private float $timeoutSeconds = 0.0;

    /**
     * @var list<BackupCronJob> Agent-mechanism cron jobs (rule paired with scope); empty when
     *     backups are disabled or no agent entries exist.
     */
    private array $agentSchedule = [];

    /** The loaded backup schedule, used to map a fired daemon cron name to its scope. */
    private ?BackupSchedule $schedule = null;

    /**
     * Single in-memory pending slot for a manual create requested while a backup runs
     * (HIL-333). Coalesced (the last manual scope wins) and drained when the current run
     * finishes; ephemeral by design (a restart/failover drops it, like cron no-catch-up).
     * Only the list action sets it — cron overlaps still skip, never queue.
     */
    private ?BackupScope $pendingScope = null;

    /**
     * Registers truth sources, rebuilds the runtime backup index, and loads the schedule.
     *
     * No-ops entirely when disabled: no scan, and no cron rules, so scheduling is off.
     *
     * @throws BackupScheduleException When the project backup schedule is malformed
     */
    public function onStart(): void
    {
        if (!Hilos::$env->bool(EnvConstants::BACKUP_ENABLED)) {
            $this->logAgentInfo('Backup disabled; skipping history scan');

            return;
        }

        $this->registerRtTruthSource(BackupHistory::RT_COLLECTION);
        $this->registerRtTruthSource(BackupRuntime::RT_ITEM);

        $this->refreshHistory();

        $this->schedule = BackupSchedule::fromCatalog();
        $this->buildAgentCronRules();
    }

    /**
     * Polls any in-flight backup child, then fires any due agent-mechanism schedule entry.
     *
     * Polling runs first so a run that finished this tick frees the lock before the schedule
     * is checked, letting a coincident scheduled fire start rather than be skipped as an overlap.
     */
    public function onTick(): void
    {
        $this->pollRunningBackup();
        $this->checkSchedule();
    }

    /**
     * Kills any in-flight child and clears the runtime flag on shutdown.
     */
    public function onStop(): void
    {
        if ($this->childProcess !== null) {
            $this->childProcess->halt();
        }
        $this->resetRun();
        $this->clearRuntime();
    }

    /**
     * Runs the daemon-mechanism schedule: maps a fired cron name to its scope and starts a backup.
     *
     * The daemon owns the cron rules for daemon-mechanism entries (registered by the framework
     * daemon from the same schedule); when one fires it routes a named DAEMON/CRON signal here.
     * Backup cron names are project-configured schedule data, not fixed constants, so this
     * handler dispatches by schedule lookup rather than a static switch: every backup cron name
     * maps to the one guarded create path, differing only in the resolved scope. A name that is
     * not a backup schedule entry is ignored, so a shared default-cron owner may forward
     * unrelated names here harmlessly.
     *
     * @param SignalDataInterface $data Cron signal payload (unused; the name carries the routing)
     * @param string $source Signal source (unused)
     * @param string $name Fired cron name, matched against the backup schedule
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        $scope = $this->schedule?->scopeForName($name);
        if ($scope === null) {
            return;
        }

        $this->startBackup($scope);
    }

    /**
     * Handles the list-page actions routed from the backup page (HIL-333).
     *
     * The page validates each request synchronously and acks the client; this owns the
     * storage mutation: create funnels through the guarded {@see startBackup()} (with an
     * in-memory pending slot when busy), delete through the shared delete path, and
     * set-keep through an atomic sidecar rewrite plus an index re-mirror.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal name is not a backup list action
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::BACKUP_AGENT_CREATE:
                if ($data->data instanceof BackupCreateSignalData) {
                    $this->handleCreateRequest($data->data);
                }

                return;

            case HilosSignalConstants::BACKUP_AGENT_DELETE:
                if ($data->data instanceof BackupDeleteSignalData) {
                    $this->handleDeleteRequest($data->data);
                }

                return;

            case HilosSignalConstants::BACKUP_AGENT_SET_KEEP:
                if ($data->data instanceof BackupSetKeepSignalData) {
                    $this->handleSetKeepRequest($data->data);
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Runs a manual create now, or coalesces it into the single pending slot when busy.
     *
     * @param BackupCreateSignalData $data Create request carrying the scope value
     */
    private function handleCreateRequest(BackupCreateSignalData $data): void
    {
        $scope = BackupScope::fromString($data->scope);
        if ($scope === null) {
            $this->logAgentWarning("Ignoring backup create for unknown scope: {$data->scope}");

            return;
        }

        if ($this->childProcess !== null) {
            // Coalesce: the newest manual request wins the single pending slot. The current
            // run drains it in finishRun(); an intervening cron overlap still just skips.
            $this->pendingScope = $scope;
            $this->logAgentInfo("Backup busy; queued pending create (scope={$scope->value})");

            return;
        }

        $this->startBackup($scope);
    }

    /**
     * Deletes one stored backup through the shared delete path and drops its index row.
     *
     * Re-guards the in-progress backup (never delete the running row) and treats an
     * already-removed backup as an idempotent no-op.
     *
     * @param BackupDeleteSignalData $data Delete request carrying the backup id
     */
    private function handleDeleteRequest(BackupDeleteSignalData $data): void
    {
        $id = $data->backupId;
        if ($id === '') {
            return;
        }
        if ($id === $this->currentBackupId) {
            $this->logAgentWarning("Ignoring delete of in-progress backup {$id}");

            return;
        }

        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return;
        }

        $row = $histories->get($id);
        if ($row === null) {
            $this->logAgentInfo("Backup {$id} already deleted; no-op");

            return;
        }

        try {
            (new BackupPruner())->deleteStored($row, Hilos::$env->string(EnvConstants::BACKUP_DIR));
            $histories->remove($id);
            $this->logAgentInfo("Backup deleted: {$id}");
        } catch (Throwable $e) {
            $this->logAgentError("Failed to delete backup {$id}: " . $e->getMessage());
        }
    }

    /**
     * Sets a stored backup's keep pin: rewrites the sidecar (truth) and re-mirrors the index.
     *
     * Only a successful, completed backup can be pinned; the in-progress and non-success
     * cases are re-guarded here as well as on the page.
     *
     * @param BackupSetKeepSignalData $data Set-keep request carrying the id and desired pin
     */
    private function handleSetKeepRequest(BackupSetKeepSignalData $data): void
    {
        $id = $data->backupId;
        if ($id === '') {
            return;
        }
        if ($id === $this->currentBackupId) {
            $this->logAgentWarning("Ignoring keep toggle of in-progress backup {$id}");

            return;
        }

        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return;
        }

        $row = $histories->get($id);
        if ($row === null) {
            $this->logAgentInfo("Backup {$id} not found; keep toggle no-op");

            return;
        }
        if (BackupStatus::fromString($row->status) !== BackupStatus::SUCCESS) {
            $this->logAgentWarning("Ignoring keep toggle of non-success backup {$id}");

            return;
        }

        try {
            (new BackupCreator())->setStoredKeep($row, Hilos::$env->string(EnvConstants::BACKUP_DIR), $data->keep);
            // Re-mirror the index from the rewritten sidecar (files=truth): the cleared +
            // recreated rows carry the new keep pin to every reader over RT sync.
            $this->refreshHistory();
            $this->logAgentInfo("Backup keep set: {$id} keep=" . ($data->keep ? 'true' : 'false'));
        } catch (Throwable $e) {
            $this->logAgentError("Failed to set keep on backup {$id}: " . $e->getMessage());
        }
    }

    /**
     * Starts one backup unless one is already running (the single-flight lock).
     *
     * Generates the id, marks the runtime running, and spawns the `backup:run` child. Every
     * create trigger funnels here: the agent-mechanism schedule ({@see checkSchedule()}), the
     * daemon-mechanism schedule ({@see onSignalCron()}), the list action (HIL-333), and the
     * manual CLI backup. A second request while a backup runs is skipped and logged, never queued.
     *
     * @param BackupScope $scope What the backup should capture
     */
    public function startBackup(BackupScope $scope): void
    {
        if (!Hilos::$env->bool(EnvConstants::BACKUP_ENABLED)) {
            $this->logAgentWarning('Backup is disabled; ignoring create request');

            return;
        }

        if ($this->childProcess !== null) {
            $this->logAgentWarning(
                "Backup already running ({$this->currentBackupId}); skipping new {$scope->value} request",
            );

            return;
        }

        $cliEntry = Hilos::$env->string(EnvConstants::BACKUP_CLI_ENTRY);
        if ($cliEntry === '') {
            $this->logAgentError('Cannot start backup: BACKUP_CLI_ENTRY is not configured');

            return;
        }

        $id = self::generateBackupId(new DateTimeImmutable());
        $this->currentBackupId = $id;
        $this->currentScope = $scope;
        $this->startedAt = microtime(true);
        $this->timeoutSeconds = (float)Hilos::$env->int(EnvConstants::BACKUP_TIMEOUT);
        $this->markRuntimeRunning($id, $scope);

        try {
            // cwd stays null: the child inherits the worker's project-root cwd, and cli.php
            // resolves its own paths from __DIR__, so no working directory needs threading.
            $this->childProcess = new Process(
                self::PHP_BINARY,
                self::buildChildArgs($cliEntry, $id, $scope),
            );
        } catch (Throwable $e) {
            // childProcess stayed null (the constructor threw), so finishRun records the
            // failed attempt and clears the lock exactly as it does for a bad exit.
            $this->finishRun(false, 'failed to spawn child: ' . $e->getMessage());

            return;
        }

        $this->logAgentInfo("Backup started: {$id} (scope={$scope->value})");
    }

    /**
     * Builds the backup id from a timestamp: the sortable `YYYY-MM-DD_HH-mm-ss` stem.
     *
     * Takes the instant as an argument (rather than reading the clock) so it stays pure and
     * unit-testable without mocking global time.
     *
     * @param DateTimeImmutable $now Moment the backup is started
     * @return string Filesystem-safe backup id
     */
    public static function generateBackupId(DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d_H-i-s');
    }

    /**
     * Builds the child argv `[<cli>, backup:run, <id>, --scope=<scope>]`.
     *
     * The command name and option come from {@see BackupConstants} so this argv and the
     * project child command that parses it cannot drift apart.
     *
     * @param string $cliEntry Absolute path to the CLI entry script hosting `backup:run`
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @return list<string> Child process argv (after the php binary)
     */
    public static function buildChildArgs(string $cliEntry, string $id, BackupScope $scope): array
    {
        return [
            $cliEntry,
            BackupConstants::RUN_COMMAND,
            $id,
            '--' . BackupConstants::SCOPE_OPTION . '=' . $scope->value,
        ];
    }

    /**
     * Polls the in-flight backup child: finalizes it on exit and kills it on timeout.
     *
     * The work here stays tiny per the onTick rule — a status poll plus, only at the very
     * end of a run, a storage rescan; the heavy dump lives in the spawned child.
     */
    private function pollRunningBackup(): void
    {
        if ($this->childProcess === null) {
            return;
        }

        $this->childProcess->tick();

        if ($this->childProcess->getStatus()[Process::STATUS_RUNNING] === true) {
            if (microtime(true) - $this->startedAt >= $this->timeoutSeconds) {
                $this->childProcess->stop();
                $this->childProcess->halt();
                $this->finishRun(false, "timed out after {$this->timeoutSeconds}s");
            }

            return;
        }

        $exitCode = $this->childProcess->getExitCode();
        if ($exitCode === 0) {
            $this->finishRun(true, null);
        } else {
            $this->finishRun(false, 'child exited with code ' . ($exitCode ?? 'unknown'));
        }
    }

    /**
     * Fires each due agent-mechanism schedule entry into the guarded create path.
     *
     * Evaluated on the cluster leader (the agent is monopolistic). A due entry calls
     * {@see startBackup()}, which is skipped when a backup is already running, so an overlap is
     * dropped rather than queued. Each rule checks at most once per minute ({@see CronRule}).
     */
    private function checkSchedule(): void
    {
        foreach ($this->agentSchedule as $job) {
            if ($job->rule->shouldRun()) {
                $this->startBackup($job->scope);
            }
        }
    }

    /**
     * Builds one cron rule per agent-mechanism schedule entry, paired with its scope.
     *
     * Each rule seeds its lastRun to the current minute on construction, so a (re)started or
     * newly promoted leader never fires a missed slot as a catch-up burst.
     */
    private function buildAgentCronRules(): void
    {
        $this->agentSchedule = [];
        foreach ($this->schedule?->agentEntries() ?? [] as $entry) {
            $this->agentSchedule[] = new BackupCronJob(
                new CronRule($entry->name, $entry->expression),
                $entry->scope,
            );
        }
    }

    /**
     * Finalizes a completed run: records success or failure, refreshes the index, and unlocks.
     *
     * @param bool $success Whether the child exited cleanly
     * @param ?string $failureReason Human-readable reason when the run failed
     */
    private function finishRun(bool $success, ?string $failureReason): void
    {
        $id = $this->currentBackupId ?? '';
        $scope = $this->currentScope;
        $durationSeconds = (int)round(microtime(true) - $this->startedAt);
        $stderr = $this->childProcess !== null ? trim($this->childProcess->getStdErr()) : '';

        if ($success) {
            $this->logAgentInfo("Backup {$id} completed in {$durationSeconds}s");
        } else {
            $detail = $stderr !== '' ? "{$failureReason}: {$stderr}" : (string)$failureReason;
            $this->logAgentError("Backup {$id} failed: {$detail}");
            if ($scope !== null) {
                $this->recordFailure($id, $scope, $durationSeconds);
            }
        }

        // Rescan storage either way: success adds the new archive, failure adds the error sidecar.
        $this->refreshHistory();
        if ($success) {
            // Rotation runs only after a successful create (HIL-273 also crons it); a failed
            // run added nothing prunable, so there is nothing to rotate.
            $this->pruneHistory();
        }
        $this->resetRun();
        $this->clearRuntime();
        $this->drainPendingCreate();
    }

    /**
     * Runs a manual create coalesced into the pending slot while the last run was busy.
     *
     * Called once the lock is released at the end of {@see finishRun()}: the slot holds at
     * most one scope (last manual request wins), so it starts exactly one follow-up backup.
     */
    private function drainPendingCreate(): void
    {
        if ($this->pendingScope === null) {
            return;
        }

        $scope = $this->pendingScope;
        $this->pendingScope = null;
        $this->logAgentInfo("Running pending backup create (scope={$scope->value})");
        $this->startBackup($scope);
    }

    /**
     * Delegates failure bookkeeping (partial-temp sweep + error sidecar) to the engine.
     *
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @param int $durationSeconds Wall-clock time consumed before failing
     */
    private function recordFailure(string $id, BackupScope $scope, int $durationSeconds): void
    {
        try {
            (new BackupCreator())->recordFailure($id, $scope, $durationSeconds);
        } catch (Throwable $e) {
            $this->logAgentError("Failed to record backup failure for {$id}: " . $e->getMessage());
        }
    }

    /**
     * Clears the in-flight run state back to idle (the lock is released).
     */
    private function resetRun(): void
    {
        $this->childProcess = null;
        $this->currentBackupId = null;
        $this->currentScope = null;
        $this->startedAt = 0.0;
        $this->timeoutSeconds = 0.0;
    }

    /**
     * Marks the runtime singleton as running and syncs the change to readers.
     *
     * @param string $id Backup id in progress
     * @param BackupScope $scope Scope in progress
     */
    private function markRuntimeRunning(string $id, BackupScope $scope): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        $state->running = true;
        $state->currentBackupId = $id;
        $state->scope = $scope->value;
        $state->startedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $state->sync();
    }

    /**
     * Clears the runtime singleton back to idle and syncs the change to readers.
     */
    private function clearRuntime(): void
    {
        $state = $this->runtimeState();
        if ($state === null) {
            return;
        }

        $state->running = false;
        $state->currentBackupId = null;
        $state->scope = null;
        $state->startedAt = null;
        $state->sync();
    }

    /**
     * Resolves the backup runtime singleton, or null when runtime state is unavailable.
     *
     * @return ?BackupRuntime Runtime singleton or null
     */
    private function runtimeState(): ?BackupRuntime
    {
        $state = Hilos::$rt?->getStateItem(BackupRuntime::RT_ITEM);

        return $state instanceof BackupRuntime ? $state : null;
    }

    /**
     * Rescans the storage tree and rebuilds the runtime index from the scanned sidecars.
     */
    private function refreshHistory(): void
    {
        $result = (new BackupHistoryScanner())->scan(Hilos::$env->string(EnvConstants::BACKUP_DIR));
        $this->rebuildHistory($result);
        $this->reportAnomalies($result);

        $this->logAgentInfo(sprintf(
            'Backup history rebuilt: %d entries, %d anomalies',
            count($result->metadatas),
            count($result->anomalies),
        ));
    }

    /**
     * Applies the retention policy to the runtime index: deletes pruned backups and their rows.
     *
     * Runs against the just-rebuilt index (files=truth, RT=index): {@see BackupPruner} plans the
     * keep-set, then each pruned backup's archive and sidecar are deleted and its index row is
     * dropped, so the runtime index stays the mirror of storage. Any failure is logged and
     * swallowed - rotation must never take down the daemon loop.
     */
    private function pruneHistory(): void
    {
        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return;
        }

        try {
            $rows = [];
            foreach ($histories as $row) {
                if ($row instanceof BackupHistory) {
                    $rows[] = $row;
                }
            }

            $pruner = new BackupPruner();
            $doomed = $pruner->selectForDeletion(
                $rows,
                BackupRetentionPolicy::fromEnv(),
                new DateTimeZone(date_default_timezone_get()),
            );
            if ($doomed === []) {
                return;
            }

            $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
            foreach ($doomed as $row) {
                $pruner->deleteStored($row, $root);
                $histories->remove($row->getId());
            }

            $this->logAgentInfo(sprintf('Backup rotation pruned %d entries', count($doomed)));
        } catch (Throwable $e) {
            $this->logAgentError('Backup rotation failed: ' . $e->getMessage());
        }
    }

    /**
     * Replaces the runtime backup index with the scanned metadata.
     *
     * The typed browser view/representation lands in HIL-278; until then the
     * monopoly agent owns the index directly on its registered state collection.
     *
     * @param BackupScanResult $result Scan result to project into runtime state
     */
    private function rebuildHistory(BackupScanResult $result): void
    {
        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return;
        }

        $histories->clear();
        foreach ($result->metadatas as $metadata) {
            $histories->add(BackupHistory::fromMetadata($metadata));
        }
    }

    /**
     * Logs each scan anomaly at its severity; anomalies never fail the scan.
     *
     * @param BackupScanResult $result Scan result whose anomalies are logged
     */
    private function reportAnomalies(BackupScanResult $result): void
    {
        foreach ($result->anomalies as $anomaly) {
            $message = "Backup scan anomaly [{$anomaly->type->value}] at {$anomaly->path}";
            if ($anomaly->type->isError()) {
                $this->logAgentError($message);
            } else {
                $this->logAgentWarning($message);
            }
        }
    }
}

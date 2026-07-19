<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupScanResult;
use Hilos\Backup\BackupScope;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Process;
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
 * one is in flight is skipped. The create trigger (cron HIL-273, list action HIL-333) calls
 * {@see startBackup()}; this class only owns the launch path and the lock.
 */
final class BackupAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_BACKUP;

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
     * Registers truth sources and rebuilds the runtime backup index, or no-ops when disabled.
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
    }

    /**
     * Polls the in-flight backup child: finalizes it on exit and kills it on timeout.
     *
     * The work here stays tiny per the onTick rule — a status poll plus, only at the very
     * end of a run, a storage rescan; the heavy dump lives in the spawned child.
     */
    public function onTick(): void
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
     * Starts one backup unless one is already running (the single-flight lock).
     *
     * Generates the id, marks the runtime running, and spawns the `backup:run` child. The
     * create trigger (HIL-273 cron, HIL-333 action) calls this; there is no in-class caller
     * yet. A second request while a backup runs is skipped and logged, never queued.
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
        $this->resetRun();
        $this->clearRuntime();
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

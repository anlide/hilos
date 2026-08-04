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
use Hilos\Backup\BackupSpaceDecision;
use Hilos\Backup\BackupSpaceGuard;
use Hilos\Backup\BackupSpacePolicy;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Process;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime;
use Hilos\Runtime\View\Collection\BackupHistories as BackupHistoriesView;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
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

    /**
     * Command-channel commands a CLI routes here, each driving the live agent so the runtime
     * index stays the mirror of storage (files=truth): a forced retention prune and a forced
     * scheduled backup, both test-only (HIL-320), and a plain index refresh, which is NOT -
     * the operator command `backup:verify` rewrites sidecars itself and then asks the agent to
     * catch up ({@see BackupConstants::REFRESH_HISTORY_COMMAND}). All three carry a plain
     * payload, so none declares an inner DTO.
     */
    public const array AGENT_COMMANDS = [
        BackupConstants::PRUNE_COMMAND,
        BackupConstants::RUN_SCHEDULE_COMMAND,
        BackupConstants::REFRESH_HISTORY_COMMAND,
    ];

    /** Child interpreter; matches the worker spine's binary ({@see WorkerServer}). */
    private const string PHP_BINARY = 'php';

    /** Longest failure detail kept in a user-facing notice ({@see failureNotice()}). */
    private const int NOTICE_DETAIL_LIMIT = 200;

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
     * Accept key of the connection that asked for the in-flight backup, or null when it
     * started unattended (schedule or CLI). Only a run with an initiator reports its failure
     * to anyone: an unattended run that fails is recorded in storage and the log, and nobody
     * is interrupted over it.
     */
    private ?string $currentInitiator = null;

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

    /** Accept key of the connection whose create is parked in {@see $pendingScope}. */
    private ?string $pendingInitiator = null;

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

        // Enabled but unconfigured is the silent-failure trap: the page and the schedule still
        // work, every create is refused by startBackup(), and the storage scan finds nothing -
        // so say it once, loudly, at the only moment an operator is reading the agent log.
        foreach (self::missingCreateConfig(
            Hilos::$env->string(EnvConstants::BACKUP_DIR),
            Hilos::$env->string(EnvConstants::BACKUP_CLI_ENTRY),
        ) as $key) {
            $this->logAgentError("Backups are enabled but {$key} is not configured; no backup can be created");
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
     * Handles the test-only command-channel commands routed here (HIL-320).
     *
     * Both force a time-based path that would otherwise wait out the clock: prune forces a
     * retention rotation, run-schedule forces a scheduled backup by name. Driving the live
     * agent (rather than mutating storage from the CLI) keeps the runtime index consistent
     * with truth. Every branch answers exactly once via {@see replyToCommand()}.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case BackupConstants::PRUNE_COMMAND:
                $this->handlePruneCommand($data);

                return;

            case BackupConstants::RUN_SCHEDULE_COMMAND:
                $this->handleRunScheduleCommand($data);

                return;

            case BackupConstants::REFRESH_HISTORY_COMMAND:
                $this->handleRefreshHistoryCommand($data);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));

                return;
        }
    }

    /**
     * Forces a retention rotation now and replies with the number of backups pruned.
     *
     * Rescans storage first so the prune runs against a fresh index (the same refresh+prune
     * the create path performs after a successful run), then reports the pruned count.
     *
     * @param CommandRequestDTO $data Command request (no payload fields consumed)
     */
    private function handlePruneCommand(CommandRequestDTO $data): void
    {
        $this->refreshHistory();
        $prunedCount = $this->pruneHistory();

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            BackupConstants::FIELD_PRUNED_COUNT => $prunedCount,
        ]));
    }

    /**
     * Re-mirrors the runtime index from storage and replies with the row count it now holds.
     *
     * Asked for by an operator command that changed sidecars on disk itself (`backup:verify`
     * stamps a verification into them). The agent is the single writer of the index, so the
     * catch-up has to happen here; the expensive part - hashing gigabytes - already happened in
     * the CLI process, and this is only the cheap rescan.
     *
     * Provisional: HIL-528 replaces the poke with filesystem watching, which covers every writer
     * instead of only the ones that know to ask.
     *
     * @param CommandRequestDTO $data Command request (no payload fields consumed)
     */
    private function handleRefreshHistoryCommand(CommandRequestDTO $data): void
    {
        $this->refreshHistory();

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            BackupConstants::FIELD_HISTORY_COUNT => count($this->indexRows()),
        ]));
    }

    /**
     * Forces the scheduled backup named in the payload and replies with its id and scope.
     *
     * Resolves the schedule entry name (defaulting to the fallback daily-full name when the
     * payload omits it) to a scope and funnels through the guarded {@see startBackup()}. An
     * unknown name, a busy single-flight lock, or a create that could not start each reply
     * with an error; the reply is immediate (the backup runs asynchronously in the child).
     *
     * @param CommandRequestDTO $data Command request carrying the optional schedule name
     */
    private function handleRunScheduleCommand(CommandRequestDTO $data): void
    {
        $name = (string)($data->payload[BackupConstants::FIELD_SCHEDULE_NAME] ?? '');
        if ($name === '') {
            $name = BackupConstants::DEFAULT_SCHEDULE_NAME;
        }

        $scope = $this->schedule?->scopeForName($name);
        if ($scope === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown backup schedule entry: {$name}"));

            return;
        }

        if ($this->childProcess !== null) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                "Backup busy: {$this->currentBackupId}",
            ));

            return;
        }

        $this->startBackup($scope);
        if ($this->currentBackupId === null) {
            // startBackup skipped the launch (backups disabled or the child failed to spawn).
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Backup did not start'));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            BackupConstants::FIELD_BACKUP_ID => $this->currentBackupId,
            BackupConstants::FIELD_SCOPE => $scope->value,
        ]));
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
            $this->pendingInitiator = $data->initiatorAcceptKey;
            $this->logAgentInfo("Backup busy; queued pending create (scope={$scope->value})");

            return;
        }

        $this->startBackup($scope, $data->initiatorAcceptKey);
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
            new BackupPruner()->deleteStored($row, Hilos::$env->string(EnvConstants::BACKUP_DIR));
            // Stamp the requester as the origin of the index write so its own row
            // removal applies at once while other tabs keep the pending gate.
            ExecutionContext::withAcceptKey(
                $data->initiatorAcceptKey,
                fn () => $this->historiesView()?->actions->forget($id),
            );
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
            new BackupCreator()->setStoredKeep($row, Hilos::$env->string(EnvConstants::BACKUP_DIR), $data->keep);
            // Re-mirror the index from the rewritten sidecar (files=truth): the cleared +
            // recreated rows carry the new keep pin to every reader over RT sync. Stamp the
            // requester as the origin so its own row update applies at once, other tabs gate.
            ExecutionContext::withAcceptKey($data->initiatorAcceptKey, fn () => $this->refreshHistory());
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
     * Refused before anything is allocated when backups are disabled or a required setting is
     * missing ({@see missingCreateConfig()}), so a misconfigured install never shows a phantom
     * running row for a child that cannot work.
     *
     * @param BackupScope $scope What the backup should capture
     * @param ?string $initiatorAcceptKey Connection to tell when the run fails, or null when unattended
     */
    public function startBackup(BackupScope $scope, ?string $initiatorAcceptKey = null): void
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

        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        $cliEntry = Hilos::$env->string(EnvConstants::BACKUP_CLI_ENTRY);
        $missing = self::missingCreateConfig($root, $cliEntry);
        if ($missing !== []) {
            $this->logAgentError('Cannot start backup: missing configuration (' . implode(', ', $missing) . ')');

            return;
        }

        // A backup must not fill the disk it protects. Prune first so space freed by rotation counts
        // toward this run (rotation ran only after a successful run before, a deadlock when the disk
        // was already full), then refuse up front a run that will not fit. A refusal is recorded like
        // a failure below and spawns no child. Prune is swallow-and-log; the gate never crashes the
        // tick (an unmeasurable filesystem or an unreadable policy proceeds).
        $this->pruneHistory();
        if (!$this->admitBySpace($scope, $root, $initiatorAcceptKey)) {
            return;
        }

        $id = self::generateBackupId(new DateTimeImmutable());
        $this->currentBackupId = $id;
        $this->currentScope = $scope;
        $this->currentInitiator = $initiatorAcceptKey;
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
     * Reports which settings the create path needs but does not have.
     *
     * Both are hard preconditions, checked before a run is ever started: an empty
     * `BACKUP_CLI_ENTRY` leaves nothing to spawn, and an empty `BACKUP_DIR` is the documented
     * "storage off" state ({@see EnvConstants::BACKUP_DIR}) under which a child could only die on
     * the missing root - and, worse, its failure could not be recorded either, because the error
     * sidecar goes to that same root. Refusing up front keeps the runtime flag, the id, and the
     * child from ever existing for a run that cannot produce an outcome.
     *
     * Pure (values in, key names out) so the precondition is unit-testable without a live env.
     *
     * @param string $backupDir Configured storage root (`BACKUP_DIR`)
     * @param string $cliEntry Configured child CLI entry (`BACKUP_CLI_ENTRY`)
     * @return list<string> Names of the unconfigured env keys; empty when a backup may start
     */
    public static function missingCreateConfig(string $backupDir, string $cliEntry): array
    {
        $missing = [];
        if ($backupDir === '') {
            $missing[] = EnvConstants::BACKUP_DIR->name;
        }
        if ($cliEntry === '') {
            $missing[] = EnvConstants::BACKUP_CLI_ENTRY->name;
        }

        return $missing;
    }

    /**
     * Decides whether a run may proceed against free space, recording a refusal as a failure.
     *
     * Measures free space, asks the pure {@see BackupSpaceGuard} to admit or refuse, and on a
     * refusal allocates an id and writes an error record ({@see refuseBackup()}) so the run
     * explains itself in the backup list rather than vanishing. Two states never stop a backup,
     * matching the "keep taking backups" bias: a filesystem whose free space cannot be measured,
     * and an unreadable space policy - both are logged and treated as admit. Returns true when the
     * caller should go on to spawn the child.
     *
     * @param BackupScope $scope Scope of the run being decided
     * @param string $root Backup storage root (its filesystem is the one measured)
     * @param ?string $initiator Connection to tell when a refusal is recorded, or null when unattended
     * @return bool True to proceed with the run; false when it was refused and recorded
     */
    private function admitBySpace(BackupScope $scope, string $root, ?string $initiator): bool
    {
        $freeBytes = $this->freeSpaceAt($root);
        if ($freeBytes === false) {
            $this->logAgentError("Cannot measure free space at {$root}; proceeding with backup");

            return true;
        }

        try {
            $decision = new BackupSpaceGuard()->decide(
                $freeBytes,
                $this->indexRows(),
                $scope,
                BackupSpacePolicy::fromEnv(),
            );
        } catch (Throwable $e) {
            $this->logAgentError('Cannot evaluate backup space policy; proceeding with backup: ' . $e->getMessage());

            return true;
        }

        if ($decision->allowed) {
            return true;
        }

        $this->refuseBackup($scope, $initiator, $decision);

        return false;
    }

    /**
     * Records a refused run: allocates an id and writes an error sidecar with the guard's reason.
     *
     * Takes the same recording path a real failure does ({@see recordFailure()}), with a zero
     * duration and no child, so the refusal is a bounded error row that explains itself through the
     * HIL-429 failure-detail modal. A manual initiator still gets the one-line ACTION_ERROR toast.
     *
     * @param BackupScope $scope Scope of the refused run
     * @param ?string $initiator Connection to tell, or null when the run was unattended
     * @param BackupSpaceDecision $decision Refusal verdict carrying the ready-made reason
     */
    private function refuseBackup(BackupScope $scope, ?string $initiator, BackupSpaceDecision $decision): void
    {
        $id = self::generateBackupId(new DateTimeImmutable());
        $reason = $decision->reason ?? 'insufficient free space';
        $this->logAgentError("Backup {$id} refused: {$reason}");
        $this->sendFailureNotice($initiator, $id, $reason);
        $this->recordFailure($id, $scope, 0, $reason);
        // Index the error sidecar just written so the refusal appears in the backup list at once.
        $this->refreshHistory();
    }

    /**
     * Free bytes on the filesystem holding the backup root, or false when it cannot be measured.
     *
     * Walks up to the nearest existing ancestor when the root has not been created yet (a first
     * backup on a fresh install), because `disk_free_space()` needs an existing path. A false
     * result is "cannot measure", which the caller treats as proceed, not stop.
     *
     * @param string $path Backup storage root
     * @return int|false Free bytes, or false when no existing ancestor can be measured
     */
    private function freeSpaceAt(string $path): int|false
    {
        $dir = $path;
        while ($dir !== '' && !is_dir($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if ($dir === '' || !is_dir($dir)) {
            return false;
        }

        $free = @disk_free_space($dir);

        return $free === false ? false : (int)$free;
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
            $this->notifyInitiatorOfFailure($id, $detail);
            if ($scope !== null) {
                $this->recordFailure($id, $scope, $durationSeconds, $detail);
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
     * Its requester travels with it, so a queued create still reports its own failure.
     */
    private function drainPendingCreate(): void
    {
        if ($this->pendingScope === null) {
            return;
        }

        $scope = $this->pendingScope;
        $initiator = $this->pendingInitiator;
        $this->pendingScope = null;
        $this->pendingInitiator = null;
        $this->logAgentInfo("Running pending backup create (scope={$scope->value})");
        $this->startBackup($scope, $initiator);
    }

    /**
     * Tells the connection that asked for this run that it failed.
     *
     * This is not the create action's reply — that was sent at acceptance, because a dump
     * outlives any request timeout. It is an addressed, uncorrelated action_error the client
     * keeps as that action's latest failure, so the requester learns the reason instead of
     * watching a table that never grows a row.
     *
     * An unattended run (schedule, CLI) has no initiator and tells nobody: a nightly backup
     * failing is a record in the backup list, not an interruption for whoever is online.
     *
     * @param string $id Backup id that failed
     * @param string $detail Failure detail, already carrying the child's stderr when it had any
     */
    private function notifyInitiatorOfFailure(string $id, string $detail): void
    {
        $this->sendFailureNotice($this->currentInitiator, $id, $detail);
    }

    /**
     * Sends the addressed create-failure toast to an initiator, if there is one.
     *
     * Shared by a failed run ({@see notifyInitiatorOfFailure()}) and an up-front refusal
     * ({@see refuseBackup()}). An unattended run (schedule, CLI) has no initiator and tells nobody.
     *
     * @param ?string $initiator Connection to tell, or null when the run was unattended
     * @param string $id Backup id the notice is about
     * @param string $detail Failure detail (first line is shown, capped)
     */
    private function sendFailureNotice(?string $initiator, string $id, string $detail): void
    {
        if ($initiator === null) {
            return;
        }

        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $initiator,
            new PageActionErrorSignalData(
                HilosSignalConstants::BACKUP_CREATE,
                self::failureNotice($id, $detail),
            ),
        );
    }

    /**
     * Builds the one-line failure notice the requester is shown.
     *
     * A child's stderr can be a wall of text while a notice is one sentence, so only its first
     * line survives, capped; the whole detail stays in the agent error log for diagnosis. Pure
     * so the wording and the cap are unit-testable.
     *
     * @param string $id Backup id that failed
     * @param string $detail Raw failure detail
     * @return string Human-readable one-line notice
     */
    public static function failureNotice(string $id, string $detail): string
    {
        $firstLine = trim(explode("\n", $detail, 2)[0]);
        if (mb_strlen($firstLine) > self::NOTICE_DETAIL_LIMIT) {
            $firstLine = mb_substr($firstLine, 0, self::NOTICE_DETAIL_LIMIT - 1) . '…';
        }

        return $firstLine === '' ? "Backup {$id} failed" : "Backup {$id} failed: {$firstLine}";
    }

    /**
     * Delegates failure bookkeeping (partial-temp sweep + error sidecar) to the engine.
     *
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @param int $durationSeconds Wall-clock time consumed before failing
     * @param ?string $failureReason Assembled failure detail (reason + child stderr), stored on the error record
     */
    private function recordFailure(string $id, BackupScope $scope, int $durationSeconds, ?string $failureReason): void
    {
        try {
            new BackupCreator()->recordFailure($id, $scope, $durationSeconds, $failureReason);
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
        $this->currentInitiator = null;
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
        $state->startedAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);
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
     * Rescans the storage tree and brings the runtime index in line with it.
     */
    private function refreshHistory(): void
    {
        $result = new BackupHistoryScanner()->scan(Hilos::$env->string(EnvConstants::BACKUP_DIR));
        $changes = $this->historiesView()?->actions->syncToScan($result->metadatas) ?? 0;
        $this->reportAnomalies($result);

        $this->logAgentInfo(sprintf(
            'Backup index synced: %d entries, %d changed, %d anomalies',
            count($result->metadatas),
            $changes,
            count($result->anomalies),
        ));
    }

    /**
     * Snapshots the runtime backup index as a plain list of state rows.
     *
     * The single reader used by both rotation ({@see pruneHistory()}) and the space estimate
     * ({@see admitBySpace()}): the RT collection is walked once, non-backup entries skipped, so
     * both consumers see the same set. Empty when runtime state or the collection is unavailable.
     *
     * @return list<BackupHistory> Current backup index rows
     */
    private function indexRows(): array
    {
        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return [];
        }

        $rows = [];
        foreach ($histories as $row) {
            if ($row instanceof BackupHistory) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Applies the retention policy to the runtime index: deletes pruned backups and their rows.
     *
     * Runs against the just-rebuilt index (files=truth, RT=index): {@see BackupPruner} plans the
     * keep-set, then each pruned backup's archive and sidecar are deleted and its index row is
     * dropped, so the runtime index stays the mirror of storage. Any failure is logged and
     * swallowed - rotation must never take down the daemon loop.
     *
     * @return int Number of backups pruned this pass (0 when nothing was rotated or on failure)
     */
    private function pruneHistory(): int
    {
        $histories = Hilos::$rt?->getStateCollection(BackupHistory::RT_COLLECTION);
        if (!$histories instanceof BackupHistories) {
            return 0;
        }

        try {
            $pruner = new BackupPruner();
            $doomed = $pruner->selectForDeletion(
                $this->indexRows(),
                BackupRetentionPolicy::fromEnv(),
                new DateTimeZone(date_default_timezone_get()),
                new DateTimeImmutable(),
            );
            if ($doomed === []) {
                return 0;
            }

            $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
            $view = $this->historiesView();
            $pruned = [];
            foreach ($doomed as $row) {
                $pruner->deleteStored($row, $root);
                $view?->actions->forget($row->getId());
                $pruned[] = $row->getId();
            }

            $this->logAgentInfo(sprintf(
                'Backup rotation pruned %d entries: %s',
                count($pruned),
                implode(', ', $pruned),
            ));

            return count($doomed);
        } catch (Throwable $e) {
            $this->logAgentError('Backup rotation failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Resolves the backup index's runtime representation, or null when it is not bound.
     *
     * Every index write goes through this view's actions rather than the state collection:
     * the actions are what put a create / update / delete on the RT sync wire, and this agent
     * runs on its own monopolistic worker while the backup page is served by another. Written
     * any other way the index would exist in this process and nowhere else — no other worker,
     * and no browser table, would ever see a backup.
     *
     * Null means the project registered the state collection but never bound the framework
     * representation to it ({@see RtContext::setRepresent()}); the backup page would be
     * permanently empty, so say so rather than write into the void.
     *
     * @return ?BackupHistoriesView Backup index view, or null when unrepresented
     */
    private function historiesView(): ?BackupHistoriesView
    {
        $collection = Hilos::$rt?->{BackupHistory::RT_COLLECTION} ?? null;
        if ($collection instanceof BackupHistoriesView) {
            return $collection;
        }

        $this->logAgentError(
            'Backup index has no runtime representation: register it with setRepresent('
            . BackupHistory::RT_COLLECTION
            . ', BackupHistories::class, BackupHistoriesActions::class, BackupHistoryActions::class)',
        );

        return null;
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

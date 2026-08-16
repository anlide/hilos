<?php

declare(strict_types=1);

namespace Hilos\Backup\Agent;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hilos\Auth\Session\SessionCarrier;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Auth\Session\SessionIdentityRef;
use Hilos\Backup\Agent\DTO\BackupCreateSignalData;
use Hilos\Backup\Agent\DTO\BackupDeleteSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreProgressSignalData;
use Hilos\Backup\Agent\DTO\BackupRestoreSignalData;
use Hilos\Backup\Agent\DTO\BackupSetKeepSignalData;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupEstimator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgressMarker;
use Hilos\Backup\BackupPruner;
use Hilos\Backup\BackupRetentionPolicy;
use Hilos\Backup\BackupScanResult;
use Hilos\Backup\BackupSchedule;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupSpaceDecision;
use Hilos\Backup\BackupSpaceGuard;
use Hilos\Backup\BackupSpacePolicy;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\Ship\BackupShipCommand;
use Hilos\Backup\Ship\BackupShipPlan;
use Hilos\Backup\Ship\BackupShipPlanner;
use Hilos\Backup\Ship\BackupShipStep;
use Hilos\Backup\Ship\BackupShipTarget;
use Hilos\Backup\Ship\BackupShipperFactory;
use Hilos\Backup\Ship\BackupShipperInterface;
use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreNotifier;
use Hilos\Backup\RestorePhase;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ProcessException;
use Hilos\Database\DatabaseException;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Environment\Exception\EnvException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Process;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Actions\Item\RestoreRuntimeActions;
use Hilos\Runtime\View\Collection\BackupHistories;
use Hilos\Runtime\View\Item\BackupHistory;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
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
 *
 * And it supervises the hot restore path (HIL-274). A `backup:restore` CLI asks over the
 * command channel ({@see handleRestoreRequest()}) and the backup page asks over an agent signal
 * ({@see handleRestoreSignal()}, HIL-276) - both through the one admission
 * ({@see admitRestore()}); the agent freezes the node through
 * protected mode, spawns the `backup:restore-run` child once the freeze is ready
 * ({@see onProtectedModeReady()}), polls it under {@see EnvConstants::BACKUP_RESTORE_TIMEOUT},
 * and lifts the freeze when the run ends ({@see finishRestore()}). Create and restore share
 * the one child slot, so the monopoly lock keeps them mutually exclusive by construction;
 * {@see BackupRunKind} routes the poll's finish.
 */
final class BackupAgent extends AbstractAgent
{
    use ProtectedModeOperatorTrait;

    public const string AGENT_TYPE = HilosAgentType::HILOS_BACKUP;

    /**
     * Page → agent routes for the list-page actions (HIL-333, restore HIL-276). All four are
     * singleton signals (the agent is monopolistic), so each maps straight to its payload DTO.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::BACKUP_AGENT_CREATE => BackupCreateSignalData::class,
        HilosSignalConstants::BACKUP_AGENT_DELETE => BackupDeleteSignalData::class,
        HilosSignalConstants::BACKUP_AGENT_SET_KEEP => BackupSetKeepSignalData::class,
        HilosSignalConstants::BACKUP_AGENT_RESTORE => BackupRestoreSignalData::class,
    ];

    /**
     * Command-channel commands a CLI routes here, each driving the live agent so the runtime
     * index stays the mirror of storage (files=truth): a forced retention prune and a forced
     * scheduled backup, both test-only (HIL-320), and a plain index refresh, which is NOT -
     * the operator command `backup:verify` rewrites sidecars itself and then asks the agent to
     * catch up ({@see BackupConstants::REFRESH_HISTORY_COMMAND}). The restore pair (HIL-274)
     * is operator-facing too: request admits a run under protected mode, status snapshots the
     * restore runtime row for the CLI monitor. All carry plain payloads, so none declares an
     * inner DTO.
     *
     * The protected-mode trio (HIL-481) is operator-facing as well, and lands here rather than
     * anywhere else for one reason: a restore is the destructive operation this framework
     * actually has, so this agent is the initiator the freeze row records - and only that agent
     * may drive it ({@see ProtectedModeOperatorTrait}).
     */
    public const array AGENT_COMMANDS = [
        BackupConstants::PRUNE_COMMAND,
        BackupConstants::SHIP_COMMAND,
        BackupConstants::RUN_SCHEDULE_COMMAND,
        BackupConstants::REFRESH_HISTORY_COMMAND,
        BackupConstants::RESTORE_REQUEST_COMMAND,
        BackupConstants::RESTORE_STATUS_COMMAND,
        CliCommands::PROTECTED_MODE_PASS,
        CliCommands::PROTECTED_MODE_OPEN,
        CliCommands::PROTECTED_MODE_CLOSE,
    ];

    /** Child interpreter; matches the worker spine's binary ({@see WorkerServer}). */
    private const string PHP_BINARY = 'php';

    /** Longest failure detail kept in a user-facing notice ({@see failureNotice()}). */
    private const int NOTICE_DETAIL_LIMIT = 200;

    /** Protected-mode operation name a restore freeze is requested under. */
    private const string RESTORE_OPERATION = 'restore';

    /**
     * How long the test-only synchronous shipping pass sleeps between polls of its transfer.
     *
     * Only that pass blocks; the production path polls once per tick and never sleeps at all.
     */
    private const int SHIP_POLL_MICROSECONDS = 20_000;

    /**
     * Seconds an accepted restore may wait for its freeze before the wait is expired.
     * Generous against a real cluster quiesce (workers drain in well under a minute), tiny
     * against the alternative: a dropped enable wedging the subsystem until agent restart.
     */
    private const int RESTORE_FREEZE_WAIT_SECONDS = 60;

    /**
     * Seconds this agent waits for the re-hydrate barrier before finishing the run without it.
     *
     * Not the daemon's timeout under another name: the daemon bounds how long it waits for the
     * processes, this bounds how long the agent waits for the daemon, and it is deliberately the
     * looser of the two so the daemon's own verdict - which names who is missing - normally
     * arrives first. Reaching this one means no answer came at all.
     */
    private const int REHYDRATE_WAIT_SECONDS = 120;

    /** The in-flight backup child, or null when idle (the single-flight lock). */
    private ?Process $childProcess = null;

    /**
     * The in-flight transfer child, or null when nothing is being copied off the machine.
     *
     * Its own slot, separate from {@see childProcess}: a slow link must not delay the next backup,
     * and a transfer must not occupy the lock a restore needs. The two never contend for anything
     * but the tick that polls them both.
     */
    private ?Process $shipProcess = null;

    /** What that transfer is doing, or null when none is in flight. */
    private ?BackupShipPlan $shipPlan = null;

    /** Instant (microtime) the in-flight transfer was spawned; 0.0 when none is. */
    private float $shipStartedAt = 0.0;

    /**
     * @var array<string, float> When the last transfer of each backup id - and of each mirror
     *     scope, under {@see BackupShipPlanner::MIRROR_ATTEMPT_PREFIX} - was SPAWNED. Held in
     *     memory rather than stored: it throttles retries, and after a restart the first pass may
     *     as well try everything again.
     */
    private array $shipAttemptAt = [];

    /**
     * Whether something was deleted locally since the receiver was last brought in line.
     *
     * The remote is a mirror, so both deletion paths raise this through {@see markMirrorDirty()};
     * the mirror pass itself lowers it once every scope has been re-stated since.
     */
    private bool $mirrorDirty = false;

    /** Whether an unusable shipping destination has already been reported; it is a standing state. */
    private bool $shipTargetReported = false;

    /**
     * The part of the child's last stdout chunk that had no line break yet.
     *
     * A pipe hands over whatever happened to be written by the time the tick read it, so a phase
     * announcement routinely arrives in two pieces. Kept here, the second piece finds the first.
     */
    private string $childProgressTail = '';

    /** What the in-flight child is doing, or null when idle. */
    private ?BackupRunKind $runKind = null;

    /** Id of a restore accepted but not yet spawned (waiting for the freeze), or null. */
    private ?string $pendingRestoreId = null;

    /** Scope of the accepted restore, or null when none is pending. */
    private ?BackupScope $pendingRestoreScope = null;

    /** Recorded ENV guard verdict of the accepted restore, or null when none is pending. */
    private ?RestoreEnvDecision $pendingRestoreDecision = null;

    /**
     * Accept key of the connection that asked for the restore, or null when nobody is watching.
     *
     * It outlives the admission for the length of the run, because it is the only address the
     * operation has: protected mode keeps this one connection alive through the freeze
     * ({@see ProtectedModeOperatorTrait}), the progress frames are sent to it, and so is the
     * refusal when the agent turns the run away after the page accepted it. A CLI or scheduled
     * restore leaves it null and is watched by nobody.
     */
    private ?string $pendingRestoreInitiator = null;

    /**
     * @var ?list<SessionIdentityRef> Identity pairs of the person who asked for the restore, read at
     *     admission; null when no restore is in flight, empty when nobody asked or they could not be
     *     read. Photographed for the same reason the sessions are, and kept in memory beside them:
     *     the numeric user id does not survive the swap ({@see SessionIdentityRef}), so this is the
     *     only thing left to recognize the initiator by once the announcement is due (HIL-279).
     */
    private ?array $pendingRestoreInitiatorIdentities = null;

    /** Child timeout (seconds) of the accepted restore, read at admission; 0.0 when none is pending. */
    private float $pendingRestoreTimeout = 0.0;

    /** Monotonic-ish instant (microtime) the pending restore was admitted; 0.0 when none is pending. */
    private float $pendingRestoreSince = 0.0;

    /**
     * @var ?list<SessionCarryover> Live sessions photographed before the restore child ran, or null
     *     when no restore is in flight. Held in memory on purpose: it is the one thing about the
     *     pre-restore world that must outlive the database it was read from.
     */
    private ?array $pendingCarryover = null;

    /**
     * Deadline (microtime) by which the re-hydrate barrier must answer; 0.0 when none is open.
     *
     * The wait outlives a tick, so the run's finalization is split around it: the child is over,
     * but the restore is not, and nothing may be let back in until every process has re-read the
     * database that was just put underneath them (HIL-436). The deadline is this agent's own
     * insurance - the daemon has one too, and this one covers the case where the daemon does not
     * answer at all.
     */
    private float $rehydrateDeadline = 0.0;

    /** Whether the restore child of the pending barrier exited cleanly; meaningless when none is open. */
    private bool $rehydrateChildSucceeded = false;

    /** Why that child failed, or null when it did not; meaningless when no barrier is open. */
    private ?string $rehydrateFailureDetail = null;

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
     * @throws EnvException When a backup env value is missing or cannot be read as its type
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

        $this->registerRtTruthSource(StateBackupHistory::RT_COLLECTION);
        $this->registerRtTruthSource(StateBackupRuntime::RT_ITEM);
        $this->registerRtTruthSource(StateRestoreRuntime::RT_ITEM);

        $this->refreshHistory();

        $this->schedule = BackupSchedule::fromCatalog();
        $this->buildAgentCronRules();
    }

    /**
     * Polls any in-flight backup child, answers any operator command in flight, then fires any
     * due agent-mechanism schedule entry.
     *
     * Polling runs first so a run that finished this tick frees the lock before the schedule
     * is checked, letting a coincident scheduled fire start rather than be skipped as an overlap.
     * The operator command follows it for the same reason: a restore that ends on this very tick
     * asks for the verification window, and answering afterwards reports the row it produced.
     *
     * Shipping is polled after the run and inside a catch-all, because it is the one thing here
     * that reaches off the machine: a receiver that is down, gone, or answering nonsense must cost
     * a log line and nothing else. It is also why it runs after the create poll rather than before
     * - a backup that just finished is a candidate this same tick.
     *
     * @throws ProcessException When the running child cannot be polled, read or terminated
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws HilosException Whatever finishing a restore that ended on this tick raises
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
     */
    public function onTick(): void
    {
        $this->pollRunningBackup();

        try {
            $this->pollShipping();
        } catch (Throwable $e) {
            $this->logAgentError('Backup shipping pass failed: ' . $e->getMessage());
        }

        $this->expireStaleRehydrate();
        $this->tickProtectedModeOperator();
        $this->checkSchedule();
    }

    /**
     * Kills any in-flight child and clears the runtime flag on shutdown.
     *
     * A restore engaged at this moment is finished as failed, so a monitor still polling learns
     * how the run ended, and its freeze is moved on through the one finalizer.
     *
     * **It is not lifted, and that is the point of HIL-481:** a restore that died halfway is the
     * last thing allowed to reopen a half-written database by itself. The node is left closed
     * for a human to look at, which also means a stop that lands before the freeze even
     * completed leaves it frozen where it stood - the verify is fail-closed on the active phase.
     * {@see CliCommands::PROTECTED_MODE_OPEN} is the way out of every such phase, and the same
     * agent type answers it after a restart, because the row records an identity rather than an
     * instance.
     *
     * @throws ProcessException When the in-flight child cannot be halted
     * @throws HilosException Whatever finishing the engaged restore as failed raises
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
     */
    public function onStop(): void
    {
        if ($this->childProcess !== null) {
            $this->childProcess->halt();
        }
        // A killed transfer loses nothing: the local archive is untouched, the sidecar still says
        // the copy has not landed, and the next pass starts it over.
        if ($this->shipProcess !== null) {
            $this->shipProcess->halt();
        }
        if ($this->restoreEngaged()) {
            // The pending create slot is dropped first: the finalizer drains it into
            // startBackup, and a stopping agent must not spawn a child nobody will poll.
            $this->pendingScope = null;
            $this->pendingInitiator = null;
            // Only when the child half has not run yet. Past it the outcome is already recorded
            // and only the barrier is outstanding, and putting the run through the finalizer a
            // second time would re-announce the swap and overwrite what the child actually did
            // with "stopped during restore".
            if (!$this->awaitingRehydrate()) {
                // A restore admitted but not yet spawned holds the freeze under its pending id
                // alone, so the finalizer is given that id the same way expireStalePendingRestore()
                // gives it: without one it refuses to run, and the node stays frozen with nobody
                // left to lift it.
                $this->currentBackupId ??= $this->pendingRestoreId;
                // Through the one finalizer, so a stopping agent records the outcome and lifts
                // the freeze exactly like any other failed run. Only a child that actually ran
                // can have been writing: a restore still waiting for its freeze touched nothing,
                // which is the same answer expireStalePendingRestore() gives in that state.
                $this->finishRestore(
                    false,
                    'backup agent stopped during restore',
                    databaseTouched: $this->childProcess !== null,
                );
            }
            // Whether the barrier was already open or the finalizer just opened it, an agent that
            // is going away cannot wait for it: settled here as unclosed, so the node stays shut -
            // the same fail-closed answer a timeout would have given.
            if ($this->awaitingRehydrate()) {
                $this->completeRestore(false, ['backup agent stopped before every process re-read']);
            }
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
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
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
     * Handles the command-channel commands routed here.
     *
     * The test-only pair (HIL-320) forces a time-based path that would otherwise wait out
     * the clock: prune forces a retention rotation, run-schedule forces a scheduled backup
     * by name. Refresh re-mirrors the index for `backup:verify`. The restore pair (HIL-274)
     * admits a restore run and snapshots its progress. Driving the live agent (rather than
     * mutating storage from the CLI) keeps the runtime index consistent with truth. The
     * protected-mode trio (HIL-481) is how an operator ends the window a finished restore left
     * the system in. Every branch answers exactly once via {@see replyToCommand()}.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws ClusterConfigurationException When the restore request cannot read the cluster layout
     * @throws InvalidArgumentException When the handler cannot name its reply to the command
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if ($this->isProtectedModeOperatorCommand($data->command)) {
            $this->handleProtectedModeOperatorCommand($data);

            return;
        }

        switch ($data->command) {
            case BackupConstants::SHIP_COMMAND:
                $this->handleShipCommand($data);

                return;

            case BackupConstants::PRUNE_COMMAND:
                $this->handlePruneCommand($data);

                return;

            case BackupConstants::RUN_SCHEDULE_COMMAND:
                $this->handleRunScheduleCommand($data);

                return;

            case BackupConstants::REFRESH_HISTORY_COMMAND:
                $this->handleRefreshHistoryCommand($data);

                return;

            case BackupConstants::RESTORE_REQUEST_COMMAND:
                $this->handleRestoreRequest($data);

                return;

            case BackupConstants::RESTORE_STATUS_COMMAND:
                $this->handleRestoreStatus($data);

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
     * Runs a whole shipping pass to completion right here and replies with what it moved.
     *
     * Test-only, and the one place shipping is synchronous. The pass exists so an integration run
     * can assert that an archive really landed on a receiver without waiting for ticks; blocking
     * the agent for the length of it is acceptable exactly because no production path calls it.
     *
     * The pass keeps its OWN attempt map rather than the agent's: the retry interval is there to
     * pace a failing link across ticks, and applying it here would make a second forced pass in
     * the same agent's life silently do nothing.
     *
     * @param CommandRequestDTO $data Command request (no payload fields consumed)
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws InvalidArgumentException When the reply to the command cannot be named
     */
    private function handleShipCommand(CommandRequestDTO $data): void
    {
        $shipped = 0;
        $failed = 0;
        $mirrorFailed = false;
        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        $shipper = $this->shipper();

        if ($shipper !== null && $root !== '') {
            $planner = new BackupShipPlanner();
            $attempts = [];
            $now = microtime(true);

            while (true) {
                $plan = $planner->plan($this->indexRows(), $root, $attempts, $this->mirrorDirty, $now);
                if ($plan === null) {
                    break;
                }

                $attempts[self::shipAttemptKey($plan)] = $now;
                $error = $this->shipStepNow($shipper, $planner, $plan);

                if ($plan->step === BackupShipStep::MIRROR) {
                    $mirrorFailed = $mirrorFailed || $error !== null;

                    continue;
                }
                if ($error === null) {
                    $shipped++;
                } else {
                    $failed++;
                }
            }

            // A mirror that did not go through leaves the deletion owed, so the flag stays up for
            // the ticking agent to carry: clearing it here would drop the deletion on the floor
            // because a test-only pass happened to run while the receiver was down.
            if (!$mirrorFailed) {
                $this->mirrorDirty = false;
            }
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            BackupConstants::FIELD_SHIPPED_COUNT => $shipped,
            BackupConstants::FIELD_SHIP_FAILED_COUNT => $failed,
        ]));
    }

    /**
     * Runs one planned transfer to completion, its sidecar half included, and records the outcome.
     *
     * @param BackupShipperInterface $shipper Driver for the configured destination
     * @param BackupShipPlanner $planner Planner the sidecar half is derived from
     * @param BackupShipPlan $plan Transfer to run
     * @return ?string Failure detail, or null when the backup reached the destination whole
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     */
    private function shipStepNow(
        BackupShipperInterface $shipper,
        BackupShipPlanner $planner,
        BackupShipPlan $plan,
    ): ?string {
        $timeout = (float)Hilos::$env->int(EnvConstants::BACKUP_SHIP_TIMEOUT);
        $command = $plan->step === BackupShipStep::MIRROR
            ? $shipper->mirrorCommand($plan->localPath, $plan->scope)
            : $shipper->pushCommand($plan->localPath, $plan->scope);

        $error = $this->runToCompletion($command, $timeout);

        if ($plan->step === BackupShipStep::MIRROR) {
            if ($error !== null) {
                $this->logAgentWarning("Backup mirror of scope {$plan->scope} failed: {$error}");
            }

            return $error;
        }

        if ($error === null && $plan->step === BackupShipStep::PUSH_ARCHIVE) {
            $sidecar = $planner->sidecarStep($plan);
            $error = $this->runToCompletion($shipper->pushCommand($sidecar->localPath, $sidecar->scope), $timeout);
        }

        $this->recordShipOutcome($plan, $error === null, $error);

        return $error;
    }

    /**
     * Spawns one transfer and blocks until it exits, is killed, or overruns its timeout.
     *
     * @param BackupShipCommand $command Transfer to spawn
     * @param float $timeoutSeconds Seconds after which the transfer is killed
     * @return ?string Failure detail, or null when it exited cleanly
     */
    private function runToCompletion(BackupShipCommand $command, float $timeoutSeconds): ?string
    {
        try {
            $process = new Process($command->binary, $command->args);
        } catch (Throwable $e) {
            return 'failed to spawn ' . $command->binary . ': ' . $e->getMessage();
        }

        $startedAt = microtime(true);

        try {
            while (true) {
                $process->tick();
                if ($process->getStatus()[Process::STATUS_RUNNING] !== true) {
                    return $this->exitError($process);
                }
                if (microtime(true) - $startedAt >= $timeoutSeconds) {
                    $process->stop();
                    $process->halt();

                    return "timed out after {$timeoutSeconds}s";
                }

                usleep(self::SHIP_POLL_MICROSECONDS);
            }
        } catch (Throwable $e) {
            return 'transfer could not be polled: ' . $e->getMessage();
        }
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
        // external-boundary: the payload is the operator's command line, which may name no schedule
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
     * Admits one restore run asked for over the command channel (HIL-274).
     *
     * The operator's entrance to {@see admitRestore()}: it parses the command line's payload,
     * hands it over, and turns the answer into a command reply. That reply is immediate -
     * accepted or refused - because the freeze and the restore outlive any command-channel wait.
     *
     * @param CommandRequestDTO $data Command request carrying the backup id, scope and decision
     */
    private function handleRestoreRequest(CommandRequestDTO $data): void
    {
        // external-boundary: the payload is the operator's command line and a missing id is rejected below
        $id = (string)($data->payload[BackupConstants::FIELD_BACKUP_ID] ?? '');
        // external-boundary: the payload is the operator's command line and a missing scope is rejected below
        $scope = BackupScope::fromString((string)($data->payload[BackupConstants::FIELD_SCOPE] ?? ''));
        // external-boundary: the payload is the operator's command line and a missing decision is rejected below
        $decision = RestoreEnvDecision::tryFrom((string)($data->payload[BackupConstants::FIELD_DECISION] ?? ''));
        if ($id === '' || $scope === null || $decision === null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Malformed restore request'));

            return;
        }

        // No initiator: the requester is a CLI, not a browser connection, so the freeze has no
        // connection to keep alive and the run has nobody to report progress to.
        $refusal = $this->admitRestore($id, $scope, $decision, null, null);
        if ($refusal !== null) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, $refusal));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            BackupConstants::FIELD_BACKUP_ID => $id,
        ]));
    }

    /**
     * Admits one restore from the backup page: the second entrance to the CLI's path (HIL-276).
     *
     * The page has already validated the request against its restore gate and acked the
     * client, so what is left here is the part only the live agent knows - whether it is free -
     * plus the same backstops the command channel gets. A malformed payload is dropped with a
     * log rather than answered: the page builds this signal from its own validated row, so a
     * broken one is a framework defect and there is no operator to tell.
     *
     * @param BackupRestoreSignalData $data Restore request carrying the id, scope, verdict and initiator
     */
    private function handleRestoreSignal(BackupRestoreSignalData $data): void
    {
        $scope = BackupScope::fromString($data->scope);
        $decision = RestoreEnvDecision::tryFrom($data->decision);
        if ($data->backupId === '' || $scope === null || $decision === null) {
            $this->logAgentWarning("Ignoring malformed restore request for backup {$data->backupId}");

            return;
        }

        $refusal = $this->admitRestore(
            $data->backupId,
            $scope,
            $decision,
            $data->initiatorAcceptKey,
            $data->initiatorUserId,
        );
        if ($refusal !== null) {
            $this->sendRestoreRefusal($data->initiatorAcceptKey, $refusal);
        }
    }

    /**
     * Admits one restore run: parks it pending and asks the cluster to freeze.
     *
     * The single admission both entrances go through, so the CLI and the page cannot drift into
     * two sets of preconditions. It answers rather than replies - the caller owns the channel the
     * refusal travels on, a command reply for the CLI and an addressed action error for the page -
     * and everything it checks is checked in the same order it always was:
     *
     * - the single-flight lock, covering both kinds and both windows: a running child (create or
     *   restore) and a restore still waiting for its freeze each refuse a second admission;
     * - {@see RestoreEnvDecision::REFUSE} as a backstop. The matrix is authoritative where the
     *   request was validated (the CLI preflight, or the page's gate), and only the plainly
     *   un-actable verdict is re-checked here;
     * - every env value the run will need, read HERE, where a failure can still be an answer: the
     *   ready relay and the frozen node are no place for a first read of a key a project catalog
     *   may not carry.
     *
     * On accept the restore runtime row starts in {@see RestorePhase::PENDING} and the child is
     * spawned only once the freeze is ready ({@see onProtectedModeReady()}); the freeze and the
     * restore both outlive any reply window, which is why acceptance is answered immediately and
     * the outcome is not.
     *
     * @param string $id Backup id to restore
     * @param BackupScope $scope Scope the archive was captured under
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict for this archive/target pair
     * @param ?string $initiator Accept key of the connection that asked, or null when unattended
     * @param ?int $initiatorUserId User id behind that connection, or null when unattended
     * @return ?string Refusal reason, or null when the restore was admitted
     */
    private function admitRestore(
        string $id,
        BackupScope $scope,
        RestoreEnvDecision $decision,
        ?string $initiator,
        ?int $initiatorUserId,
    ): ?string {
        if ($this->childProcess !== null || $this->restoreEngaged()) {
            $busyId = $this->currentBackupId ?? $this->pendingRestoreId;

            return "Backup subsystem busy: {$busyId}";
        }
        if ($decision === RestoreEnvDecision::REFUSE) {
            return 'Restore refused by the environment guard';
        }

        try {
            $missing = self::missingCreateConfig(
                Hilos::$env->string(EnvConstants::BACKUP_DIR),
                Hilos::$env->string(EnvConstants::BACKUP_CLI_ENTRY),
            );
            $timeoutSeconds = (float)Hilos::$env->int(EnvConstants::BACKUP_RESTORE_TIMEOUT);
        } catch (EnvException $e) {
            return 'Cannot restore: ' . $e->getMessage();
        }
        if ($missing !== []) {
            return 'Cannot restore: missing configuration (' . implode(', ', $missing) . ')';
        }

        $this->pendingRestoreId = $id;
        $this->pendingRestoreScope = $scope;
        $this->pendingRestoreDecision = $decision;
        $this->pendingRestoreTimeout = $timeoutSeconds;
        $this->pendingRestoreSince = microtime(true);
        $this->pendingRestoreInitiator = $initiator;
        $this->pendingRestoreInitiatorIdentities = $this->captureInitiatorIdentities($initiatorUserId);
        $this->restoreView()?->actions->markRunning($id, $scope, $this->restoreEstimate($id, $scope));
        $this->reportRestoreProgress();
        if ($initiator === null) {
            // Empty accept key: the initiator is a CLI, not a browser connection, so the freeze
            // has no connection to keep alive on its behalf.
            $this->requestProtectedModeEnable(self::RESTORE_OPERATION, '');
        } else {
            // The tab that asked stays connected through the freeze - protected mode lets its
            // accept key through - because it is the one being shown the operation.
            $this->requestProtectedModeEnable(self::RESTORE_OPERATION, $initiator);
        }
        $this->logAgentInfo("Restore accepted: {$id} (scope={$scope->value}); requesting protected mode");

        return null;
    }

    /**
     * Tells the connection that asked for a restore that the agent turned it away.
     *
     * The page accepted the action and acked it, so a refusal decided here has no reply to ride
     * back on: it goes as an addressed, uncorrelated action error the client keeps as that
     * action's latest failure - the same device a failed create uses
     * ({@see sendFailureNotice()}), under this action's own name so the button that sent it is
     * the one that shows it. A CLI restore has no initiator and is answered by its command reply
     * instead.
     *
     * @param ?string $initiator Connection to tell, or null when the request came from a CLI
     * @param string $reason Why the run was refused, shown as it is
     */
    private function sendRestoreRefusal(?string $initiator, string $reason): void
    {
        if ($initiator === null) {
            return;
        }

        $this->sendToUser(
            SignalConstants::ACTION_ERROR,
            $initiator,
            new PageActionErrorSignalData(HilosSignalConstants::BACKUP_RESTORE, $reason),
        );
    }

    /**
     * Reports that the restore moved, to both parties that are waiting to hear it.
     *
     * Called at every point where the restore's runtime row changes, and it tells two different
     * audiences one fact:
     *
     * - the freeze's progress mark, so the watchdog watching this node can tell an operation that
     *   is legitimately long from one that hung (HIL-482). Sent first and unconditionally, because
     *   the run that most needs a watchdog is the unattended one the branch below returns on;
     * - the connection that asked for the run. The node is frozen while a restore runs: the page's
     *   own agent is stopped, so its table produces no deltas, and this addressed frame is the
     *   only thing moving on the initiator's screen. It carries the snapshot the CLI monitor is
     *   answered with, so the two views of one run cannot disagree.
     *
     * An unattended run (CLI, schedule) has no initiator and quietly sends nothing there, exactly
     * as {@see sendFailureNotice()} does - its progress is read from the row instead.
     *
     * @throws InvalidArgumentException When the progress mark cannot be named
     */
    private function reportRestoreProgress(): void
    {
        $this->reportProtectedModeProgress();

        $initiator = $this->pendingRestoreInitiator;
        if ($initiator === null) {
            return;
        }

        $view = $this->restoreView();
        if ($view === null) {
            return;
        }

        $this->sendToUser(
            HilosSignalConstants::BACKUP_RESTORE_PROGRESS,
            $initiator,
            BackupRestoreProgressSignalData::fromRuntime($view),
        );
    }

    /**
     * Replies with the restore runtime row's snapshot for the CLI monitor.
     *
     * @param CommandRequestDTO $data Command request (no payload fields consumed)
     */
    private function handleRestoreStatus(CommandRequestDTO $data): void
    {
        $view = $this->restoreView();
        if ($view === null) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Restore runtime row is not mounted',
            ));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $view->toArray()));
    }

    /**
     * Handles the list-page actions routed from the backup page (HIL-333).
     *
     * The page validates each request synchronously and acks the client; this owns the
     * storage mutation: create funnels through the guarded {@see startBackup()} (with an
     * in-memory pending slot when busy), delete through the shared delete path, set-keep
     * through an atomic sidecar rewrite plus an index re-mirror, and restore through the same
     * admission the `backup:restore` CLI goes through ({@see admitRestore()}).
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the signal name is not a backup list action
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws ClusterConfigurationException When the restore request cannot read the cluster layout
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
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

            case HilosSignalConstants::BACKUP_AGENT_RESTORE:
                if ($data->data instanceof BackupRestoreSignalData) {
                    $this->handleRestoreSignal($data->data);
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

        if ($this->childProcess !== null || $this->restoreEngaged()) {
            // Coalesce: the newest manual request wins the single pending slot. The current
            // run drains it in finishRun(); an intervening cron overlap still just skips.
            // A restore counts as busy for its whole length, not just while its child is alive:
            // it is engaged before the freeze and still engaged after the child exits, waiting
            // out the re-hydrate barrier (HIL-436). Without that half a request landing in those
            // windows would fall through to startBackup, be turned away by its restore guard and
            // be lost, instead of running the moment the restore lets go.
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

        $histories = $this->historiesView();
        if ($histories === null) {
            return;
        }

        $row = $histories[$id];
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
                fn () => $histories->actions->forget($id),
            );
            // The receiver is a mirror: what left here has to leave there too. Raised after the
            // local delete succeeded, so a failure above never schedules a remote one.
            $this->markMirrorDirty();
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

        $histories = $this->historiesView();
        if ($histories === null) {
            return;
        }

        $row = $histories[$id];
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
     * Spawns the restore child once the cluster freeze is ready (HIL-274).
     *
     * Reached over the daemon->worker ready relay after {@see handleRestoreRequest()} asked
     * for the freeze. A ready signal with no restore pending is ignored (this agent only
     * requests protected mode for restores today), as is a duplicate ready while the child
     * already runs. Spawn failure finishes the run as failed, which also lifts the freeze -
     * the node must never stay frozen for a child that never lived.
     *
     * This is also where the live sessions are photographed (HIL-479): the node is frozen, so
     * the set of connections is final, and the database the sessions live in is still the old
     * one. A moment later the child starts replacing it.
     *
     * @throws RtActionsCollectionNameNullException When the restore row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent is not the row's truth source
     * @throws HilosException Whatever finishing a restore whose child never started raises
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
     */
    public function onProtectedModeReady(): void
    {
        $id = $this->pendingRestoreId;
        $scope = $this->pendingRestoreScope;
        $decision = $this->pendingRestoreDecision;
        if ($id === null || $scope === null || $decision === null) {
            return;
        }
        if ($this->childProcess !== null || $this->awaitingRehydrate()) {
            // A duplicate ready relay must not spawn a second child over the running one - nor
            // over one that is already done. The run outlives its child now (HIL-436): between
            // that child's exit and the barrier's verdict the slot stands free while the restore
            // is not over, and a child spawned there would replay the archive over the database
            // the first one just wrote.
            $this->logAgentWarning("Ignoring duplicate protected-mode ready for restore {$id}");

            return;
        }

        $this->currentBackupId = $id;
        $this->currentScope = $scope;
        $this->startedAt = microtime(true);
        $this->timeoutSeconds = $this->pendingRestoreTimeout;
        $this->runKind = BackupRunKind::RESTORE;
        $this->pendingCarryover = $this->captureSessions();
        // The phase the child opens with, marked before it can say so itself: from here on the
        // child announces each one on its stdout and {@see consumeChildProgress()} follows along.
        // A child too old to announce anything therefore reports the truth up to this point
        // rather than standing in PENDING for the whole run.
        $this->restoreView()?->actions->markPhase(RestorePhase::VERIFYING);
        $this->reportRestoreProgress();

        try {
            $this->childProcess = new Process(
                self::PHP_BINARY,
                self::buildRestoreChildArgs(
                    Hilos::$env->string(EnvConstants::BACKUP_CLI_ENTRY),
                    $id,
                    $scope,
                    $decision,
                ),
            );
        } catch (Throwable $e) {
            // A child that never started wrote nothing.
            $this->finishRestore(false, 'failed to spawn child: ' . $e->getMessage(), databaseTouched: false);

            return;
        }

        $this->logAgentInfo("Restore started: {$id} (scope={$scope->value})");
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
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
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

        if ($this->restoreEngaged()) {
            // Covers the freeze window too: an accepted restore has no child yet, but the node
            // is about to be frozen for it, and a create spawned now would run into the freeze.
            $this->logAgentWarning(
                "Restore engaged ({$this->pendingRestoreId}); skipping new {$scope->value} request",
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
        $this->runKind = BackupRunKind::CREATE;
        $this->markRuntimeRunning($id, $scope, BackupEstimator::createSeconds($this->indexRows(), $scope));

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

        // warning-suppressed: false means "not measured" and the caller keeps the backup going
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
     * Polls the in-flight child: finalizes it on exit and kills it on timeout.
     *
     * The work here stays tiny per the onTick rule — a status poll plus, only at the very
     * end of a run, a storage rescan; the heavy dump or import lives in the spawned child.
     * The timeout budget was captured at spawn from the kind's own env key, so one poll
     * serves both kinds; only the finish routes by {@see BackupRunKind}.
     *
     * @throws InvalidArgumentException When the progress mark cannot be named
     * @throws RtActionsCollectionNameNullException When the row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent is not the row's truth source
     */
    private function pollRunningBackup(): void
    {
        if ($this->childProcess === null) {
            $this->expireStalePendingRestore();

            return;
        }

        $this->childProcess->tick();
        $this->consumeChildProgress($this->childProcess->getStdOut());

        if ($this->childProcess->getStatus()[Process::STATUS_RUNNING] === true) {
            if (microtime(true) - $this->startedAt >= $this->timeoutSeconds) {
                $this->childProcess->stop();
                $this->childProcess->halt();
                // No exit code to read from a child we killed, and a killed restore is assumed to
                // have been writing: the pessimistic half of that guess costs a look, the
                // optimistic half costs a database nobody checked.
                $this->finishChild(false, "timed out after {$this->timeoutSeconds}s", null);
            }

            return;
        }

        $exitCode = $this->childProcess->getExitCode();
        if ($exitCode === 0) {
            $this->finishChild(true, null, $exitCode);
        } else {
            $this->finishChild(false, 'child exited with code ' . ($exitCode ?? 'unknown'), $exitCode);
        }
    }

    /**
     * Drives the off-machine copy: ticks a transfer in flight, or starts the next one.
     *
     * One transfer at a time and never more, so a narrow link is used for the newest restore point
     * rather than shared between everything at once.
     *
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws ProcessException When the transfer child cannot be polled, read or terminated
     */
    private function pollShipping(): void
    {
        if ($this->shipProcess !== null) {
            $this->tickShipping();

            return;
        }

        $this->startNextShipStep();
    }

    /**
     * Polls the transfer in flight and finishes it when it exits or overruns its timeout.
     *
     * @throws EnvException When the transfer timeout cannot be read
     * @throws ProcessException When the transfer child cannot be polled, read or terminated
     */
    private function tickShipping(): void
    {
        if ($this->shipProcess === null) {
            return;
        }

        $this->shipProcess->tick();

        if ($this->shipProcess->getStatus()[Process::STATUS_RUNNING] === true) {
            $timeout = (float)Hilos::$env->int(EnvConstants::BACKUP_SHIP_TIMEOUT);
            if (microtime(true) - $this->shipStartedAt >= $timeout) {
                $this->shipProcess->stop();
                $this->shipProcess->halt();
                $this->finishShipStep("timed out after {$timeout}s");
            }

            return;
        }

        $this->finishShipStep($this->exitError($this->shipProcess));
    }

    /**
     * How a finished transfer child failed, read off its exit code and stderr.
     *
     * @param Process $process Transfer child that has stopped running
     * @return ?string Failure detail, or null when it exited cleanly
     * @throws ProcessException When the child's streams cannot be read
     */
    private function exitError(Process $process): ?string
    {
        $exitCode = $process->getExitCode();
        if ($exitCode === 0) {
            return null;
        }

        $stdErr = trim($process->getStdErr());
        if ($stdErr === '') {
            return 'transfer exited with code ' . ($exitCode ?? 'unknown');
        }

        return $stdErr;
    }

    /**
     * Closes out the transfer that just ended, recording what it means for the backup.
     *
     * A finished archive step spawns its sidecar step straight away rather than going back to the
     * planner: the pair is one publish, and leaving the gap open for a tick would let a rotation
     * or a restart strand an archive on the receiver with no sidecar beside it.
     *
     * @param ?string $error Failure detail, or null when the transfer succeeded
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws ProcessException When the follow-up transfer child cannot be started
     */
    private function finishShipStep(?string $error): void
    {
        $plan = $this->shipPlan;
        $this->shipProcess = null;
        $this->shipPlan = null;
        $this->shipStartedAt = 0.0;

        if ($plan === null) {
            return;
        }

        // The attempt was stamped when this transfer was spawned, not here: rsync builds its file
        // list at the start, so a delete that lands mid-transfer is NOT in the pass now ending,
        // and a stamp written now would claim otherwise and swallow it.
        if ($plan->step === BackupShipStep::MIRROR) {
            if ($error !== null) {
                // Never fatal, by design: an unreachable receiver must not stop rotation from
                // freeing the disk it protects.
                $this->logAgentWarning("Backup mirror of scope {$plan->scope} failed: {$error}");
            }

            return;
        }

        if ($error !== null) {
            $this->recordShipOutcome($plan, false, $error);

            return;
        }

        if ($plan->step === BackupShipStep::PUSH_ARCHIVE) {
            $shipper = $this->shipper();
            if ($shipper !== null) {
                $this->spawnShipStep($shipper, new BackupShipPlanner()->sidecarStep($plan));
            }

            return;
        }

        $this->recordShipOutcome($plan, true, null);
    }

    /**
     * Records that something was deleted locally and owes the receiver a mirror pass.
     *
     * Clearing the mirror marks is the half that is easy to leave out and impossible to notice:
     * a scope carrying its mark is one the planner reads as already re-stated, and a delete
     * landing after that pass would be told "this scope was just mirrored" - true, and about a
     * directory that still held the file. Dropping the marks says instead that every scope is
     * owed a fresh look, which is what a delete means, and the pass still ends by itself once
     * each has had one.
     */
    private function markMirrorDirty(): void
    {
        $this->mirrorDirty = true;

        foreach (array_keys($this->shipAttemptAt) as $key) {
            if (str_starts_with($key, BackupShipPlanner::MIRROR_ATTEMPT_PREFIX)) {
                unset($this->shipAttemptAt[$key]);
            }
        }
    }

    /**
     * Asks the planner for the next transfer and spawns it, or ends the mirror pass.
     *
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws ProcessException When the transfer child cannot be started
     */
    private function startNextShipStep(): void
    {
        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        if ($root === '') {
            return;
        }

        $shipper = $this->shipper();
        if ($shipper === null) {
            return;
        }

        $plan = new BackupShipPlanner()->plan(
            $this->indexRows(),
            $root,
            $this->shipAttemptAt,
            $this->mirrorDirty,
            microtime(true),
        );

        if ($plan === null) {
            // Nothing to push and every scope re-stated since the last delete: the receiver is
            // in line, and the next local delete is what raises the flag again.
            $this->mirrorDirty = false;

            return;
        }

        $this->spawnShipStep($shipper, $plan);
    }

    /**
     * Spawns one transfer and takes the slot.
     *
     * @param BackupShipperInterface $shipper Driver for the configured destination
     * @param BackupShipPlan $plan Transfer to run
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     */
    private function spawnShipStep(BackupShipperInterface $shipper, BackupShipPlan $plan): void
    {
        $command = $plan->step === BackupShipStep::MIRROR
            ? $shipper->mirrorCommand($plan->localPath, $plan->scope)
            : $shipper->pushCommand($plan->localPath, $plan->scope);

        // Stamped before the spawn, so the mark names when this transfer started looking at the
        // store rather than when it stopped. A transfer that failed after running for a while is
        // owed an immediate retry; one that failed instantly is what the interval paces.
        $this->shipAttemptAt[self::shipAttemptKey($plan)] = microtime(true);

        try {
            // cwd stays null: rsync is given absolute paths on both sides.
            $this->shipProcess = new Process($command->binary, $command->args);
        } catch (Throwable $e) {
            $this->shipProcess = null;
            $this->recordShipOutcome($plan, false, 'failed to spawn ' . $command->binary . ': ' . $e->getMessage());

            return;
        }

        $this->shipPlan = $plan;
        $this->shipStartedAt = microtime(true);
    }

    /**
     * Writes the outcome of a backup's copy into its sidecar and its index row.
     *
     * Both halves get the SAME error text - the sidecar caps it, and the capped value is what the
     * index row records - so the Copy column and the file never disagree over what went wrong.
     * A mirror step records nothing: it is about a directory, not a backup.
     *
     * @param BackupShipPlan $plan Transfer whose outcome is being recorded
     * @param bool $succeeded Whether the copy reached the destination
     * @param ?string $error Failure detail, or null on success
     * @throws EnvException When the storage root cannot be read
     */
    private function recordShipOutcome(BackupShipPlan $plan, bool $succeeded, ?string $error): void
    {
        $histories = $this->historiesView();
        if ($histories === null || $plan->backupId === null) {
            return;
        }

        $row = $histories[$plan->backupId];
        if ($row === null) {
            return;
        }

        // A failed attempt keeps whatever instant an earlier success left: the column reads the
        // failure, and the record still says when this backup was last actually copied.
        $shippedAt = $succeeded
            ? new DateTimeImmutable()->format(DateTimeInterface::ATOM)
            : $row->shippedAt;
        $outcome = $succeeded ? BackupShipOutcome::OK : BackupShipOutcome::FAILED;

        try {
            $stored = new BackupCreator()->recordShipping(
                $row,
                Hilos::$env->string(EnvConstants::BACKUP_DIR),
                $shippedAt,
                $outcome,
                $error,
            );
            $row->actions->recordShipping($shippedAt, $outcome->value, $stored);
        } catch (Throwable $e) {
            $this->logAgentError("Failed to record shipping of backup {$plan->backupId}: " . $e->getMessage());

            return;
        }

        if ($succeeded) {
            $this->logAgentInfo("Backup shipped: {$plan->backupId}");

            return;
        }

        $this->logAgentWarning("Backup {$plan->backupId} could not be shipped: " . ($stored ?? 'no detail'));
    }

    /**
     * The driver for the configured destination, or null when this installation ships nowhere.
     *
     * An unusable destination is reported once and then treated as "off": it is a standing
     * configuration state, and a line per tick would bury the agent log it is written to.
     *
     * @return ?BackupShipperInterface Driver, or null when shipping is off or unusable
     * @throws EnvException When a shipping env value is missing or cannot be read as its type
     */
    private function shipper(): ?BackupShipperInterface
    {
        $target = BackupShipTarget::fromEnv();
        if ($target === null) {
            $this->reportUnusableShipTarget(
                'is set but is not a destination this framework can parse '
                . '(expected ssh://<user>@<host>[:<port>]/<path> or file:///<path>)',
            );

            return null;
        }

        $shipper = BackupShipperFactory::fromTarget($target);
        if ($shipper === null) {
            $this->reportUnusableShipTarget(
                "names scheme '{$target->scheme}', which no driver serves "
                . '(an ssh destination also needs ' . EnvConstants::BACKUP_SHIP_SSH_KNOWN_HOSTS->name . ')',
            );

            return null;
        }

        return $shipper;
    }

    /**
     * Says once that a configured destination cannot be used, staying silent when there is none.
     *
     * @param string $detail What is wrong with the value, as a phrase following the key name
     * @throws EnvException When the destination value cannot be read
     */
    private function reportUnusableShipTarget(string $detail): void
    {
        if ($this->shipTargetReported) {
            return;
        }
        // An empty destination is the documented "shipping off" state, not a misconfiguration.
        if (Hilos::$env->string(EnvConstants::BACKUP_SHIP_TARGET) === '') {
            return;
        }

        $this->shipTargetReported = true;
        $this->logAgentError(EnvConstants::BACKUP_SHIP_TARGET->name . ' ' . $detail . '; backups stay on this machine');
    }

    /**
     * The key one transfer throttles its retries under.
     *
     * @param BackupShipPlan $plan Transfer to key
     * @return string Backup id, or the prefixed scope of a mirror pass
     */
    private static function shipAttemptKey(BackupShipPlan $plan): string
    {
        return $plan->backupId ?? BackupShipPlanner::MIRROR_ATTEMPT_PREFIX . $plan->scope;
    }

    /**
     * Puts whatever phase announcements the child has printed into the runtime row.
     *
     * The chunk is passed in rather than read here: the tick already drains the child's stdout
     * into the process buffer, and taking it at the one call site keeps the pipe out of this
     * method - which is what lets the phase channel be tested without a live child.
     *
     * Anything that is not a phase of the run in flight is dropped without a word: the child's own
     * output, an unknown token, a create phase arriving during a restore. A phase equal to the one
     * already on the row is dropped too, because the row's instant is what a progress bar measures
     * from and re-stamping it would walk the bar backwards inside its own phase.
     *
     * A restore child that wrote anything at all also refreshes the freeze's progress mark, which
     * is a separate question from what it wrote: the mark answers "is the operation behind this
     * freeze still moving", and output on the pipe says yes whether or not it names a phase. The
     * mark is therefore taken from the chunk rather than from the parsed phases - most of what a
     * restore prints is its own chatter, and a child stuck importing one enormous table would
     * announce no phase for an hour while plainly working. Marking as often as the pipe delivers
     * costs a row write on a node that is serving nobody, which is what a freeze means.
     *
     * @param string $chunk Whatever the child wrote to stdout since the last tick
     * @throws InvalidArgumentException When the progress mark cannot be named
     * @throws RtActionsCollectionNameNullException When the row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent is not the row's truth source
     */
    private function consumeChildProgress(string $chunk): void
    {
        $read = BackupProgressMarker::read($this->childProgressTail . $chunk);
        $this->childProgressTail = $read->tail;

        if ($chunk !== '' && $this->runKind === BackupRunKind::RESTORE) {
            $this->reportProtectedModeProgress();
        }

        if ($read->phases === []) {
            return;
        }

        if ($this->runKind === BackupRunKind::RESTORE) {
            $this->applyRestorePhases($read->phases);

            return;
        }

        $view = $this->runtimeView();
        if ($view === null) {
            return;
        }

        foreach ($read->phases as $value) {
            $phase = BackupPhase::tryFrom($value);
            if ($phase === null || $phase->value === $view->phase) {
                continue;
            }

            $view->actions->markPhase($phase);
        }
    }

    /**
     * Puts the phases a restore child announced on the restore runtime row.
     *
     * Each accepted phase is also pushed to the connection that asked for the restore: protected
     * mode stopped the page's agent, so the addressed frame is the only channel that tab has left
     * (HIL-276).
     *
     * @param list<string> $values Phase values the child announced, in order
     * @throws InvalidArgumentException When the progress mark cannot be named
     * @throws RtActionsCollectionNameNullException When the row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this agent is not the row's truth source
     */
    private function applyRestorePhases(array $values): void
    {
        $view = $this->restoreView();
        if ($view === null) {
            return;
        }

        foreach ($values as $value) {
            $phase = RestorePhase::tryFrom($value);
            if ($phase === null || $phase->value === $view->phase) {
                continue;
            }

            $view->actions->markPhase($phase);
            $this->reportRestoreProgress();
        }
    }

    /**
     * How long the restore of one stored archive is expected to take.
     *
     * The size comes from the index row of the archive being restored, because the estimate is a
     * speed times a size: an archive twice as large is promised twice the time. An archive with no
     * row - a restore of a file that arrived outside the index - simply has no estimate.
     *
     * @param string $id Backup id being restored
     * @param BackupScope $scope Scope the archive was captured under
     * @return ?int Estimated seconds, or null when the archive or the history says nothing
     */
    private function restoreEstimate(string $id, BackupScope $scope): ?int
    {
        $histories = $this->historiesView();
        if ($histories === null) {
            return null;
        }

        $row = $histories[$id];
        if ($row === null) {
            return null;
        }

        return BackupEstimator::restoreSeconds($this->indexRows(), $scope, $row->sizeBytes);
    }

    /**
     * Expires a restore that was admitted but whose freeze never became ready.
     *
     * The enable request can be dropped without a word to this agent (no known leader, a
     * stale freeze already in flight, an unmounted protected-mode row are all log-and-return
     * paths in the switch), and nothing else bounds the wait: the child timeout arms only at
     * spawn. Left alone, the pending state would suppress the schedule and refuse every
     * create and restore until an agent restart, while the CLI monitor polls a PENDING row
     * forever. Expiring finishes the run as failed through the one finalizer, which also
     * sends the disable — a no-op warning on a node that never froze, the needed lift on one
     * that froze after the deadline.
     */
    private function expireStalePendingRestore(): void
    {
        if ($this->pendingRestoreId === null || $this->runKind === BackupRunKind::RESTORE) {
            return;
        }
        if (microtime(true) - $this->pendingRestoreSince < self::RESTORE_FREEZE_WAIT_SECONDS) {
            return;
        }

        $this->currentBackupId = $this->pendingRestoreId;
        $this->startedAt = $this->pendingRestoreSince;
        $this->runKind = BackupRunKind::RESTORE;
        $this->finishRestore(
            false,
            'protected mode never became ready within ' . self::RESTORE_FREEZE_WAIT_SECONDS . 's',
            // Nothing was ever spawned, so nothing can have been written.
            databaseTouched: false,
        );
    }

    /**
     * Routes a finished child to its kind's finalizer.
     *
     * @param bool $success Whether the child exited cleanly
     * @param ?string $failureReason Human-readable reason when the run failed
     * @param ?int $exitCode Exit code the child reported, or null when it was killed or never read
     */
    private function finishChild(bool $success, ?string $failureReason, ?int $exitCode): void
    {
        if ($this->runKind === BackupRunKind::RESTORE) {
            $this->finishRestore($success, $failureReason, self::restoreTouchedDatabase($exitCode));
        } else {
            $this->finishRun($success, $failureReason);
        }
    }

    /**
     * Whether a finished restore child had begun replacing the database.
     *
     * One exit code means "it did not" and everything else means "assume it did", including a
     * child that was killed and left no code at all (HIL-436). The asymmetry is deliberate: being
     * told a database may be half-overwritten when it is not costs a look at it, while the reverse
     * costs a production database nobody checked.
     *
     * Public because it is one half of a contract with the project's restore child: that command
     * chooses the exit code, this reads it, and a test that pins them apart would pass while they
     * drifted (the sibling contract {@see buildRestoreChildArgs()} is public for the same reason).
     *
     * @param ?int $exitCode Exit code the child reported, or null when it was killed
     * @return bool True unless the child reported that it stopped before the first destructive step
     */
    public static function restoreTouchedDatabase(?int $exitCode): bool
    {
        return $exitCode !== BackupConstants::RESTORE_EXIT_DATABASE_INTACT;
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
        if ($this->restoreEngaged()) {
            // A scheduled create must not race a restore through the freeze window; the
            // slot fires again on its next cron match once the restore is over.
            return;
        }

        foreach ($this->agentSchedule as $job) {
            if ($job->rule->shouldRun()) {
                $this->startBackup($job->scope);
            }
        }
    }

    /**
     * Whether a restore currently owns the subsystem: accepted and awaiting its freeze,
     * or already running as the in-flight child.
     *
     * @return bool True while a restore is pending or running
     */
    private function restoreEngaged(): bool
    {
        return $this->pendingRestoreId !== null || $this->runKind === BackupRunKind::RESTORE;
    }

    /**
     * Whether a restore is waiting for its re-hydrate barrier to answer.
     *
     * @return bool True between the child's exit and the barrier's verdict
     */
    private function awaitingRehydrate(): bool
    {
        return $this->rehydrateDeadline > 0.0;
    }

    /**
     * Finalizes a restore run: records its outcome, lifts the freeze, and unlocks.
     *
     * The restore row keeps its terminal snapshot (outcome, failure reason, finish time)
     * rather than being cleared: the CLI monitor learns how the run ended from exactly this
     * row, possibly polling seconds after the child died. The next accepted restore resets
     * it through {@see RestoreRuntimeActions::markRunning()}. No storage rescan: a restore
     * changes databases, not the archive store.
     *
     * **The freeze is moved on, not lifted** (HIL-481). This used to call
     * {@see AbstractAgent::requestProtectedModeDisable()} outside the branch on success, so a
     * restore that died mid-import opened a half-loaded database by itself. It now asks for the
     * verification window: the system stays closed to everyone, a hand-picked circle is let in by
     * pass to confirm it really came back, and only {@see CliCommands::PROTECTED_MODE_OPEN} - a
     * human - opens it.
     *
     * **The restore does not end where the SQL ends** (HIL-436), which is why this half stops at
     * the announcement. Every process on this node - and, in a cluster, every node - still holds
     * caches of the database that was just replaced, and a verifier let in to read those would be
     * confirming a fiction. So the run enters {@see RestorePhase::REHYDRATING}, asks everyone to
     * re-read, and {@see completeRestore()} finishes it when they have answered. Re-reading is
     * asked for on both branches, not only on success: a failed import may have left the database
     * half-rewritten, and re-reading one that was never touched is harmless.
     *
     * @param bool $success Whether the child exited cleanly
     * @param ?string $failureReason Human-readable reason when the run failed
     * @param bool $databaseTouched Whether the run got as far as writing to the database
     */
    private function finishRestore(bool $success, ?string $failureReason, bool $databaseTouched): void
    {
        $id = $this->currentBackupId;
        if ($id === null) {
            // A restore only finishes after one started, so this is a broken invariant
            // rather than an unnamed backup: an empty id would flow on into the log,
            // the initiator notice and the failure record.
            $this->logAgentError('Restore finished with no backup in progress');

            return;
        }

        $durationSeconds = (int)round(microtime(true) - $this->startedAt);
        $stderr = $this->childStdErr();

        if ($success) {
            $this->logAgentInfo("Restore {$id} replayed in {$durationSeconds}s; re-reading state");
            $this->rehydrateFailureDetail = null;
        } else {
            $detail = $stderr !== null ? "{$failureReason}: {$stderr}" : (string)$failureReason;
            $this->logAgentError("Restore {$id} failed: {$detail}");
            $this->rehydrateFailureDetail = $detail;
        }

        $this->rehydrateChildSucceeded = $success;
        $this->rehydrateDeadline = microtime(true) + self::REHYDRATE_WAIT_SECONDS;

        $view = $this->restoreView();
        $view?->actions->markDatabaseTouched($databaseTouched);
        $view?->actions->markRehydrating();
        $this->reportRestoreProgress();

        // The child slot is released here rather than in completeRestore(): its process is over,
        // and a poller that found it again would finalize the same run twice. Everything else the
        // second half needs - the id, the photographed sessions, the run kind that keeps a
        // scheduled create out of this window - stays until the barrier answers.
        $this->childProcess = null;

        try {
            $this->requestDbReHydrate();
        } catch (DatabaseException | LogicException $e) {
            // This process could not re-read the database it just replaced, so there is nobody to
            // wait for and nothing to open: settled here as unclosed, on the spot.
            $this->logAgentError("Restore {$id} could not re-read the database: {$e->getMessage()}");
            $this->completeRestore(false, ['backup agent: read failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Finishes a restore once every process has answered the re-hydrate announcement, or stopped.
     *
     * The half of {@see finishRestore()} that runs after the barrier, and the terminal outcome is
     * decided here rather than by the child's exit code: a restore whose SQL succeeded but whose
     * node did not finish re-reading has not come back, whatever the child said.
     *
     * **Fail-closed on an unclosed barrier.** The verification window is asked for only when every
     * process confirmed. Otherwise the node stays in the full freeze - closed even to verifiers -
     * with the offending processes named on the runtime row, and a human decides with the
     * protected-mode commands whether to open it anyway or to close it back and restore again.
     * When the barrier did close, the window is asked for however the run itself ended, for the
     * reason HIL-481 gave: a node left fully frozen has nobody else to move it on.
     *
     * @param bool $complete Whether every process confirmed re-reading the replaced database
     * @param list<string> $problems Processes that failed to re-read or never answered
     */
    private function completeRestore(bool $complete, array $problems): void
    {
        $this->rehydrateDeadline = 0.0;

        $id = $this->currentBackupId;
        if ($id === null) {
            $this->logAgentError('Restore re-hydration finished with no backup in progress');

            return;
        }

        $view = $this->restoreView();
        $view?->actions->markRehydrateOutcome($complete, $problems);

        if ($complete) {
            if ($this->rehydrateChildSucceeded) {
                // Only on success: carrying sessions over WRITES rows, and writing into a database
                // whose import was cut short would be building on top of the damage.
                $this->carryOverSessions();
            }

            try {
                $this->requestProtectedModeVerify();
            } catch (InvalidArgumentException $e) {
                // Documented by the request and unreachable in practice - the signal name is a
                // constant - but a throw escaping here would abandon the finalization halfway: the
                // outcome is already recorded and the node would stay fully frozen with nothing
                // left to move it on.
                $this->logAgentError("Restore {$id} could not open the verification window: {$e->getMessage()}");
            }
        } else {
            $this->logAgentError(
                "Restore {$id} left the node closed - not every process re-read the database: "
                . implode('; ', $problems),
            );
        }

        if ($this->rehydrateChildSucceeded && $complete) {
            $this->logAgentInfo("Restore {$id} completed");
            $this->recordRestoreHistory($id, $this->restoreElapsedSeconds());
            $view?->actions->finish(BackupStatus::SUCCESS);
        } else {
            $view?->actions->finish(BackupStatus::ERROR, $this->restoreFailureDetail($complete, $problems));
        }

        // The terminal frame goes out before the run is forgotten: it is the last thing the
        // initiator hears, and on a barrier that did not close it is the only thing that will
        // ever say so - the node stays frozen, so no reload follows to show the row instead.
        $this->reportRestoreProgress();

        $this->announceRestoreOutcome($id, $complete, $problems);

        $this->rehydrateFailureDetail = null;
        $this->resetRun();
        // A manual create coalesced while the restore held the child slot must not rot in
        // its slot until some unrelated later run happens to drain it.
        $this->drainPendingCreate();
    }

    /**
     * Announces the finished restore to the administrators and to whoever asked for it (HIL-279).
     *
     * Placed at the very end of the run rather than at its start, because a notification written
     * before the import is erased by the import: the row and its delivery queue live in the
     * database the archive replaces. Here the new database is in place and every process has
     * already re-read it, so the row lands in the world that will be read from - and on a barrier
     * that did not close it lands anyway, waiting in the queue until a human opens the node,
     * because that is the only trace the administrators will ever get.
     *
     * The state it needs is still in memory and is gone one statement later ({@see resetRun()}),
     * which is what fixes this call's position rather than taste.
     *
     * The span is the one every other surface of this run counts ({@see restoreElapsedSeconds()}):
     * from the admission, not from the spawn. A notification measuring the child alone would name a
     * shorter run than the page the reader is being sent to, and start it at a different instant
     * than the row does - for the same restore, in two places the same person reads.
     *
     * @param string $id Backup id that was replayed
     * @param bool $complete Whether every process confirmed re-reading the replaced database
     * @param list<string> $problems Processes that failed to re-read or never answered
     */
    private function announceRestoreOutcome(string $id, bool $complete, array $problems): void
    {
        $scope = $this->currentScope;
        if ($scope === null) {
            // The scope is written in the same breath as the id the caller has already checked, so
            // a missing one is a broken invariant. The run's own outcome is recorded either way;
            // only the announcement, which states what the archive held, cannot be composed.
            $this->logAgentError("Restore {$id} finished with no scope to announce");

            return;
        }

        new RestoreNotifier()->notifyOutcome(
            $id,
            $scope,
            $this->rehydrateChildSucceeded && $complete,
            $this->restoreFailureDetail($complete, $problems),
            date('Y-m-d H:i:s', (int)$this->pendingRestoreSince),
            $this->restoreElapsedSeconds(),
            $complete,
            $this->pendingRestoreInitiatorIdentities ?? [],
        );
    }

    /**
     * How long this restore has taken, from the point of view of whoever asked for it.
     *
     * Measured from ADMISSION rather than from the spawn: the two differ by the freeze, the
     * runtime row's `startedAt` is stamped at the former, and every surface counts its elapsed
     * from there. The number written back is what the next restore is estimated from, so it has
     * to cover the same span it will later be compared against - an estimate built over the
     * shorter one turns a perfectly normal restore into "taking longer than usual" a freeze
     * window early. The child's own replay time is a different figure and is only logged.
     *
     * @return int Seconds since the restore was admitted
     */
    private function restoreElapsedSeconds(): int
    {
        return (int)round(microtime(true) - $this->pendingRestoreSince);
    }

    /**
     * Writes this restore onto the archive it replayed: first the sidecar, then the index row.
     *
     * Only a restore that came back whole is recorded. A run that failed or left the node closed
     * measured something other than the work - and the number is read back as the speed the next
     * restore is promised by, so a wrong one is worse than none.
     *
     * The sidecar goes first because files are the truth and the index is its projection; the row
     * is updated beside it so readers do not wait for the next scan. Neither is worth taking the
     * finalization down for: a restore that came back is a success whether or not its own history
     * could be written, so a failure here is logged and the run ends as it would have.
     *
     * @param string $id Backup id of the archive that was replayed
     * @param int $durationSeconds How long the run took, in seconds
     */
    private function recordRestoreHistory(string $id, int $durationSeconds): void
    {
        $histories = $this->historiesView();
        if ($histories === null) {
            return;
        }

        $row = $histories[$id];
        if ($row === null) {
            $this->logAgentInfo("Restore {$id} is not in the index; its duration is not recorded");

            return;
        }

        $restoredAt = new DateTimeImmutable()->format(DateTimeInterface::ATOM);

        try {
            new BackupCreator()->recordRestore(
                $id,
                $row->env,
                $row->scope,
                Hilos::$env->string(EnvConstants::BACKUP_DIR),
                $restoredAt,
                $durationSeconds,
            );
            $row->actions->recordRestore($restoredAt, $durationSeconds);
        } catch (Throwable $e) {
            $this->logAgentError("Restore {$id} could not be recorded on its archive: {$e->getMessage()}");
        }
    }

    /**
     * Composes what the monitor shows for a restore that did not come back whole.
     *
     * A run can fail on either side of the barrier, and the two failures read differently: the
     * child's own reason is the story when it failed, and an unclosed barrier is the story when it
     * did not. Both can be true at once, and then both are said - a half-imported database whose
     * workers also never re-read is two problems, not one.
     *
     * @param bool $complete Whether every process confirmed re-reading the replaced database
     * @param list<string> $problems Processes that failed to re-read or never answered
     * @return string Operator-facing failure detail
     */
    private function restoreFailureDetail(bool $complete, array $problems): string
    {
        $barrier = $complete
            ? null
            : 'the database was replaced, but these did not re-read it: ' . implode('; ', $problems);

        return implode('; ', array_filter([$this->rehydrateFailureDetail, $barrier]));
    }

    /**
     * Settles a re-hydrate barrier the daemon never answered.
     *
     * The daemon bounds the wait itself and answers on its own deadline, so reaching this one means
     * the answer never arrived at all - a lost frame, or a daemon that stopped ticking. Waiting
     * forever is the one outcome that must not happen: the run would stay unfinished, the monitor
     * would poll a re-hydrating row that never moves, and the subsystem would refuse every later
     * backup and restore until the agent was restarted.
     */
    private function expireStaleRehydrate(): void
    {
        if (!$this->awaitingRehydrate() || microtime(true) < $this->rehydrateDeadline) {
            return;
        }

        $this->completeRestore(false, ['daemon: timeout']);
    }

    /**
     * Receives the aggregated verdict of the re-hydrate barrier this agent opened (HIL-436).
     *
     * A verdict arriving with no barrier open is dropped: the wait has already been settled by
     * {@see expireStaleRehydrate()}, and re-finishing a run that is over would report an outcome
     * twice and drain the pending create slot into a second child.
     *
     * @param DbReHydrateOutcome $outcome Whether every process re-read, and who did not
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     * @throws InvalidArgumentException When the failure notice to the initiator cannot be named
     */
    public function onDbReHydrateComplete(DbReHydrateOutcome $outcome): void
    {
        if (!$this->awaitingRehydrate()) {
            return;
        }

        $this->completeRestore($outcome->complete, $outcome->problems);
    }

    /**
     * Builds the restore child argv `[<cli>, backup:restore-run, <id>, --scope=, --decision=]`.
     *
     * The command name and options come from {@see BackupConstants} so this argv and the
     * project child command that parses it cannot drift apart - the same contract
     * {@see buildChildArgs()} keeps with `backup:run`.
     *
     * @param string $cliEntry Absolute path to the CLI entry script hosting `backup:restore-run`
     * @param string $id Backup id to restore
     * @param BackupScope $scope Scope the archive was captured under
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict the child acts on
     * @return list<string> Child process argv (after the php binary)
     */
    public static function buildRestoreChildArgs(
        string $cliEntry,
        string $id,
        BackupScope $scope,
        RestoreEnvDecision $decision,
    ): array {
        return [
            $cliEntry,
            BackupConstants::RESTORE_RUN_COMMAND,
            $id,
            '--' . BackupConstants::SCOPE_OPTION . '=' . $scope->value,
            '--' . BackupConstants::FIELD_DECISION . '=' . $decision->value,
        ];
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
        $id = $this->currentBackupId;
        if ($id === null) {
            // A run only finishes after one started, so this is a broken invariant
            // rather than an unnamed backup: an empty id would flow on into the log,
            // the initiator notice and the failure record.
            $this->logAgentError('Backup run finished with no backup in progress');

            return;
        }

        $scope = $this->currentScope;
        $durationSeconds = (int)round(microtime(true) - $this->startedAt);
        $stderr = $this->childStdErr();

        if ($success) {
            $this->logAgentInfo("Backup {$id} completed in {$durationSeconds}s");
        } else {
            $detail = $stderr !== null ? "{$failureReason}: {$stderr}" : (string)$failureReason;
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
     * Photographs the identities of the person who asked for the restore, before the swap.
     *
     * The numeric user id the page sent is worth nothing once the database under it is replaced -
     * the same id in another installation's archive belongs to somebody else - so it is turned
     * into (type, identifier) pairs here, while the database that knows the answer is still the
     * live one. After the restore those pairs are looked up in the new database, exactly as the
     * carried-over sessions are ({@see SessionCarrier}).
     *
     * Contained like the session snapshot: an announcement nobody could compose must not be the
     * reason a restore the operator asked for is refused before it starts.
     *
     * @param ?int $userId User id behind the requesting connection, or null when unattended
     * @return list<SessionIdentityRef> Identity pairs to recognize the initiator by (empty when none)
     */
    private function captureInitiatorIdentities(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        try {
            $references = [];
            foreach (Hilos::$db->identities->listByUser($userId) as $identity) {
                $references[] = new SessionIdentityRef($identity->type, $identity->identifier);
            }

            return $references;
        } catch (Throwable $e) {
            $this->logAgentError('Restore could not photograph the initiator identities: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Photographs the live authenticated sessions before the child replaces the database.
     *
     * A snapshot that cannot be read must not stop a restore the operator asked for: the cost
     * of an empty one is that people log in again, which is nothing beside a restore that
     * refuses to run. Failures are therefore contained here rather than propagated.
     *
     * @return list<SessionCarryover> Sessions to re-create after the swap (empty when none could be read)
     */
    private function captureSessions(): array
    {
        try {
            return SessionCarrier::capture();
        } catch (Throwable $e) {
            $this->logAgentError('Restore could not photograph live sessions: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Re-creates the sessions photographed before the database was replaced (HIL-479).
     *
     * The step lives in the window between a successful restore and the thaw, and its order is the
     * point: nothing may be written after the clients are told to reload, because the reloaded
     * browser looks its session up immediately.
     *
     * The re-hydrate announcement used to live here and has moved up to the finalizer (HIL-436):
     * it belongs to the swap, not to the sessions, so it has to happen on the failed branch too -
     * and it has to be waited for, which this step cannot do. By the time this runs, every process
     * has already confirmed re-reading, so the rows written here land on top of a database
     * everybody agrees about.
     *
     * Contained like the snapshot: a restore that has already succeeded is not undone, and the
     * freeze is not held, because sessions could not be written back.
     */
    private function carryOverSessions(): void
    {
        try {
            $result = SessionCarrier::carryOver($this->pendingCarryover ?? []);
        } catch (Throwable $e) {
            $this->logAgentError('Restore could not carry sessions over: ' . $e->getMessage());

            return;
        }

        $this->logAgentInfo("Restore carried over {$result->carried} session(s), dropped {$result->dropped}");
    }

    /**
     * Clears the in-flight run state back to idle (the lock is released).
     */
    private function resetRun(): void
    {
        $this->childProcess = null;
        $this->childProgressTail = '';
        $this->runKind = null;
        $this->currentBackupId = null;
        $this->currentScope = null;
        $this->currentInitiator = null;
        $this->startedAt = 0.0;
        $this->timeoutSeconds = 0.0;
        $this->pendingRestoreId = null;
        $this->pendingRestoreScope = null;
        $this->pendingRestoreDecision = null;
        $this->pendingRestoreTimeout = 0.0;
        $this->pendingRestoreSince = 0.0;
        $this->pendingRestoreInitiator = null;
        $this->pendingRestoreInitiatorIdentities = null;
        $this->pendingCarryover = null;
        $this->rehydrateDeadline = 0.0;
        $this->rehydrateChildSucceeded = false;
        $this->rehydrateFailureDetail = null;
    }

    /**
     * Marks the runtime singleton as running and syncs the change to readers.
     *
     * @param string $id Backup id in progress
     * @param BackupScope $scope Scope in progress
     * @param ?int $estimatedSeconds How long the run is expected to take; null without history
     */
    private function markRuntimeRunning(string $id, BackupScope $scope, ?int $estimatedSeconds): void
    {
        $this->runtimeView()?->actions->markRunning($id, $scope, $estimatedSeconds);
    }

    /**
     * Clears the runtime singleton back to idle and syncs the change to readers.
     */
    private function clearRuntime(): void
    {
        $this->runtimeView()?->actions->clearRunning();
    }

    /**
     * Resolves the backup runtime singleton, or null when the project mounted none.
     *
     * A running backup agent with no runtime row is a forgotten activation step rather
     * than a subsystem switched off — the agent only exists because the project asked for
     * backups — so the miss is logged instead of passing silently.
     *
     * @return ?BackupRuntime Runtime singleton view, or null when it is not mounted
     */
    private function runtimeView(): ?BackupRuntime
    {
        $view = Hilos::$rt?->hilosBackupRuntime;
        if ($view instanceof BackupRuntime) {
            return $view;
        }

        $this->logAgentError(
            'Backup runtime singleton is not mounted: register it with $this->_stateItems['
            . StateBackupRuntime::RT_ITEM
            . '] = BackupRuntime::create() in the project RtContext::configure()',
        );

        return null;
    }

    /**
     * Resolves the restore runtime singleton, or null when the project mounted none.
     *
     * The same forgotten-activation logic as {@see runtimeView()}: the agent only exists
     * because the project declared the backup feature, and that mount carries this row.
     *
     * @return ?RestoreRuntime Restore runtime singleton view, or null when it is not mounted
     */
    private function restoreView(): ?RestoreRuntime
    {
        $view = Hilos::$rt?->hilosRestoreRuntime;
        if ($view instanceof RestoreRuntime) {
            return $view;
        }

        $this->logAgentError(
            'Restore runtime singleton is not mounted: declaring HilosFeature::BACKUP mounts '
            . StateRestoreRuntime::RT_ITEM
            . ' via BackupFeature::mount(); the project runtime context must not replace it',
        );

        return null;
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
     * Snapshots the runtime backup index as a plain list of index rows.
     *
     * The single reader used by both rotation ({@see pruneHistory()}) and the space estimate
     * ({@see admitBySpace()}): the RT collection is walked once, so both consumers see the same
     * set. Empty when runtime state or the index is unavailable.
     *
     * @return list<BackupHistory> Current backup index rows
     */
    private function indexRows(): array
    {
        $histories = $this->historiesView();
        if ($histories === null) {
            return [];
        }

        $rows = [];
        foreach ($histories as $row) {
            $rows[] = $row;
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
        $histories = $this->historiesView();
        if ($histories === null) {
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
            $pruned = [];
            foreach ($doomed as $row) {
                $pruner->deleteStored($row, $root);
                $histories->actions->forget($row->getId());
                $pruned[] = $row->getId();
            }

            // Same reason as the manual delete: rotation is mirrored, which is also why `keep`
            // needs no remote meaning - it protects from rotation, and rotation travels.
            $this->markMirrorDirty();

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
     * Resolves the backup index, or null when the project mounted none.
     *
     * The agent's single door to the index, for reads as much as for writes. Every write goes
     * through this view's actions rather than the state collection: the actions are what put a
     * create / update / delete on the RT sync wire, and this agent runs on its own monopolistic
     * worker while the backup page is served by another. Written any other way the index would
     * exist in this process and nowhere else — no other worker, and no browser table, would
     * ever see a backup.
     *
     * The same forgotten-activation logic as {@see runtimeView()}. The `??` is what makes an
     * unmounted index a null rather than a throw: it asks the runtime context's `__isset()`
     * first, where a bare read would raise RtCollectionNotFoundException.
     *
     * @return ?BackupHistories Backup index view, or null when it is not mounted
     */
    private function historiesView(): ?BackupHistories
    {
        $view = Hilos::$rt?->hilosBackupHistories ?? null;
        if ($view !== null) {
            return $view;
        }

        $this->logAgentError(
            'Backup index is not mounted: declaring HilosFeature::BACKUP mounts '
            . StateBackupHistory::RT_COLLECTION
            . ' via BackupFeature::mount(); the project runtime context must not replace it',
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

    /**
     * What the child process said on stderr, trimmed. A child that said nothing
     * and no child at all are the same thing to the caller: there is no detail to
     * append to the failure reason.
     *
     * @return ?string Trimmed stderr text, or null when there is none
     */
    private function childStdErr(): ?string
    {
        if ($this->childProcess === null) {
            return null;
        }

        $stderr = trim($this->childProcess->getStdErr());

        return $stderr === '' ? null : $stderr;
    }
}

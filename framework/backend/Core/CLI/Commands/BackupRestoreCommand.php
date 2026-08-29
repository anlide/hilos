<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupEstimator;
use Hilos\Backup\BackupHistoryScanner;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupProgress;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\BackupException;
use Hilos\Backup\Exception\RestoreArchiveNotFoundException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreEnvGuard;
use Hilos\Backup\RestoreMigrationDecision;
use Hilos\Backup\RestoreMigrationGuard;
use Hilos\Backup\RestoreNotifier;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\AppEnv;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\View\Item\BackupHistory;

/**
 * BackupRestoreCommand - restore a stored backup into this installation's databases.
 *
 * The preflight (resolve, ENV guard, PII registry, migration gate, explicit --yes, digest check) runs here
 * in full on both paths, so a doomed request is refused before anything is asked to freeze. Then the
 * paths split. The default HOT path assumes a live daemon: the request goes over the
 * command channel to the monopoly backup agent, which freezes the node through protected
 * mode and runs the restore in a spawned child; this process stays a monitor, and closing
 * it does not touch the running restore. The COLD path (explicit `--cold`, for a daemon
 * that is down) runs the engine synchronously right here; there is nothing to freeze on a
 * dead system. A silent daemon is an error, never an automatic fallback to cold - a
 * daemon that is alive but busy must not have its restore run around the freeze.
 *
 * The ENV matrix is authoritative here (per the ticket): the agent receives the recorded
 * decision as a value. The migration gate is not passed on the same way - it needs only the
 * sidecar and the migration files, so the engine re-derives it, as it re-checks the digest,
 * before its destructive steps.
 */
class BackupRestoreCommand implements CommandInterface
{
    use CommandChannelClientTrait;

    /** Seconds between status polls while monitoring a hot restore. */
    private const int MONITOR_POLL_SECONDS = 1;

    /**
     * Accepted `--migration-index` values: a plain integer of zero or more.
     *
     * Matched as text rather than cast, because a cast turns every wrong answer into 0 - and 0
     * is a level a schema archive may legitimately be restored at ("this database never
     * migrated"), so it is the one value that must not double as "the operator typed nonsense".
     */
    private const string MIGRATION_INDEX_PATTERN = '/^\d+$/';

    /**
     * Consecutive unanswered status polls after which the monitor gives up. The restore
     * itself lives in the agent and its child - the monitor abandoning it stops nothing.
     */
    private const int MONITOR_MAX_SILENCE = 5;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (backup:restore)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_RESTORE;
    }

    /**
     * Declares the rule: the daemon does the work and this process only initiates it and prints.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Restore a stored backup into the configured databases';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: backup:restore

Description:
  Restore a stored backup into this installation's databases. Destructive: the tables
  carried by the dump are dropped and recreated; tables absent from the dump are left
  in place. The archive digest is re-checked before anything is touched.

  By default the running daemon supervises the restore: the node enters protected mode
  (every other agent frozen), a child process replays the dump, and this command stays a
  monitor - Ctrl-C stops the monitor, not the restore. With --cold the engine runs
  synchronously in THIS process instead; use it when the daemon is down. A daemon that
  does not answer is an error, not a silent fallback to cold.

  Environment matrix: prod archive -> prod is allowed (disaster recovery); prod archive
  -> non-prod restores through the anonymization pass, which rewrites the personal data
  the backup catalog declares under its [pii] registry and refuses when no registry is
  declared (a schema-only archive carries no rows and is exempt); non-prod archive ->
  prod is always refused; an archive with no recorded environment needs --force to enter
  prod.

  Migration gate: each connection's migration level recorded in the archive is compared
  with the level this code expects. Behind -> restored, then migrated forward; the gap is
  printed before anything destructive runs. Ahead -> always refused, --force does not lift
  it (there is no downgrade path). Not recorded -> restored with a printed warning.

  Migration level of a schema archive: a schema dump carries the migration table without
  its rows, so the level travels as a marker written into the dump. An archive taken
  before that marker existed records no level, and such a restore is refused rather than
  guessed at - pass --migration-index=<N>, where N is the highest numeric prefix among
  the migration files of this tree. --force does not lift that refusal: the option
  delivers a missing fact, it does not overrule a gate. An archive that does record a
  level cannot be overruled either - a differing --migration-index is refused.

Usage:
  php cli.php backup:restore <id> [--scope=<scope>] [--migration-index=<N>] [--yes] [--force] [--cold]

Options:
  --scope=<scope>       Scope of the stored backup (full | schema-seed | schema-only), default full
  --migration-index=<N> Migration level for a schema archive that records none; schema scopes only
  --yes                 Confirm the destructive restore (required)
  --force               Allow an unknown-environment archive into production
  --cold                Run the engine synchronously here, without the daemon

Exit codes:
  0  restore completed (hot: reported by the agent; cold: engine returned)
  1  refused (digest mismatch, ENV guard, migration gate, daemon silent or busy) or failed
  2  unknown id/scope, or missing --yes
  3  BACKUP_DIR is not configured, or no [pii] registry where anonymization is required

Examples:
  php cli.php backup:restore 2026-08-08_03-00-00 --yes
  php cli.php backup:restore 2026-08-08_03-00-00 --scope=schema-seed --yes
  php cli.php backup:restore 2026-08-08_03-00-00 --yes --cold
HELP;
    }

    /**
     * Runs the preflight and dispatches the restore to the hot or cold path.
     *
     * @param array<string, mixed> $options Parsed options (scope, migration-index, yes, force, cold)
     * @param list<string> $args Positional args (the backup id)
     * @return int Exit code (0 success, 1 refused/failed, 2 bad argument, 3 unconfigured)
     * @throws EnvException When the hot path needs daemon host/port env values and they are missing or invalid
     */
    public function execute(array $options, array $args): int
    {
        try {
            $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }
        if ($root === '') {
            echo "Error: backup directory (BACKUP_DIR) is not configured\n";

            return ExitCode::CONFIG_ERROR;
        }

        // external-boundary: the operator's command line, checked on the very next line
        $id = $args[0] ?? '';
        if ($id === '') {
            echo "Error: backup id is required\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $scope = BackupScope::FULL;
        if (isset($options[BackupConstants::SCOPE_OPTION])) {
            $scope = BackupScope::fromString((string)$options[BackupConstants::SCOPE_OPTION]);
            if ($scope === null) {
                echo "Error: unknown backup scope: {$options[BackupConstants::SCOPE_OPTION]}\n";

                return ExitCode::INVALID_ARGUMENT;
            }
        }

        $migrationIndex = null;
        if (isset($options[BackupConstants::MIGRATION_INDEX_OPTION])) {
            // external-boundary: the operator's command line, checked on the very next lines
            $raw = (string)$options[BackupConstants::MIGRATION_INDEX_OPTION];
            if (preg_match(self::MIGRATION_INDEX_PATTERN, $raw) !== 1) {
                echo "Error: --" . BackupConstants::MIGRATION_INDEX_OPTION
                    . " takes a migration level (an integer of 0 or more), got: {$raw}\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            if ($scope === BackupScope::FULL) {
                // Not a silent no-op: the operator named a number and must learn it meant nothing,
                // rather than watch a restore go past it and believe it was honoured.
                echo 'Error: a full archive carries its migration level in the rows of the migration'
                    . ' table; --' . BackupConstants::MIGRATION_INDEX_OPTION
                    . " applies to schema archives only\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            $migrationIndex = (int)$raw;
        }

        try {
            $archivePath = BackupRestorer::locateArchive($root, $id, $scope);
            $metadata = BackupRestorer::readSidecarForArchive($archivePath);
        } catch (RestoreArchiveNotFoundException $refusal) {
            echo "Error: {$refusal->getMessage()}\n";

            return ExitCode::INVALID_ARGUMENT;
        } catch (RestoreFailedException $refusal) {
            echo "Error: {$refusal->getMessage()}\n";

            return ExitCode::ERROR;
        }

        // Cheap refusals first, the digest pass last: hashing is a full read of a possibly
        // multi-gigabyte archive, and paying it before a missing --yes or a guard refusal
        // would waste minutes on a request that free checks already doom. (The ticket's
        // Flow lists verify before the guard and --yes; the reorder keeps every refusal
        // and moves only the cost - noted on the ticket.)
        $decision = $this->decideEnv($metadata, (bool)($options[BackupConstants::FORCE_OPTION] ?? false));
        if ($decision === null) {
            return ExitCode::ERROR;
        }

        // A schema-only archive is exempt: it carries no rows, so the engine skips the pass
        // whatever the catalog says, and demanding a registry here would refuse a restore that
        // has nothing to anonymize.
        if ($decision === RestoreEnvDecision::REQUIRE_ANONYMIZATION
            && $scope !== BackupScope::SCHEMA_ONLY
            && !$this->reportPiiRegistry()
        ) {
            return ExitCode::CONFIG_ERROR;
        }

        if (!$this->reportMigrationGate($metadata)) {
            return ExitCode::ERROR;
        }

        if (!isset($options[BackupConstants::YES_OPTION])) {
            echo "Error: restoring is destructive; confirm with --yes\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $verify = new BackupVerifier()->verify($archivePath, $metadata);
        if ($verify->outcome !== BackupVerifyOutcome::OK && $verify->outcome !== BackupVerifyOutcome::NO_DIGEST) {
            $detail = $verify->reason ?? $verify->outcome->value;
            echo "Error: archive failed verification ({$detail}); refusing to restore\n";

            return ExitCode::ERROR;
        }

        return isset($options[BackupConstants::COLD_OPTION])
            ? $this->runCold($metadata, $root, $scope, $decision, $migrationIndex)
            : $this->runHot($id, $scope, $decision, $migrationIndex);
    }

    /**
     * Applies the ENV guard matrix and reports a refusal to the operator.
     *
     * A verdict of {@see RestoreEnvDecision::REQUIRE_ANONYMIZATION} is actionable now that
     * the toolkit exists (HIL-275); whether this installation configured it is a separate
     * question, asked by {@see reportPiiRegistry()} right after.
     *
     * @param BackupMetadata $metadata Sidecar metadata carrying the archive environment
     * @param bool $force Operator's `--force`
     * @return ?RestoreEnvDecision Actionable decision, or null when the restore was refused
     */
    private function decideEnv(BackupMetadata $metadata, bool $force): ?RestoreEnvDecision
    {
        $targetEnv = AppEnv::fromString(Hilos::$env->string(EnvConstants::APP_ENV));
        if ($targetEnv === null) {
            echo "Error: APP_ENV does not name a known environment\n";

            return null;
        }

        $result = RestoreEnvGuard::decide(AppEnv::fromString($metadata->env), $targetEnv, $force);
        if ($result->decision === RestoreEnvDecision::REFUSE) {
            echo "Error: {$result->reason}\n";

            return null;
        }
        return $result->decision;
    }

    /**
     * Tells the operator whether this installation declared what its personal data is.
     *
     * Asked only when the ENV guard demands anonymization, and asked in the preflight
     * rather than left to the engine, because the answer is a configuration fact the
     * operator can act on immediately: nothing about the archive, the daemon or the target
     * will change it. An empty registry is not "no personal data" - it is a project that
     * has not classified its tables, and running the pass over it would rewrite nothing
     * while reporting success.
     *
     * @return bool Whether a PII registry is declared
     */
    private function reportPiiRegistry(): bool
    {
        try {
            if (!PiiRegistry::collect()->isEmpty()) {
                return true;
            }
            echo 'Error: this restore requires anonymization, but no table declares what of it is '
                . 'personal data (Entity constant [' . Entity::META_PII . "])\n";
        } catch (AnonymizationConfigException $refusal) {
            echo "Error: the declared personal-data verdicts are not usable: {$refusal->getMessage()}\n";
        }

        return false;
    }

    /**
     * Applies the migration-index gate and tells the operator what it found.
     *
     * Runs before `--yes` and before the digest pass, on this preflight's cheap-refusals-first
     * rule: the sidecar is already in hand, so the gate costs nothing, while hashing the archive
     * costs a full read of it. An archive older than the code is not a refusal - the missing
     * migrations are applied after the import - but the operator is told before anything
     * destructive happens, because a silent migration is a surprise even when it is right.
     *
     * @param BackupMetadata $metadata Sidecar metadata carrying the per-connection levels
     * @return bool Whether the restore may proceed
     */
    private function reportMigrationGate(BackupMetadata $metadata): bool
    {
        $result = RestoreMigrationGuard::decide(
            $metadata->connections,
            RestoreMigrationGuard::codeMigrationIndex(),
        );
        if ($result->decision === RestoreMigrationDecision::REFUSE) {
            echo "Error: {$result->reason}\n";

            return false;
        }

        // The words live on the verdict, not here: the backup page shows the same lines, and
        // two presentations of one verdict have no right to word it differently.
        foreach ($result->describeGaps() as $line) {
            echo '  ' . $line . "\n";
        }

        return true;
    }

    /**
     * Runs the engine synchronously in this process (the cold path).
     *
     * @param BackupMetadata $metadata Sidecar of the archive being replayed
     * @param string $root Backup storage root
     * @param BackupScope $scope Scope of the stored backup
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict
     * @param ?int $migrationIndex Migration level the operator named, or null when they named none
     * @return int Exit code (0 success, 1 failed)
     */
    private function runCold(
        BackupMetadata $metadata,
        string $root,
        BackupScope $scope,
        RestoreEnvDecision $decision,
        ?int $migrationIndex,
    ): int {
        $id = $metadata->id;
        echo "Restoring {$id} (scope={$scope->value}) cold, in this process\n";

        // The cold path has no index to read, so it builds one: a single scan of the store before
        // the run, for the same estimate the hot path is given by the agent. Without it the ETA
        // would be a property of which entrance the operator used, which is not a difference the
        // two entrances are allowed to have.
        $estimatedSeconds = self::coldEstimate($root, $metadata, $scope);
        $startedAt = microtime(true);

        try {
            $this->createRestorer()->restore(
                $id,
                $scope,
                $decision,
                $migrationIndex,
                static function (RestorePhase $phase) use ($estimatedSeconds, $startedAt): void {
                    echo '  ' . self::phaseLabel($phase->value) . '...' . self::remainingLabel(
                        $estimatedSeconds,
                        microtime(true) - $startedAt,
                    ) . "\n";
                },
            );
        } catch (RestoreFailedException $failure) {
            echo "Error: {$failure->getMessage()}\n";
            // The same sentence the hot path prints, from the exception rather than from a runtime
            // row - and it is the only half of the report the cold path can make. There is no
            // barrier here and nothing to open: a cold restore runs against a dead system, so no
            // process is holding caches of the replaced database and nobody was ever shut out.
            echo $failure->databaseTouched()
                ? "The database may be left partially replaced - check it before starting the system\n"
                : "The database was not touched\n";
            $this->announceColdOutcome($id, $scope, false, $failure->getMessage(), $startedAt);

            return ExitCode::ERROR;
        }

        echo "Restore completed\n";
        $this->recordColdRestore($metadata, $root, (int)round(microtime(true) - $startedAt));
        $this->announceColdOutcome($id, $scope, true, null, $startedAt);

        return ExitCode::SUCCESS;
    }

    /**
     * Announces how the cold restore ended to the administrators of the restored database (HIL-279).
     *
     * **The collections are re-read first, and that order is the whole point.** This process was
     * started against the database the archive has just replaced and still holds it in memory; the
     * administrators read out of that memory would be the ones the archive overwrote, and the
     * notification would go to whoever holds their ids today - which is the same mistake the
     * initiator's identities exist to avoid on the hot path. There is no barrier to wait for here,
     * so re-reading is a single call and the run is complete the moment it returns.
     *
     * Nobody is watching a cold restore: the operator is at a console with no user id, so the
     * announcement carries no initiator and reaches the audience alone. The live signal inside the
     * emit reaches nobody either (no router in a CLI process), while the row and its delivery
     * queue wait in the database until the daemon is started and take the channels from there.
     *
     * @param string $id Backup id that was replayed
     * @param BackupScope $scope Scope the archive was captured under
     * @param bool $success Whether the engine replayed the archive
     * @param ?string $failureDetail Why it did not, or null on success
     * @param float $startedAt Microtime the run started
     */
    private function announceColdOutcome(
        string $id,
        BackupScope $scope,
        bool $success,
        ?string $failureDetail,
        float $startedAt,
    ): void {
        try {
            Hilos::$db?->reHydrateDbBackedCollections();
        } catch (HilosException $e) {
            // Announcing out of the replaced world is the one thing that must not happen, so a
            // re-read that failed ends the announcement rather than proceeding without it. The
            // restore itself is over either way and its own outcome has already been printed.
            echo "Note: this restore was not announced: the new database could not be read ({$e->getMessage()})\n";

            return;
        }

        new RestoreNotifier()->notifyOutcome(
            $id,
            $scope,
            $success,
            $failureDetail,
            date('Y-m-d H:i:s', (int)$startedAt),
            (int)round(microtime(true) - $startedAt),
            rehydrateComplete: true,
            initiatorIdentities: [],
        );
    }

    /**
     * Writes a finished cold restore onto the archive it replayed.
     *
     * The hot path records through the agent, which holds an index row to update beside the
     * sidecar; here there is neither, so the sidecar named by the archive's own metadata is
     * rewritten alone. Skipping it would leave the estimate above reading a history that this
     * entrance never writes: a store restored only with `--cold` would accumulate nothing, and
     * the ETA would after all become a property of which entrance the operator used.
     *
     * The span is the one the ETA was counted over, so what is written back measures the same
     * thing the next estimate is compared against.
     *
     * A record that cannot be written is reported and swallowed: the database has already been
     * replaced, and reporting a completed restore as an error because a sidecar could not be
     * rewritten would be a lie about the work that mattered.
     *
     * @param BackupMetadata $metadata Sidecar of the archive that was replayed
     * @param string $root Backup storage root
     * @param int $durationSeconds How long the restore took, in seconds
     */
    private function recordColdRestore(BackupMetadata $metadata, string $root, int $durationSeconds): void
    {
        try {
            new BackupCreator()->recordRestore(
                $metadata->id,
                $metadata->env,
                $metadata->scope->value,
                $root,
                new DateTimeImmutable()->format(DateTimeInterface::ATOM),
                $durationSeconds,
            );
        } catch (BackupException $e) {
            echo "Note: the length of this restore was not recorded: {$e->getMessage()}\n";
        }
    }

    /**
     * Asks the daemon's backup agent to run the restore, then monitors it to a terminal
     * outcome (the hot path).
     *
     * @param string $id Backup id
     * @param BackupScope $scope Scope of the stored backup
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict
     * @param ?int $migrationIndex Migration level the operator named, or null when they named none
     * @return int Exit code (0 success, 1 refused/failed/silent daemon)
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function runHot(
        string $id,
        BackupScope $scope,
        RestoreEnvDecision $decision,
        ?int $migrationIndex,
    ): int {
        $payload = [
            BackupConstants::FIELD_BACKUP_ID => $id,
            BackupConstants::FIELD_SCOPE => $scope->value,
            BackupConstants::FIELD_DECISION => $decision->value,
        ];
        // Present only when the operator named one: the key's absence is what tells the agent
        // to build a child argv without the option, rather than one carrying a null.
        if ($migrationIndex !== null) {
            $payload[BackupConstants::FIELD_MIGRATION_INDEX] = $migrationIndex;
        }

        // Not printChannelFailure(): a refused restore has a second road, and naming it is the
        // whole point of the sentence. The shared text says the channel is down; this one says
        // what to do about it.
        $result = $this->sendCommand(BackupConstants::RESTORE_REQUEST_COMMAND, $payload);
        $reply = $result->reply;
        if ($reply === null) {
            echo "Error: the daemon did not answer; start it, or restore with --cold\n";

            return ExitCode::ERROR;
        }
        if (!$reply->isOk()) {
            $message = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Error: restore refused: {$message}\n";

            return ExitCode::ERROR;
        }

        echo "Restore accepted: {$id} (scope={$scope->value}); the node is entering protected mode\n";

        return $this->monitor();
    }

    /**
     * Polls the restore status until the run reaches a terminal outcome.
     *
     * Prints each phase change. Ctrl-C or a lost connection stops only this monitor: the
     * agent and its child keep restoring, and the outcome stays readable in the restore
     * runtime row. A daemon that stops answering mid-run is reported after a bounded number
     * of silent polls rather than waited on forever.
     *
     * @return int Exit code mirroring the run's terminal outcome
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function monitor(): int
    {
        $lastPhase = null;
        $silentPolls = 0;

        while (true) {
            $reply = $this->sendCommand(BackupConstants::RESTORE_STATUS_COMMAND, [])->reply;
            if ($reply === null) {
                if (++$silentPolls >= self::MONITOR_MAX_SILENCE) {
                    echo "Error: the daemon stopped answering; the restore may still be running\n";

                    return ExitCode::ERROR;
                }

                sleep(self::MONITOR_POLL_SECONDS);
                continue;
            }
            if (!$reply->isOk()) {
                // An error reply is an answer, not silence: the daemon named a problem
                // (e.g. an unmounted restore row), and the operator should read exactly it.
                $message = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
                echo "Error: restore status unavailable: {$message}; the restore may still be running\n";

                return ExitCode::ERROR;
            }
            $silentPolls = 0;

            // A run that has not named a phase yet, or a finished one that carries no outcome,
            // is a legitimate answer during the poll — hence the null, not an empty string.
            $phase = $reply->payload[BackupConstants::FIELD_RESTORE_PHASE] ?? null;
            if (is_string($phase) && $phase !== '' && $phase !== $lastPhase) {
                echo '  ' . self::phaseLabel($phase) . '...' . self::remainingLabel(
                    self::payloadSeconds($reply->payload[BackupConstants::FIELD_RESTORE_ESTIMATED_SECONDS] ?? null),
                    self::elapsedSince($reply->payload[BackupConstants::FIELD_RESTORE_STARTED_AT] ?? null),
                ) . "\n";
                $lastPhase = $phase;
            }

            $running = (bool)($reply->payload[BackupConstants::FIELD_RESTORE_RUNNING] ?? false);
            $outcomeName = $reply->payload[BackupConstants::FIELD_RESTORE_OUTCOME] ?? null;
            $outcome = is_string($outcomeName) ? BackupStatus::fromString($outcomeName) : null;
            if (!$running && $outcome !== null) {
                $rehydrated = (bool)($reply->payload[BackupConstants::FIELD_REHYDRATE_COMPLETE] ?? false);

                if ($outcome === BackupStatus::SUCCESS) {
                    echo "Restore completed\n";
                    echo self::closedSystemLine($rehydrated);

                    return ExitCode::SUCCESS;
                }

                $failure = $reply->payload[BackupConstants::FIELD_RESTORE_FAILURE] ?? null;
                // external-boundary: the neutral element of the message — an unnamed failure adds nothing
                $detail = is_string($failure) && $failure !== '' ? ": {$failure}" : '';
                echo "Error: restore failed{$detail}\n";
                echo (bool)($reply->payload[BackupConstants::FIELD_DATABASE_TOUCHED] ?? false)
                    ? "The database may be left partially replaced - check it before opening the system\n"
                    : "The database was not touched\n";
                echo self::closedSystemLine($rehydrated);

                return ExitCode::ERROR;
            }

            sleep(self::MONITOR_POLL_SECONDS);
        }
    }

    /**
     * How long the cold run is expected to take, from a single scan of the store.
     *
     * The scan is the cold path's whole index: it works straight off disk, and reading the same
     * sidecars the agent's index is built from is what makes the two entrances agree. A store it
     * cannot read, or an archive nothing was ever restored from, simply yields no estimate.
     *
     * @param string $root Backup storage root
     * @param BackupMetadata $metadata Sidecar of the archive being replayed (its size scales the estimate)
     * @param BackupScope $scope Scope the archive was captured under
     * @return ?int Estimated seconds, or null when nothing can be estimated from
     */
    private static function coldEstimate(string $root, BackupMetadata $metadata, BackupScope $scope): ?int
    {
        $rows = [];
        foreach (new BackupHistoryScanner()->scan($root)->metadatas as $scanned) {
            $rows[] = self::indexRow($scanned);
        }

        return BackupEstimator::restoreSeconds($rows, $scope, $metadata->sizeBytes);
    }

    /**
     * Wraps one scanned sidecar in the index row the estimator reads.
     *
     * @param BackupMetadata $metadata Scanned sidecar
     * @return BackupHistory Detached index row over that sidecar
     */
    private static function indexRow(BackupMetadata $metadata): BackupHistory
    {
        $state = StateBackupHistory::fromMetadata($metadata);

        return new BackupHistory($state);
    }

    /**
     * What the monitor prints after a phase to say how much longer the run has.
     *
     * A run that outlived its estimate is told so in words rather than in a negative number or a
     * zero that never moves: both of those read as "about to finish", which is the one thing that
     * is certainly not true. A run with no estimate says nothing at all.
     *
     * @param ?int $estimatedSeconds Expected duration of the whole run; null when it cannot be estimated
     * @param ?float $elapsedSeconds Seconds since the run started; null when that instant is unknown
     * @return string Suffix for the phase line, empty when there is nothing to say
     */
    private static function remainingLabel(?int $estimatedSeconds, ?float $elapsedSeconds): string
    {
        if ($elapsedSeconds === null) {
            return '';
        }

        $remaining = BackupProgress::remainingSeconds($estimatedSeconds, $elapsedSeconds);
        if ($remaining === null) {
            return '';
        }

        return $remaining > 0 ? " (~{$remaining}s left)" : ' (taking longer than usual)';
    }

    /**
     * Reads a duration out of a status payload the daemon answered with.
     *
     * @param mixed $value Payload value under a seconds field
     * @return ?int Duration in seconds, or null when the field carries no number
     */
    private static function payloadSeconds(mixed $value): ?int
    {
        // external-boundary: the payload crossed the command channel as JSON and may carry anything
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Seconds since an ISO-8601 instant a status payload named.
     *
     * @param mixed $startedAt Payload value under an instant field
     * @return ?float Seconds since that instant, or null when it names none
     */
    private static function elapsedSince(mixed $startedAt): ?float
    {
        // external-boundary: the payload crossed the command channel as JSON and may carry anything
        if (!is_string($startedAt) || $startedAt === '') {
            return null;
        }

        $started = strtotime($startedAt);

        return $started === false ? null : microtime(true) - $started;
    }

    /**
     * Names a phase the way the operator needs to read it rather than the way the row stores it.
     *
     * Only the phase that is not a step of the engine needs translating: a restore does not end
     * where its SQL ends, and "rehydrating" on its own reads like a stall rather than like the node
     * putting itself back together (HIL-436). The rest are printed as they are - they are already
     * the names of what is happening.
     *
     * @param string $phase Phase value from the restore runtime row
     * @return string Line the monitor prints for it
     */
    private static function phaseLabel(string $phase): string
    {
        return $phase === RestorePhase::REHYDRATING->value ? 'reading the restored state back' : $phase;
    }

    /**
     * Says what the system is still closed to, and which command opens it.
     *
     * A restore never opens anything by itself (HIL-481), so every terminal line has to end by
     * telling the operator what is waiting for them. Which of the two it is depends on the barrier:
     * a node whose processes all re-read moved on to the verification window and lets pass holders
     * in, while one that did not stays shut to everybody - deliberately, because a verifier reading
     * caches of a database that no longer exists would confirm a fiction.
     *
     * @param bool $rehydrated Whether every process confirmed re-reading the replaced database
     * @return string Line to print after the outcome
     */
    private static function closedSystemLine(bool $rehydrated): string
    {
        $open = 'php cli.php ' . CliCommands::PROTECTED_MODE_OPEN;

        return $rehydrated
            ? "The system is open to pass holders only; let everyone back in with `{$open}`\n"
            : "The system stays closed to everyone, including pass holders; `{$open}` opens it anyway\n";
    }

    /**
     * Restore engine factory; the override point tests replace the engine through.
     *
     * @return BackupRestorer Engine the cold path runs
     */
    protected function createRestorer(): BackupRestorer
    {
        return new BackupRestorer();
    }

}

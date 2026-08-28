<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupProgressMarker;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\ExitCode;
use Hilos\Core\Process;

/**
 * BackupRestoreRunCommand - the short-lived child that replays one backup.
 *
 * The monopoly backup agent supervisor spawns this over {@see Process} once protected
 * mode is up; it delegates to the framework {@see BackupRestorer} engine. It needs a
 * full Hilos init (it imports into the configured database connections), so it is not
 * among the init-skipping commands. It returns 0 on success and a non-zero exit code
 * on any failure, which the supervisor reads to record the run's outcome - and which of
 * the two failure codes it returns tells the supervisor whether the database was left
 * untouched ({@see BackupConstants::RESTORE_EXIT_DATABASE_INTACT}).
 *
 * The `--decision` value arrives from the CLI preflight through the supervisor's argv
 * ({@see BackupConstants::FIELD_DECISION}); the engine acts on it without re-deriving
 * the ENV matrix. Operators restoring by hand should use `backup:restore` (hot or
 * `--cold`), which runs that preflight - this child is its executor, not its judge.
 */
final class BackupRestoreRunCommand implements CommandInterface
{
    /** Default scope when `--scope` is omitted. */
    private const string DEFAULT_SCOPE = BackupScope::FULL->value;

    /**
     * Accepted `--migration-index` values: a plain integer of zero or more.
     *
     * Checked here as well as in `backup:restore`, on this child's own boundary rule: argv is an
     * external boundary whoever built it, and a cast would silently turn a malformed value into
     * level 0 - a level a schema archive may legitimately be restored at.
     */
    private const string MIGRATION_INDEX_PATTERN = '/^\d+$/';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (backup:restore-run, the framework-owned contract)
     */
    public function getName(): string
    {
        return BackupConstants::RESTORE_RUN_COMMAND;
    }

    /**
     * Declares the departure: the daemon spawns this process itself, with no operator at its entrance.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemonSpawned(
            "the backup agent spawns this child itself; a restore writes to the database with the daemon up, legitimately, under protected mode",
        );
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Replay one stored backup into the configured database connections';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: backup:restore-run <id> [--scope=full|schema-seed|schema-only]
         [--migration-index=<N>] --decision=<decision>

Description:
  Verifies the stored archive's digest, unpacks it, and replays each connection dump
  through the mysql client. Destructive: tables carried by the dump are dropped and
  recreated. Spawned by the backup agent supervisor under protected mode; run it
  directly only to debug the engine - the operator entry point with the ENV guard
  preflight is `backup:restore`.

  --migration-index names the migration level of a schema archive that records none of
  its own; the supervisor passes on what the operator gave `backup:restore`.

Usage:
  php cli.php backup:restore-run <id> [--scope=full] --decision=allow

Examples:
  php cli.php backup:restore-run 2026-08-08_03-00-00 --decision=allow
  php cli.php backup:restore-run 2026-08-08_03-00-00 --scope=schema-seed --decision=allow
HELP;
    }

    /**
     * Replays one backup and reports success or failure via the exit code.
     *
     * @param array<string, mixed> $options Parsed options; `--scope` selects the scope,
     *     `--decision` carries the recorded ENV guard verdict, and `--migration-index` the
     *     migration level of a schema archive that records none
     * @param list<string> $args Positional args; $args[0] is the backup id
     * @return int Exit code (0 on success, non-zero on failure)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: a positional argument the operator may omit; the error below rejects it
        $id = $args[0] ?? '';
        if ($id === '') {
            fwrite(STDERR, "backup:restore-run requires a backup id as the first argument\n");

            return ExitCode::ERROR;
        }

        $scopeRaw = (string)($options[BackupConstants::SCOPE_OPTION] ?? self::DEFAULT_SCOPE);
        $scope = BackupScope::fromString($scopeRaw);
        if ($scope === null) {
            fwrite(STDERR, "Unknown backup scope: {$scopeRaw}\n");

            return ExitCode::ERROR;
        }

        // external-boundary: an option the operator may omit; the error below rejects it
        $decisionRaw = (string)($options[BackupConstants::FIELD_DECISION] ?? '');
        $decision = RestoreEnvDecision::tryFrom($decisionRaw);
        if ($decision === null) {
            fwrite(
                STDERR,
                "backup:restore-run requires --decision with a recorded guard verdict, got: {$decisionRaw}\n",
            );

            return ExitCode::ERROR;
        }

        $migrationIndex = null;
        if (isset($options[BackupConstants::MIGRATION_INDEX_OPTION])) {
            // external-boundary: an option the supervisor may omit; the error below rejects a bad one
            $migrationIndexRaw = (string)$options[BackupConstants::MIGRATION_INDEX_OPTION];
            if (preg_match(self::MIGRATION_INDEX_PATTERN, $migrationIndexRaw) !== 1) {
                fwrite(
                    STDERR,
                    'backup:restore-run takes --' . BackupConstants::MIGRATION_INDEX_OPTION
                    . " as an integer of 0 or more, got: {$migrationIndexRaw}\n",
                );

                return ExitCode::ERROR;
            }
            $migrationIndex = (int)$migrationIndexRaw;
        }

        try {
            new BackupRestorer()->restore($id, $scope, $decision, $migrationIndex, static function (
                RestorePhase $phase,
            ): void {
                // Flushed line by line: the pipe buffers, and a phase that arrives when the run is
                // over is a phase nobody was shown.
                fwrite(STDOUT, BackupProgressMarker::statement($phase->value));
                fflush(STDOUT);
            });
        } catch (RestoreFailedException $e) {
            fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n");

            return self::exitCodeFor($e);
        } catch (BackupException $e) {
            fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n");

            return ExitCode::ERROR;
        }

        echo "Restore completed: {$id}\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Maps a restore failure to the exit code the supervisor reads it by (HIL-436).
     *
     * Two codes rather than one, because they ask different things of whoever holds the failure: an
     * untouched database can be fixed and retried, a half-replaced one cannot be left alone. Public
     * and separate from {@see execute()} because it is one half of a contract - the other half is
     * {@see BackupAgent::restoreTouchedDatabase()}, which reads exactly this code back - and a
     * contract only spelled inside a method that needs a database to reach is a contract nothing
     * checks.
     *
     * @param RestoreFailedException $failure Failure the engine raised
     * @return int Exit code for the supervisor
     */
    public static function exitCodeFor(RestoreFailedException $failure): int
    {
        return $failure->databaseTouched()
            ? ExitCode::ERROR
            : BackupConstants::RESTORE_EXIT_DATABASE_INTACT;
    }
}

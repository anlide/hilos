<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgressMarker;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupException;
use Hilos\Constants\ExitCode;
use Hilos\Core\Process;

/**
 * BackupRunCommand - the short-lived child that creates one backup.
 *
 * The monopoly backup agent supervisor spawns this over {@see Process}
 * for each backup run; it delegates to the framework {@see BackupCreator} engine.
 * It needs a full Hilos init (it dumps the configured database connections), so it is
 * not among the init-skipping commands. It returns 0 on success and a non-zero exit
 * code on any failure, which the supervisor reads to record the run's outcome.
 */
final class BackupRunCommand implements CommandInterface
{
    /** Default scope when `--scope` is omitted. */
    private const string DEFAULT_SCOPE = BackupScope::FULL->value;

    public function getName(): string
    {
        return BackupConstants::RUN_COMMAND;
    }

    /**
     * Declares the departure: the daemon spawns this process itself, with no operator at its entrance.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemonSpawned(
            "the backup agent spawns this child itself, so writing a whole archive never runs inside its loop",
        );
    }

    public function getDescription(): string
    {
        return 'Create one backup archive of all database connections';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: backup:run <id> [--scope=full|schema-seed|schema-only]

Description:
  Dumps every configured database connection with mysqldump, archives them into
  <BACKUP_DIR>/<scope>/<id>-<env>-<scope>.tar.gz and writes its JSON sidecar. The
  run is all-or-nothing: any failure leaves no archive behind and exits non-zero.

  Spawned by the backup agent supervisor; run it directly only to create a backup
  by hand or to debug the engine.

Usage:
  php cli.php backup:run <id> [--scope=full]

Examples:
  php cli.php backup:run 2026-07-19_10-30-00
  php cli.php backup:run 2026-07-19_10-30-00 --scope=schema-only
HELP;
    }

    /**
     * Creates one backup and reports success or failure via the exit code.
     *
     * @param array<string, mixed> $options Parsed options; `--scope` selects the scope
     * @param list<string> $args Positional args; $args[0] is the backup id
     * @return int Exit code (0 on success, non-zero on failure)
     */
    public function execute(array $options, array $args): int
    {
        // external-boundary: a positional argument the operator may omit; the error below rejects it
        $id = $args[0] ?? '';
        if ($id === '') {
            fwrite(STDERR, "backup:run requires a backup id as the first argument\n");

            return ExitCode::ERROR;
        }

        $scopeRaw = (string)($options[BackupConstants::SCOPE_OPTION] ?? self::DEFAULT_SCOPE);
        $scope = BackupScope::fromString($scopeRaw);
        if ($scope === null) {
            fwrite(STDERR, "Unknown backup scope: {$scopeRaw}\n");

            return ExitCode::ERROR;
        }

        try {
            $metadata = new BackupCreator()->create($id, $scope, static function (BackupPhase $phase): void {
                // Flushed line by line: the pipe buffers, and a phase that arrives when the run is
                // over is a phase nobody was shown.
                fwrite(STDOUT, BackupProgressMarker::statement($phase->value));
                fflush(STDOUT);
            });
        } catch (BackupException $e) {
            fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");

            return ExitCode::ERROR;
        }

        foreach ($metadata->warnings as $warning) {
            fwrite(STDERR, "Backup warning: {$warning}\n");
        }

        echo "Backup created: {$metadata->id} ({$metadata->sizeBytes} bytes)\n";

        return ExitCode::SUCCESS;
    }
}

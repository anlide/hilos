<?php

declare(strict_types=1);

namespace Demo\Chat\CLI\Commands;

use Demo\Chat\Constants\ChatCliCommands;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Exception\BackupException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\Process;

/**
 * BackupRestoreRunCommand - the short-lived child that replays one backup.
 *
 * The monopoly backup agent supervisor spawns this over {@see Process} once protected
 * mode is up; it delegates to the framework {@see BackupRestorer} engine. It needs a
 * full Hilos init (it imports into the configured database connections), so it is not
 * among the init-skipping commands. It returns 0 on success and a non-zero exit code
 * on any failure, which the supervisor reads to record the run's outcome.
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
     * Returns command name for CLI routing.
     *
     * @return string Command name (backup:restore-run, the framework-owned contract)
     */
    public function getName(): string
    {
        return ChatCliCommands::BACKUP_RESTORE_RUN;
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
Command: backup:restore-run <id> [--scope=full|schema-seed|schema-only] --decision=<decision>

Description:
  Verifies the stored archive's digest, unpacks it, and replays each connection dump
  through the mysql client. Destructive: tables carried by the dump are dropped and
  recreated. Spawned by the backup agent supervisor under protected mode; run it
  directly only to debug the engine - the operator entry point with the ENV guard
  preflight is `backup:restore`.

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
     *     `--decision` carries the recorded ENV guard verdict
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

        try {
            new BackupRestorer()->restore($id, $scope, $decision);
        } catch (BackupException $e) {
            fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n");

            return ExitCode::ERROR;
        }

        echo "Restore completed: {$id}\n";

        return ExitCode::SUCCESS;
    }
}

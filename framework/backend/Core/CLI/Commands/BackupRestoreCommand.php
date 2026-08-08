<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\BackupVerifyOutcome;
use Hilos\Backup\Exception\RestoreArchiveNotFoundException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreEnvGuard;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\AppEnv;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * BackupRestoreCommand - restore a stored backup into this installation's databases.
 *
 * The preflight (resolve, digest check, ENV guard, explicit --yes) runs here in full on
 * both paths, so a doomed request is refused before anything is asked to freeze. Then the
 * paths split. The default HOT path assumes a live daemon: the request goes over the
 * command channel to the monopoly backup agent, which freezes the node through protected
 * mode and runs the restore in a spawned child; this process stays a monitor, and closing
 * it does not touch the running restore. The COLD path (explicit `--cold`, for a daemon
 * that is down) runs the engine synchronously right here; there is nothing to freeze on a
 * dead system. A silent daemon is an error, never an automatic fallback to cold - a
 * daemon that is alive but busy must not have its restore run around the freeze.
 *
 * The ENV matrix is authoritative here (per the ticket): the agent receives the recorded
 * decision as a value, and the engine re-checks only the digest before its destructive
 * steps.
 */
class BackupRestoreCommand implements CommandInterface
{
    use CommandChannelClientTrait;

    /** Seconds between status polls while monitoring a hot restore. */
    private const int MONITOR_POLL_SECONDS = 1;

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
  -> non-prod requires anonymization (unavailable until HIL-275, so refused); non-prod
  archive -> prod is always refused; an archive with no recorded environment needs
  --force to enter prod.

Usage:
  php cli.php backup:restore <id> [--scope=<scope>] [--yes] [--force] [--cold]

Options:
  --scope=<scope>    Scope of the stored backup (full | schema-seed | schema-only), default full
  --yes              Confirm the destructive restore (required)
  --force            Allow an unknown-environment archive into production
  --cold             Run the engine synchronously here, without the daemon

Exit codes:
  0  restore completed (hot: reported by the agent; cold: engine returned)
  1  refused (digest mismatch, ENV guard, daemon silent or busy) or failed
  2  unknown id/scope, or missing --yes
  3  BACKUP_DIR is not configured

Examples:
  php cli.php backup:restore 2026-08-08_03-00-00 --yes
  php cli.php backup:restore 2026-08-08_03-00-00 --scope=schema-seed --yes
  php cli.php backup:restore 2026-08-08_03-00-00 --yes --cold
HELP;
    }

    /**
     * Runs the preflight and dispatches the restore to the hot or cold path.
     *
     * @param array<string, mixed> $options Parsed options (scope, yes, force, cold)
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
            ? $this->runCold($id, $scope, $decision)
            : $this->runHot($id, $scope, $decision);
    }

    /**
     * Applies the ENV guard matrix and reports a refusal to the operator.
     *
     * A restore that requires anonymization is refused here while HIL-275 has not landed:
     * there is no anonymizer to resolve, and accepting the run would only move the same
     * refusal into the engine's backstop.
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
        if ($result->decision === RestoreEnvDecision::REQUIRE_ANONYMIZATION) {
            echo "Error: {$result->reason}; the anonymization toolkit (HIL-275) is not available yet\n";

            return null;
        }

        return $result->decision;
    }

    /**
     * Runs the engine synchronously in this process (the cold path).
     *
     * @param string $id Backup id
     * @param BackupScope $scope Scope of the stored backup
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict
     * @return int Exit code (0 success, 1 failed)
     */
    private function runCold(string $id, BackupScope $scope, RestoreEnvDecision $decision): int
    {
        echo "Restoring {$id} (scope={$scope->value}) cold, in this process\n";

        try {
            $this->createRestorer()->restore($id, $scope, $decision, static function (RestorePhase $phase): void {
                echo "  {$phase->value}...\n";
            });
        } catch (RestoreFailedException $failure) {
            echo "Error: {$failure->getMessage()}\n";

            return ExitCode::ERROR;
        }

        echo "Restore completed\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Asks the daemon's backup agent to run the restore, then monitors it to a terminal
     * outcome (the hot path).
     *
     * @param string $id Backup id
     * @param BackupScope $scope Scope of the stored backup
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict
     * @return int Exit code (0 success, 1 refused/failed/silent daemon)
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function runHot(string $id, BackupScope $scope, RestoreEnvDecision $decision): int
    {
        $reply = $this->sendCommand(BackupConstants::RESTORE_REQUEST_COMMAND, [
            BackupConstants::FIELD_BACKUP_ID => $id,
            BackupConstants::FIELD_SCOPE => $scope->value,
            BackupConstants::FIELD_DECISION => $decision->value,
        ]);
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
            $reply = $this->sendCommand(BackupConstants::RESTORE_STATUS_COMMAND, []);
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

            $phase = (string)($reply->payload[BackupConstants::FIELD_RESTORE_PHASE] ?? '');
            if ($phase !== '' && $phase !== $lastPhase) {
                echo "  {$phase}...\n";
                $lastPhase = $phase;
            }

            $running = (bool)($reply->payload[BackupConstants::FIELD_RESTORE_RUNNING] ?? false);
            $outcome = BackupStatus::fromString(
                (string)($reply->payload[BackupConstants::FIELD_RESTORE_OUTCOME] ?? ''),
            );
            if (!$running && $outcome !== null) {
                if ($outcome === BackupStatus::SUCCESS) {
                    echo "Restore completed\n";

                    return ExitCode::SUCCESS;
                }

                $failure = (string)($reply->payload[BackupConstants::FIELD_RESTORE_FAILURE] ?? '');
                echo 'Error: restore failed' . ($failure !== '' ? ": {$failure}" : '') . "\n";

                return ExitCode::ERROR;
            }

            sleep(self::MONITOR_POLL_SECONDS);
        }
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

<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\BackupHistoryScanner;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Test-only: empty the backup storage tree, so a stand rises with no backup history behind it.
 *
 * The twin of {@see LogsTestResetCommand}, and for the same reason: the history of backups is the
 * FILES under `BACKUP_DIR` ({@see BackupHistoryScanner} reads the tree, not a table), so
 * `test:db:reset` leaves it exactly where the last run left it. On a stand whose backup directory
 * is a bind mount that means every run inherits every archive the checkout has ever made, and a
 * case that counts rows in the history table is counting other runs' work.
 *
 * Found by a case that wanted an empty history and met ten rows in it - a full window - because
 * the restore case of the same spec does not delete what it made. Absolute row counts were wrong
 * from the first line of that case, and no reading of the diff would have said so.
 *
 * Everything under the root goes, scope directories included: they are made again at the next
 * write, and a reset that kept them would be choosing which run's leftovers are acceptable.
 *
 * {@see DatabaseFreeCommand} for the same reason its log-side twin is: it reads one environment
 * value and walks a directory, and it runs before the stand has a database to connect to.
 */
final class BackupTestResetCommand extends TestOnlyCommand implements DatabaseFreeCommand
{
    public function getName(): string
    {
        return CliCommands::BACKUP_TEST_RESET;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'empties the very tree a running backup agent writes its archives into and scans',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: drop every archive under the backup root';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: test:backup:reset

Description:
  Empties BACKUP_DIR: every scope directory and every archive under it. Meant for
  the preparation of a test stand, where the backup root is a bind mount and the
  history is the files in it, so archives would otherwise survive every run and be
  counted by the next one. Says so and does nothing when BACKUP_DIR is unset, which
  is how an installation turns the storage off. Refuses on a production-like
  environment, and beside a daemon that is answering.

Usage:
  php cli.php test:backup:reset
HELP;
    }

    /**
     * Empties the backup root and says what went.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws EnvException When BACKUP_DIR is outside the catalog or not a string
     */
    protected function run(array $options, array $args): int
    {
        $root = Hilos::$env[EnvConstants::BACKUP_DIR]->string();
        if ($root === '') {
            echo 'Nothing to empty: ' . EnvConstants::BACKUP_DIR->name . " is unset, so this node stores no backups.\n";

            return ExitCode::SUCCESS;
        }

        if (!is_dir($root)) {
            echo "Nothing to empty: backup root {$root} does not exist yet.\n";

            return ExitCode::SUCCESS;
        }

        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($root);

        if ($sweeper->failed() !== []) {
            echo 'Backup root ' . $root . ' was left with ' . count($sweeper->failed()) . " path(s) behind:\n";
            foreach ($sweeper->failed() as $path) {
                echo "  {$path}\n";
            }

            return ExitCode::ERROR;
        }

        echo "Backup root {$root} emptied: {$sweeper->removed()} path(s) removed.\n";

        return ExitCode::SUCCESS;
    }
}

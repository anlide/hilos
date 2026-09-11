<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\LogRotationConstants;
use Hilos\Log\DaemonLogAddress;
use Hilos\Log\LogStoreAgent;

/**
 * Test-only: empty this node's log tree, so a stand rises with no rotation history behind it.
 *
 * A rotated batch is FILES, not rows, and that is the whole reason this command exists: every
 * other fixture a stand starts from is rebuilt by `test:db:reset`, which drops the database and
 * knows nothing about a directory. The log root of a test stand is a bind mount, so what one run
 * rotates is still there for the next one, and the batches pile up for as long as the checkout
 * lives.
 *
 * The pile is not inert. A batch waits for an operator to carry it off, retention cannot prune
 * what nobody answered for ({@see LogStoreAgent}), and every live walk of the tree reads all of
 * them - so each run makes the next one slower, until a spec that waits on a rotation stops
 * fitting in its patience and the suite goes red for a reason that is nowhere in the diff. Found
 * that way: 1617 batches and 16348 files, the oldest eight weeks old, on the box where
 * `logs-rotation.spec.ts` had been failing since the daily output tripled.
 *
 * What goes: the archive subtree, the staging subtree, and the live `*.log` files of the root.
 * What stays: everything else there, the freeze marker included - `test:up` removes that one by
 * name, and a command that swept the whole directory would be deleting other people's files on a
 * guess.
 *
 * {@see DatabaseFreeCommand} because the whole of it is files: it is run during the preparation
 * of a stand, before the database this demo will use has been created, and a bootstrap connect
 * would fail on the one thing this command does not need.
 */
final class LogsTestResetCommand extends TestOnlyCommand implements DatabaseFreeCommand
{
    public function getName(): string
    {
        return CliCommands::LOGS_TEST_RESET;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'empties the very directory a running node writes its logs into and rotates within',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: drop the rotation archive, the staging tree and the live log files';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: test:logs:reset

Description:
  Empties this node's log root: the rotation archive, the staging subtree and the
  live *.log files. Meant for the preparation of a test stand, where the log root
  is a bind mount and rotated batches would otherwise survive every run and keep
  slowing the next one down. Leaves every other file of the root alone. Refuses on
  a production-like environment, and beside a daemon that is answering.

Usage:
  php cli.php test:logs:reset
HELP;
    }

    /**
     * Empties the log root and says what went.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    protected function run(array $options, array $args): int
    {
        $logFile = DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE);
        if ($logFile === null) {
            echo 'No log root to empty: ' . EnvConstants::DAEMON_LOG_FILE->name . " is not set.\n";

            return ExitCode::CONFIG_ERROR;
        }

        $root = dirname($logFile);
        $sweeper = new TestPathSweeper();

        $sweeper->emptyDirectory($root . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME);
        $sweeper->emptyDirectory($root . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_STAGING_SUBDIR_NAME);
        $sweeper->removeMatching($root . DIRECTORY_SEPARATOR . '*.log');

        if ($sweeper->failed() !== []) {
            echo 'Log root ' . $root . ' was left with ' . count($sweeper->failed()) . " path(s) behind:\n";
            foreach ($sweeper->failed() as $path) {
                echo "  {$path}\n";
            }

            return ExitCode::ERROR;
        }

        echo "Log root {$root} emptied: {$sweeper->removed()} path(s) removed.\n";

        return ExitCode::SUCCESS;
    }
}

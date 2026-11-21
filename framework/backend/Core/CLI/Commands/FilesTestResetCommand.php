<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Empties the published files directory for a test stand.
 *
 * Stored copies receive randomly generated hex names (see AbstractFilesLibraryAgent), and the
 * library's background cleaner walks database rows. Because test:db:reset recreates only the
 * database without touching the filesystem, published bytes without rows accumulate across runs
 * unless emptied explicitly.
 *
 * What this command does NOT do:
 * - It does not clean temporary files in files/tmp.
 * - It does not empty storage for a project that overrides Hilos::createFilesStorage() with a
 *   custom implementation.
 * - It preserves the cluster directory marker (ClusterDirectoryMarker::FILE_NAME).
 */
final class FilesTestResetCommand extends TestOnlyCommand implements DatabaseFreeCommand
{
    public function getName(): string
    {
        return CliCommands::FILES_TEST_RESET;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'empties the very directory a running files library stores its published files in and serves them from',
        );
    }

    public function getDescription(): string
    {
        return 'Test-only: empty the published files directory';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: test:files:reset

Description:
  Empties the published files directory, so stored bytes do not outlive their
  rows. Meant for the preparation of a test stand, where published files would
  otherwise survive test database resets. Does nothing when the project does
  not declare FILES. Refuses on a production-like environment, and beside a
  daemon that is answering.

Usage:
  php cli.php test:files:reset
HELP;
    }

    /**
     * Empties the published files directory and reports removed paths.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws DirectoryNotFoundException When FS context or its files directory is not configured
     * @throws HilosException When filesystem factory or configuration cannot initialize context
     */
    protected function run(array $options, array $args): int
    {
        if (!Hilos::hasFeature(HilosFeature::FILES)) {
            echo "Nothing to empty: the project does not declare FILES.\n";

            return ExitCode::SUCCESS;
        }

        Hilos::appClass()::initFs();

        $directory = (Hilos::$fs ?? throw new DirectoryNotFoundException('FS context is not configured'))
            ->getDirectory(FsContext::FILES)
            ->getPath();

        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($directory);

        if ($sweeper->failed() !== []) {
            echo 'Files directory ' . $directory . ' was left with ' . count($sweeper->failed()) . " path(s) behind:\n";
            foreach ($sweeper->failed() as $path) {
                echo "  {$path}\n";
            }

            return ExitCode::ERROR;
        }

        if (!is_dir($directory)) {
            echo "Nothing to empty: files directory {$directory} does not exist yet.\n";

            return ExitCode::SUCCESS;
        }

        echo "Files directory {$directory} emptied: {$sweeper->removed()} path(s) removed.\n";

        return ExitCode::SUCCESS;
    }
}

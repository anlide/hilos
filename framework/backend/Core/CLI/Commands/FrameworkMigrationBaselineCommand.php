<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Hilos;
use Throwable;

/** Writes the current project's composite framework baseline before deployment. */
class FrameworkMigrationBaselineCommand implements CommandInterface, DatabaseFreeCommand
{
    public const array OPTIONS = [];

    /** @return string CLI routing name */
    public function getName(): string
    {
        return CliCommands::DB_FRAMEWORK_BASELINE;
    }

    /** @return CommandExecution Source-writing site, independent of daemon presence */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliSourceWrite(
            'writes a composite framework baseline into versioned project sources before deployment; no running daemon owns these files',
        );
    }

    /** @return string Help-list description */
    public function getDescription(): string
    {
        return 'Create a composite framework baseline in the current project migration track';
    }

    /** @return string Detailed CLI help */
    public function getHelp(): string
    {
        return <<<'HELP'
Command: db:framework:baseline

Description:
  Create one composite framework baseline from the current project's activated features.
  Does not connect to a database, apply SQL, or contact a daemon.
  Commit the generated files before deployment.

Usage:
  php cli.php db:framework:baseline

For later framework updates, run db:framework:pull.
HELP;
    }

    /**
     * @param array<string, mixed> $options No options are declared
     * @param list<string> $args No positional arguments are accepted
     * @return int Zero when the baseline is created or already exists, nonzero on refusal or failure
     */
    public function execute(array $options, array $args): int
    {
        $targetRoot = null;
        try {
            if ($args !== []) {
                throw new CommandException('db:framework:baseline takes no positional arguments');
            }
            $targetRoot = Migration::trackPath();
            $outcome = new FrameworkMigrationInstaller()->executeBaseline(
                new FrameworkMigrationProject($targetRoot, Hilos::appClass()),
            );
            foreach ($outcome->projects as $target) {
                if ($target->publishedPaths !== []) {
                    $fileCount = count($target->publishedPaths);
                    $fileWord = $fileCount === 1 ? 'file' : 'files';
                    echo "{$target->project->root}: created {$fileCount} {$fileWord}\n";
                    foreach ($target->publishedPaths as $path) {
                        echo "  {$path}\n";
                    }
                } elseif ($target->attempted && $target->failure === null) {
                    echo "{$target->project->root}: skipped (baseline already exists); run db:framework:pull for later framework updates\n";
                }
                if ($target->failure !== null) {
                    $partialResult = $target->publishedPaths === [] ? '' : 'partial result: ';
                    $this->writeToStandardError("{$target->project->root}: {$partialResult}{$target->failure->getMessage()}");
                } elseif (!$target->attempted) {
                    $this->writeToStandardError("{$target->project->root}: not written because baseline preflight failed");
                }
            }

            return $outcome->hasFailures() ? ExitCode::ERROR : ExitCode::SUCCESS;
        } catch (Throwable $exception) {
            $target = $targetRoot === null ? '' : "{$targetRoot}: ";
            $this->writeToStandardError($target . $exception->getMessage());

            return ExitCode::ERROR;
        }
    }

    /** @param string $text Source-delivery refusal or failure */
    protected function writeToStandardError(string $text): void
    {
        fwrite(STDERR, $text . "\n");
    }
}

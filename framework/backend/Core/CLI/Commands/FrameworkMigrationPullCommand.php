<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Database\Migration\FrameworkMigrationProjectRegistry;
use Hilos\Hilos;
use Throwable;

/** Delivers versioned SQL sources through the shared installer, without applying them. */
class FrameworkMigrationPullCommand implements CommandInterface, DatabaseFreeCommand
{
    public const array OPTIONS = ['projects'];

    /** @return string CLI routing name */
    public function getName(): string
    {
        return CliCommands::DB_FRAMEWORK_PULL;
    }

    /** @return CommandExecution Source-writing site, independent of daemon presence */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliSourceWrite(
            'writes versioned project migration sources before deployment; no running daemon owns these files',
        );
    }

    /** @return string Help-list description */
    public function getDescription(): string
    {
        return 'Pull missing framework migration sources into project tracks';
    }

    /** @return string Detailed CLI help */
    public function getHelp(): string
    {
        return <<<'HELP'
Command: db:framework:pull

Description:
  Write missing framework migration pairs into origin-aware project source tracks.
  Does not connect to a database, apply SQL, or contact a daemon.
  Commit the generated files before deployment.

Usage:
  php cli.php db:framework:pull
  php cli.php db:framework:pull --projects=/path/to/framework-projects.json

Options:
  --projects=<path>  Explicit JSON registry; roots are relative to that file.
                    Without it, use the current project's track and facade.
HELP;
    }

    /**
     * @param array<string, mixed> $options Declared projects option
     * @param list<string> $args No positional arguments are accepted
     * @return int Zero on completion, nonzero on invalid input or any target failure
     */
    public function execute(array $options, array $args): int
    {
        try {
            if ($args !== []) {
                throw new CommandException('db:framework:pull takes no positional arguments');
            }
            $registryPath = $options['projects'] ?? null;
            if ($registryPath !== null && (!is_string($registryPath) || trim($registryPath) === '')) {
                throw new CommandException('--projects requires a JSON registry path');
            }
            $projects = $registryPath === null
                ? [new FrameworkMigrationProject(Migration::trackPath(), Hilos::appClass())]
                : FrameworkMigrationProjectRegistry::load($registryPath);
            $outcome = new FrameworkMigrationInstaller()->execute(...$projects);
            foreach ($outcome->projects as $target) {
                if ($target->publishedPaths !== []) {
                    echo "{$target->project->root}: created " . count($target->publishedPaths) . " files\n";
                    foreach ($target->publishedPaths as $path) {
                        echo "  {$path}\n";
                    }
                } elseif ($target->attempted && $target->failure === null) {
                    echo "{$target->project->root}: skipped (already current)\n";
                }
                if ($target->failure !== null) {
                    $this->writeToStandardError("{$target->project->root}: {$target->failure->getMessage()}");
                } elseif (!$target->attempted) {
                    $this->writeToStandardError("{$target->project->root}: not written because batch preflight failed");
                }
            }

            return $outcome->hasFailures() ? ExitCode::ERROR : ExitCode::SUCCESS;
        } catch (Throwable $exception) {
            $this->writeToStandardError($exception->getMessage());

            return ExitCode::ERROR;
        }
    }

    /** @param string $text Source-delivery refusal or failure */
    protected function writeToStandardError(string $text): void
    {
        fwrite(STDERR, $text . "\n");
    }
}

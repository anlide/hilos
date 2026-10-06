<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerGenerator;
use Hilos\HilosException;

/** Developer command that materializes the current journal trigger plan as project files. */
final class ChangeLogGenerateCommand implements CommandInterface
{
    /**
     * @return string CLI routing name
     */
    public function getName(): string
    {
        return CliCommands::CHANGE_LOG_GENERATE;
    }

    /**
     * @return CommandExecution Offline file generation site
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite('generates source files from the applied schema before daemon startup');
    }

    /**
     * @return string Help-list description
     */
    public function getDescription(): string
    {
        return 'Generate change log trigger SQL files';
    }

    /**
     * @return string Detailed CLI help
     */
    public function getHelp(): string
    {
        return <<<'HELP'
Command: db:change-log:generate

Description:
  Generate the project SQL files for every journaled table.
  Requires all migrations to be applied and no daemon to be running.

Usage:
  php cli.php db:change-log:generate
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options; none are accepted
     * @param list<string> $args Positional arguments; none are accepted
     * @return int Exit code
     * @throws CommandException When arguments are supplied
     * @throws HilosException When migration, schema, placement, or file publication fails
     */
    public function execute(array $options, array $args): int
    {
        if ($options !== [] || $args !== []) {
            throw new CommandException('db:change-log:generate takes no arguments');
        }

        $plan = JournalTriggerGenerator::plan();
        $changed = JournalTriggerFiles::write($plan);
        if ($changed === []) {
            echo "Change log trigger files are already current.\n";
        } else {
            $tombstones = [];
            foreach ($plan as $file) {
                if ($file->tombstone) {
                    $tombstones[$file->name . '.sql'] = true;
                }
            }
            foreach ($changed as $filename) {
                echo (isset($tombstones[$filename]) ? 'Tombstone' : 'Generated') . " {$filename}\n";
            }
        }

        return ExitCode::SUCCESS;
    }
}

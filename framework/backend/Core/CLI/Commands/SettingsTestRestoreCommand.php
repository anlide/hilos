<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Database\Settings\RawSettingSnapshot;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;

/** Asks the live settings owner to restore exact raw state on a non-production stand. */
class SettingsTestRestoreCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** No options: the complete request is positional. */
    public const array OPTIONS = [];

    /** @return string Test-only command name */
    public function getName(): string
    {
        return CliCommands::SETTINGS_TEST_RESTORE;
    }

    /** @return string Description of the owner operation */
    public function getDescription(): string
    {
        return 'Restore exact raw settings state through the live owner (test-only)';
    }

    /** @return string Positional usage and environment restriction */
    public function getHelp(): string
    {
        return 'test:settings:restore <snapshot-json> (non-production only)';
    }

    /**
     * Parses one argument, asks the daemon, and renders its acknowledgement.
     *
     * @param array<string, mixed> $options Unused options
     * @param list<string> $args Snapshot JSON as one argument
     * @return int Process exit code
     * @throws CommandException When the command is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if (count($args) !== 1) {
            echo "Usage: test:settings:restore <snapshot-json>\n";
            return ExitCode::INVALID_ARGUMENT;
        }
        try {
            $snapshot = RawSettingSnapshot::fromJson($args[0]);
        } catch (HilosException) {
            echo "Invalid settings snapshot.\n";
            return ExitCode::INVALID_ARGUMENT;
        }
        $payload = [SettingsCommandConstants::FIELD_SNAPSHOT => $snapshot->toArray()];
        try {
            $result = $this->sendCommand($this->getName(), $payload);
        } catch (EnvException $failure) {
            echo "Error: {$failure->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }
        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }
        if (($result->reply->payload[SettingsCommandConstants::FIELD_RESTORED] ?? null) !== true) {
            echo "Command failed: settings restoration was not acknowledged\n";
            return ExitCode::ERROR;
        }
        echo "Settings snapshot restored.\n";

        return ExitCode::SUCCESS;
    }
}

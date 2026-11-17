<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Settings\RawSettingSnapshot;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;

/** Asks the live settings owner to snapshot exact raw state on a non-production stand. */
class SettingsTestSnapshotCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** No options: the complete request is positional. */
    public const array OPTIONS = [];

    /** @return string Test-only command name */
    public function getName(): string
    {
        return CliCommands::SETTINGS_TEST_SNAPSHOT;
    }

    /** @return string Description of the owner operation */
    public function getDescription(): string
    {
        return 'Snapshot exact raw settings state through the live owner (test-only)';
    }

    /** @return string Positional usage and environment restriction */
    public function getHelp(): string
    {
        return 'test:settings:snapshot <key> (non-production only)';
    }

    /**
     * Parses one argument, asks the daemon, and renders its acknowledgement.
     *
     * @param array<string, mixed> $options Unused options
     * @param list<string> $args Settings key
     * @return int Process exit code
     * @throws CommandException When the command is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if (count($args) !== 1 || $args[0] === '') {
            echo "Usage: test:settings:snapshot <key>\n";
            return ExitCode::INVALID_ARGUMENT;
        }
        $payload = [SettingsCommandConstants::FIELD_KEY => $args[0]];
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
        try {
            $raw = $result->reply->payload[SettingsCommandConstants::FIELD_SNAPSHOT] ?? null;
            if (!is_array($raw)) {
                throw new InvalidFormatException('The reply carries no snapshot.');
            }
            $snapshot = RawSettingSnapshot::fromArray($raw);
        } catch (HilosException) {
            echo "Command failed: invalid settings snapshot reply\n";
            return ExitCode::ERROR;
        }
        echo $snapshot->toJson() . "\n";

        return ExitCode::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Status\StatusTestCommandConstants;

/** Test-only CLI half; the Status owner commits cleanup and publishes the fixture's absence. */
class StatusTestSilenceRemoveCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::STATUS_TEST_SILENCE_REMOVE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Remove one closed test-owned Status silence period (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <publicId>

Description:
  Remove exactly the named closed silence period through the live Status owner.
  The owner first commits and removes any matching private transit, then deletes
  the archive row and publishes its absence. Refused during maintenance, while
  publication is held or the target remains unfinished, and outside test environments.

Arguments:
  <publicId>  Public id of the closed silence period

Exit codes:
  0  the closed silence period is absent from the published pair
  1  the daemon did not answer, refused, or answered without the public id
  2  the public id is missing or blank, or more arguments were given
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} 0123456789abcdef0123456789abcdef
HELP;
    }

    /**
     * Asks the Status owner to remove one closed test fixture and prints its identity.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args The closed silence period's public id
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $publicId = $args[0] ?? null;
        if (count($args) !== 1 || !is_string($publicId) || trim($publicId) === '') {
            echo "Usage: {$this->getName()} <publicId>\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), [StatusTestCommandConstants::FIELD_PUBLIC_ID => $publicId]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        $removedId = $result->reply->payload[StatusTestCommandConstants::FIELD_PUBLIC_ID] ?? null;
        if ($removedId !== $publicId) {
            echo "Command failed: the reply carries no matching public id\n";
            return ExitCode::ERROR;
        }
        echo "Removed closed Status silence period {$publicId}\n";
        return ExitCode::SUCCESS;
    }
}

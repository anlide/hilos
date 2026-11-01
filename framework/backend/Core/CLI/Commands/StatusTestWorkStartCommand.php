<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusTestCommandConstants;

/**
 * Asks the live Status owner to start an announced maintenance work now (test-only, HIL-1856).
 *
 * The CLI half only: the owner ({@see StatusAgent}) moves the work's start to now, rounded down to
 * a whole second, inside its ordinary work write and without mail to subscribers, and answers
 * with the new start and version. Why a work cannot be moved is the owner's to say.
 */
class StatusTestWorkStartCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::STATUS_TEST_WORK_START;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Move an announced maintenance work\'s start to now, by its public id (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <publicId>

Description:
  Start an announced maintenance work now instead of waiting for its scheduled
  start. The Status owner moves the start to now, rounded down to a whole second,
  in its ordinary work write, and no mail goes to subscribers. When the start
  actually moves, the version goes up by one and the public page reads the
  change; a work whose start is already this second keeps its version.
  Refused for a work that is gone, canceled, started or expired, for a window that
  would overlap another announcement, during maintenance, and on a
  production-like environment.

Arguments:
  <publicId>  Public id of the maintenance announcement

Exit codes:
  0  the work's start was moved
  1  the daemon did not answer, refused, or answered without the new start
  2  the public id is missing or blank, or more arguments were given
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} 0123456789abcdef0123456789abcdef
HELP;
    }

    /**
     * Asks the Status owner to start the work now and prints its new start and version.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args The work's public id
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

        $startedId = $result->reply->payload[StatusTestCommandConstants::FIELD_PUBLIC_ID] ?? null;
        $startsAt = $result->reply->payload[StatusTestCommandConstants::FIELD_STARTS_AT] ?? null;
        $version = $result->reply->payload[StatusTestCommandConstants::FIELD_VERSION] ?? null;
        if (!is_string($startedId) || !is_int($startsAt) || !is_int($version)) {
            echo "Command failed: the reply carries no public id, start or version\n";

            return ExitCode::ERROR;
        }

        echo "Started maintenance announcement {$startedId}: starts at epoch ms {$startsAt}, version {$version}\n";

        return ExitCode::SUCCESS;
    }
}

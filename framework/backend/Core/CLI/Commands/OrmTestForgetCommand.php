<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Database\OrmTestConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use JsonException;

/** Asks one live process to forget its loaded ORM data without opening SQL here. */
class OrmTestForgetCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Test-only command name */
    public function getName(): string
    {
        return CliCommands::ORM_TEST_FORGET;
    }

    /** @return string Registry help description */
    public function getDescription(): string
    {
        return 'Forget loaded ORM data in one daemon or worker (test-only)';
    }

    /** @return string Target syntax and restrictions */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:orm:forget

Usage:
  php cli.php test:orm:forget daemon
  php cli.php test:orm:forget worker <workerId>

Forgets loaded ORM data only in the named process. The signed worker id is
positive for a regular worker and negative for a monopolistic worker.
Requires a known non-production environment and no open transactions.
HELP;
    }

    /**
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Process and optional signed worker id
     * @return int Command exit code
     * @throws CommandException When the wire name is not test-only
     * @throws JsonException When the success payload cannot be encoded
     */
    protected function run(array $options, array $args): int
    {
        $process = $args[0] ?? null;
        $payload = [OrmTestConstants::FIELD_PROCESS => $process];
        if ($process === OrmTestConstants::PROCESS_DAEMON && count($args) === 1) {
            // The daemon target deliberately omits workerId.
        } elseif ($process === OrmTestConstants::PROCESS_WORKER && count($args) === 2
            && preg_match('/^[+-]?[0-9]+$/D', $args[1]) === 1
            && ($workerId = filter_var($args[1], FILTER_VALIDATE_INT)) !== false && $workerId !== 0) {
            $payload[OrmTestConstants::FIELD_WORKER_ID] = $workerId;
        } else {
            return $this->printRefusal(CommandReplyDTO::error($this->getName(), OrmTestConstants::INVALID_TARGET));
        }

        try {
            $result = $this->sendCommand(CliCommands::ORM_TEST_FORGET, $payload);
        } catch (EnvException $failure) {
            $this->printToStandardError($failure->getMessage());
            return ExitCode::CONFIG_ERROR;
        }
        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::ORM_TEST_FORGET);
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }
        echo json_encode($result->reply->payload, JSON_THROW_ON_ERROR) . "\n";
        return ExitCode::SUCCESS;
    }
}

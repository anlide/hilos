<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\ProtectedModeReAskVerdict;

/**
 * One state re-ask shared by the unrelated protected-mode operator commands.
 */
trait ProtectedModeReAskTrait
{
    use CommandChannelClientTrait;

    /**
     * @param string $command Drive command whose reply was lost
     * @param ?string $operation Operation the drive named, or null
     * @param list<string> $takenPhases Phases that mean the drive happened
     * @return ProtectedModeReAskOutcome Verdict plus the snapshot it was read from
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    protected function reAskProtectedMode(
        string $command,
        ?string $operation,
        array $takenPhases,
    ): ProtectedModeReAskOutcome {
        $result = $this->sendCommand(
            CliCommands::PROTECTED_MODE_INSPECT,
            [],
            CommandChannelWindows::RE_ASK_WAIT_SECONDS,
        );
        if ($result->reply === null || !$result->reply->isOk()) {
            return new ProtectedModeReAskOutcome(ProtectedModeReAskVerdict::UNKNOWN, [], $command);
        }

        return new ProtectedModeReAskOutcome(
            ProtectedModeReAskVerdict::read($result->reply->payload, $operation, $takenPhases),
            $result->reply->payload,
            $command,
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Exception;

use Hilos\Environment\NonProductionGate;
use Hilos\HilosException;

/**
 * Thrown when a test-only CLI command is invoked in a production-like (or unset) environment.
 *
 * Also the home of the refusal SENTENCE, which the command socket answers with without
 * throwing anything: the CLI half raises, the socket half replies, and a caller that drives
 * a command both ways must not have to recognize two wordings for one verdict
 * ({@see NonProductionGate}).
 */
final class TestOnlyCommandOnProductionException extends HilosException
{
    /**
     * @param string $commandName Name of the refused command
     */
    public function __construct(string $commandName)
    {
        parent::__construct(self::message($commandName));
    }

    /**
     * Builds the refusal sentence for one command name.
     *
     * @param string $commandName Name of the refused command
     * @return string Refusal sentence, identical on both transports
     */
    public static function message(string $commandName): string
    {
        return "Test-only command '{$commandName}' is refused: APP_ENV is production-like or unset";
    }
}

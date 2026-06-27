<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Exception;

use Hilos\HilosException;

/**
 * Thrown when a test-only CLI command is invoked in a production-like (or unset) environment.
 */
final class TestOnlyCommandOnProductionException extends HilosException
{
    /**
     * @param string $commandName Name of the refused command
     */
    public function __construct(string $commandName)
    {
        parent::__construct(
            "Test-only command '{$commandName}' is refused: APP_ENV is production-like or unset",
        );
    }
}

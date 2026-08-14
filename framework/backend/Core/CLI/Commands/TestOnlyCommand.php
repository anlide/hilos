<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/**
 * Base for CLI commands that may only run outside production — state-manipulation
 * commands used by tests (create/delete an orphan row, fast-forward a deletion timer).
 *
 * Subclassing is the marker: a command that extends this is test-only by contract, so a
 * reader sees the parent and knows it must never run on prod. execute() is final so the
 * guard cannot be skipped; subclasses implement run().
 */
abstract class TestOnlyCommand implements CommandInterface
{
    /**
     * Refuses on a production-like or unset APP_ENV, then runs the command body.
     *
     * @param array<string, mixed> $options Parsed options (--key=value or --flag)
     * @param list<string> $args Positional arguments
     * @return int Exit code (0 = success)
     * @throws TestOnlyCommandOnProductionException When APP_ENV is production-like or unset
     * @throws HilosException When the command body refuses its input or its work fails
     * @throws JsonException When the command cannot encode its output
     */
    final public function execute(array $options, array $args): int
    {
        $appEnv = AppEnv::fromString(Hilos::$env[EnvConstants::APP_ENV]);
        if ($appEnv === null || $appEnv->isProductionLike()) {
            throw new TestOnlyCommandOnProductionException($this->getName());
        }

        return $this->run($options, $args);
    }

    /**
     * Command body, run only after the non-production guard passes.
     *
     * @param array<string, mixed> $options Parsed options (--key=value or --flag)
     * @param list<string> $args Positional arguments
     * @return int Exit code (0 = success)
     * @throws HilosException When the command body refuses its input or its work fails
     * @throws JsonException When the command cannot encode its output
     */
    abstract protected function run(array $options, array $args): int;
}

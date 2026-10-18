<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Stand-in for the production command interface. The toy tree names its own so a
 * second declaration of the real interface cannot collide with the autoloader.
 */
interface CommandInterface
{
    /** @var list<string> Option names a command reads */
    public const array OPTIONS = [];

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int;
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * An abstract command that reads an option and declares none. It is not judged:
 * only a concrete command answers for a read.
 */
abstract class AbstractBareCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        $value = $options['bare'];

        return $value === null ? count($args) : 0;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * A trait whose read is judged on the command that uses it.
 */
trait OptionReader
{
    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function readFromTrait(array $options, array $args): int
    {
        $value = $options['from-trait'];

        return $value === null ? count($args) : 0;
    }
}

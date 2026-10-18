<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Passes `$options` to another class, which is a finding, and to its own method,
 * which is not.
 */
final class HandoffCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        ForeignParser::take($options);

        return $this->run($options, $args);
    }

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    private function run(array $options, array $args): int
    {
        return count($args);
    }
}

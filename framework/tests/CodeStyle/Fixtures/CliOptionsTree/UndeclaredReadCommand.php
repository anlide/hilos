<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Reads an option and declares none. The read is a finding.
 */
final class UndeclaredReadCommand implements CommandInterface
{
    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        $value = $options['missing'];

        return $value === null ? count($args) : 0;
    }
}

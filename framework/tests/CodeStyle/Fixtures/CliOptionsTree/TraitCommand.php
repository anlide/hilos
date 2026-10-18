<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Uses a trait that reads an option this command does not declare.
 */
final class TraitCommand implements CommandInterface
{
    use OptionReader;

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        return $this->readFromTrait($options, $args);
    }
}

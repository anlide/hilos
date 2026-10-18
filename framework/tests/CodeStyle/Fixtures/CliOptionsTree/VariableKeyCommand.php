<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Reads `$options` through a variable. The guard cannot name that key.
 */
final class VariableKeyCommand implements CommandInterface
{
    /** @var list<string> Option names this command reads */
    public const array OPTIONS = ['window'];

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        $name = 'window';
        $value = $options[$name];

        return $value === null ? count($args) : 0;
    }
}

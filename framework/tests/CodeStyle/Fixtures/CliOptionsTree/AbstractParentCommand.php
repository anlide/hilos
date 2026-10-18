<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Declares the option its method reads. A concrete child that replaces OPTIONS
 * without spreading this list drops the name, and the read is then a finding.
 */
abstract class AbstractParentCommand implements CommandInterface
{
    /** @var list<string> Option names this command reads */
    public const array OPTIONS = ['from-parent'];

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        return $this->readInherited($options, $args);
    }

    /**
     * @param array<string, mixed> $options Parsed options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function readInherited(array $options, array $args): int
    {
        $value = $options['from-parent'];

        return $value === null ? count($args) : 0;
    }
}

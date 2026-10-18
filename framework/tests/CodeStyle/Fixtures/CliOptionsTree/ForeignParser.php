<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\CliOptionsTree;

/**
 * Not a command. A read of `$options` here is silent, and receiving the variable
 * from a command is the command's finding.
 */
final class ForeignParser
{
    /**
     * @param array<string, mixed> $options Parsed options
     */
    public static function take(array $options): void
    {
        $value = $options['x'];
        if ($value === null) {
            return;
        }
    }
}

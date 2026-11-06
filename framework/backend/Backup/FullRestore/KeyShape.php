<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class KeyShape
{
    /**
     * @param string $kind PRIMARY, UNIQUE or ordinary INDEX
     * @param array<string, string> $columns Ordered columns with prefix/direction
     * @param string $algorithm Physical index algorithm
     */
    public function __construct(public string $kind, public array $columns, public string $algorithm)
    {
    }
}

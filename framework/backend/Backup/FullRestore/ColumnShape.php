<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class ColumnShape
{
    /**
     * @param string $type Normalized SQL type, including length and signedness
     * @param bool $nullable Effective nullability, including primary-key implications
     * @param ?string $default Exact default expression, or no default on a required column
     * @param array<string, string> $attributes All admitted remaining attributes
     */
    public function __construct(public string $type, public bool $nullable, public ?string $default, public array $attributes)
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Numeric identity with a name fetched from the current person row. */
final readonly class ChangeLogPerson
{
    public function __construct(public int $userId, public string $label)
    {
    }
}

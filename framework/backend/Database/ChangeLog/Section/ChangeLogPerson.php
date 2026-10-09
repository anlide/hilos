<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Numeric identity with a current name or a label for a deleted person. */
final readonly class ChangeLogPerson
{
    public function __construct(public int $userId, public string $label, public bool $deleted)
    {
    }
}

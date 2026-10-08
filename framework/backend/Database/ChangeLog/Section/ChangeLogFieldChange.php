<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Presence flags distinguish an absent side from a present SQL NULL. */
final readonly class ChangeLogFieldChange
{
    public function __construct(
        public string $field,
        public string $kind,
        public bool $oldPresent,
        public bool $newPresent,
        public ?string $oldValue,
        public ?string $newValue,
        public bool $bodyOmitted,
    ) {
    }
}

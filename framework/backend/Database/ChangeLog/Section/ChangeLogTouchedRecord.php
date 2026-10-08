<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** The one changed record when a receipt touched a table only once. */
final readonly class ChangeLogTouchedRecord
{
    /**
     * @param ?list<int|string|null> $recordKey Null after anonymized restore
     * @param string $mutation create, update, or delete
     * @param int $changedFields Number of changed field rows
     */
    public function __construct(
        public ?array $recordKey,
        public string $mutation,
        public int $changedFields,
    ) {
    }
}

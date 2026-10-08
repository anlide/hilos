<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** One changed record and its field changes. */
final readonly class ChangeLogEntry
{
    /**
     * @param int $id Journal row number
     * @param DateTimeImmutable $createdAt UTC source moment
     * @param ?int $receiptId Null for a write past the application
     * @param string $table Journal dictionary table name
     * @param ?list<int|string|null> $recordKey Null after anonymized restore
     * @param string $mutation create, update, or delete
     * @param list<ChangeLogFieldChange> $changes Fields in trigger order
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public ?int $receiptId,
        public string $table,
        public ?array $recordKey,
        public string $mutation,
        public array $changes,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** One bounded feed row: a nonempty receipt or one unattributed journal entry. */
final readonly class ChangeLogFeedItem
{
    public function __construct(
        public ChangeLogFeedItemKind $kind,
        public DateTimeImmutable $createdAt,
        public ?ChangeLogReceipt $receipt,
        public ?ChangeLogEntry $entry,
    ) {
    }
}

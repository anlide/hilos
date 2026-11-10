<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** One bounded feed row: a nonempty receipt or one unattributed journal entry. */
final readonly class ChangeLogFeedItem
{
    /**
     * @param ChangeLogFeedItemKind $kind Receipt or unattributed entry
     * @param int $createdAt Source epoch milliseconds
     * @param ?ChangeLogReceipt $receipt Summary for the receipt kind
     * @param ?ChangeLogEntry $entry Record for the entry kind
     */
    public function __construct(
        public ChangeLogFeedItemKind $kind,
        public int $createdAt,
        public ?ChangeLogReceipt $receipt,
        public ?ChangeLogEntry $entry,
    ) {
    }
}

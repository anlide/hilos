<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Full key of a feed row, including its source kind. */
final readonly class ChangeLogFeedAnchor
{
    /**
     * @param int $createdAt Source epoch milliseconds
     * @param ChangeLogFeedItemKind $kind Receipt or unattributed entry
     * @param int $id Row number within its source kind
     */
    public function __construct(
        public int $createdAt,
        public ChangeLogFeedItemKind $kind,
        public int $id,
    ) {
    }
}

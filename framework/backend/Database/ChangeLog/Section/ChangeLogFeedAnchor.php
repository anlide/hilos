<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** Full key of a feed row, including its source kind. */
final readonly class ChangeLogFeedAnchor
{
    public function __construct(
        public DateTimeImmutable $createdAt,
        public ChangeLogFeedItemKind $kind,
        public int $id,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** Attribution needed beside one history row, without loading a receipt's other entries. */
final readonly class ChangeLogReceiptHeader
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public ?ChangeLogPerson $actor,
        public ?ChangeLogPerson $subject,
        public ?int $sessionId,
        public string $channel,
        public string $action,
        public ?string $agent,
        public ?string $source,
    ) {
    }
}

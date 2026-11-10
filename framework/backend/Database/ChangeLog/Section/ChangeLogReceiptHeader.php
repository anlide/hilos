<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

/** Attribution needed beside one history row, without loading a receipt's other entries. */
final readonly class ChangeLogReceiptHeader
{
    /**
     * @param int $id Receipt row number
     * @param int $createdAt Source epoch milliseconds
     * @param ?ChangeLogPerson $actor Person at the keyboard
     * @param ?ChangeLogPerson $subject Person acted for
     * @param ?int $sessionId Browser session number
     * @param string $channel Operation channel
     * @param string $action Operation name
     * @param ?string $agent Agent responsible for the operation
     * @param ?string $source Operation source description
     */
    public function __construct(
        public int $id,
        public int $createdAt,
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

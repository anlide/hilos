<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;

/** Receipt header and bounded-window summary of every table it touched. */
final readonly class ChangeLogReceipt
{
    /**
     * @param int $id Receipt row number
     * @param DateTimeImmutable $createdAt UTC source moment
     * @param ?ChangeLogPerson $actor Person at the keyboard
     * @param ?ChangeLogPerson $subject Person acted for
     * @param ?int $sessionId Browser session number
     * @param string $channel Attribution channel
     * @param string $action Server action name
     * @param ?string $agent Agent identifier
     * @param ?string $source Session or migration source
     * @param int $entryCount Number of journal rows
     * @param list<ChangeLogTouchedTable> $touched Tables in first-touch order
     */
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
        public int $entryCount,
        public array $touched,
    ) {
    }
}

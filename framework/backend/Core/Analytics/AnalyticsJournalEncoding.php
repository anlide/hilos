<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** The result of encoding an event for one journal line. */
final readonly class AnalyticsJournalEncoding
{
    /**
     * @param ?string $line Encoded line, or null when the whole event was dropped
     * @param bool $payloadDropped Whether an event was kept after removing its payload
     */
    public function __construct(public ?string $line, public bool $payloadDropped)
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** What a node journal discarded under a restore freeze. */
final readonly class AnalyticsJournalDiscard
{
    /**
     * @param int $files Journal files removed
     * @param int $events Events the removed files held
     * @param ?int $oldestOpenedTs Opening moment of the oldest removed file
     */
    public function __construct(public int $files, public int $events, public ?int $oldestOpenedTs)
    {
    }
}

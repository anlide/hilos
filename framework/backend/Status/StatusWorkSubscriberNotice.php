<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Detached public words and clocks; later edits never change a queued letter. */
final readonly class StatusWorkSubscriberNotice
{
    public const string SCHEDULED = 'scheduled';
    public const string CHANGED = 'changed';
    public const string CANCELED = 'canceled';
    public const string COMPLETED = 'completed';

    /**
     * @param string $kind Scheduled, changed, canceled or completed event
     * @param ?int $workId Primary id captured with the public identity, or null after a restore removed its row
     * @param StatusPublicWork $work Immutable public announcement snapshot
     * @param int $eventAt Event moment in UTC epoch milliseconds
     * @param string $componentNames Public component names at the event
     * @param ?string $changesText Detached description of the changed fields
     * @param ?int $endedAt Exact observed end, or null after an observation gap
     * @param ?int $completionObservedAt First proven inactive observation, or null for announcement events
     */
    public function __construct(
        public string $kind,
        public ?int $workId,
        public StatusPublicWork $work,
        public int $eventAt,
        public string $componentNames,
        public ?string $changesText = null,
        public ?int $endedAt = null,
        public ?int $completionObservedAt = null,
    ) {
    }
}

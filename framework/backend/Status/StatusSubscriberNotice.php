<?php

declare(strict_types=1);

namespace Hilos\Status;

/** Detached public event; later incident edits cannot change a queued letter. */
final readonly class StatusSubscriberNotice
{
    /**
     * @param int $updateId Newly appended timeline primary id
     * @param int $incidentId Incident primary id at append
     * @param string $componentName Public component name at append
     * @param StatusUpdateKind $kind Published, team or resolved event
     * @param StatusSeverity $severity Severity saved with the update
     * @param string $text Public text saved with the update
     * @param int $detectedAt Incident detection in UTC epoch milliseconds
     * @param int $eventAt Update moment in UTC epoch milliseconds
     * @param ?int $resolvedAt Resolution moment, present only for a resolved event
     */
    public function __construct(
        public int $updateId,
        public int $incidentId,
        public string $componentName,
        public StatusUpdateKind $kind,
        public StatusSeverity $severity,
        public string $text,
        public int $detectedAt,
        public int $eventAt,
        public ?int $resolvedAt,
    ) {
    }
}

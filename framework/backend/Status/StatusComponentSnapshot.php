<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Database\View\Item\StatusIncident;

/** Temporary read projection; the incident remains a DB View, with no copied timeline in RT. */
final readonly class StatusComponentSnapshot
{
    /**
     * @param StatusComponentDefinition $component Declared public identity and agent type
     * @param StatusComponentState $state Current read verdict
     * @param ?int $expectedInstances Expected slots, or null before the owner has initialized them
     * @param ?int $liveInstances Observed slots, or null without a complete observation
     * @param ?int $observedAt Observation epoch milliseconds UTC, or null
     * @param ?StatusIncident $incident Open DB fact, when available
     */
    public function __construct(
        public StatusComponentDefinition $component,
        public StatusComponentState $state,
        public ?int $expectedInstances,
        public ?int $liveInstances,
        public ?int $observedAt,
        public ?StatusIncident $incident,
    ) {
    }
}

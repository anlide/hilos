<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Demo\Chat\Hilos;
use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;

/**
 * DbEventsReadCapability - Guardian capability for reading chat events from database.
 *
 * Returns recent events sorted by id for guardian analysis.
 */
final class DbEventsReadCapability extends AbstractGuardianCapability
{
    /**
     * Get capability name.
     *
     * @return string Capability name
     */
    public function getName(): string
    {
        return 'db.events.read';
    }

    /**
     * Execute capability: read recent events from database.
     *
     * @param array $payload Payload (limit: max events to return, default 20)
     * @param array $context Execution context (unused)
     * @return CapabilityResult Result with events array
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult
    {
        $limit = (int) ($payload['limit'] ?? 20);
        $limit = max(1, min($limit, 200));

        $events = [];
        foreach (Hilos::$db->events as $event) {
            $events[] = $event->toArray();
        }

        usort(
            $events,
            static fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0))
        );

        return new CapabilityResult(
            ok: true,
            data: ['events' => array_slice($events, -$limit)],
        );
    }
}

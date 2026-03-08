<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Demo\Chat\Hilos;
use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;

final class DbEventsReadCapability extends AbstractGuardianCapability
{
    public function getName(): string
    {
        return 'db.events.read';
    }

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

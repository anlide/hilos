<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/** A single read of catalog, current RT counts and open incidents; never a cache or a browser wire contract. */
final readonly class StatusSnapshot
{
    /**
     * @param array<string, StatusComponentSnapshot> $components Components in declaration order
     * @param list<StatusIncident>|null $incidents Open facts, null while unavailable and empty when known absent
     */
    private function __construct(public array $components, public ?array $incidents)
    {
    }

    /**
     * The unconditional freeze row overlays every component, even if its owner has stopped.
     * Null runtime means this process holds no runtime state; it cannot attest current health.
     * Readers must declare the incident DB source and component RT source through the existing interest mechanism.
     *
     * @return self Temporary projection with explicit unavailable incident data
     * @throws HilosException When declarations or source-interest wiring are invalid
     */
    public static function read(): self
    {
        $catalog = Hilos::statusComponents();
        $enabled = Hilos::hasFeature(HilosFeature::STATUS);
        $maintenance = Hilos::$rt?->hilosProtectedModeRuntime !== null
            && Hilos::$rt->hilosProtectedModeRuntime->phase !== ProtectedModeRuntime::PHASE_INACTIVE;
        $incidents = null;
        if ($enabled && Hilos::$db !== null && Hilos::$rt?->hilosProtectedModeRuntime !== null
            && !Hilos::$rt->hilosProtectedModeRuntime->silencesUnstoppedWriters()) {
            try {
                $incidents = [];
                foreach (Hilos::$db->statusIncidents->whereColumnIn(
                    EntityStatusIncident::active_component_key,
                    ...array_keys(iterator_to_array($catalog)),
                ) as $incident) {
                    $incidents[$incident->componentKey] = $incident;
                }
            } catch (DatabaseException) {
                $incidents = null;
            }
        }
        $components = [];
        foreach ($catalog as $key => $component) {
            $hasCounts = $enabled && Hilos::$rt !== null && isset(Hilos::$rt->hilosStatusComponents[$key]);
            $incident = $incidents[$key] ?? null;
            $state = StatusComponentState::UNKNOWN;
            if ($maintenance) {
                $state = StatusComponentState::MAINTENANCE;
            } elseif ($hasCounts && Hilos::$rt->hilosStatusComponents[$key]->staleSince() === null
                && Hilos::$rt->hilosStatusComponents[$key]->liveInstances !== null
                && Hilos::$rt->hilosStatusComponents[$key]->observedAt !== null && $incidents !== null) {
                if ($incident !== null) {
                    $state = $incident->publishedAt === null ? StatusComponentState::CHECKING
                        : StatusComponentState::from($incident->severity);
                } elseif (Hilos::$rt->hilosStatusComponents[$key]->liveInstances >= Hilos::$rt->hilosStatusComponents[$key]->expectedInstances) {
                    $state = StatusComponentState::OPERATIONAL;
                }
            }
            $components[$key] = new StatusComponentSnapshot(
                $component,
                $state,
                $hasCounts ? Hilos::$rt->hilosStatusComponents[$key]->expectedInstances : null,
                $hasCounts ? Hilos::$rt->hilosStatusComponents[$key]->liveInstances : null,
                $hasCounts ? Hilos::$rt->hilosStatusComponents[$key]->observedAt : null,
                $incident,
            );
        }
        return new self($components, $incidents === null ? null : array_values($incidents));
    }
}

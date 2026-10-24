<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Status\StatusComponentSnapshot;

/** One open fact and its own timeline, read without copying history into runtime state. */
final readonly class StatusIncidentData
{
    public const string incidentId = 'incidentId';
    public const string componentKey = 'componentKey';
    public const string componentName = 'componentName';
    public const string agentType = 'agentType';
    public const string severity = 'severity';
    public const string detectedAt = 'detectedAt';
    public const string publishAt = 'publishAt';
    public const string publishedAt = 'publishedAt';
    public const string suppressedAt = 'suppressedAt';
    public const string expectedInstances = 'expectedInstances';
    public const string liveInstances = 'liveInstances';
    public const string observedAt = 'observedAt';
    public const string updates = 'updates';

    /**
     * @param int $incidentId Persisted incident id
     * @param string $componentKey Persisted component key
     * @param string $componentName Detection-time component name
     * @param string $agentType Current catalog agent type
     * @param string $severity Current durable severity
     * @param int $detectedAt Detection epoch milliseconds UTC
     * @param int $publishAt Original publication deadline, epoch milliseconds UTC
     * @param ?int $publishedAt Publication epoch milliseconds UTC, or null
     * @param ?int $suppressedAt Suppression epoch milliseconds UTC, or null
     * @param ?int $expectedInstances Current expected slots, or null before initialization
     * @param ?int $liveInstances Current observed slots, or null when unknown
     * @param ?int $observedAt Observation epoch milliseconds UTC, or null
     * @param list<StatusIncidentUpdateData> $updates Timeline, newest first by moment and id
     */
    public function __construct(
        public int $incidentId,
        public string $componentKey,
        public string $componentName,
        public string $agentType,
        public string $severity,
        public int $detectedAt,
        public int $publishAt,
        public ?int $publishedAt,
        public ?int $suppressedAt,
        public ?int $expectedInstances,
        public ?int $liveInstances,
        public ?int $observedAt,
        public array $updates,
    ) {
    }

    /**
     * @param StatusIncident $incident Active fact selected by the snapshot
     * @param StatusComponentSnapshot $component Current catalog and RT projection
     * @return self Browser incident retaining the stored deadline and suppression
     */
    public static function fromIncident(StatusIncident $incident, StatusComponentSnapshot $component): self
    {
        $updates = [];
        foreach ($incident->updates as $update) {
            $updates[] = StatusIncidentUpdateData::fromUpdate($update);
        }
        usort($updates, static fn (StatusIncidentUpdateData $left, StatusIncidentUpdateData $right): int =>
            $right->at <=> $left->at ?: $right->id <=> $left->id);

        return new self(
            $incident->id, $incident->componentKey, $incident->componentName, $component->component->agentType,
            $incident->severity, $incident->detectedAt, $incident->publishAt, $incident->publishedAt, $incident->suppressedAt,
            $component->expectedInstances, $component->liveInstances, $component->observedAt, $updates,
        );
    }

    /** @return array<string, WireField> DB origins and computed catalog/RT facts */
    public static function wireFields(): array
    {
        return [
            self::incidentId => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::id),
            self::componentKey => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::component_key),
            self::componentName => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::component_name),
            self::agentType => WireField::notPersonal(),
            self::severity => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::severity),
            self::detectedAt => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::detected_at),
            self::publishAt => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::publish_at),
            self::publishedAt => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::published_at),
            self::suppressedAt => WireField::column(HilosDbContext::statusIncidents, EntityStatusIncident::suppressed_at),
            self::expectedInstances => WireField::notPersonal(),
            self::liveInstances => WireField::notPersonal(),
            self::observedAt => WireField::notPersonal(),
            self::updates => WireField::each(StatusIncidentUpdateData::wireFields()),
        ];
    }

    /** @return array<string, mixed> Open incident with explicit nullable facts */
    public function toArray(): array
    {
        return [
            self::incidentId => $this->incidentId,
            self::componentKey => $this->componentKey,
            self::componentName => $this->componentName,
            self::agentType => $this->agentType,
            self::severity => $this->severity,
            self::detectedAt => $this->detectedAt,
            self::publishAt => $this->publishAt,
            self::publishedAt => $this->publishedAt,
            self::suppressedAt => $this->suppressedAt,
            self::expectedInstances => $this->expectedInstances,
            self::liveInstances => $this->liveInstances,
            self::observedAt => $this->observedAt,
            self::updates => array_map(static fn (StatusIncidentUpdateData $update): array => $update->toArray(), $this->updates),
        ];
    }
}

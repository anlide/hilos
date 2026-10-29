<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\Status\StatusIncidentOutcome;
use Hilos\Status\StatusSeverity;

/** Completed fact projected from storage, without current catalog or announcement joins. */
final readonly class StatusHistoryEntry
{
    public const string INCIDENT = 'incident';
    public const string MAINTENANCE = 'maintenance';
    public const string MAINTENANCE_COMPLETED = 'maintenance_completed';

    public string $id;

    /**
     * @param string $kind Persisted source namespace
     * @param int $sourceId Positive numeric primary key of that source
     * @param int $startedAt Start in epoch milliseconds UTC
     * @param ?int $endedAt Exact end in epoch milliseconds UTC, null when unproven
     * @param ?int $completionObservedAt Maintenance completion observation in epoch milliseconds UTC
     * @param ?int $publishedAt Incident publication in epoch milliseconds UTC
     * @param string $outcome Retained completion reason
     * @param string $title Historical component name or period title
     * @param list<string> $componentKeys Historical component set
     * @param ?string $severity Incident severity, peak after publication
     * @param ?bool $announcementKnown Maintenance announcement classification
     * @param ?string $workPublicId Historical public work identity
     */
    private function __construct(
        public string $kind,
        public int $sourceId,
        public int $startedAt,
        public ?int $endedAt,
        public ?int $completionObservedAt,
        public ?int $publishedAt,
        public string $outcome,
        public string $title,
        public array $componentKeys,
        public ?string $severity,
        public ?bool $announcementKnown,
        public ?string $workPublicId,
    ) {
        $this->id = $kind . ':' . $sourceId;
    }

    /**
     * @param array<string, mixed> $row Completed incident SQL projection
     * @return self Detached incident with its saved name and publication severity
     * @throws DatabaseException When storage returns an incomplete or invalid completed incident
     */
    public static function fromIncidentRow(array $row): self
    {
        $severity = $row['published_at'] !== null ? ($row['peak_severity'] ?? $row['severity']) : $row['severity'];
        if ((int)$row['id'] <= 0 || $row['resolved_at'] === null || !is_string($row['outcome'])
            || StatusIncidentOutcome::tryFrom($row['outcome']) === null || StatusSeverity::tryFrom($severity) === null) {
            throw new DatabaseException('History requires a completed incident with its retained outcome and severity');
        }
        return new self(
            self::INCIDENT, (int)$row['id'], (int)$row['detected_at'], (int)$row['resolved_at'], null,
            $row['published_at'] === null ? null : (int)$row['published_at'], $row['outcome'], (string)$row['component_name'],
            [(string)$row['component_key']], $severity, null, null,
        );
    }

    /**
     * @param array<string, mixed> $row Completed maintenance SQL projection
     * @return self Detached period retaining an unknown exact end and historical announcement identity
     * @throws DatabaseException When storage returns an invalid identity, component set or incomplete period
     */
    public static function fromMaintenanceRow(array $row): self
    {
        $keys = json_decode((string)$row['component_keys_json'], true);
        if ((int)$row['id'] <= 0 || $row['completion_observed_at'] === null || !is_array($keys) || !array_is_list($keys)) {
            throw new DatabaseException('History requires a completed maintenance period and a component list');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new DatabaseException('History maintenance component keys must be nonempty strings');
            }
        }
        return new self(
            self::MAINTENANCE, (int)$row['id'], (int)$row['started_at'],
            $row['ended_at'] === null ? null : (int)$row['ended_at'], (int)$row['completion_observed_at'], null,
            self::MAINTENANCE_COMPLETED, (string)$row['title'], $keys, null, (bool)$row['announcement_known'],
            $row['work_public_id'] === null ? null : (string)$row['work_public_id'],
        );
    }

    /**
     * @param StatusIncident $incident Known completed source item
     * @return self Same projection as a detached incident read
     * @throws DatabaseException When the item is not a valid completed incident
     */
    public static function fromIncident(StatusIncident $incident): self
    {
        return self::fromIncidentRow([
            'id' => $incident->id, 'component_key' => $incident->componentKey, 'component_name' => $incident->componentName,
            'severity' => $incident->severity, 'peak_severity' => $incident->peakSeverity, 'detected_at' => $incident->detectedAt,
            'resolved_at' => $incident->resolvedAt, 'published_at' => $incident->publishedAt, 'outcome' => $incident->outcome,
        ]);
    }

    /**
     * @param StatusMaintenancePeriod $period Known completed source item
     * @return self Same projection as a detached maintenance read
     * @throws DatabaseException When the item is not a valid completed period
     */
    public static function fromMaintenance(StatusMaintenancePeriod $period): self
    {
        return self::fromMaintenanceRow([
            'id' => $period->id, 'started_at' => $period->startedAt, 'ended_at' => $period->endedAt,
            'completion_observed_at' => $period->completionObservedAt, 'title' => $period->title,
            'component_keys_json' => $period->componentKeysJson, 'announcement_known' => $period->announcementKnown,
            'work_public_id' => $period->workPublicId,
        ]);
    }

    /**
     * @return StatusHistoryAnchor Full tuple independent of the string namespace key
     * @throws ValidationException When a source returned an invalid primary key
     */
    public function anchor(): StatusHistoryAnchor
    {
        return new StatusHistoryAnchor(
            $this->startedAt,
            $this->kind === self::INCIDENT ? StatusHistoryAnchor::INCIDENT_RANK : StatusHistoryAnchor::MAINTENANCE_RANK,
            $this->sourceId,
        );
    }

    /** @return array<string, mixed> Exact read-only browser projection; all moments are epoch milliseconds UTC */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'sourceId' => $this->sourceId,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'completionObservedAt' => $this->completionObservedAt,
            'publishedAt' => $this->publishedAt,
            'outcome' => $this->outcome,
            'title' => $this->title,
            'componentKeys' => $this->componentKeys,
            'severity' => $this->severity,
            'announcementKnown' => $this->announcementKnown,
            'workPublicId' => $this->workPublicId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tables\Status;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Database\View\Item\StatusMaintenancePeriod;
use Hilos\Status\DTO\StatusHistoryEntry;
use Hilos\Status\StatusIncidentOutcome;
use Hilos\Status\StatusSeverity;

/** Normalized completed fact; its namespace is part of its stable browser identity. */
final class HilosStatusHistoryTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string kind = 'kind';
    public const string sourceId = 'sourceId';
    public const string startedAt = 'startedAt';
    public const string endedAt = 'endedAt';
    public const string completionObservedAt = 'completionObservedAt';
    public const string publishedAt = 'publishedAt';
    public const string outcome = 'outcome';
    public const string title = 'title';
    public const string componentKeys = 'componentKeys';
    public const string severity = 'severity';
    public const string announcementKnown = 'announcementKnown';
    public const string workPublicId = 'workPublicId';

    /**
     * @param string $id Namespaced row key
     * @param string $kind Incident or maintenance
     * @param int $sourceId Numeric source primary key
     * @param int $startedAt Start in UTC epoch milliseconds
     * @param ?int $endedAt Exact end in UTC epoch milliseconds, null when unknown
     * @param ?int $completionObservedAt Maintenance completion observation in UTC epoch milliseconds
     * @param ?int $publishedAt Incident publication in UTC epoch milliseconds
     * @param string $outcome Retained completion reason
     * @param string $title Saved component name or work title
     * @param list<string> $componentKeys Historical component keys
     * @param ?string $severity Incident severity, peak after publication
     * @param ?bool $announcementKnown Maintenance announcement classification
     * @param ?string $workPublicId Saved public work identity
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly int $sourceId,
        public readonly int $startedAt,
        public readonly ?int $endedAt,
        public readonly ?int $completionObservedAt,
        public readonly ?int $publishedAt,
        public readonly string $outcome,
        public readonly string $title,
        public readonly array $componentKeys,
        public readonly ?string $severity,
        public readonly ?bool $announcementKnown,
        public readonly ?string $workPublicId,
    ) {
    }

    /**
     * @param StatusHistoryEntry $entry Detached, typed completed fact
     * @return self Exact browser row
     */
    public static function fromEntry(StatusHistoryEntry $entry): self
    {
        return new self(...$entry->toArray());
    }

    /**
     * @param StatusIncident $incident Known completed source item
     * @return self Incident row with the same projection as a window
     * @throws DatabaseException When the source is not a valid completed incident
     */
    public static function fromIncident(StatusIncident $incident): self
    {
        return self::fromEntry(StatusHistoryEntry::fromIncident($incident));
    }

    /**
     * @param StatusMaintenancePeriod $period Known completed source item
     * @return self Maintenance row with the same projection as a window
     * @throws DatabaseException When the source is not a valid completed period
     */
    public static function fromMaintenance(StatusMaintenancePeriod $period): self
    {
        return self::fromEntry(StatusHistoryEntry::fromMaintenance($period));
    }

    /** @return string Namespaced primary key */
    public function getRowKey(): string
    {
        return $this->id;
    }

    /** @return string Browser identity field */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * @param array<string, mixed> $data Complete normalized row
     * @return static Typed completed row
     * @throws InvalidFormatException When a field or the completed-source identity is malformed
     */
    public static function fromArray(array $data): static
    {
        foreach ([self::id, self::kind, self::sourceId, self::startedAt, self::endedAt, self::completionObservedAt,
            self::publishedAt, self::outcome, self::title, self::componentKeys, self::severity,
            self::announcementKnown, self::workPublicId] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidFormatException('History row is missing field ' . $field);
            }
        }
        $row = new static(
            self::requireString($data, self::id),
            self::requireString($data, self::kind),
            self::requireInt($data, self::sourceId),
            self::requireInt($data, self::startedAt),
            self::optionalInt($data, self::endedAt),
            self::optionalInt($data, self::completionObservedAt),
            self::optionalInt($data, self::publishedAt),
            self::requireString($data, self::outcome),
            self::requireString($data, self::title),
            self::optionalStringList($data, self::componentKeys) ?? throw new InvalidFormatException('History component keys are required'),
            self::optionalString($data, self::severity),
            self::optionalBool($data, self::announcementKnown),
            self::optionalString($data, self::workPublicId),
        );
        if ($row->sourceId <= 0 || $row->id !== $row->kind . ':' . $row->sourceId
            || !in_array($row->kind, [StatusHistoryEntry::INCIDENT, StatusHistoryEntry::MAINTENANCE], true)) {
            throw new InvalidFormatException('History row has no valid source identity');
        }
        if ($row->kind === StatusHistoryEntry::INCIDENT) {
            if ($row->endedAt === null || $row->completionObservedAt !== null || $row->announcementKnown !== null
                || $row->workPublicId !== null || StatusIncidentOutcome::tryFrom($row->outcome) === null
                || $row->severity === null || StatusSeverity::tryFrom($row->severity) === null) {
                throw new InvalidFormatException('History incident is not a completed fact');
            }
        } elseif ($row->completionObservedAt === null || $row->publishedAt !== null || $row->severity !== null
            || $row->announcementKnown === null || $row->outcome !== StatusHistoryEntry::MAINTENANCE_COMPLETED) {
            throw new InvalidFormatException('History maintenance is not a completed fact');
        }
        return $row;
    }

    /** @return array<string, mixed> Only the approved normalized history fields */
    public function toArray(): array
    {
        return [
            self::id => $this->id, self::kind => $this->kind, self::sourceId => $this->sourceId,
            self::startedAt => $this->startedAt, self::endedAt => $this->endedAt,
            self::completionObservedAt => $this->completionObservedAt, self::publishedAt => $this->publishedAt,
            self::outcome => $this->outcome, self::title => $this->title, self::componentKeys => $this->componentKeys,
            self::severity => $this->severity, self::announcementKnown => $this->announcementKnown, self::workPublicId => $this->workPublicId,
        ];
    }
}

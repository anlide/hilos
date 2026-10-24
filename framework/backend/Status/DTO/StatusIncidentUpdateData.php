<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\AdminViewMode\WireField;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use Hilos\Database\View\Item\StatusIncidentUpdate;

/** Immutable browser projection of one incident's timeline entry. */
final readonly class StatusIncidentUpdateData
{
    public const string id = 'id';
    public const string kind = 'kind';
    public const string severity = 'severity';
    public const string text = 'text';
    public const string at = 'at';

    /**
     * @param int $id Persisted update id
     * @param string $kind Published, severity change, resolution or team update
     * @param string $severity Incident severity at this entry
     * @param string $text Plain text; the viewer bridge applies its column verdict
     * @param int $at Epoch milliseconds UTC
     */
    public function __construct(public int $id, public string $kind, public string $severity, public string $text, public int $at)
    {
    }

    /**
     * @param StatusIncidentUpdate $update Entry from the incident's own relation
     * @return self Immutable browser data
     */
    public static function fromUpdate(StatusIncidentUpdate $update): self
    {
        return new self($update->id, $update->kind, $update->severity, $update->text, $update->at);
    }

    /** @return array<string, WireField> Column origins, including the personal text verdict */
    public static function wireFields(): array
    {
        return [
            self::id => WireField::column(HilosDbContext::statusIncidentUpdates, EntityStatusIncidentUpdate::id),
            self::kind => WireField::column(HilosDbContext::statusIncidentUpdates, EntityStatusIncidentUpdate::kind),
            self::severity => WireField::column(HilosDbContext::statusIncidentUpdates, EntityStatusIncidentUpdate::severity),
            self::text => WireField::column(HilosDbContext::statusIncidentUpdates, EntityStatusIncidentUpdate::text),
            self::at => WireField::column(HilosDbContext::statusIncidentUpdates, EntityStatusIncidentUpdate::at),
        ];
    }

    /** @return array<string, int|string> Timeline entry with its original moment */
    public function toArray(): array
    {
        return [self::id => $this->id, self::kind => $this->kind, self::severity => $this->severity,
            self::text => $this->text, self::at => $this->at];
    }
}

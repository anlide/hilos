<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Core\Exception\ValidationException;

/** Complete numeric key shared by the two history sources. */
final readonly class StatusHistoryAnchor
{
    public const int INCIDENT_RANK = 0;
    public const int MAINTENANCE_RANK = 1;

    /**
     * @param int $startedAt Start in epoch milliseconds UTC
     * @param int $kindRank Incident zero or maintenance one
     * @param int $sourceId Positive persisted primary key
     * @throws ValidationException When the source kind or primary key is invalid
     */
    public function __construct(public int $startedAt, public int $kindRank, public int $sourceId)
    {
        if (!in_array($kindRank, [self::INCIDENT_RANK, self::MAINTENANCE_RANK], true) || $sourceId <= 0) {
            throw new ValidationException('Choose a valid history position.');
        }
    }

    /** @return array{startedAt: int, kindRank: int, sourceId: int} Full tuple inside the standard table anchor envelope */
    public function toArray(): array
    {
        return ['startedAt' => $this->startedAt, 'kindRank' => $this->kindRank, 'sourceId' => $this->sourceId];
    }
}

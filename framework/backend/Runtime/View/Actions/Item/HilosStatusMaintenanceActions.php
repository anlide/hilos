<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use Hilos\Runtime\View\Item\HilosStatusMaintenance as ViewHilosStatusMaintenance;

/**
 * @extends RtActions<ViewHilosStatusMaintenance, StateHilosStatusMaintenance>
 * @property-read StateHilosStatusMaintenance $state Current maintenance facts
 */
final class HilosStatusMaintenanceActions extends RtActions
{
    /**
     * @param bool $available Whether the unconditional freeze source is known
     * @param bool $active Last known occupied fact; unavailable does not imply inactive
     * @param bool $announcementKnown Whether announcement classification is proven
     * @param ?string $periodId Opaque public period identity
     * @param ?string $workPublicId Historical public announcement identity
     * @param ?string $title Sanitized title snapshot
     * @param list<string> $componentKeys Sanitized component keys
     * @param ?int $startsAt Planned inclusive start in epoch milliseconds UTC
     * @param ?int $endsAt Planned exclusive end in epoch milliseconds UTC
     * @param ?int $startedAt First occupied fact in epoch milliseconds UTC
     * @param ?int $lastObservedAt Last occupied fact in epoch milliseconds UTC
     * @throws InvalidFormatException When maintenance fields are invalid
     * @throws RtActionsCollectionNameNullException When the collection is unmounted
     * @throws RtTruthSourceWriteNotAllowedException When the caller cannot update the singleton
     */
    public function update(
        bool $available = false,
        bool $active = false,
        bool $announcementKnown = false,
        ?string $periodId = null,
        ?string $workPublicId = null,
        ?string $title = null,
        array $componentKeys = [],
        ?int $startsAt = null,
        ?int $endsAt = null,
        ?int $startedAt = null,
        ?int $lastObservedAt = null,
    ): void
    {
        $this->ensureCanWrite();
        $next = StateHilosStatusMaintenance::create(
            $available, $active, $announcementKnown, $periodId, $workPublicId, $title,
            $componentKeys, $startsAt, $endsAt, $startedAt, $lastObservedAt,
        );
        $this->state->available = $next->available;
        $this->state->active = $next->active;
        $this->state->announcementKnown = $next->announcementKnown;
        $this->state->periodId = $next->periodId;
        $this->state->workPublicId = $next->workPublicId;
        $this->state->title = $next->title;
        $this->state->componentKeys = $next->componentKeys;
        $this->state->startsAt = $next->startsAt;
        $this->state->endsAt = $next->endsAt;
        $this->state->startedAt = $next->startedAt;
        $this->state->lastObservedAt = $next->lastObservedAt;
        $this->sync();
    }
}

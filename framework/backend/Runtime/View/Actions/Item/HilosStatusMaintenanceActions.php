<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use Hilos\Runtime\View\Item\HilosStatusMaintenance as ViewHilosStatusMaintenance;
use Hilos\HilosException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

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
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws HilosException Whatever the row's read of the diff or the preparation of its RT operation raises
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
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
        $this->applyDiffWithSync([
            StateHilosStatusMaintenance::available => $next->available,
            StateHilosStatusMaintenance::active => $next->active,
            StateHilosStatusMaintenance::announcementKnown => $next->announcementKnown,
            StateHilosStatusMaintenance::periodId => $next->periodId,
            StateHilosStatusMaintenance::workPublicId => $next->workPublicId,
            StateHilosStatusMaintenance::title => $next->title,
            StateHilosStatusMaintenance::componentKeys => $next->componentKeys,
            StateHilosStatusMaintenance::startsAt => $next->startsAt,
            StateHilosStatusMaintenance::endsAt => $next->endsAt,
            StateHilosStatusMaintenance::startedAt => $next->startedAt,
            StateHilosStatusMaintenance::lastObservedAt => $next->lastObservedAt,
        ]);
    }
}

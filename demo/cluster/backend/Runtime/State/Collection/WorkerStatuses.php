<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\State\Collection;

use Demo\Cluster\Runtime\State\Item\WorkerStatus;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * Runtime fleet worker statuses keyed by fleet member index.
 *
 * @extends RtStates<WorkerStatus>
 */
final class WorkerStatuses extends RtStates
{
    public const string STATE_CLASS = WorkerStatus::class;

    /**
     * @param ?string $id Fleet member index, or null for a missing optional runtime key
     * @return ?WorkerStatus Worker status, or null when missing
     */
    public function get(?string $id): ?WorkerStatus
    {
        /** @var ?WorkerStatus $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Fleet member index
     * @return WorkerStatus Worker status
     * @throws OutOfBoundsException When no row stands under that index
     */
    public function offsetGet(mixed $offset): WorkerStatus
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Worker status not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Worker status not found: {$offset}");
    }
}

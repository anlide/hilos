<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Runtime\State\Item\HilosUpload;
use OutOfBoundsException;

/**
 * HilosUploads - the files connections are sending, and the received ones not yet handed over (HIL-135).
 *
 * Framework-owned state collection mounted by {@see UploadsFeature::mount()} and by nothing
 * else: a project that accepts no uploads has no rows to hold.
 *
 * A row appears when a declaration is accepted and goes when it is canceled, re-declared, its
 * connection is gone, it has not changed for an hour, or the agent that owns it starts or stops.
 * The collection is the size of the uploads in flight plus the received files waiting for their
 * consumer.
 *
 * @extends RtStates<HilosUpload>
 */
final class HilosUploads extends RtStates
{
    public const string STATE_CLASS = HilosUpload::class;

    /**
     * @param ?string $key Row id `acceptKey|clientUploadId`, or null for a missing optional key
     * @return ?HilosUpload Upload row, or null when none is kept under that id
     */
    public function get(?string $key): ?HilosUpload
    {
        /** @var ?HilosUpload $state */
        $state = parent::get($key);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid - and here it often
     * is, because a chunk or a cancel may name an upload that has just been cleaned up.
     *
     * @param mixed $offset Row id `acceptKey|clientUploadId`
     * @return HilosUpload Upload row
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): HilosUpload
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Upload not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Upload not found: {$offset}");
    }
}

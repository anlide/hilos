<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * Immutable window snapshot: null sendUpToBytes is an unpublished internal hold.
 *
 * UploadMemoryMaster publishes local grants after any ordered peer acquisition has completed.
 * The pool replaces snapshots; retaining an older one cannot mutate its ledger.
 * Reservation identity stays fixed across publication, acceptance and unused release.
 */
final readonly class UploadMemoryReservation
{
    /**
     * @param string $id Pool-generation window identity
     * @param string $requestId Upload incarnation, distinct after cancel/recreate
     * @param string $acceptKey Connection identity
     * @param string $clientUploadId Connection-local upload identity
     * @param int $offsetBytes Beginning of this window in the file
     * @param int $byteCount Original reserved capacity, bounding absolute releases
     * @param int $remainingBytes Unused capacity, excluding accepted receipts
     * @param int $nextOffsetBytes Next contiguous portion offset
     * @param ?int $sendUpToBytes Published cumulative boundary, or null for a hold
     */
    public function __construct(
        public string $id,
        public string $requestId,
        public string $acceptKey,
        public string $clientUploadId,
        public int $offsetBytes,
        public int $byteCount,
        public int $remainingBytes,
        public int $nextOffsetBytes,
        public ?int $sendUpToBytes,
    ) {
    }

    /**
     * @param int $remainingBytes Unused capacity after a ledger transition
     * @param int $nextOffsetBytes Next portion offset after that transition
     * @param ?int $sendUpToBytes Published boundary, or null for an internal hold
     * @return self Replacement snapshot; does not change any pool
     */
    public function withAccounting(int $remainingBytes, int $nextOffsetBytes, ?int $sendUpToBytes): self
    {
        return new self(
            $this->id,
            $this->requestId,
            $this->acceptKey,
            $this->clientUploadId,
            $this->offsetBytes,
            $this->byteCount,
            $remainingBytes,
            $nextOffsetBytes,
            $sendUpToBytes,
        );
    }
}

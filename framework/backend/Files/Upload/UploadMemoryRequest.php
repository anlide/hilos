<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * Pool-private upload demand and cumulative published boundary.
 *
 * This record never leaves the pool; it contains no file payload.
 *
 * @internal
 */
final class UploadMemoryRequest
{
    public int $sendUpToBytes = 0;

    public ?string $heldReservationId = null;

    /** @var array<string, true> Unused windows in grant order; payloads never enter this record */
    public array $reservationIds = [];

    /**
     * @param string $id Pool-generation identity
     * @param string $acceptKey Connection identity
     * @param string $clientUploadId Connection-local upload identity
     * @param int $desiredBytes Full declared raw file size
     */
    public function __construct(
        public readonly string $id,
        public readonly string $acceptKey,
        public readonly string $clientUploadId,
        public readonly int $desiredBytes,
    ) {
    }
}

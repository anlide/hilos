<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

/**
 * Raw bytes retained by a transport destination until processing or final discard.
 *
 * Worker IPC and peer upload envelopes carry these distinct portion identities.
 * A null channel retains a portion in the master's queues until a destination is assigned.
 * Channel identities include their connection/process generation, supplied by the adapter.
 */
final readonly class UploadMemoryReceipt
{
    /**
     * @param string $id Pool-generation receipt identity
     * @param array<string, int> $reservationBytes Raw bytes taken from each published window
     * @param ?string $channel Destination incarnation, or null while retained by the master
     * @param int $byteCount Raw bytes retained, independent of encoded transport size
     */
    public function __construct(
        public string $id,
        public array $reservationBytes,
        public ?string $channel,
        public int $byteCount,
    ) {
    }
}

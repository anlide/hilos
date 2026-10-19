<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Upload\DTO\UploadMemoryReservationData;

/** Ordered acquisition for one declared upload, pinned to its original peer incarnation. */
final class UploadMemoryRemoteUpload
{
    private ?UploadMemoryReservation $held = null;

    private ?UploadMemoryPeerGrant $acquiring = null;

    /** @var array<string, UploadMemoryPeerGrant> Receiver window for each published local window */
    private array $windows = [];

    /**
     * @param UploadMemoryPool $pool Existing source ledger
     * @param UploadMemoryPeer $peer Original receiver incarnation
     * @param string $acceptKey Real browser or explicit test delivery identity
     * @param string $clientUploadId Declared upload identity
     * @param int $desiredBytes Full declared size, unchanged across windows
     * @param bool $localFirst Whether this node sorts below the receiver
     */
    public function __construct(
        private readonly UploadMemoryPool $pool,
        public readonly UploadMemoryPeer $peer,
        private readonly string $acceptKey,
        private readonly string $clientUploadId,
        private readonly int $desiredBytes,
        private readonly bool $localFirst,
    ) {
    }

    /**
     * Holds are taken low node first. A smaller second grant trims the first before publication.
     * No payload is retained while either capacity is unavailable.
     *
     * @return ?UploadMemoryReservation Next two-node window, or null while waiting/completed
     * @throws LogicException When a local capacity invariant is broken
     * @throws PeerTransportException When the original peer window becomes invalid
     */
    public function reserveNext(): ?UploadMemoryReservation
    {
        $remainingBytes = $this->desiredBytes - ($this->pool->getSendUpToBytes($this->acceptKey, $this->clientUploadId) ?? 0);
        if ($remainingBytes === 0 || !$this->peer->isOpen()) {
            return null;
        }
        if ($this->localFirst && $this->held === null) {
            $this->held = $this->pool->hold($this->acceptKey, $this->clientUploadId, $this->desiredBytes);
            if ($this->held === null) {
                return null;
            }
        }
        $this->acquiring ??= $this->peer->request($this->held?->remainingBytes ?? $remainingBytes);
        if ($this->acquiring->bytes === null || $this->acquiring->canceled) {
            return null;
        }
        $this->held ??= $this->pool->hold($this->acceptKey, $this->clientUploadId, $this->desiredBytes);
        if ($this->held === null) {
            return null;
        }
        $bytes = min($this->held->remainingBytes, $this->acquiring->bytes);
        $this->pool->releaseUnused($this->held->id, $bytes);
        if ($bytes < $this->acquiring->remainingBytes) {
            $this->peer->release($this->acquiring, $bytes);
        }
        $published = $this->pool->publish($this->held->id);
        if ($published === null) {
            throw new LogicException('Ordered upload hold disappeared before publication');
        }
        $this->windows[$published->id] = $this->acquiring;
        $this->held = null;
        $this->acquiring = null;

        return $published;
    }

    /**
     * Preflight all contributions before the pool replaces a logical receipt with portions.
     *
     * @param array<string, int> $reservationBytes Source window contributions in original byte order
     * @throws PeerTransportException When any part has lost its original receiver window
     */
    public function assertPortions(array $reservationBytes): void
    {
        foreach ($reservationBytes as $localWindowId => $bytes) {
            $grant = $this->windows[$localWindowId] ?? null;
            if ($grant === null || $grant->canceled || !$this->peer->isOpen() || $bytes <= 0 || $bytes > $grant->remainingBytes) {
                throw new PeerTransportException('Upload portion has no live two-node window');
            }
        }
    }

    /**
     * @param string $localWindowId Source contribution in byte order
     * @param string $receiptId Distinct source portion identity
     * @param int $bytes Raw portion length
     * @return UploadMemoryReservationData Receiver envelope covering exactly this portion
     * @throws PeerTransportException When bytes exceed or leave the original two-node window
     * @throws InvalidFormatException When the portion envelope has invalid identities or size
     */
    public function takePortion(string $localWindowId, string $receiptId, int $bytes): UploadMemoryReservationData
    {
        $grant = $this->windows[$localWindowId] ?? null;
        if ($grant === null || $grant->canceled || !$this->peer->isOpen() || $bytes <= 0 || $bytes > $grant->remainingBytes) {
            throw new PeerTransportException('Upload portion has no live two-node window');
        }
        $grant->remainingBytes -= $bytes;

        return new UploadMemoryReservationData($grant->id, $receiptId, $bytes);
    }

    /**
     * The release must follow its last serialized portion in the peer FIFO.
     *
     * @param string $localWindowId Window whose portion was just queued on the original link
     * @throws PeerTransportException When unused receiver metadata is inconsistent
     */
    public function afterSend(string $localWindowId): void
    {
        $grant = $this->windows[$localWindowId] ?? null;
        if ($grant !== null && $grant->remainingBytes === 0) {
            $this->peer->release($grant, 0);
            unset($this->windows[$localWindowId]);
        }
    }

    /**
     * @throws PeerTransportException When an unused receiver release is inconsistent
     * @throws LogicException When a local unused hold is inconsistent
     */
    public function cancel(): void
    {
        if ($this->acquiring !== null) {
            $this->peer->release($this->acquiring, 0);
            $this->acquiring = null;
        }
        foreach ($this->windows as $grant) {
            $this->peer->release($grant, 0);
        }
        $this->windows = [];
        $this->held = null;
        $this->pool->cancel($this->acceptKey, $this->clientUploadId);
    }
}

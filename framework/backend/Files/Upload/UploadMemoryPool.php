<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Exception\LogicException;

/**
 * Exact raw-byte obligations of one daemon and its live transport channels.
 *
 * DaemonManager mounts one private instance through UploadMemoryMaster.
 * Workers and peers report facts, never share or mutate this object. Its lifetime ends
 * with the master and the queues whose bytes it reserves; it stores metadata only.
 * Hilos::$rt->hilosUploads is UploadsAgent's replicated FSM/progress, throttled by
 * UploadsAgent::PROGRESS_MIN_INTERVAL_SECONDS, not the master's pre-queue byte ledger.
 * This process-private state follows docs/agents/framework-first.md,
 * docs/agents/runtime/rt-context.md (Finding Existing Logic) and
 * docs/agents/architecture/truth-source.md. No DB, I/O or runtime writes occur here.
 */
final class UploadMemoryPool
{
    private const string ID_SEPARATOR = ':';

    private int $usedBytes = 0;

    private int $sequence = 0;

    /** @var array<string, UploadMemoryRequest> Active uploads by incarnation */
    private array $requests = [];

    /** @var array<string, array<string, string>> Incarnation by connection and upload */
    private array $requestIds = [];

    /** @var array<string, UploadMemoryRequest> Ordered, deduplicated metadata waiters */
    private array $waiting = [];

    /** @var array<string, UploadMemoryReservation> Live unused windows and holds */
    private array $reservations = [];

    /** @var array<string, UploadMemoryReceipt> Accepted bytes awaiting processing or discard */
    private array $receipts = [];

    /** @var array<string, true> Holds scheduled by the ordered peer acquisition, outside browser FIFO */
    private array $externalRequests = [];

    /**
     * @param int $limitBytes Installed raw-byte budget; zero suspends new reservations
     * @param string $generation Unique pool incarnation, including node/master boot identity
     * @throws LogicException When the budget is negative or the generation is empty
     */
    public function __construct(private int $limitBytes, private readonly string $generation)
    {
        if ($limitBytes < 0 || $generation === '') {
            throw new LogicException('Upload memory pool needs a nonnegative limit and a generation');
        }
    }

    /** @return int Installed budget, which can be below existing obligations */
    public function getLimitBytes(): int
    {
        return $this->limitBytes;
    }

    /** @return int Holds plus unused published bytes plus live receipt bytes */
    public function getUsedBytes(): int
    {
        return $this->usedBytes;
    }

    /** @return int Capacity available for a new reservation */
    public function getFreeBytes(): int
    {
        return max(0, $this->limitBytes - $this->usedBytes);
    }

    /** @return bool Whether unfinished uploads are waiting for capacity */
    public function hasPending(): bool
    {
        return $this->waiting !== [];
    }

    /**
     * @param string $receiptId Exact identity issued by this pool
     * @return bool Whether processing or final deletion is still owed for this portion
     */
    public function hasReceipt(string $receiptId): bool
    {
        return isset($this->receipts[$receiptId]);
    }

    /**
     * @param string $acceptKey Connection identity
     * @param string $clientUploadId Connection-local upload identity
     * @return ?int Current cumulative permission, or null when no active demand exists
     */
    public function getSendUpToBytes(string $acceptKey, string $clientUploadId): ?int
    {
        $requestId = $this->requestIds[$acceptKey][$clientUploadId] ?? null;

        return $requestId === null ? null : $this->requests[$requestId]->sendUpToBytes;
    }

    /**
     * Existing permissions survive a reduction; callers can resume reserveNext on an increase.
     *
     * @param int $limitBytes Newly installed budget
     * @throws LogicException When the budget is negative
     */
    public function setLimitBytes(int $limitBytes): void
    {
        if ($limitBytes < 0) {
            throw new LogicException('Upload memory limit cannot be negative');
        }
        $this->limitBytes = $limitBytes;
    }

    /**
     * Submit the full size from the accepted upload declaration, never a requested chunk size.
     * Repeats keep the incarnation and queue position. Zero-byte files need no ledger entry.
     *
     * @param string $acceptKey Connection identity
     * @param string $clientUploadId Connection-local upload identity
     * @param int $desiredBytes Full declared raw file size
     * @throws LogicException When identities/size are invalid, a repeat changes the size or identities are exhausted
     */
    public function request(string $acceptKey, string $clientUploadId, int $desiredBytes): void
    {
        if ($acceptKey === '' || $clientUploadId === '' || $desiredBytes < 0) {
            throw new LogicException('Upload memory request has invalid identities or size');
        }
        $requestId = $this->requestIds[$acceptKey][$clientUploadId] ?? null;
        if ($requestId !== null) {
            if ($this->requests[$requestId]->desiredBytes !== $desiredBytes) {
                throw new LogicException('Upload memory request changed its declared size');
            }
            return;
        }
        if ($desiredBytes === 0) {
            return;
        }
        $request = new UploadMemoryRequest($this->nextId(), $acceptKey, $clientUploadId, $desiredBytes);
        $this->requests[$request->id] = $request;
        $this->requestIds[$acceptKey][$clientUploadId] = $request->id;
        $this->waiting[$request->id] = $request;
    }

    /**
     * Take one FIFO turn, reserving even a single free byte. The hold counts immediately.
     * Publish it only after any other node's required hold is secured in nodeId order.
     * No payload is parked here, and this method never loops over the waiting population.
     *
     * @return ?UploadMemoryReservation New hold, or null when no waiter/capacity exists
     * @throws LogicException When the pool identity sequence is exhausted
     */
    public function reserveNext(): ?UploadMemoryReservation
    {
        if ($this->waiting === [] || $this->getFreeBytes() === 0) {
            return null;
        }
        return $this->reserveRequest($this->waiting[array_key_first($this->waiting)]);
    }

    /**
     * The caller schedules this metadata demand in node order. It never enters browser FIFO.
     *
     * @param string $acceptKey Process-private acquisition or peer channel identity
     * @param string $clientUploadId Acquisition identity, never reused on this channel
     * @param int $desiredBytes Desired raw capacity
     * @return ?UploadMemoryReservation Existing/new hold, or null while capacity is unavailable
     * @throws LogicException When demand identities, size or the ledger sequence are invalid
     */
    public function hold(string $acceptKey, string $clientUploadId, int $desiredBytes): ?UploadMemoryReservation
    {
        $this->request($acceptKey, $clientUploadId, $desiredBytes);
        $requestId = $this->requestIds[$acceptKey][$clientUploadId] ?? null;
        if ($requestId === null) {
            return null;
        }
        $this->externalRequests[$requestId] = true;
        unset($this->waiting[$requestId]);
        $request = $this->requests[$requestId];
        if ($request->heldReservationId !== null) {
            return $this->reservations[$request->heldReservationId];
        }
        if ($request->sendUpToBytes >= $request->desiredBytes || $this->getFreeBytes() === 0) {
            return null;
        }

        return $this->reserveRequest($request);
    }

    /**
     * @param string $receiptId Pending frame covering one or more published windows
     * @return list<UploadMemoryReceipt> One pending portion per window in original byte order
     * @throws LogicException When the receipt is absent or already assigned, or identities are exhausted
     */
    public function splitPending(string $receiptId): array
    {
        $receipt = $this->receipts[$receiptId] ?? null;
        if ($receipt === null || $receipt->channel !== null) {
            throw new LogicException('Upload splitting needs a pending receipt');
        }
        $parts = [];
        foreach ($receipt->reservationBytes as $reservationId => $bytes) {
            $parts[] = new UploadMemoryReceipt($this->nextId(), [$reservationId => $bytes], null, $bytes);
        }
        unset($this->receipts[$receiptId]);
        foreach ($parts as $part) {
            $this->receipts[$part->id] = $part;
        }

        return $parts;
    }

    /**
     * Converts a hold to a cumulative permission without changing used bytes.
     * An unfinished upload rejoins the tail; repeating publication never grants twice.
     *
     * @param string $reservationId Hold identity
     * @return ?UploadMemoryReservation Published window, or null for an expired identity
     */
    public function publish(string $reservationId): ?UploadMemoryReservation
    {
        $reservation = $this->reservations[$reservationId] ?? null;
        if ($reservation === null || $reservation->sendUpToBytes !== null) {
            return $reservation;
        }
        $request = $this->requests[$reservation->requestId];
        $request->sendUpToBytes += $reservation->remainingBytes;
        $request->heldReservationId = null;
        $reservation = $reservation->withAccounting(
            $reservation->remainingBytes,
            $reservation->nextOffsetBytes,
            $request->sendUpToBytes,
        );
        $this->reservations[$reservationId] = $reservation;
        $this->enqueue($request);

        return $reservation;
    }

    /**
     * Transfer unused permission into an accepted portion, retaining the same byte obligation.
     * Queueing, partial socket writes and parking cause no further ledger transition.
     * Offsets suppress replay even after a receipt has been consumed, without tombstone growth.
     *
     * @param string $reservationId Published window identity
     * @param int $offsetBytes Contiguous raw offset within the file
     * @param int $byteCount Raw portion size
     * @param string $channel Destination including its connection/process generation
     * @return ?UploadMemoryReceipt New receipt, or null for a replay/expired window
     * @throws LogicException When a portion contradicts its permission/channel or identities are exhausted
     */
    public function accept(string $reservationId, int $offsetBytes, int $byteCount, string $channel): ?UploadMemoryReceipt
    {
        if ($offsetBytes < 0 || $byteCount <= 0 || $channel === '') {
            throw new LogicException('Upload memory portion has invalid offset, size or channel');
        }
        $reservation = $this->reservations[$reservationId] ?? null;
        if ($reservation === null) {
            return null;
        }
        if ($reservation->sendUpToBytes === null) {
            throw new LogicException('Upload memory hold has not been published');
        }
        if ($offsetBytes < $reservation->nextOffsetBytes) {
            return null;
        }
        if ($offsetBytes !== $reservation->nextOffsetBytes || $byteCount > $reservation->remainingBytes) {
            throw new LogicException('Upload memory portion exceeds or skips its permission');
        }
        $receipt = new UploadMemoryReceipt($this->nextId(), [$reservationId => $byteCount], $channel, $byteCount);
        $this->receipts[$receipt->id] = $receipt;
        $this->reservations[$reservationId] = $reservation->withAccounting(
            $reservation->remainingBytes - $byteCount,
            $offsetBytes + $byteCount,
            $reservation->sendUpToBytes,
        );
        if ($this->reservations[$reservationId]->remainingBytes === 0) {
            unset($this->reservations[$reservationId], $this->requests[$reservation->requestId]->reservationIds[$reservationId]);
        }

        return $receipt;
    }

    /**
     * Contiguous unused permission beginning at this exact offset, across published windows.
     * A released gap ends the range even when later cumulative grants remain published.
     *
     * @param string $requestId Upload incarnation from a reserved window
     * @param int $offsetBytes Next raw file offset
     * @return int Reserved contiguous bytes, or zero for an expired/replayed/gapped offset
     * @throws LogicException When the offset is negative
     */
    public function getAvailableBytes(string $requestId, int $offsetBytes): int
    {
        if ($offsetBytes < 0) {
            throw new LogicException('Upload memory offset cannot be negative');
        }
        $request = $this->requests[$requestId] ?? null;
        if ($request === null) {
            return 0;
        }
        $availableBytes = 0;
        foreach ($request->reservationIds as $reservationId => $_) {
            $reservation = $this->reservations[$reservationId];
            if ($reservation->sendUpToBytes === null || $reservation->nextOffsetBytes !== $offsetBytes + $availableBytes) {
                break;
            }
            $availableBytes += $reservation->remainingBytes;
            if ($reservation->nextOffsetBytes + $reservation->remainingBytes !== $reservation->sendUpToBytes) {
                break;
            }
        }

        return $availableBytes;
    }

    /**
     * Atomically move a browser portion across any number of windows into one pending receipt.
     * No destination is guessed while the master routes or parks the payload.
     *
     * @param string $requestId Upload incarnation from a reserved window
     * @param int $offsetBytes Contiguous raw file offset
     * @param int $byteCount Raw portion size
     * @return ?UploadMemoryReceipt Pending receipt, or null for an expired incarnation or replay
     * @throws LogicException When the portion skips/exceeds permission, has invalid size/offset, or identities are exhausted
     */
    public function acceptPending(string $requestId, int $offsetBytes, int $byteCount): ?UploadMemoryReceipt
    {
        if ($offsetBytes < 0 || $byteCount <= 0) {
            throw new LogicException('Upload memory portion has invalid offset or size');
        }
        $request = $this->requests[$requestId] ?? null;
        if ($request === null) {
            return null;
        }
        $firstId = array_key_first($request->reservationIds);
        if ($firstId === null || $offsetBytes < $this->reservations[$firstId]->nextOffsetBytes) {
            return null;
        }
        if ($byteCount > $this->getAvailableBytes($requestId, $offsetBytes)) {
            throw new LogicException('Upload memory portion exceeds or skips its permission');
        }

        // Preflight and identity allocation precede every mutation, including a multi-window failure.
        $receiptId = $this->nextId();
        $remainingBytes = $byteCount;
        $reservationBytes = [];
        foreach ($request->reservationIds as $reservationId => $_) {
            $reservation = $this->reservations[$reservationId];
            $acceptedBytes = min($remainingBytes, $reservation->remainingBytes);
            $reservationBytes[$reservationId] = $acceptedBytes;
            $this->reservations[$reservationId] = $reservation->withAccounting(
                $reservation->remainingBytes - $acceptedBytes,
                $reservation->nextOffsetBytes + $acceptedBytes,
                $reservation->sendUpToBytes,
            );
            if ($this->reservations[$reservationId]->remainingBytes === 0) {
                unset($this->reservations[$reservationId], $request->reservationIds[$reservationId]);
            }
            $remainingBytes -= $acceptedBytes;
            if ($remainingBytes === 0) {
                break;
            }
        }
        $receipt = new UploadMemoryReceipt($receiptId, $reservationBytes, null, $byteCount);
        $this->receipts[$receiptId] = $receipt;

        return $receipt;
    }

    /**
     * Join the parts of one fragmented browser message without releasing any raw bytes.
     * Preflight every identity before replacing the pending parts with the first identity.
     *
     * @param list<string> $receiptIds Pending parts in admission order, without duplicates
     * @return ?UploadMemoryReceipt Combined pending receipt, or null for a message with no raw bytes
     * @throws LogicException When a part is absent, duplicated or already assigned
     */
    public function coalescePending(array $receiptIds): ?UploadMemoryReceipt
    {
        if ($receiptIds === []) {
            return null;
        }
        $reservationBytes = [];
        $seen = [];
        $byteCount = 0;
        foreach ($receiptIds as $receiptId) {
            $receipt = $this->receipts[$receiptId] ?? null;
            if ($receipt === null || $receipt->channel !== null || isset($seen[$receiptId])) {
                throw new LogicException('Upload memory coalescing needs distinct pending receipts');
            }
            $seen[$receiptId] = true;
            $byteCount += $receipt->byteCount;
            foreach ($receipt->reservationBytes as $reservationId => $bytes) {
                $reservationBytes[$reservationId] = ($reservationBytes[$reservationId] ?? 0) + $bytes;
            }
        }
        $combined = new UploadMemoryReceipt($receiptIds[0], $reservationBytes, null, $byteCount);
        foreach ($receiptIds as $receiptId) {
            unset($this->receipts[$receiptId]);
        }
        $this->receipts[$combined->id] = $combined;

        return $combined;
    }

    /**
     * Assign once, only when the payload actually enters its destination's channel.
     * A repeat for the same channel returns its snapshot; another incarnation cannot take it.
     * Worker and peer delivery bind here to the actual destination incarnation.
     *
     * @param string $receiptId Accepted portion identity
     * @param string $channel Exact destination incarnation
     * @return ?UploadMemoryReceipt Bound receipt, or null for an expired/foreign assignment
     * @throws LogicException When the channel is empty
     */
    public function assign(string $receiptId, string $channel): ?UploadMemoryReceipt
    {
        if ($channel === '') {
            throw new LogicException('Upload memory destination channel cannot be empty');
        }
        $receipt = $this->receipts[$receiptId] ?? null;
        if ($receipt === null || ($receipt->channel !== null && $receipt->channel !== $channel)) {
            return null;
        }
        if ($receipt->channel === null) {
            $receipt = new UploadMemoryReceipt($receipt->id, $receipt->reservationBytes, $channel, $receipt->byteCount);
            $this->receipts[$receiptId] = $receipt;
        }

        return $receipt;
    }

    /**
     * Call only after the master has finally removed the unassigned payload from its queues.
     * A worker-bound receipt can only be settled by its own channel or final channel cleanup.
     *
     * @param string $receiptId Pending portion identity
     */
    public function discardPending(string $receiptId): void
    {
        $receipt = $this->receipts[$receiptId] ?? null;
        if ($receipt === null || $receipt->channel !== null) {
            return;
        }
        unset($this->receipts[$receiptId]);
        $this->usedBytes -= $receipt->byteCount;
    }

    /**
     * An absolute, monotone upper bound on unused capacity, never a delta or a receipt release.
     * A repeated/older larger bound is ignored after acceptance or a narrower release.
     * Releasing an unpublished hold returns its upload to the tail without advancing permission.
     * A published boundary never retreats; released bytes cannot be sent on that window again.
     *
     * @param string $reservationId Window identity, including its pool generation
     * @param int $remainingBytes Absolute unused bound
     * @throws LogicException When the bound is negative or exceeds the original reservation
     */
    public function releaseUnused(string $reservationId, int $remainingBytes): void
    {
        if ($remainingBytes < 0) {
            throw new LogicException('Upload memory unused bound cannot be negative');
        }
        $reservation = $this->reservations[$reservationId] ?? null;
        if ($reservation === null) {
            return;
        }
        if ($remainingBytes > $reservation->byteCount) {
            throw new LogicException('Upload memory unused bound exceeds its reservation');
        }
        if ($remainingBytes >= $reservation->remainingBytes) {
            return;
        }
        $this->usedBytes -= $reservation->remainingBytes - $remainingBytes;
        if ($remainingBytes > 0) {
            $this->reservations[$reservationId] = $reservation->withAccounting(
                $remainingBytes,
                $reservation->nextOffsetBytes,
                $reservation->sendUpToBytes,
            );
            return;
        }
        unset($this->reservations[$reservationId]);
        if (isset($this->requests[$reservation->requestId])) {
            $request = $this->requests[$reservation->requestId];
            unset($request->reservationIds[$reservationId]);
            if ($reservation->sendUpToBytes === null) {
                $request->heldReservationId = null;
                $this->enqueue($request);
            }
        }
    }

    /**
     * Call only after processing or final deletion of this portion's retained bytes.
     * A peer acceptance permits this at the source only once its own copy is gone.
     * Count comes exclusively from the ledger; unknown, duplicate and foreign facts do nothing.
     *
     * @param string $receiptId Receipt identity
     * @param string $channel Exact destination generation which consumed/discarded the bytes
     */
    public function settle(string $receiptId, string $channel): void
    {
        $receipt = $this->receipts[$receiptId] ?? null;
        if ($receipt === null || $receipt->channel !== $channel) {
            return;
        }
        unset($this->receipts[$receiptId]);
        $this->usedBytes -= $receipt->byteCount;
    }

    /**
     * Stops future grants and releases unused capacity, retaining all live portions.
     *
     * @param string $acceptKey Connection identity
     * @param string $clientUploadId Connection-local upload identity
     * @throws LogicException When an internal unused-capacity invariant is broken
     */
    public function cancel(string $acceptKey, string $clientUploadId): void
    {
        $requestId = $this->requestIds[$acceptKey][$clientUploadId] ?? null;
        if ($requestId === null) {
            return;
        }
        $request = $this->requests[$requestId];
        unset($this->externalRequests[$requestId]);
        unset($this->requestIds[$acceptKey][$clientUploadId], $this->requests[$requestId], $this->waiting[$requestId]);
        if ($this->requestIds[$acceptKey] === []) {
            unset($this->requestIds[$acceptKey]);
        }
        foreach ($request->reservationIds as $reservationId => $_) {
            $this->releaseUnused($reservationId, 0);
        }
    }

    /**
     * Connection departure discards permissions, not queued or parked accepted portions.
     *
     * @param string $acceptKey Departing connection identity
     * @throws LogicException When an internal unused-capacity invariant is broken
     */
    public function closeConnection(string $acceptKey): void
    {
        foreach (array_keys($this->requestIds[$acceptKey] ?? []) as $clientUploadId) {
            $this->cancel($acceptKey, (string) $clientUploadId);
        }
    }

    /**
     * Final channel cleanup, only after its queues are discarded and its process has exited.
     * A disconnect notification alone does not authorize this operation.
     *
     * @param string $channel Dead destination generation
     */
    public function discardChannel(string $channel): void
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt->channel === $channel) {
                $this->settle($receipt->id, $channel);
            }
        }
    }

    /**
     * @param UploadMemoryRequest $request Scheduled demand with free capacity
     * @return UploadMemoryReservation New hold, already charged to this pool
     * @throws LogicException When the ledger sequence is exhausted
     */
    private function reserveRequest(UploadMemoryRequest $request): UploadMemoryReservation
    {
        $requestId = $request->id;
        $bytes = min($this->getFreeBytes(), $request->desiredBytes - $request->sendUpToBytes);
        $reservation = new UploadMemoryReservation(
            $this->nextId(),
            $request->id,
            $request->acceptKey,
            $request->clientUploadId,
            $request->sendUpToBytes,
            $bytes,
            $bytes,
            $request->sendUpToBytes,
            null,
        );
        unset($this->waiting[$requestId]);
        $request->heldReservationId = $reservation->id;
        $this->reservations[$reservation->id] = $reservation;
        $request->reservationIds[$reservation->id] = true;
        $this->usedBytes += $bytes;

        return $reservation;
    }

    private function enqueue(UploadMemoryRequest $request): void
    {
        if (!isset($this->externalRequests[$request->id])
            && $request->heldReservationId === null && $request->sendUpToBytes < $request->desiredBytes) {
            $this->waiting[$request->id] = $request;
        }
    }

    /**
     * @return string Identity unique within the caller-supplied pool incarnation
     * @throws LogicException When the identity sequence is exhausted
     */
    private function nextId(): string
    {
        if ($this->sequence === PHP_INT_MAX) {
            throw new LogicException('Upload memory identity sequence exhausted');
        }

        return $this->generation . self::ID_SEPARATOR . ++$this->sequence;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Core\Exception\LogicException;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;

/**
 * Metadata admission for one socket's incomplete and fragmented binary message.
 *
 * The WS parser retains the bytes; this object retains only their pool obligations. Once a
 * signature is known, the entire physical frame is admitted before waiting for its tail.
 * Cancel may then release unused permission without forgetting the input already in flight.
 * Hilos::$rt->hilosUploads is replicated progress, not this process-private parser state
 * (docs/agents/framework-first.md and docs/agents/runtime/rt-context.md).
 */
final class UploadMemoryInput
{
    /** @var array<string, UploadMemoryInputPermission> Granted uploads of this socket */
    private array $permissions = [];

    private ?UploadMemoryInputPermission $messagePermission = null;

    private ?string $messageUploadId = null;

    private int $messageRawBytes = 0;

    /** @var list<string> Pending physical-frame portions of the current logical message */
    private array $receiptIds = [];

    /**
     * @param UploadMemoryPool $pool The daemon's sole ledger, never a per-socket pool
     * @param string $acceptKey Identity of the real socket
     * @throws LogicException When the socket identity is empty
     */
    public function __construct(private readonly UploadMemoryPool $pool, private readonly string $acceptKey)
    {
        if ($acceptKey === '') {
            throw new LogicException('Upload memory input needs a socket identity');
        }
    }

    /**
     * Remember the incarnation only after the master has published its grant.
     * Repeated and later windows of that incarnation keep the admitted file offset.
     *
     * @param UploadMemoryReservation $reservation Published permission for this socket
     * @throws LogicException When the grant belongs to another socket or has not been published
     */
    public function allow(UploadMemoryReservation $reservation): void
    {
        if ($reservation->acceptKey !== $this->acceptKey || $reservation->sendUpToBytes === null) {
            throw new LogicException('Upload memory input needs its own published grant');
        }
        $permission = $this->permissions[$reservation->clientUploadId] ?? null;
        if ($permission === null || $permission->requestId !== $reservation->requestId) {
            $this->permissions[$reservation->clientUploadId] = new UploadMemoryInputPermission(
                $reservation->requestId,
                $reservation->offsetBytes,
            );
        }
    }

    /**
     * Inspect at most the signature prefix, before the parser keeps an incomplete frame.
     * The length includes earlier fragments and this physical frame's declared payload.
     * Re-observing the same incomplete frame creates no second receipt or offset advance.
     * Malformed/non-upload signatures retain their existing binary semantics.
     *
     * @param string $prefix Available, unmasked prefix of the whole logical message
     * @param int $messageBytes Payload length through the current physical frame
     * @throws InvalidFrameException When signed raw bytes lack contiguous published permission
     * @throws LogicException When the pool identity sequence is exhausted
     */
    public function observe(string $prefix, int $messageBytes): void
    {
        $frame = UploadFrame::parse($prefix);
        if ($frame === null) {
            return;
        }
        $rawBytes = $messageBytes - strlen($frame->clientUploadId) - 1;
        if ($rawBytes <= $this->messageRawBytes) {
            return;
        }
        $permission = $this->permissions[$frame->clientUploadId] ?? null;
        if (
            $permission === null
            || ($this->messagePermission !== null && $this->messagePermission !== $permission)
            || ($this->messageUploadId !== null && $this->messageUploadId !== $frame->clientUploadId)
        ) {
            throw new InvalidFrameException('Upload frame has no current memory permission');
        }
        $byteCount = $rawBytes - $this->messageRawBytes;
        if ($byteCount > $this->pool->getAvailableBytes($permission->requestId, $permission->offsetBytes)) {
            throw new InvalidFrameException('Upload frame exceeds its memory permission');
        }
        $receipt = $this->pool->acceptPending($permission->requestId, $permission->offsetBytes, $byteCount);
        if ($receipt === null) {
            throw new InvalidFrameException('Upload frame memory permission has expired');
        }
        $permission->offsetBytes += $byteCount;
        $this->messagePermission = $permission;
        $this->messageUploadId = $frame->clientUploadId;
        $this->messageRawBytes = $rawBytes;
        $this->receiptIds[] = $receipt->id;
    }

    /**
     * Transfer the complete logical message to the master's queued-frame receipt tracking.
     *
     * @return ?UploadMemoryReceipt One pending receipt, or null for unsigned/empty raw input
     * @throws LogicException When a pending part was settled or assigned before message completion
     */
    public function finishMessage(): ?UploadMemoryReceipt
    {
        $receipt = $this->pool->coalescePending($this->receiptIds);
        $this->resetMessage();

        return $receipt;
    }

    /** Call only after the WS parser has removed the current raw input from its buffers. */
    public function discardMessage(): void
    {
        foreach ($this->receiptIds as $receiptId) {
            $this->pool->discardPending($receiptId);
        }
        $this->resetMessage();
    }

    /**
     * Cancel keeps the admitted incomplete frame until it is delivered or actually deleted.
     *
     * @param string $clientUploadId Socket-local upload to stop
     * @throws LogicException When an internal unused-capacity invariant is broken
     */
    public function cancel(string $clientUploadId): void
    {
        $this->pool->cancel($this->acceptKey, $clientUploadId);
        unset($this->permissions[$clientUploadId]);
    }

    /**
     * Call after the parser clears its buffers; queued/assigned receipts stay with their owners.
     *
     * @throws LogicException When an internal unused-capacity invariant is broken
     */
    public function close(): void
    {
        $this->discardMessage();
        $this->pool->closeConnection($this->acceptKey);
        $this->permissions = [];
    }

    private function resetMessage(): void
    {
        $this->messagePermission = null;
        $this->messageUploadId = null;
        $this->messageRawBytes = 0;
        $this->receiptIds = [];
    }
}

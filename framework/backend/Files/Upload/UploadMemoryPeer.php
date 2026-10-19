<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadAcceptedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadGrantedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReserveDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Core\Exception\LogicException;
use Hilos\Files\Upload\DTO\UploadMemoryReservationData;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use WeakReference;

/**
 * One real link's metadata over the master's existing pool, never a second pool.
 * Private to this process/link lifetime (framework-first.md and rt-context.md);
 * the replicated upload FSM and its truth source remain unchanged.
 */
final class UploadMemoryPeer
{
    private const string WINDOW_ID_SEPARATOR = ':window:';

    /** @var array<string, UploadMemoryPeerWindow> Receiver windows with unused capacity or a pending grant */
    private array $incoming = [];

    /** @var array<string, UploadMemoryPeerWindow> Only requests still waiting for receiver capacity */
    private array $waitingIncoming = [];

    /** @var array<string, UploadMemoryPeerGrant> Requests originated on this link */
    private array $outgoing = [];

    /** @var array<string, UploadMemoryPeerReceipt> Duplicate metadata for unsettled receiver portions only */
    private array $received = [];

    /** @var array<string, UploadMemoryPeerTransfer> Source debts awaiting acceptance and raw-reference deletion */
    private array $transfers = [];

    private readonly UploadMemoryPeerSequence $incomingSequence;

    private readonly UploadMemoryPeerSequence $receivedSequence;

    private int $sequence = 0;

    private bool $closed = false;

    /**
     * @param UploadMemoryPool $pool The master's sole raw-byte ledger
     * @param PeerLink $link Actual transport incarnation, never looked up again by node id
     * @param string $channel Master boot plus link generation and recipient
     * @param string $nodeId Recipient node identity
     */
    public function __construct(
        private readonly UploadMemoryPool $pool,
        public readonly PeerLink $link,
        public readonly string $channel,
        public readonly string $nodeId,
    ) {
        $this->incomingSequence = new UploadMemoryPeerSequence(self::WINDOW_ID_SEPARATOR, true);
        $this->receivedSequence = new UploadMemoryPeerSequence(':', false);
    }

    /** @return bool Whether new windows may still be acquired on this incarnation */
    public function isOpen(): bool
    {
        return !$this->closed && !$this->link->shouldClose() && !$this->link->isDraining();
    }

    /** @return bool Whether the dead link has no source payload obligations left */
    public function canForget(): bool
    {
        return $this->closed && $this->transfers === [];
    }

    /** @return int Incoming capacity waits plus outgoing requests awaiting a grant, without received worker debts */
    public function pendingReservations(): int
    {
        $waiting = count($this->waitingIncoming);
        foreach ($this->outgoing as $grant) {
            if (!$grant->canceled && $grant->bytes === null) {
                $waiting++;
            }
        }

        return $waiting;
    }

    /**
     * @param int $bytes Full remaining demand or the lower node's existing hold
     * @return UploadMemoryPeerGrant Metadata to wait on before any raw serialization
     * @throws PeerTransportException When the original link cannot accept a new acquisition
     */
    public function request(int $bytes): UploadMemoryPeerGrant
    {
        if (!$this->isOpen() || $this->sequence === PHP_INT_MAX) {
            throw new PeerTransportException('Upload acquisition has no live peer generation');
        }
        $grant = new UploadMemoryPeerGrant($this->channel . self::WINDOW_ID_SEPARATOR . ++$this->sequence, $bytes);
        $this->outgoing[$grant->id] = $grant;
        $this->link->sendFrame(new PeerUploadReserveDTO($grant->id, $bytes));

        return $grant;
    }

    /**
     * @param PeerDTO $frame One of the four established-link upload control frames
     * @throws PeerTransportException When a live duplicate changes its metadata or a new ID breaks the ordered stream
     * @throws LogicException When a pool invariant is broken
     */
    public function receive(PeerDTO $frame): void
    {
        if ($this->closed) {
            return;
        }
        if ($frame instanceof PeerUploadReserveDTO) {
            $window = $this->incoming[$frame->reservationId] ?? null;
            if ($window === null) {
                if ($this->incomingSequence->hasPassed($frame->reservationId)) {
                    return;
                }
                // request() increments and queues synchronously; the link FIFO includes every reserve.
                $this->incomingSequence->advance($frame->reservationId);
                $window = $this->incoming[$frame->reservationId] = new UploadMemoryPeerWindow($frame->bytes);
            }
            if ($window->requestedBytes !== $frame->bytes) {
                throw new PeerTransportException('Repeated peer reservation changed its byte count');
            }
            if ($window->reservation === null && !$window->canceled) {
                $this->waitingIncoming[$frame->reservationId] = $window;
            }
            if ($window->reservation !== null && !$window->canceled) {
                $this->link->sendFrame(new PeerUploadGrantedDTO($frame->reservationId, $window->reservation->remainingBytes));
            }
            $this->wake();
        } elseif ($frame instanceof PeerUploadGrantedDTO) {
            $grant = $this->outgoing[$frame->reservationId] ?? null;
            if ($grant === null) {
                return;
            }
            if ($frame->bytes > $grant->requestedBytes || ($grant->bytes !== null && $grant->bytes !== $frame->bytes)) {
                throw new PeerTransportException('Peer grant contradicts its original window');
            }
            if ($grant->bytes === null) {
                $grant->bytes = $frame->bytes;
                $grant->remainingBytes = $frame->bytes;
            }
            if ($grant->canceled) {
                $this->release($grant, 0);
            }
        } elseif ($frame instanceof PeerUploadReleaseDTO) {
            $window = $this->incoming[$frame->reservationId] ?? null;
            if ($window === null) {
                return;
            }
            if ($window->reservation !== null) {
                if ($frame->remainingBytes > $window->reservation->byteCount) {
                    throw new PeerTransportException('Unused peer release exceeds its original grant');
                }
                $this->pool->releaseUnused($window->reservation->id, $frame->remainingBytes);
            }
            if ($frame->remainingBytes === 0) {
                $window->canceled = true;
                unset($this->waitingIncoming[$frame->reservationId], $this->incoming[$frame->reservationId]);
                $this->pool->cancel($this->channel, $frame->reservationId);
            }
        } elseif ($frame instanceof PeerUploadAcceptedDTO) {
            if (isset($this->transfers[$frame->receiptId])) {
                $this->transfers[$frame->receiptId]->accepted = true;
            }
        }
    }

    /**
     * @param UploadMemoryPeerGrant $grant Request owned by this exact channel
     * @param int $remainingBytes Absolute unused capacity to retain
     * @throws PeerTransportException When a release exceeds the unused grant
     */
    public function release(UploadMemoryPeerGrant $grant, int $remainingBytes): void
    {
        if ($grant->canceled && $remainingBytes === 0 && !isset($this->outgoing[$grant->id])) {
            return;
        }
        if (($this->outgoing[$grant->id] ?? null) !== $grant || $remainingBytes < 0
            || $remainingBytes > $grant->remainingBytes) {
            throw new PeerTransportException('Invalid unused peer reservation release');
        }
        $grant->remainingBytes = $remainingBytes;
        $grant->canceled = $remainingBytes === 0;
        if (!$this->closed) {
            // This exact link owns the grant; a draining link must never reroute it.
            $this->link->sendEncodedFrame((new PeerUploadReleaseDTO($grant->id, $remainingBytes))->toJson());
        }
        if ($grant->canceled) {
            // Even cancellation before a grant is ordered after its reserve on this exact link.
            unset($this->outgoing[$grant->id]);
        }
    }

    /**
     * @param UploadMemoryReservationData $envelope Receiver window and sender portion identity
     * @param int $bytes Actual decoded signed frame's raw bytes
     * @return ?UploadMemoryReceipt Received debt, or null for an exact duplicate of an unsettled portion
     * @throws PeerTransportException When the portion is not covered by this link's unused grant
     * @throws LogicException When the pool conservation invariant is broken
     */
    public function accept(UploadMemoryReservationData $envelope, int $bytes): ?UploadMemoryReceipt
    {
        $previous = $this->received[$envelope->receiptId] ?? null;
        if ($previous !== null) {
            if ($previous->envelope->toArray() !== $envelope->toArray() || $bytes !== $envelope->bytes) {
                throw new PeerTransportException('Repeated peer receipt changed its portion');
            }
            return null;
        }
        if ($this->receivedSequence->hasPassed($envelope->receiptId)) {
            // No old ack is invented for an unknown identity in a gap of the pool's sequence.
            throw new PeerTransportException('Peer upload portion identity has already retired');
        }
        $window = $this->incoming[$envelope->reservationId] ?? null;
        if ($this->closed || $window === null || $window->canceled || $window->reservation === null || $bytes !== $envelope->bytes
            || $bytes > $this->pool->getAvailableBytes($window->reservation->requestId, $window->receivedBytes)) {
            throw new PeerTransportException('Peer upload portion has no matching unused reservation');
        }
        // sendPeerSignal() always splitPending()s immediately before FIFO enqueue, including parked frames.
        // Only those freshly minted portions advance this stream, never the older input/coalesced IDs.
        $this->receivedSequence->advance($envelope->receiptId);
        $receipt = $this->pool->acceptPending($window->reservation->requestId, $window->receivedBytes, $bytes);
        if ($receipt === null) {
            throw new PeerTransportException('Peer upload reservation expired before acceptance');
        }
        $window->receivedBytes += $bytes;
        $this->received[$envelope->receiptId] = new UploadMemoryPeerReceipt($envelope, $receipt->id);

        return $receipt;
    }

    /**
     * @param string $receiptId Source receipt bound to this channel
     * @param WebSocketFrameBinarySignalDTO $frame Original admitted logical frame
     * @throws LogicException When a portion cannot bind to this exact peer incarnation
     */
    public function track(string $receiptId, WebSocketFrameBinarySignalDTO $frame): void
    {
        if ($this->pool->assign($receiptId, $this->channel) === null) {
            throw new LogicException('Upload portion cannot enter this peer generation');
        }
        $this->transfers[$receiptId] = new UploadMemoryPeerTransfer($receiptId, WeakReference::create($frame), $this->link->uploadOutboundPosition());
    }

    /** Called outside raw dispatch scopes, after actual transport removal and raw reference deletion. */
    public function afterDispatch(): void
    {
        foreach ($this->transfers as $receiptId => $transfer) {
            if (($transfer->accepted || $this->closed) && $transfer->frame->get() === null
                && $this->link->hasRemovedUploadThrough($transfer->outboundPosition)) {
                $this->pool->settle($receiptId, $this->channel);
                unset($this->transfers[$receiptId]);
            }
        }
        foreach ($this->received as $receiptId => $received) {
            if (!$this->pool->hasReceipt($received->receiptId)) {
                unset($this->received[$receiptId]);
            }
        }
    }

    /**
     * A capacity/settings/link fact wakes metadata waiters without polling or resending requests.
     *
     * @throws LogicException When an internal receiver hold is inconsistent
     * @throws PeerTransportException When a receiver grant is invalid
     */
    public function wake(): void
    {
        if (!$this->isOpen()) {
            return;
        }
        foreach ($this->waitingIncoming as $id => $window) {
            if ($window->canceled || $window->reservation !== null) {
                continue;
            }
            $held = $this->pool->hold($this->channel, $id, $window->requestedBytes);
            if ($held === null) {
                continue;
            }
            $window->reservation = $this->pool->publish($held->id);
            unset($this->waitingIncoming[$id]);
            $this->link->sendFrame(new PeerUploadGrantedDTO($id, $held->byteCount));
        }
    }

    /**
     * Transport buffers have been deleted. Received worker debt is deliberately untouched.
     *
     * @throws LogicException When unused capacity is inconsistent
     */
    public function close(): void
    {
        $this->closed = true;
        $this->waitingIncoming = [];
        $this->pool->closeConnection($this->channel);
        foreach ($this->outgoing as $grant) {
            $grant->canceled = true;
        }
        $this->incoming = [];
        $this->outgoing = [];
        $this->received = [];
    }
}

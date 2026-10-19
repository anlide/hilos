<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Closure;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadAcceptedDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Environment\Exception\EnvException;
use Hilos\Files\Upload\DTO\UploadCreditRequestSignalData;
use Hilos\Files\Upload\DTO\UploadCreditSignalData;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Logger;
use WeakMap;
use WeakReference;

/**
 * The daemon's sole upload ledger and its transport metadata, with no database or payload copies.
 *
 * Hilos::$rt->hilosUploads (owned by UploadsAgent) supplies the replicated declaration/FSM.
 * Permissions, parser state and receipt/channel lifetimes belong to this master alone and die
 * with its queues, per framework-first.md, rt-context.md and architecture/truth-source.md.
 */
final class UploadMemoryMaster implements UploadMemoryDeliverySink
{
    private readonly UploadMemoryPool $pool;

    /** @var array<string, UploadMemoryInput> Admission owned by each live real socket */
    private array $inputs = [];

    /** @var array<string, array<string, UploadMemoryCreditDemand>> Small metadata waits by socket/upload */
    private array $demands = [];

    /** @var WeakMap<WebSocketFrameBinarySignalDTO, UploadMemoryReceipt> Associations which never retain a raw payload */
    private readonly WeakMap $frameReceipts;

    /** @var array<string, WeakReference<WebSocketFrameBinarySignalDTO>> Unassigned receipts watching actual payload deletion */
    private array $pendingReceipts = [];

    /** @var WeakMap<WorkerClient, string> Exact channel incarnations, never reusable worker indexes */
    private readonly WeakMap $channels;

    private int $channelSequence = 0;

    private bool $needsWake = false;

    private ?PeerServer $peerServer = null;

    private ?string $nodeId = null;

    /** @var array<int, UploadMemoryPeer> Metadata belongs to exact real link objects */
    private array $peers = [];

    /** @var array<string, array<string, UploadMemoryRemoteUpload>> Uploads pinned before their first remote grant */
    private array $remoteUploads = [];

    /** @var array<string, UploadMemoryTestDelivery> Test-only metadata, never materialized before admission */
    private array $testDeliveries = [];

    private int $testSequence = 0;

    /**
     * @param string $generation Unique node/master boot identity
     * @throws LogicException When the generation is empty
     */
    public function __construct(private readonly string $generation)
    {
        $this->pool = new UploadMemoryPool(0, $generation);
        $this->frameReceipts = new WeakMap();
        $this->channels = new WeakMap();
    }

    /**
     * @param string $acceptKey Real socket identity
     * @return UploadMemoryInput Stable admission over the sole master ledger
     * @throws LogicException When the socket identity is empty
     */
    public function getUploadInput(string $acceptKey): UploadMemoryInput
    {
        return $this->inputs[$acceptKey] ??= new UploadMemoryInput($this->pool, $acceptKey);
    }

    /**
     * Late declarations keep their original request within the existing connection upload bound.
     * Excess identities receive the existing gone signal rather than waiting without a slot.
     *
     * @param UploadCreditRequestSignalData $request Socket-stamped demand, never an action
     * @throws EnvException When placement configuration is invalid
     * @throws RtActionsStateCollectionNullException When the mounted upload collection is unavailable
     * @throws LogicException When a ledger invariant is broken
     * @throws InvalidFormatException When an internally produced permission is invalid
     * @throws InvalidArgumentException When an internally queued credit signal cannot be named
     * @throws PeerTransportException When an ordered peer reservation is inconsistent
     */
    public function requestCredit(UploadCreditRequestSignalData $request): void
    {
        if (!isset($this->inputs[$request->acceptKey]) || $request->bytes === 0) {
            return;
        }
        $previous = $this->demands[$request->acceptKey][$request->clientUploadId] ?? null;
        if ($previous !== null && $previous->request->bytes !== $request->bytes) {
            Logger::warning('Upload credit repeat changed its full file size');
            return;
        }
        if ($previous === null
            && count($this->demands[$request->acceptKey] ?? []) >= UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION) {
            $this->wake();
            if (count($this->demands[$request->acceptKey] ?? []) >= UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION) {
                $this->interrupt($request->acceptKey, $request->clientUploadId);
                return;
            }
        }
        $this->demands[$request->acceptKey][$request->clientUploadId] ??= new UploadMemoryCreditDemand($request);
        $this->wake();
    }

    /** Remember a source-ready/change fact; the master drain wakes after all replica writes apply. */
    public function onUploadsChanged(): void
    {
        $this->needsWake = true;
    }

    /**
     * @param string $acceptKey Real socket identity
     * @param string $clientUploadId Upload canceled, failed or gone
     * @throws LogicException When an unused-capacity invariant is broken
     * @throws PeerTransportException When an ordered peer reservation is inconsistent
     */
    public function cancel(string $acceptKey, string $clientUploadId): void
    {
        ($this->inputs[$acceptKey] ?? null)?->cancel($clientUploadId);
        ($this->remoteUploads[$acceptKey][$clientUploadId] ?? null)?->cancel();
        unset($this->remoteUploads[$acceptKey][$clientUploadId]);
        $this->needsWake = true;
        unset($this->demands[$acceptKey][$clientUploadId]);
        if (($this->demands[$acceptKey] ?? []) === []) {
            unset($this->demands[$acceptKey]);
        }
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $frame The same raw object entering SignalRouter
     * @param UploadMemoryReceipt $receipt Existing input's combined pending receipt
     */
    public function onUploadFrameQueued(WebSocketFrameBinarySignalDTO $frame, UploadMemoryReceipt $receipt): void
    {
        $this->frameReceipts[$frame] = $receipt;
        $this->pendingReceipts[$receipt->id] = WeakReference::create($frame);
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $frame Admitted raw object, including after agent-start parking
     * @param WorkerClient $worker Actual destination
     * @return ?string Existing receipt for the IPC field, or null for non-upload input
     * @throws LogicException When the pending receipt cannot be assigned
     */
    public function assignUploadFrame(WebSocketFrameBinarySignalDTO $frame, WorkerClient $worker): ?string
    {
        $receiptId = ($this->frameReceipts[$frame] ?? null)?->id;
        if ($receiptId === null) {
            return null;
        }
        $this->channels[$worker] ??= $this->generation . ':worker:' . ++$this->channelSequence;
        if ($this->pool->assign($receiptId, $this->channels[$worker]) === null) {
            throw new LogicException('Upload receipt cannot enter another worker incarnation');
        }
        unset($this->pendingReceipts[$receiptId]);

        return $receiptId;
    }

    /**
     * Called after a complete router/start-queue mutation, outside the drain's local payload scope.
     * Weak references prove deletion of ALL retained raw objects, including local temporaries;
     * they do not infer deletion from a routing outcome or release bytes from a parked frame.
     *
     * @throws EnvException When placement configuration is invalid
     * @throws RtActionsStateCollectionNullException When the mounted upload collection is unavailable
     * @throws LogicException When a ledger invariant is broken
     * @throws InvalidFormatException When an internally produced permission is invalid
     * @throws InvalidArgumentException When an internally queued credit signal cannot be named
     * @throws PeerTransportException When an ordered peer reservation is inconsistent
     */
    public function afterDispatch(): void
    {
        $usedBytes = $this->pool->getUsedBytes();
        foreach ($this->peers as $id => $peer) {
            $peer->afterDispatch();
            if ($peer->canForget()) {
                unset($this->peers[$id]);
            }
        }
        $released = $usedBytes !== $this->pool->getUsedBytes();
        foreach ($this->pendingReceipts as $receiptId => $frame) {
            if ($frame->get() === null) {
                $this->pool->discardPending($receiptId);
                unset($this->pendingReceipts[$receiptId]);
                $released = true;
            }
        }
        if ($released || $this->needsWake) {
            $this->needsWake = false;
            foreach ($this->peers as $peer) {
                $peer->wake();
            }
            $this->advanceTestDeliveries();
            $this->wake();
        }
    }

    /**
     * @param string $acceptKey Parser already cleared its bytes and unused permission
     * @throws PeerTransportException When peer cancellation metadata is inconsistent
     * @throws LogicException When local unused capacity is inconsistent
     */
    public function onUploadConnectionClosed(string $acceptKey): void
    {
        foreach ($this->remoteUploads[$acceptKey] ?? [] as $remote) {
            $remote->cancel();
        }
        unset($this->remoteUploads[$acceptKey]);
        unset($this->inputs[$acceptKey], $this->demands[$acceptKey]);
        $this->needsWake = true;
    }

    /**
     * @param WorkerClient $worker Registered reporter
     * @param int $memoryPoolBytes Positive effective setting
     * @throws EnvException When placement configuration is invalid
     * @throws RtActionsStateCollectionNullException When the mounted upload collection is unavailable
     * @throws LogicException When a ledger invariant is broken
     * @throws InvalidFormatException When an internally produced permission is invalid
     * @throws InvalidArgumentException When an internally queued credit signal cannot be named
     * @throws PeerTransportException When an ordered peer reservation is inconsistent
     */
    public function onUploadSettings(WorkerClient $worker, int $memoryPoolBytes): void
    {
        if (!$worker->isRegistered() || $memoryPoolBytes <= 0) {
            return;
        }
        $this->pool->setLimitBytes($memoryPoolBytes);
        $this->needsWake = true;
        $this->wake();
    }

    /**
     * @param WorkerClient $worker Exact registered channel that processed these receipts
     * @param list<string> $receiptIds Ids only; byte counts come from the ledger
     * @throws EnvException When placement configuration is invalid
     * @throws RtActionsStateCollectionNullException When the mounted upload collection is unavailable
     * @throws LogicException When a ledger invariant is broken
     * @throws InvalidFormatException When an internally produced permission is invalid
     * @throws InvalidArgumentException When an internally queued credit signal cannot be named
     * @throws PeerTransportException When an ordered peer reservation is inconsistent
     */
    public function onUploadConsumed(WorkerClient $worker, array $receiptIds): void
    {
        $channel = $this->channels[$worker] ?? null;
        if (!$worker->isRegistered() || $channel === null) {
            return;
        }
        foreach ($receiptIds as $receiptId) {
            $this->pool->settle($receiptId, $channel);
        }
        $this->needsWake = true;
        $this->wake();
    }

    /** @param WorkerClient $worker Channel with confirmed process exit and discarded transport buffers */
    public function discardUploadWorker(WorkerClient $worker): void
    {
        $channel = $this->channels[$worker] ?? null;
        if ($channel !== null) {
            $this->pool->discardChannel($channel);
            unset($this->channels[$worker]);
            $this->needsWake = true;
        }
    }

    /** @return array{limitBytes: int, usedBytes: int, waitingUploads: int, pendingPeerReservations: int} Live ledger boundary */
    public function inspect(): array
    {
        $waitingUploads = count($this->testDeliveries);
        foreach ($this->demands as $uploads) {
            foreach ($uploads as $demand) {
                if (($this->pool->getSendUpToBytes($demand->request->acceptKey, $demand->request->clientUploadId) ?? 0)
                    < $demand->request->bytes) {
                    $waitingUploads++;
                }
            }
        }
        $pendingPeerReservations = 0;
        foreach ($this->peers as $peer) {
            $pendingPeerReservations += $peer->pendingReservations();
        }

        return ['limitBytes' => $this->pool->getLimitBytes(), 'usedBytes' => $this->pool->getUsedBytes(),
            'waitingUploads' => $waitingUploads, 'pendingPeerReservations' => $pendingPeerReservations];
    }

    /**
     * Called only behind both ordinary test:* environment gates, without a DB setting write.
     *
     * @param int $bytes Nonnegative test capacity, including suspension at zero
     * @throws LogicException When the requested limit is negative
     */
    public function setTestLimit(int $bytes): void
    {
        $this->pool->setLimitBytes($bytes);
        $this->needsWake = true;
    }

    /**
     * @param string $targetNodeId Validated live test-agent node
     * @param string $agentType Validated test-agent type
     * @param ?string $agentIndex Validated test-agent instance
     * @param int $bytes Raw fixture length, validated by the command boundary
     * @param Closure(UploadMemoryTestDelivery, SignalDTO): void $deliver Production daemon delivery helper
     * @throws LogicException When a local reservation invariant is broken
     * @throws PeerTransportException When the destination link disappears before admission
     * @throws InvalidArgumentException When the binary signal cannot be named
     */
    public function enqueueTest(string $targetNodeId, string $agentType, ?string $agentIndex, int $bytes, Closure $deliver): void
    {
        $id = 'pool_' . ++$this->testSequence;
        $delivery = new UploadMemoryTestDelivery($id, $targetNodeId, $agentType, $agentIndex, $bytes, $deliver);
        if ($this->nodeId !== null && $targetNodeId !== $this->nodeId) {
            $link = $this->peerServer?->uploadLinkToNode($targetNodeId);
            if ($link === null) {
                throw new PeerTransportException('Upload fixture target has no live peer link');
            }
            $this->remoteUploads[$id][$id] = new UploadMemoryRemoteUpload(
                $this->pool, $this->peer($link), $id, $id, $bytes, strcmp($this->nodeId, $targetNodeId) < 0,
            );
        }
        $this->testDeliveries[$id] = $delivery;
        $this->needsWake = true;
        $this->advanceTestDeliveries();
    }

    /**
     * @param PeerServer $server This node's existing peer transport
     * @param string $nodeId Stable lexicographic acquisition identity
     */
    public function setPeerServer(PeerServer $server, string $nodeId): void
    {
        $this->peerServer = $server;
        $this->nodeId = $nodeId;
    }

    /**
     * @param PeerLink $link Actual incarnation supplying the control frame
     * @param PeerDTO $frame Validated upload control metadata
     * @throws PeerTransportException When link or request metadata is invalid
     * @throws LogicException When a pool invariant is broken
     */
    public function onPeerControl(PeerLink $link, PeerDTO $frame): void
    {
        $this->peer($link)->receive($frame);
        $this->needsWake = true;
    }

    /**
     * @param PeerLink $link Incarnation whose actual transport buffers have been cleared
     * @throws LogicException When unused peer capacity is inconsistent
     * @throws PeerTransportException When the original peer window cannot be canceled
     * @throws InvalidArgumentException When the upload interruption signal cannot be named
     */
    public function onPeerClosed(PeerLink $link): void
    {
        ($this->peers[spl_object_id($link)] ?? null)?->close();
        foreach ($this->remoteUploads as $acceptKey => $uploads) {
            foreach ($uploads as $clientUploadId => $remote) {
                if ($remote->peer->link === $link && isset($this->demands[$acceptKey][$clientUploadId])) {
                    $this->interrupt($acceptKey, (string)$clientUploadId);
                }
            }
        }
        $this->needsWake = true;
    }

    /** Remember a link/placement fact without doing work in its notification stack. */
    public function onPeerChanged(): void
    {
        $this->needsWake = true;
    }

    /**
     * @param WebSocketFrameBinarySignalDTO $frame Raw object entering the production delivery path
     * @return bool Whether input admission has already budgeted its raw bytes
     */
    public function isBudgeted(WebSocketFrameBinarySignalDTO $frame): bool
    {
        return isset($this->frameReceipts[$frame]);
    }

    /**
     * Split a logical receipt along its existing windows without adding a byte obligation.
     * Every part retains the original signature, source, target and analytics metadata.
     *
     * @param PeerLink $link Actual destination incarnation, checked before serialization
     * @param string $agentType Already resolved receiver
     * @param ?string $agentIndex Already resolved receiver instance
     * @param SignalDTO $signal Original admitted binary signal
     * @throws PeerTransportException When the original two-node window is no longer usable
     * @throws LogicException When a receipt cannot be split or assigned
     * @throws InvalidArgumentException When a portion signal cannot be named
     */
    public function sendPeerSignal(PeerLink $link, string $agentType, ?string $agentIndex, SignalDTO $signal): void
    {
        if (!$signal->data instanceof WebSocketFrameBinarySignalDTO) {
            throw new PeerTransportException('Budgeted peer delivery needs the original raw binary DTO');
        }
        $frame = $signal->data;
        $chunk = UploadFrame::parse($frame->payload);
        $remote = $chunk === null ? null : ($this->remoteUploads[$frame->acceptKey][$chunk->clientUploadId] ?? null);
        if ($remote === null || $remote->peer->link !== $link || !$remote->peer->isOpen()) {
            throw new PeerTransportException('Upload placement or link changed after its browser grant');
        }
        $receiptId = ($this->frameReceipts[$frame] ?? null)?->id;
        if ($receiptId === null) {
            throw new PeerTransportException('Upload peer frame has no admitted receipt');
        }
        $original = $this->frameReceipts[$frame];
        if ($original->byteCount !== strlen($chunk->bytes)) {
            throw new PeerTransportException('Upload peer frame length disagrees with its admitted receipt');
        }
        $remote->assertPortions($original->reservationBytes);
        $parts = $this->pool->splitPending($receiptId);
        unset($this->pendingReceipts[$receiptId]);
        $offsetBytes = 0;
        foreach ($parts as $part) {
            $localWindowId = array_key_first($part->reservationBytes);
            $envelope = $remote->takePortion($localWindowId, $part->id, $part->byteCount);
            $portion = new WebSocketFrameBinarySignalDTO($frame->acceptKey,
                chr(strlen($chunk->clientUploadId)) . $chunk->clientUploadId . substr($chunk->bytes, $offsetBytes, $part->byteCount));
            $link->sendFrame(new PeerSignalDTO($this->nodeId, $remote->peer->nodeId, $agentType, $agentIndex,
                new SignalDTO($signal->signalSource, $signal->signalType, $signal->signalName, $portion, $signal->meta), $envelope));
            $remote->peer->track($part->id, $frame);
            $remote->afterSend($localWindowId);
            $offsetBytes += $part->byteCount;
        }
    }

    /**
     * @param PeerLink $link Original established receiver channel
     * @param PeerSignalDTO $frame Decoded signal with receiver reservation
     * @return ?UploadMemoryReceipt Newly accepted portion, or null for an exact duplicate of an unsettled portion
     * @throws PeerTransportException When raw bytes are not covered by the original receiver grant
     * @throws LogicException When a receiver ledger invariant is broken
     */
    public function acceptPeerSignal(PeerLink $link, PeerSignalDTO $frame): ?UploadMemoryReceipt
    {
        if (!$frame->signal->data instanceof WebSocketFrameBinarySignalDTO || $frame->uploadReservation === null) {
            throw new PeerTransportException('Reserved peer signal needs a signed binary upload portion');
        }
        $chunk = UploadFrame::parse($frame->signal->data->payload);
        if ($chunk === null) {
            throw new PeerTransportException('Reserved peer upload has no valid signature');
        }
        $receipt = $this->peer($link)->accept($frame->uploadReservation, strlen($chunk->bytes));
        if ($receipt !== null) {
            $this->onUploadFrameQueued($frame->signal->data, $receipt);
        }
        $link->sendFrame(new PeerUploadAcceptedDTO($frame->uploadReservation->receiptId));

        return $receipt;
    }

    /**
     * Reconcile only metadata; the declared upload is read by its existing composite finder.
     *
     * @throws EnvException When placement configuration is invalid
     * @throws RtActionsStateCollectionNullException When the mounted upload collection is unavailable
     * @throws LogicException When a ledger invariant is broken
     * @throws InvalidFormatException When an internally produced permission is invalid
     * @throws InvalidArgumentException When an internally queued credit signal cannot be named
     */
    private function wake(): void
    {
        if (Hilos::$rt === null || !SourceInterestRegistry::isReady(SourceChange::KIND_RT, StateHilosUpload::RT_COLLECTION)) {
            return;
        }
        foreach ($this->demands as $acceptKey => $uploads) {
            foreach ($uploads as $clientUploadId => $demand) {
                $upload = Hilos::$rt->hilosUploads->find($acceptKey, (string)$clientUploadId);
                if ($upload === null) {
                    if ($demand->admitted || isset($this->remoteUploads[$acceptKey][$clientUploadId])) {
                        $this->cancel($acceptKey, (string)$clientUploadId);
                    }
                    continue;
                }
                if ($upload->declaredSize !== $demand->request->bytes || !$upload->phase->isReceiving()) {
                    $this->cancel($acceptKey, (string)$clientUploadId);
                    continue;
                }
                $destination = Hilos::$sr->placeAgentDestination(new AgentDestination(HilosAgentType::HILOS_UPLOADS));
                if ($demand->localPlacement === true && !$destination instanceof AgentDestination) {
                    $this->interrupt($acceptKey, (string)$clientUploadId);
                    continue;
                }
                $remote = $this->remoteUploads[$acceptKey][$clientUploadId] ?? null;
                if ($remote !== null && (!$destination instanceof RemoteAgentDestination
                    || $destination->nodeId !== $remote->peer->nodeId || !$remote->peer->isOpen()
                    || $this->peerServer?->uploadLinkToNode($destination->nodeId) !== $remote->peer->link)) {
                    $this->interrupt($acceptKey, (string)$clientUploadId);
                    continue;
                }
                if ($upload->staleSince() !== null) {
                    continue;
                }
                if ($destination instanceof RemoteAgentDestination) {
                    $link = $this->peerServer?->uploadLinkToNode($destination->nodeId);
                    if ($link === null) {
                        continue;
                    }
                    $demand->localPlacement = false;
                    $remote ??= $this->remoteUploads[$acceptKey][$clientUploadId] = new UploadMemoryRemoteUpload(
                        $this->pool, $this->peer($link), $acceptKey, (string)$clientUploadId, $upload->declaredSize,
                        strcmp($this->nodeId, $destination->nodeId) < 0,
                    );
                    while (($published = $remote->reserveNext()) !== null) {
                        $demand->admitted = true;
                        $this->publishCredit($published);
                    }
                    continue;
                }
                if (!$destination instanceof AgentDestination) {
                    continue;
                }
                $demand->localPlacement = true;
                $this->pool->request($acceptKey, (string)$clientUploadId, $upload->declaredSize);
                $demand->admitted = true;
            }
        }

        $deferred = [];
        while (($held = $this->pool->reserveNext()) !== null) {
            $upload = Hilos::$rt->hilosUploads->find($held->acceptKey, $held->clientUploadId);
            if ($upload === null || !$upload->phase->isReceiving()) {
                $this->cancel($held->acceptKey, $held->clientUploadId);
                continue;
            }
            if ($upload->staleSince() !== null
                || !Hilos::$sr->placeAgentDestination(new AgentDestination(HilosAgentType::HILOS_UPLOADS)) instanceof AgentDestination) {
                $this->pool->releaseUnused($held->id, 0);
                if (isset($deferred[$held->requestId])) {
                    break;
                }
                $deferred[$held->requestId] = true;
                continue;
            }
            $published = $this->pool->publish($held->id);
            if ($published === null) {
                throw new LogicException('Upload memory reservation disappeared before publication');
            }
            $this->publishCredit($published);
        }
    }

    /**
     * @param PeerLink $link Exact established channel
     * @return UploadMemoryPeer Link-scoped accounting over the existing node pool
     * @throws PeerTransportException When the channel has not completed its handshake
     */
    private function peer(PeerLink $link): UploadMemoryPeer
    {
        $remote = $link->remoteIdentity();
        if ($remote === null || $link->shouldClose()) {
            throw new PeerTransportException('Upload control needs an established live peer identity');
        }

        return $this->peers[spl_object_id($link)] ??= new UploadMemoryPeer(
            $this->pool, $link, $this->generation . ':peer:' . ++$this->channelSequence . ':' . $remote->nodeId, $remote->nodeId,
        );
    }

    /**
     * @param UploadMemoryReservation $published Window already held on every required node
     * @throws LogicException When admission cannot install the window
     * @throws InvalidFormatException When its cumulative boundary is invalid
     * @throws InvalidArgumentException When the credit signal cannot be named
     */
    private function publishCredit(UploadMemoryReservation $published): void
    {
        $this->inputs[$published->acceptKey]->allow($published);
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(HilosSignalConstants::HILOS_UPLOAD_CREDIT),
            new WebSocketSignalData(
                new UploadCreditSignalData($published->clientUploadId, $published->sendUpToBytes),
                targetAcceptKey: $published->acceptKey,
            ),
        );
    }

    /**
     * @param string $acceptKey Browser whose original owner/link disappeared
     * @param string $clientUploadId Upload to close through the existing gone contract
     * @throws LogicException When unused capacity cannot be canceled
     * @throws PeerTransportException When peer cancellation metadata is inconsistent
     * @throws InvalidArgumentException When the gone signal cannot be named
     */
    private function interrupt(string $acceptKey, string $clientUploadId): void
    {
        $this->cancel($acceptKey, $clientUploadId);
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(HilosSignalConstants::HILOS_UPLOAD_STATE),
            new WebSocketSignalData(UploadStateSignalData::gone($clientUploadId), targetAcceptKey: $acceptKey));
    }


    /** Materialize exactly one signed fixture only after every required window has been granted. */
    private function advanceTestDeliveries(): void
    {
        foreach ($this->testDeliveries as $id => $delivery) {
            $remote = $this->remoteUploads[$id][$id] ?? null;
            if ($remote !== null && (!$remote->peer->isOpen()
                || $this->peerServer?->uploadLinkToNode($delivery->targetNodeId) !== $remote->peer->link)) {
                $remote->cancel();
                unset($this->remoteUploads[$id], $this->testDeliveries[$id]);
                continue;
            }
            while ($delivery->reservedBytes < $delivery->bytes) {
                $held = $remote === null ? $this->pool->hold($id, $id, $delivery->bytes) : $remote->reserveNext();
                if ($held === null) {
                    break;
                }
                if ($remote === null) {
                    $held = $this->pool->publish($held->id);
                }
                $delivery->requestId = $held->requestId;
                $delivery->reservedBytes += $held->remainingBytes;
            }
            if ($delivery->reservedBytes !== $delivery->bytes) {
                continue;
            }
            $receipt = $this->pool->acceptPending($delivery->requestId, 0, $delivery->bytes);
            if ($receipt === null) {
                throw new LogicException('Upload fixture lost its admitted windows');
            }
            $frame = new WebSocketFrameBinarySignalDTO($id, chr(strlen($id)) . $id . str_repeat('x', $delivery->bytes));
            $this->onUploadFrameQueued($frame, $receipt);
            unset($this->testDeliveries[$id]);
            try {
                ($delivery->deliver)($delivery, new SignalDTO(new SignalSource(SignalSource::DAEMON),
                    new SignalType(SignalTypeConstants::FRAME_BINARY), new SignalName(SignalTypeConstants::FRAME_BINARY), $frame));
            } finally {
                if ($remote === null) {
                    $this->pool->cancel($id, $id);
                } else {
                    $remote->cancel();
                    unset($this->remoteUploads[$id]);
                }
            }
        }
    }

}

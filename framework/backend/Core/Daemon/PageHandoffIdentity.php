<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementId;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;

/**
 * The confirmed connection row a handoff needs in the serving worker.
 * The internal signal envelope carries it alongside the existing delivery token.
 */
final readonly class PageHandoffIdentity
{
    public const string META_COLLECTION = 'pageHandoffCollection';

    public const string META_PROOF = 'pageHandoffProof';

    public const string META_CHALLENGE = 'pageHandoffChallenge';

    public const string META_REPLY_NODE = 'pageHandoffReplyNode';

    public const string META_RELAY_FROM = 'pageHandoffRelayFrom';

    public const string META_RELAY_LEADER = 'pageHandoffRelayLeader';

    public const string META_RELAY_TERM = 'pageHandoffRelayTerm';

    public const string META_RELAY_SEQUENCE = 'pageHandoffRelaySequence';

    public const string META_WORKER_ORDER = 'pageHandoffWorkerOrder';

    public const string META_TERMINAL_REFUSAL = 'pageHandoffTerminalRefusal';

    public const string PROBE = 'probe';

    public const string GRANTED = 'granted';

    public const string REFUSED = 'refused';

    public const int CHALLENGE_BYTES = 16;

    private const string META_USER_ID = 'pageHandoffUserId';

    private const string META_SESSION_ID = 'pageHandoffSessionId';

    /**
     * @param string $collectionKey Mounted connection source
     * @param ?int $userId User certified by the source, or null for a guest
     * @param ?int $sessionId Session certified by the source, when the project carries sessions
     */
    public function __construct(public string $collectionKey, public ?int $userId, public ?int $sessionId)
    {
    }

    /**
     * @param string $acceptKey Exact connection whose page is being handed off
     * @return ?self Identity on a fresh connection row, absent before the source confirms it
     */
    public static function current(string $acceptKey): ?self
    {
        $source = Hilos::$rt?->connectionsSource();
        $collectionKey = Hilos::$rt?->connectionsRegistry()?->getCollectionName();
        $row = $source?->get($acceptKey);
        $identity = Hilos::$browser?->connectionIdentity($acceptKey);
        if ($collectionKey === null || $row === null || $identity === null || $identity->pending
            || RtStaleness::staleSince($collectionKey, $acceptKey) !== null) {
            return null;
        }

        $sessionId = $row instanceof HilosSessionConnection ? $row->sessionId : null;
        if ($row instanceof HilosSessionConnection && ($sessionId === null || $sessionId <= 0)) {
            return null;
        }

        return new self($collectionKey, $identity->userId, $sessionId);
    }

    /**
     * @param array<string, int|string|array{leaderNodeId: string, term: int, sequence: int}> $meta Internal signal metadata
     * @return ?self Expected row, present only on an identity handoff
     */
    public static function fromMeta(array $meta): ?self
    {
        $collectionKey = $meta[self::META_COLLECTION] ?? null;
        $userId = $meta[self::META_USER_ID] ?? null;
        $sessionId = $meta[self::META_SESSION_ID] ?? null;
        if (!is_string($collectionKey) || ($userId !== null && !is_int($userId)) || ($sessionId !== null && !is_int($sessionId))) {
            return null;
        }

        return new self($collectionKey, $userId, $sessionId);
    }

    /** @return array<string, int|string> Certified identity carried internally beside the existing delivery token */
    public function toMeta(): array
    {
        $meta = [self::META_COLLECTION => $this->collectionKey];
        if ($this->userId !== null) {
            $meta[self::META_USER_ID] = $this->userId;
        }
        if ($this->sessionId !== null) {
            $meta[self::META_SESSION_ID] = $this->sessionId;
        }

        return $meta;
    }

    /**
     * @param array<string, int|string|array{leaderNodeId: string, term: int, sequence: int}> $meta Internal metadata of the relay or probe
     * @return ?PlacementId Placement order proven by the forwarding node
     */
    public static function relayOrder(array $meta): ?PlacementId
    {
        $leader = $meta[self::META_RELAY_LEADER] ?? null;
        $term = $meta[self::META_RELAY_TERM] ?? null;
        $sequence = $meta[self::META_RELAY_SEQUENCE] ?? null;
        if (!is_string($leader) || $leader === '' || !is_int($term) || $term < 0 || !is_int($sequence) || $sequence <= 0) {
            return null;
        }

        return new PlacementId($leader, $term, $sequence);
    }

    /**
     * @param array<string, mixed> $meta Handoff envelope stamped by the serving or socket master
     * @return ?PlacementId Confirmed worker order, absent on unplaced paths or malformed metadata
     */
    public static function workerOrder(array $meta): ?PlacementId
    {
        $raw = $meta[self::META_WORKER_ORDER] ?? null;
        if ($raw === null) {
            return null;
        }
        try {
            return PlacementId::fromWire($raw);
        } catch (PeerTransportException) {
            return null;
        }
    }

    /**
     * @param mixed $challenge Metadata received on the page proof service
     * @return bool Whether it has the worker's challenge form
     */
    public static function isChallenge(mixed $challenge): bool
    {
        return is_string($challenge) && strlen($challenge) === self::CHALLENGE_BYTES * 2 && ctype_xdigit($challenge);
    }

    /**
     * @param SignalDTO $signal Possible internal proof response
     * @param WebSocketPageSubscribeSignalDTO $request Correlated page request
     * @return bool Whether the envelope names the socket master's proof service
     */
    public static function isReply(SignalDTO $signal, WebSocketPageSubscribeSignalDTO $request): bool
    {
        return $signal->signalType->getType() === SignalTypeConstants::PAGE_ACCESS_REASSESS
            && $signal->signalSource->getSource() === SignalSource::DAEMON
            && $signal->signalSource->getType() === null && $signal->signalSource->getIndex() === null
            && $request->page !== null && $request->deliveryId !== null
            && $signal->signalName->getName() === $request->page
            && in_array($signal->meta[self::META_PROOF] ?? null, [self::GRANTED, self::REFUSED], true)
            && self::isChallenge($signal->meta[self::META_CHALLENGE] ?? null)
            && self::fromMeta($signal->meta) !== null;
    }

    /**
     * @param string $acceptKey Socket that minted both tokens
     * @param string $deliveryId Proposed subscription authority
     * @param string $previousId Authority already seen by this worker
     * @return bool Whether the proposal succeeds that authority in the socket's admission sequence
     */
    public static function follows(string $acceptKey, string $deliveryId, string $previousId): bool
    {
        $prefix = $acceptKey . ':';
        if (!str_starts_with($deliveryId, $prefix) || !str_starts_with($previousId, $prefix)) {
            return false;
        }

        return (int)substr($deliveryId, strlen($prefix)) > (int)substr($previousId, strlen($prefix));
    }
}

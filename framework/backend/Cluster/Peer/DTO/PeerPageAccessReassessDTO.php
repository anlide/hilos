<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Router\DTO\SignalDTO;
use Throwable;

/**
 * Carries a page access re-decision announcement verbatim to every other node.
 *
 * Slaves receive it too: the page subscription mirror lives in a worker of the node
 * serving the page's agent. The receiver writes to its workers on arrival and never
 * forwards the frame (HIL-1306).
 */
final class PeerPageAccessReassessDTO extends PeerDTO
{
    /** @var string Wire message type for a cross-node page access re-decision */
    public const string MESSAGE_TYPE = 'peer_page_access_reassess';

    /** @var string Payload key: node that announced the re-decision */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: serialized announcement */
    public const string FIELD_SIGNAL = 'signal';

    /**
     * @param string $originNodeId Id of the announcing node
     * @param SignalDTO $signal Page access re-decision announcement
     */
    public function __construct(
        public readonly string $originNodeId,
        public readonly SignalDTO $signal,
    ) {
    }

    /**
     * @return string Wire message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_SIGNAL => $this->signal->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored frame
     * @throws PeerTransportException When the origin or inner signal is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer page access re-decision is missing the origin node id');
        }

        $signalRaw = $data[self::FIELD_SIGNAL] ?? null;
        if (!is_array($signalRaw)) {
            throw new PeerTransportException('Peer page access re-decision is missing the inner signal payload');
        }

        try {
            $signal = SignalDTO::fromArray($signalRaw);
        } catch (Throwable $e) {
            throw new PeerTransportException('Peer page access re-decision carries a malformed inner signal: ' . $e->getMessage());
        }

        return new static($originNodeId, $signal);
    }
}

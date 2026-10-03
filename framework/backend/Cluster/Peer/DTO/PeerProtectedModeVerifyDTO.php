<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that moves the freeze into its verification window.
 *
 * Unlike the enable/quiesce pair, one frame serves both directions: an initiator that does not
 * lead sends it to the leader with {@see PeerServer::sendToMaster}, and the leader sends the same
 * frame on to every follower with {@see PeerServer::broadcastToMasters}. The receiving node knows
 * which half it is playing without being told - it either leads the freeze the sender initiated,
 * or it is frozen by the sender - so a second name would carry no information the node does not
 * already hold. Each node then writes {@see ProtectedModeRuntime::PHASE_VERIFYING} locally, which
 * is what lets a verifier land on any node. There is only ever one freeze in flight, so the frame
 * carries the initiator agent identity so the leader can authorize the move even after relocation.
 */
final class PeerProtectedModeVerifyDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode verify frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_verify';

    /** @var string Frame key naming the initiator agent type */
    public const string FIELD_INITIATOR_AGENT_TYPE = 'initiatorAgentType';

    /** @var string Frame key naming the initiator agent index */
    public const string FIELD_INITIATOR_AGENT_INDEX = 'initiatorAgentIndex';

    /**
     * @param string $initiatorAgentType Type of the agent that initiated the freeze
     * @param ?int $initiatorAgentIndex Index of that agent, or null for a singleton
     */
    public function __construct(
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
    ) {
    }

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the verify frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_INITIATOR_AGENT_TYPE => $this->initiatorAgentType,
            self::FIELD_INITIATOR_AGENT_INDEX => $this->initiatorAgentIndex,
        ];
    }

    /**
     * Restores a verify frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the initiator identity or payload is malformed
     */
    public static function fromArray(array $data): static
    {
        try {
            $agentType = self::requireString($data, self::FIELD_INITIATOR_AGENT_TYPE);
            $agentIndex = self::optionalInt($data, self::FIELD_INITIATOR_AGENT_INDEX);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException(
                'Peer protected-mode verify frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($agentType === '') {
            throw new PeerTransportException('Peer protected-mode verify frame is malformed: initiatorAgentType is empty');
        }

        return new static($agentType, $agentIndex);
    }
}

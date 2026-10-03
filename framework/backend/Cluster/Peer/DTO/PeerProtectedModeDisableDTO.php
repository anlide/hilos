<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Peer frame the initiator node sends to the leader to lift the freeze cluster-wide.
 *
 * Sent after the destructive operation finishes. The leader authorizes the release by
 * the agent identity carried in the frame; the node id from the link is used for logging.
 */
final class PeerProtectedModeDisableDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode disable frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_disable';

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
     * Serializes the disable frame to its wire array.
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
     * Restores a disable frame from its wire array.
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
                'Peer protected-mode disable frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($agentType === '') {
            throw new PeerTransportException('Peer protected-mode disable frame is malformed: initiatorAgentType is empty');
        }

        return new static($agentType, $agentIndex);
    }
}

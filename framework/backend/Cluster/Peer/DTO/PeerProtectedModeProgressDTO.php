<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that reports the operation behind a freeze still moving.
 *
 * Travels one way only - from the node running the operation to the leader, with
 * {@see PeerServer::sendToMaster} - and is never broadcast onward, unlike the verification frames:
 * the mark exists to be read by the watchdog, and the watchdog runs on the leader alone. The leader
 * stamps {@see ProtectedModeRuntime::$progressAt} from its OWN clock when this lands, which is why
 * the frame carries no timestamp: a value read off the wire would let a node with a skewed clock
 * decide how long another node's freeze may stay silent. There is only ever one freeze in flight,
 * Its only identifier is the initiator agent identity, which the leader checks before stamping.
 */
final class PeerProtectedModeProgressDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode progress frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_progress';

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
     * Serializes the progress frame to its wire array.
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
     * Restores a progress frame from its wire array.
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
                'Peer protected-mode progress frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($agentType === '') {
            throw new PeerTransportException('Peer protected-mode progress frame is malformed: initiatorAgentType is empty');
        }

        return new static($agentType, $agentIndex);
    }
}

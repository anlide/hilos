<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that asks the leader to close the freeze back out of its verification window.
 *
 * The mirror of {@see PeerProtectedModeVerifyDTO}, but it travels one way only: from the initiator's
 * node to the leader. The leader closes the window with the quiesce round an entry runs (HIL-1128):
 * every node goes back to {@see ProtectedModeRuntime::PHASE_ACTIVATING}, stops the agents the window
 * had brought back and voids every pass it held, and active is written only once all of them have
 * stopped - so the operator can act on what the verifiers found without first opening the system
 * to real users. Carries the initiator identity for leader authorization.
 */
final class PeerProtectedModeRefreezeDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode refreeze frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_refreeze';

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
     * Serializes the refreeze frame to its wire array.
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
     * Restores a refreeze frame from its wire array.
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
                'Peer protected-mode refreeze frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($agentType === '') {
            throw new PeerTransportException('Peer protected-mode refreeze frame is malformed: initiatorAgentType is empty');
        }

        return new static($agentType, $agentIndex);
    }
}

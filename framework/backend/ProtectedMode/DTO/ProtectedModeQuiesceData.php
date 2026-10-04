<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\ProtectedMode\ProtectedModeExecutor;

/**
 * ProtectedModeQuiesceData - leader -> follower freeze descriptor for the peer QUIESCE frame.
 *
 * The leader broadcasts it to every follower once an initiator has asked to freeze the cluster:
 * it names the operation and identifies the initiator agent so the follower can quiesce its own
 * agents while leaving that one running, then reports back with the QUIESCED frame. Unlike the
 * initiator->leader {@see ProtectedModeEnableSignalData}, this hand-off never rides the agent-signal
 * fabric — it is peer-transport only, leader to follower — so it is a plain payload and not a
 * {@see SignalDataInterface}. The operator's session hash travels to every master because any
 * master may receive that browser's next connection (HIL-1305). The accept key stays on the node
 * that owns its socket.
 */
final class ProtectedModeQuiesceData extends BaseDTO
{
    /** Payload key: the operation name the freeze protects. */
    public const string operation = 'operation';

    /** Payload key: the initiator agent type left running during the freeze. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the initiator agent index left running during the freeze. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /** Payload key: the node id that hosts the initiator agent, or null off-cluster. */
    public const string initiatorNodeId = 'initiatorNodeId';

    /** Payload key: the operator session hash shared by every master. */
    public const string initiatorSessionTokenHash = 'initiatorSessionTokenHash';

    /**
     * @param string $operation Operation the freeze protects
     * @param string $initiatorAgentType Agent type left running during the freeze
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     * @param ?string $initiatorNodeId Node id that hosts the initiator agent, or null on a
     *                                 single-node installation, which never sends this
     *                                 descriptor to a peer and only reuses it as the freeze
     *                                 argument of {@see ProtectedModeExecutor::enterActivating()}
     * @param ?string $initiatorSessionTokenHash Operator session hash, or null without a browser
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly ?string $initiatorNodeId,
        public readonly ?string $initiatorSessionTokenHash,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::operation => $this->operation,
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorAgentIndex => $this->initiatorAgentIndex,
            self::initiatorNodeId => $this->initiatorNodeId,
            self::initiatorSessionTokenHash => $this->initiatorSessionTokenHash,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no operation or no initiator agent type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            operation: self::requireString($data, self::operation),
            initiatorAgentType: self::requireString($data, self::initiatorAgentType),
            initiatorAgentIndex: self::optionalInt($data, self::initiatorAgentIndex),
            initiatorNodeId: self::optionalString($data, self::initiatorNodeId),
            initiatorSessionTokenHash: self::optionalString($data, self::initiatorSessionTokenHash),
        );
    }

    /**
     * @param ?string $initiatorSessionTokenHash Operator session hash for the next round
     * @return static Freeze descriptor for the next round
     */
    public function withInitiatorSessionTokenHash(?string $initiatorSessionTokenHash): static
    {
        return new static(
            $this->operation,
            $this->initiatorAgentType,
            $this->initiatorAgentIndex,
            $this->initiatorNodeId,
            $initiatorSessionTokenHash,
        );
    }
}

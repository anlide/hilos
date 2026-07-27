<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;

/**
 * ProtectedModeQuiesceData - leader -> follower freeze descriptor for the peer QUIESCE frame.
 *
 * The leader broadcasts it to every follower once an initiator has asked to freeze the cluster:
 * it names the operation and identifies the initiator agent so the follower can quiesce its own
 * agents while leaving that one running, then reports back with the QUIESCED frame. Unlike the
 * initiator->leader {@see ProtectedModeEnableSignalData}, this hand-off never rides the agent-signal
 * fabric — it is peer-transport only, leader to follower — so it is a plain payload and not a
 * {@see \Hilos\Core\Router\SignalDataInterface}. The accept-key of the initiator connection is a
 * leader/welcome concern and stays out of this descriptor; followers only need whom not to stop.
 */
final class ProtectedModeQuiesceData extends BaseDTO
{
    /** Payload key: the operation name the freeze protects. */
    public const string operation = 'operation';

    /** Payload key: the initiator agent type left running during the freeze. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the initiator agent index left running during the freeze. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /** Payload key: the node id that hosts the initiator agent. */
    public const string initiatorNodeId = 'initiatorNodeId';

    /**
     * @param string $operation Operation the freeze protects
     * @param string $initiatorAgentType Agent type left running during the freeze
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     * @param string $initiatorNodeId Node id that hosts the initiator agent
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly string $initiatorNodeId,
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
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $agentIndex = $data[self::initiatorAgentIndex] ?? null;

        return new static(
            operation: (string)($data[self::operation] ?? ''),
            initiatorAgentType: (string)($data[self::initiatorAgentType] ?? ''),
            initiatorAgentIndex: $agentIndex === null ? null : (int)$agentIndex,
            initiatorNodeId: (string)($data[self::initiatorNodeId] ?? ''),
        );
    }
}

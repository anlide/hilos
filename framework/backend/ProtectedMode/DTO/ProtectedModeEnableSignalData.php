<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * ProtectedModeEnableSignalData - initiator -> leader payload for PROTECTED_MODE_ENABLE.
 *
 * The initiator (the backup restore agent today, other operations later) is passive: it
 * asks the leader to freeze the cluster and identifies itself so the leader can leave it
 * running and let its connection through the lockdown. The leader records these fields on
 * {@see ProtectedModeRuntime}, drives the two-phase freeze, and
 * signals PROTECTED_MODE_READY back to this initiator once every node has quiesced.
 */
final class ProtectedModeEnableSignalData extends BaseDTO implements SignalDataInterface
{
    /** Payload key: the operation name the initiator will run. */
    public const string operation = 'operation';

    /** Payload key: the initiator connection's accept key. */
    public const string initiatorAcceptKey = 'initiatorAcceptKey';

    /** Payload key: the initiator agent type left running during the freeze. */
    public const string initiatorAgentType = 'initiatorAgentType';

    /** Payload key: the initiator agent index left running during the freeze. */
    public const string initiatorAgentIndex = 'initiatorAgentIndex';

    /** Payload key: the node id that hosts the initiator agent, or null off-cluster. */
    public const string initiatorNodeId = 'initiatorNodeId';

    /**
     * @param string $operation Operation the initiator will run under the freeze
     * @param ?string $initiatorAcceptKey Accept key of the initiator connection, or null when the
     *                                    freeze was asked for by something without a connection
     * @param string $initiatorAgentType Agent type left running during the freeze
     * @param ?int $initiatorAgentIndex Agent index, or null for a singleton agent
     * @param ?string $initiatorNodeId Node id that hosts the initiator agent, or null on a
     *                                 single-node installation, which has no node ids at all
     */
    public function __construct(
        public readonly string $operation,
        public readonly ?string $initiatorAcceptKey,
        public readonly string $initiatorAgentType,
        public readonly ?int $initiatorAgentIndex,
        public readonly ?string $initiatorNodeId,
    ) {
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::operation => $this->operation,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
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
        $nodeId = $data[self::initiatorNodeId] ?? null;
        $acceptKey = $data[self::initiatorAcceptKey] ?? null;

        return new static(
            operation: (string)($data[self::operation] ?? ''),
            initiatorAcceptKey: $acceptKey === null ? null : (string)$acceptKey,
            initiatorAgentType: (string)($data[self::initiatorAgentType] ?? ''),
            initiatorAgentIndex: $agentIndex === null ? null : (int)$agentIndex,
            initiatorNodeId: $nodeId === null ? null : (string)$nodeId,
        );
    }
}

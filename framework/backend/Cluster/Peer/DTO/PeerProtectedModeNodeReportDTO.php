<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/** Diagnostic snapshot only; the authenticated link supplies the sender identity. */
final class PeerProtectedModeNodeReportDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_protected_mode_node_report';
    public const string phase = 'phase';
    public const string term = 'term';
    public const string quiesceNodeIds = 'quiesceNodeIds';
    public const string pendingNodeIds = 'pendingNodeIds';
    public const string initiatorNodeId = 'initiatorNodeId';

    /**
     * @param ?string $phase Sender's own phase, null when it holds no runtime state
     * @param ?int $term Sender's consensus term, null without consensus inspection
     * @param ?list<string> $quiesceNodeIds Full current leader round, null outside a round
     * @param ?list<string> $pendingNodeIds Outstanding participants, null outside a round
     * @param ?string $initiatorNodeId Initiator named by the current leader, null outside a round
     * @throws PeerTransportException When the phase or participant lists are malformed
     */
    public function __construct(
        public readonly ?string $phase,
        public readonly ?int $term,
        public readonly ?array $quiesceNodeIds,
        public readonly ?array $pendingNodeIds,
        public readonly ?string $initiatorNodeId,
    ) {
        if ($phase !== null && !in_array($phase, [
            ProtectedModeRuntime::PHASE_INACTIVE,
            ProtectedModeRuntime::PHASE_ACTIVATING,
            ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::PHASE_VERIFYING,
            ProtectedModeRuntime::PHASE_DEACTIVATING,
        ], true)) {
            throw new PeerTransportException('Peer protected-mode node report carries an unknown phase');
        }
        self::validateNodeIds($quiesceNodeIds);
        self::validateNodeIds($pendingNodeIds);
        if ($pendingNodeIds !== null && ($quiesceNodeIds === null || array_diff($pendingNodeIds, $quiesceNodeIds) !== [])) {
            throw new PeerTransportException('Peer protected-mode pending nodes are not a subset of the participants');
        }
        if ($initiatorNodeId === '') {
            throw new PeerTransportException('Peer protected-mode initiator node id is empty');
        }
    }

    /** @return string Diagnostic message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Complete nullable wire snapshot */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::phase => $this->phase,
            self::term => $this->term,
            self::quiesceNodeIds => $this->quiesceNodeIds,
            self::pendingNodeIds => $this->pendingNodeIds,
            self::initiatorNodeId => $this->initiatorNodeId,
        ];
    }

    /**
     * @param array<string, mixed> $data Complete diagnostic frame
     * @return static Validated snapshot
     * @throws PeerTransportException When a nullable key is absent or the snapshot is malformed
     */
    public static function fromArray(array $data): static
    {
        foreach ([self::phase, self::term, self::quiesceNodeIds, self::pendingNodeIds, self::initiatorNodeId] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new PeerTransportException("Peer protected-mode node report is missing '{$field}'");
            }
        }
        try {
            return new static(
                self::optionalString($data, self::phase),
                self::optionalInt($data, self::term),
                self::optionalArray($data, self::quiesceNodeIds),
                self::optionalArray($data, self::pendingNodeIds),
                self::optionalString($data, self::initiatorNodeId),
            );
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException('Peer protected-mode node report is malformed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param ?array<array-key, mixed> $nodeIds Nullable participant list
     * @throws PeerTransportException When a list contains duplicate, empty or non-string ids
     */
    private static function validateNodeIds(?array $nodeIds): void
    {
        if ($nodeIds === null) {
            return;
        }
        if (!array_is_list($nodeIds)) {
            throw new PeerTransportException('Peer protected-mode node report carries a non-list of node ids');
        }
        foreach ($nodeIds as $nodeId) {
            if (!is_string($nodeId) || $nodeId === '') {
                throw new PeerTransportException('Peer protected-mode node report carries a malformed node id');
            }
        }
        if (count(array_unique($nodeIds)) !== count($nodeIds)) {
            throw new PeerTransportException('Peer protected-mode node report carries duplicate node ids');
        }
    }
}

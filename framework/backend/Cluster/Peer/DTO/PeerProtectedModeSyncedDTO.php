<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/** A slave's acknowledgement of one fully applied snapshot, on the requesting connection. */
final class PeerProtectedModeSyncedDTO extends PeerDTO
{
    public const string MESSAGE_TYPE = 'peer_protected_mode_synced';

    public const string FIELD_SYNC_ID = 'syncId';

    public const string FIELD_PHASE = 'phase';

    public const array PHASES = [
        ProtectedModeRuntime::PHASE_INACTIVE,
        ProtectedModeRuntime::PHASE_ACTIVATING,
        ProtectedModeRuntime::PHASE_ACTIVE,
        ProtectedModeRuntime::PHASE_VERIFYING,
        ProtectedModeRuntime::PHASE_DEACTIVATING,
    ];

    /**
     * @param string $syncId Request correlation, never an access credential
     * @param string $phase Fully applied target phase
     * @throws PeerTransportException When the correlation or phase is invalid
     */
    public function __construct(
        public readonly string $syncId,
        public readonly string $phase,
    ) {
        if (trim($syncId) === '' || !in_array($phase, self::PHASES, true)) {
            throw new PeerTransportException('Peer protected-mode synchronization requires a nonempty syncId and a known phase');
        }
    }

    /** @return string Wire message type */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /** @return array<string, mixed> Wire payload */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::FIELD_SYNC_ID => $this->syncId, self::FIELD_PHASE => $this->phase];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Validated acknowledgement
     * @throws PeerTransportException When the acknowledgement is malformed
     */
    public static function fromArray(array $data): static
    {
        try {
            return new static(self::requireString($data, self::FIELD_SYNC_ID), self::requireString($data, self::FIELD_PHASE));
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException('Peer protected-mode synced frame is malformed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}

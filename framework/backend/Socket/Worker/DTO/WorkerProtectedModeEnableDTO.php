<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeEnableDTO - worker -> daemon request to enter protected mode.
 *
 * The initiator agent (the backup restore agent today, other destructive operations later)
 * runs in a worker and cannot emit a peer frame itself, so it asks its own master daemon to
 * start the freeze. The worker builds the initiator identity into the carried
 * {@see ProtectedModeEnableSignalData} and sends this frame; the daemon hands the payload to
 * {@see ClusterProtectedMode::requestEnable()}, which either freezes the
 * cluster locally when this node leads or forwards the request to the current leader. The frame
 * is a thin transport envelope; the contract-gated field shape lives in the wrapped payload.
 */
class WorkerProtectedModeEnableDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the enable payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_ENABLE;

    /**
     * @param ProtectedModeEnableSignalData $data Initiator identity and operation the freeze protects
     */
    public function __construct(
        public readonly ProtectedModeEnableSignalData $data,
    ) {
    }

    /**
     * Get message type.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_PAYLOAD => $this->data->toArray(),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (payload)
     * @return static DTO instance
     * @throws InvalidArgumentException When the enable payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Worker protected-mode enable frame carries a non-object payload');
        }

        return new static(ProtectedModeEnableSignalData::fromArray($payload));
    }
}

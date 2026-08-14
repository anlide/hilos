<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeDisableDTO - worker -> daemon request to leave protected mode.
 *
 * The mirror of {@see WorkerProtectedModeEnableDTO}: once the initiator agent has finished its
 * destructive operation it asks its own master daemon to release the freeze. The daemon hands the
 * payload to {@see ProtectedModeSwitch::requestDisable()}, which on a single node authorizes the
 * release against the recorded initiator and lifts it, and in a cluster lifts locally when this
 * node leads or forwards to the current leader otherwise. The frame is a thin transport envelope;
 * the contract-gated field shape lives in the wrapped payload.
 */
class WorkerProtectedModeDisableDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the disable payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_DISABLE;

    /**
     * @param ProtectedModeDisableSignalData $data Identity of the agent asking for the release
     */
    public function __construct(
        public readonly ProtectedModeDisableSignalData $data,
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
     * @throws InvalidArgumentException When the disable payload is not an object
     * @throws InvalidFormatException When the disable payload names no initiator agent type
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Worker protected-mode disable frame carries a non-object payload');
        }

        return new static(ProtectedModeDisableSignalData::fromArray($payload));
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeCircleDTO - worker -> daemon delivery of the verifier circle photograph.
 *
 * The initiator agent reads the circle against the live connections while the node is frozen and
 * the database is still the old one, and sends the result on; the daemon hands the payload to
 * {@see ProtectedModeSwitch::requestCircle()}. The frame is a thin transport envelope; the
 * contract-gated field shape lives in the wrapped payload.
 */
class WorkerProtectedModeCircleDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the circle payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_CIRCLE;

    /**
     * @param ProtectedModeCircleSignalData $data Photographing agent identity and the circle it saw
     */
    public function __construct(
        public readonly ProtectedModeCircleSignalData $data,
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
     * @throws InvalidFormatException When the circle payload is not an object, or is malformed
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker protected-mode circle frame carries a non-object payload');
        }

        return new static(ProtectedModeCircleSignalData::fromArray($payload));
    }
}

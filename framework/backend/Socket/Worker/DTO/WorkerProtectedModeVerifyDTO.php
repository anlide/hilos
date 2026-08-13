<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeVerifyDTO - worker -> daemon request to open the verification window.
 *
 * The frame the initiator agent sends where it used to send {@see WorkerProtectedModeDisableDTO}:
 * its destructive operation is over, but the system opens to a hand-picked circle first rather
 * than to everyone. The daemon hands the payload to {@see ProtectedModeSwitch::requestVerify()}.
 * The frame is a thin transport envelope; the contract-gated field shape lives in the wrapped
 * payload.
 */
class WorkerProtectedModeVerifyDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the verify payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_VERIFY;

    /**
     * @param ProtectedModeVerifySignalData $data Identity of the agent asking for the window
     */
    public function __construct(
        public readonly ProtectedModeVerifySignalData $data,
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
     * @throws InvalidFormatException When the verify payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker protected-mode verify frame carries a non-object payload');
        }

        return new static(ProtectedModeVerifySignalData::fromArray($payload));
    }
}

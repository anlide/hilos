<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModePassDTO - worker -> daemon request to record one more verifier's pass.
 *
 * The initiator agent mints the key, prints it back to the operator and sends only its hash on
 * this frame; the daemon hands the payload to {@see ProtectedModeSwitch::requestPass()}. The
 * frame is a thin transport envelope; the contract-gated field shape lives in the wrapped payload.
 */
class WorkerProtectedModePassDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the pass payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_PASS;

    /**
     * @param ProtectedModePassSignalData $data Minting agent identity and the hash of the pass
     */
    public function __construct(
        public readonly ProtectedModePassSignalData $data,
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
     * @throws InvalidFormatException When the pass payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker protected-mode pass frame carries a non-object payload');
        }

        return new static(ProtectedModePassSignalData::fromArray($payload));
    }
}

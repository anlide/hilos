<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeRefreezeDTO - worker -> daemon request to close the system again.
 *
 * The mirror of {@see WorkerProtectedModeVerifyDTO}: the verifiers found something wrong, so the
 * operator closes the verification window back to a full freeze instead of opening it to
 * everyone. The daemon hands the payload to {@see ProtectedModeSwitch::requestRefreeze()}. The
 * frame is a thin transport envelope; the contract-gated field shape lives in the wrapped payload.
 */
class WorkerProtectedModeRefreezeDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the refreeze payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_REFREEZE;

    /**
     * @param ProtectedModeRefreezeSignalData $data Identity of the agent asking to close back
     */
    public function __construct(
        public readonly ProtectedModeRefreezeSignalData $data,
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
     * @throws InvalidFormatException When the refreeze payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker protected-mode refreeze frame carries a non-object payload');
        }

        return new static(ProtectedModeRefreezeSignalData::fromArray($payload));
    }
}

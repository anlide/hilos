<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerProtectedModeProgressDTO - worker -> daemon mark that the frozen operation moved.
 *
 * The frame an initiator sends while its destructive operation runs, as often as that operation
 * has something to show for itself. The daemon hands the payload to
 * {@see ProtectedModeSwitch::requestProgress()}, which stamps the freeze row. Like its neighbours
 * the frame is a thin transport envelope; the contract-gated field shape lives in the wrapped
 * payload, and the moment of the progress is deliberately not in it - the master stamps that.
 */
class WorkerProtectedModeProgressDTO extends WorkerDTO
{
    /** @var string Envelope key carrying the progress payload */
    public const string FIELD_PAYLOAD = 'payload';

    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_PROTECTED_MODE_PROGRESS;

    /**
     * @param ProtectedModeProgressSignalData $data Identity of the agent reporting the progress
     */
    public function __construct(
        public readonly ProtectedModeProgressSignalData $data,
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
     * @throws InvalidFormatException When the progress payload is not an object
     */
    public static function fromArray(array $data): static
    {
        $payload = $data[self::FIELD_PAYLOAD] ?? [];
        if (!is_array($payload)) {
            throw new InvalidFormatException('Worker protected-mode progress frame carries a non-object payload');
        }

        return new static(ProtectedModeProgressSignalData::fromArray($payload));
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO;

use Hilos\Core\Router\SignalSourceInterface;

/**
 * SignalDTO - DTO for queued signal
 *
 * Represents a signal queued for dispatch with source, type, name and data.
 */
class SignalDTO extends BaseDTO
{
    public function __construct(
        public readonly SignalSourceInterface $signalSource,
        public readonly string $signalType,
        public readonly string $signalName,
        public readonly SignalDataDTO $data,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            'signalSource' => $this->signalSource,
            'signalType' => $this->signalType,
            'signalName' => $this->signalName,
            'data' => $this->data,
        ];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            signalSource: $data['signalSource'],
            signalType: $data['signalType'] ?? '',
            signalName: $data['signalName'] ?? '',
            data: $data['data'],
        );
    }
}

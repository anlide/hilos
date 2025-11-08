<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO;

use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalName;

/**
 * SignalDTO - DTO for queued signal
 *
 * Represents a signal queued for dispatch with source, type, name and data.
 */
class SignalDTO extends BaseDTO
{
    public function __construct(
        public readonly SignalSourceInterface $signalSource,
        public readonly SignalTypeInterface $signalType,
        public readonly SignalNameInterface $signalName,
        public readonly SignalDataInterface $data,
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
            'signalType' => $this->signalType->getType(),
            'signalName' => $this->signalName->getName(),
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
        $signalSource = $data['signalSource'] instanceof SignalSourceInterface
            ? $data['signalSource']
            : new SignalSource($data['signalSource'] ?? '');

        $signalType = $data['signalType'] instanceof SignalTypeInterface
            ? $data['signalType']
            : new SignalType($data['signalType'] ?? '');

        $signalName = $data['signalName'] instanceof SignalNameInterface
            ? $data['signalName']
            : new SignalName($data['signalName'] ?? '');

        return new self(
            signalSource: $signalSource,
            signalType: $signalType,
            signalName: $signalName,
            data: $data['data'],
        );
    }
}

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
use Hilos\Core\Router\SignalData;

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
        $dataArray = $this->data instanceof BaseDTO
            ? $this->data->toArray()
            : $this->data;

        // Serialize signalSource
        $signalSourceArray = $this->signalSource instanceof SignalSourceInterface
            ? [
                'source' => $this->signalSource->getSource(),
                'type' => $this->signalSource->getType(),
                'index' => $this->signalSource->getIndex(),
            ]
            : $this->signalSource;

        return [
            'signalSource' => $signalSourceArray,
            'signalType' => $this->signalType->getType(),
            'signalName' => $this->signalName->getName(),
            'data' => $dataArray,
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
        // Deserialize signalSource
        $signalSourceData = $data['signalSource'] ?? [];
        if ($signalSourceData instanceof SignalSourceInterface) {
            $signalSource = $signalSourceData;
        } elseif (is_array($signalSourceData)) {
            // From JSON deserialization - array with source, type, index
            $signalSource = new SignalSource(
                source: $signalSourceData['source'] ?? '',
                type: $signalSourceData['type'] ?? null,
                index: $signalSourceData['index'] ?? null,
            );
        } else {
            // Fallback: treat as string
            $signalSource = new SignalSource((string)$signalSourceData);
        }

        $signalType = $data['signalType'] instanceof SignalTypeInterface
            ? $data['signalType']
            : new SignalType($data['signalType'] ?? '');

        $signalName = $data['signalName'] instanceof SignalNameInterface
            ? $data['signalName']
            : new SignalName($data['signalName'] ?? '');

        $signalData = $data['data'] ?? [];
        if (!($signalData instanceof SignalDataInterface)) {
            // If data is an array (from JSON deserialization), create empty SignalData
            // In practice, specific SignalData DTOs should be created based on signal type
            $signalData = new SignalData();
        }

        return new self(
            signalSource: $signalSource,
            signalType: $signalType,
            signalName: $signalName,
            data: $signalData,
        );
    }
}

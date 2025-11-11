<?php

declare(strict_types=1);

namespace Hilos\DTO;

use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;

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
        // Serialize signalData - use toArray() from BaseDTO
        $dataArray = $this->data instanceof BaseDTO
            ? $this->data->toArray()
            : [];

        // Store data class name for deserialization
        $dataType = get_class($this->data);

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
            'dataType' => $dataType,
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

        // Deserialize signalData
        $dataArray = $data['data'] ?? [];
        $dataType = $data['dataType'] ?? null;
        $signalData = self::deserializeSignalData($dataArray, $dataType);

        return new self(
            signalSource: $signalSource,
            signalType: $signalType,
            signalName: $signalName,
            data: $signalData,
        );
    }

    /**
     * Deserialize signal data from array
     *
     * @param array $dataArray Signal data array
     * @param ?string $dataType Signal data class name
     * @return SignalDataInterface Deserialized signal data
     */
    private static function deserializeSignalData(array $dataArray, ?string $dataType): SignalDataInterface
    {
        // If dataType is provided, try to deserialize using that class
        if (is_string($dataType) && class_exists($dataType)) {
            // Check if class implements SignalDataInterface
            if (is_a($dataType, SignalDataInterface::class, true)) {
                // All SignalDataInterface implementations extend BaseDTO and have fromArray()
                try {
                    return $dataType::fromArray($dataArray);
                } catch (\Throwable $e) {
                    // If deserialization fails, fall back to SignalData with data
                    // Some SignalData implementations may not support fromArray
                }
            }
        }

        // Fallback: create SignalData with provided data array
        return SignalData::fromArray($dataArray);
    }
}

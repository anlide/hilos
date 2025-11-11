<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\DTO\BaseDTO;

/**
 * WebSocketSignalData - Signal data for WebSocket signals
 *
 * Contains both the actual signal data and targeting metadata
 * (targetClientId, targetGroup, excludeClientId).
 */
class WebSocketSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly SignalDataInterface $data,
        public readonly ?string $targetClientId = null,
        public readonly ?string $targetGroup = null,
        public readonly ?string $excludeClientId = null,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        #var_dump($this);
        $dataArray = $this->data instanceof BaseDTO
            ? $this->data->toArray()
            : [];

        // Store data class name for deserialization
        $dataType = get_class($this->data);

        $result = [
            'data' => $dataArray,
            'dataType' => $dataType,
        ];

        if ($this->targetClientId !== null) {
            $result['targetClientId'] = $this->targetClientId;
        }

        if ($this->targetGroup !== null) {
            $result['targetGroup'] = $this->targetGroup;
        }

        if ($this->excludeClientId !== null) {
            $result['excludeClientId'] = $this->excludeClientId;
        }

        return $result;
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $innerDataArray = $data['data'] ?? [];
        $dataType = $data['dataType'] ?? null;

        // Deserialize inner data using dataType if provided
        $signalData = self::deserializeInnerData($innerDataArray, $dataType);

        return new self(
            data: $signalData,
            targetClientId: $data['targetClientId'] ?? null,
            targetGroup: $data['targetGroup'] ?? null,
            excludeClientId: $data['excludeClientId'] ?? null,
        );
    }

    /**
     * Deserialize inner signal data from array
     *
     * @param array $dataArray Signal data array
     * @param ?string $dataType Signal data class name
     * @return SignalDataInterface Deserialized signal data
     */
    private static function deserializeInnerData(array $dataArray, ?string $dataType): SignalDataInterface
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
        return new SignalData($dataArray);
    }
}

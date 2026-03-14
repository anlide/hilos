<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;

/**
 * WebSocketSignalData - Signal data for WebSocket signals.
 *
 * Contains both the actual signal data and targeting metadata
 * (targetAcceptKey, targetGroup, excludeAcceptKey).
 */
class WebSocketSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates WebSocket signal data with targeting metadata.
     *
     * @param SignalDataInterface $data Inner signal payload
     * @param ?string $targetAcceptKey Target connection accept key (user delivery)
     * @param ?string $targetGroup Target group name (group delivery)
     * @param ?string $excludeAcceptKey Accept key to exclude from broadcast
     */
    public function __construct(
        public readonly SignalDataInterface $data,
        public readonly ?string $targetAcceptKey = null,
        public readonly ?string $targetGroup = null,
        public readonly ?string $excludeAcceptKey = null,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with data, dataType, optional target keys
     */
    public function toArray(): array
    {
        $dataArray = $this->data instanceof BaseDTO
            ? $this->data->toArray()
            : [];

        // Store data class name for deserialization
        $dataType = get_class($this->data);

        $result = [
            'data' => $dataArray,
            'dataType' => $dataType,
        ];

        if ($this->targetAcceptKey !== null) {
            $result['targetAcceptKey'] = $this->targetAcceptKey;
        }

        if ($this->targetGroup !== null) {
            $result['targetGroup'] = $this->targetGroup;
        }

        if ($this->excludeAcceptKey !== null) {
            $result['excludeAcceptKey'] = $this->excludeAcceptKey;
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
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
            targetAcceptKey: $data['targetAcceptKey'] ?? null,
            targetGroup: $data['targetGroup'] ?? null,
            excludeAcceptKey: $data['excludeAcceptKey'] ?? null,
        );
    }

    /**
     * Deserializes inner signal data from array.
     *
     * @param array<string, mixed> $dataArray Signal data array
     * @param ?class-string $dataType Signal data class name for deserialization
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

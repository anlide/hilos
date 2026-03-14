<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;

/**
 * AgentSignalData - Signal data container for agent-to-agent signals.
 *
 * Wraps the actual payload (e.g. ModerationRequestSignalData) for delivery to target agent.
 * Similar to WebSocketSignalData for WebSocket signals.
 */
class AgentSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates agent signal data with inner payload.
     *
     * @param SignalDataInterface $data Inner signal payload (e.g. ModerationRequestSignalData)
     */
    public function __construct(
        public readonly SignalDataInterface $data,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with data and dataType keys
     */
    public function toArray(): array
    {
        $dataArray = $this->data instanceof BaseDTO
            ? $this->data->toArray()
            : [];

        return [
            'data' => $dataArray,
            'dataType' => get_class($this->data),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (data, dataType keys)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $innerDataArray = $data['data'] ?? [];
        $dataType = $data['dataType'] ?? null;

        $signalData = self::deserializeInnerData($innerDataArray, $dataType);

        return new self(data: $signalData);
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
        if (is_string($dataType) && class_exists($dataType)) {
            if (is_a($dataType, SignalDataInterface::class, true)) {
                try {
                    return $dataType::fromArray($dataArray);
                } catch (\Throwable $e) {
                    // Fall through to fallback
                }
            }
        }

        return new SignalData($dataArray);
    }
}

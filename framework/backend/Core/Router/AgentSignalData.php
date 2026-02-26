<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;

/**
 * AgentSignalData - Signal data container for agent-to-agent signals
 *
 * Wraps the actual payload (e.g. ModerationRequestSignalData) for delivery to target agent.
 * Similar to WebSocketSignalData for WebSocket signals.
 */
class AgentSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
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
            : [];

        return [
            'data' => $dataArray,
            'dataType' => get_class($this->data),
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
        $innerDataArray = $data['data'] ?? [];
        $dataType = $data['dataType'] ?? null;

        $signalData = self::deserializeInnerData($innerDataArray, $dataType);

        return new self(data: $signalData);
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

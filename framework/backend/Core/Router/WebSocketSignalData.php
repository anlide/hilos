<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;

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
        $result = SignalDataEnvelope::encode($this->data);

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
        return new static(
            data: SignalDataEnvelope::decode(
                $data[SignalPayloadConstants::FIELD_DATA] ?? [],
                $data[SignalPayloadConstants::FIELD_DATA_TYPE] ?? null,
            ),
            targetAcceptKey: $data['targetAcceptKey'] ?? null,
            targetGroup: $data['targetGroup'] ?? null,
            excludeAcceptKey: $data['excludeAcceptKey'] ?? null,
        );
    }
}

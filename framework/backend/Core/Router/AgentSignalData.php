<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * AgentSignalData - Signal data container for agent-to-agent signals.
 *
 * Wraps the actual payload (e.g. ModerationRequestSignalData) for delivery to target agent.
 * Similar to WebSocketSignalData for WebSocket signals.
 */
class AgentSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
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
     * Accept key from the inner payload, or empty string when it carries none.
     *
     * @return string Inner payload accept key, or empty string
     */
    public function getAcceptKey(): string
    {
        return $this->data instanceof WebSocketAcceptKeySignalDTO ? $this->data->getAcceptKey() : '';
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with data and dataType keys
     */
    public function toArray(): array
    {
        return SignalDataEnvelope::encode($this->data);
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (data, dataType keys)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            data: SignalDataEnvelope::decode(
                $data[SignalPayloadConstants::FIELD_DATA] ?? [],
                $data[SignalPayloadConstants::FIELD_DATA_TYPE] ?? null,
            ),
        );
    }
}

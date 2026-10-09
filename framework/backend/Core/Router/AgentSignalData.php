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
     * @param ?int $receiptId Journal receipt of the action that sent this signal
     */
    public function __construct(
        public readonly SignalDataInterface $data,
        public readonly ?int $receiptId = null,
    ) {
    }

    /**
     * Accept key from the inner payload.
     *
     * @return ?string Inner payload accept key, or null when it carries none
     */
    public function getAcceptKey(): ?string
    {
        return $this->data instanceof WebSocketAcceptKeySignalDTO ? $this->data->getAcceptKey() : null;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data with data, dataType, and optional receipt keys
     */
    public function toArray(): array
    {
        $envelope = SignalDataEnvelope::encode($this->data);
        if ($this->receiptId !== null) {
            $envelope[SignalPayloadConstants::FIELD_RECEIPT] = $this->receiptId;
        }

        return $envelope;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (data, dataType, optional receipt keys)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            data: SignalDataEnvelope::decode(
                $data[SignalPayloadConstants::FIELD_DATA] ?? [],
                $data[SignalPayloadConstants::FIELD_DATA_TYPE] ?? null,
            ),
            receiptId: is_int($data[SignalPayloadConstants::FIELD_RECEIPT] ?? null)
                && $data[SignalPayloadConstants::FIELD_RECEIPT] > 0
                    ? $data[SignalPayloadConstants::FIELD_RECEIPT]
                    : null,
        );
    }
}

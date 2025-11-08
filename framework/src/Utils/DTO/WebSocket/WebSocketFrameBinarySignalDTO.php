<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\WebSocket;

use Hilos\Utils\DTO\BaseDTO;
use Hilos\Utils\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketFrameBinarySignalDTO - DTO for WebSocket binary frame signal
 *
 * Represents a WebSocket binary frame signal sent from WebSocket client.
 */
class WebSocketFrameBinarySignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string PAYLOAD = 'payload';

    public function __construct(
        public readonly string $clientId,
        public readonly string $payload,
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
            self::CLIENT_ID => $this->clientId,
            self::PAYLOAD => $this->payload,
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
        return new self(
            clientId: $data[self::CLIENT_ID] ?? '',
            payload: $data[self::PAYLOAD] ?? '',
        );
    }
}


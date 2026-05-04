<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal.
 *
 * Represents an action signal sent from WebSocket client.
 */
class WebSocketActionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string ACTION = 'action';
    public const string DATA = 'data';

    /**
     * Creates WebSocket action signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $action Action name
     * @param array<string, mixed> $data Action payload data
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly array $data = [],
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::ACCEPT_KEY => $this->acceptKey,
            self::ACTION => $this->action,
        ];

        if (!empty($this->data)) {
            $result[self::DATA] = $this->data;
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
        return new self(
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
        );
    }
}

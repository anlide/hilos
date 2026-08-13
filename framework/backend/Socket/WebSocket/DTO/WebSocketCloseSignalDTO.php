<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketCloseSignalDTO - DTO for WebSocket close signal.
 *
 * Represents a WebSocket close signal sent from WebSocket client.
 */
class WebSocketCloseSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';

    /**
     * Creates WebSocket close signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function __construct(
        public readonly string $acceptKey,
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::ACCEPT_KEY => $this->acceptKey,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no accept key
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
        );
    }
}

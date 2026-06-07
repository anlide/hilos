<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketGroupUnsubscribeSignalDTO - DTO for WebSocket group unsubscribe signal.
 *
 * Represents a group unsubscribe signal sent from WebSocket client.
 */
class WebSocketGroupUnsubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string GROUP = 'group';

    /**
     * Creates group unsubscribe signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $group Group name
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $group = '',
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
        ];

        if ($this->group !== '') {
            $result[self::GROUP] = $this->group;
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
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            group: $data[self::GROUP] ?? '',
        );
    }
}

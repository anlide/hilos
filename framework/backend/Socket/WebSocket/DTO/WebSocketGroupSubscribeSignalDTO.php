<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketGroupSubscribeSignalDTO - DTO for WebSocket group subscribe signal.
 *
 * Represents a group subscription signal sent from WebSocket client.
 */
class WebSocketGroupSubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string GROUP = 'group';
    public const string PARAMS = 'params';

    /**
     * Creates group subscribe signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $group Group name, null when the signal name carries it
     * @param array<string, string> $params Route params
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $group = null,
        public readonly array $params = [],
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

        if ($this->group !== null) {
            $result[self::GROUP] = $this->group;
        }

        if (!empty($this->params)) {
            $result[self::PARAMS] = $this->params;
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
        $group = $data[self::GROUP] ?? null;

        return new static(
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            group: $group === '' ? null : $group,
            params: $data[self::PARAMS] ?? [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketPageSubscribeSignalDTO - DTO for WebSocket page subscribe signal.
 *
 * Represents a page subscription signal sent from WebSocket client.
 */
class WebSocketPageSubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string PARAMS = 'params';

    /**
     * Creates page subscribe signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $page Page name
     * @param array<string, string> $params Route params
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $page = '',
        public readonly array $params = [],
    ) {
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

        if ($this->page !== '') {
            $result[self::PAGE] = $this->page;
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
        return new self(
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            page: $data[self::PAGE] ?? '',
            params: $data[self::PARAMS] ?? [],
        );
    }
}

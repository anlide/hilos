<?php

declare(strict_types=1);

namespace Hilos\DTO\WebSocket;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\SignalDataDTO;

/**
 * WebSocketPageSubscribeSignalDTO - DTO for WebSocket page subscribe signal
 *
 * Represents a page subscription signal sent from WebSocket client.
 */
class WebSocketPageSubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string PARAMS = 'params';

    public function __construct(
        public readonly string $acceptKey,
        public readonly string $page = '',
        public readonly array $params = [],
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
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
     * Create DTO from array
     *
     * @param array $data Source data
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

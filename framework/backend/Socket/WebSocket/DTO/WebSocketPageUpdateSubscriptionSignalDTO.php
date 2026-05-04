<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketPageUpdateSubscriptionSignalDTO - DTO for WebSocket page update subscription signal.
 *
 * Represents a page subscription update signal sent from WebSocket client.
 */
class WebSocketPageUpdateSubscriptionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string PARAMS = 'params';

    /**
     * Creates page update subscription signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $page Page name to update subscription for
     * @param array<string, string> $params Route params for the page
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $page = '',
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

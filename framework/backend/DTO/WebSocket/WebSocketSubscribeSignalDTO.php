<?php

declare(strict_types=1);

namespace Hilos\DTO\WebSocket;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\SignalDataDTO;

/**
 * WebSocketSubscribeSignalDTO - DTO for WebSocket subscribe signal
 *
 * Represents a subscription signal sent from WebSocket client.
 */
class WebSocketSubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string GROUPS = 'groups';
    public const string PARAMS = 'params';

    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly array $groups = [],
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

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        if (!empty($this->groups)) {
            $result[self::GROUPS] = $this->groups;
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
            page: $data[self::PAGE] ?? null,
            groups: $data[self::GROUPS] ?? [],
            params: $data[self::PARAMS] ?? [],
        );
    }
}

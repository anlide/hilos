<?php

declare(strict_types=1);

namespace Hilos\DTO\WebSocket;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\SignalDataDTO;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal
 *
 * Represents an action signal sent from WebSocket client.
 */
class WebSocketActionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string PAGE = 'page';
    public const string GROUP = 'group';
    public const string ACTION = 'action';
    public const string DATA = 'data';

    public function __construct(
        public readonly string $clientId,
        public readonly string $action,
        public readonly array $data = [],
        public readonly ?string $page = null,
        public readonly ?string $group = null,
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
            self::CLIENT_ID => $this->clientId,
            self::ACTION => $this->action,
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        if ($this->group !== null) {
            $result[self::GROUP] = $this->group;
        }

        if (!empty($this->data)) {
            $result[self::DATA] = $this->data;
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
            clientId: $data[self::CLIENT_ID] ?? '',
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
            page: $data[self::PAGE] ?? null,
            group: $data[self::GROUP] ?? null,
        );
    }
}


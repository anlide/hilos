<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO\WebSocket;

use Hilos\Utils\DTO\BaseDTO;
use Hilos\Utils\DTO\SignalDataDTO;

/**
 * WebSocketUnsubscribeSignalDTO - DTO for WebSocket unsubscribe signal
 *
 * Represents an unsubscribe signal sent from WebSocket client.
 */
class WebSocketUnsubscribeSignalDTO extends BaseDTO implements SignalDataDTO
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string PAGE = 'page';
    public const string GROUPS = 'groups';

    public function __construct(
        public readonly string $clientId,
        public readonly bool $page = false,
        public readonly array $groups = [],
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
        ];

        if ($this->page) {
            $result[self::PAGE] = true;
        }

        if (!empty($this->groups)) {
            $result[self::GROUPS] = $this->groups;
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
            page: $data[self::PAGE] ?? false,
            groups: $data[self::GROUPS] ?? [],
        );
    }
}


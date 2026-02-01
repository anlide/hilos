<?php

declare(strict_types=1);

namespace Hilos\DTO\WebSocket;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\SignalDataDTO;

/**
 * WebSocketUnsubscribeSignalDTO - DTO for WebSocket unsubscribe signal
 *
 * Represents an unsubscribe signal sent from WebSocket client.
 */
class WebSocketUnsubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string GROUPS = 'groups';

    public function __construct(
        public readonly string $acceptKey,
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
            self::ACCEPT_KEY => $this->acceptKey,
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
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            page: $data[self::PAGE] ?? false,
            groups: $data[self::GROUPS] ?? [],
        );
    }
}

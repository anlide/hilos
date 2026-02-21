<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketGroupUpdateSubscriptionSignalDTO - DTO for WebSocket group update subscription signal
 *
 * Represents a group subscription update signal sent from WebSocket client.
 */
class WebSocketGroupUpdateSubscriptionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string GROUP = 'group';
    public const string PARAMS = 'params';

    public function __construct(
        public readonly string $acceptKey,
        public readonly string $group = '',
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

        if ($this->group !== '') {
            $result[self::GROUP] = $this->group;
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
            group: $data[self::GROUP] ?? '',
            params: $data[self::PARAMS] ?? [],
        );
    }
}

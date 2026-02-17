<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Hilos\BaseDTO;

/**
 * WebSocketGroupUnsubscribeSignalDTO - DTO for WebSocket group unsubscribe signal
 *
 * Represents a group unsubscribe signal sent from WebSocket client.
 */
class WebSocketGroupUnsubscribeSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string GROUP = 'group';

    public function __construct(
        public readonly string $acceptKey,
        public readonly string $group = '',
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
        );
    }
}

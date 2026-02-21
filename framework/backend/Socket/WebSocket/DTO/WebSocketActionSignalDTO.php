<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * WebSocketActionSignalDTO - DTO for WebSocket action signal
 *
 * Represents an action signal sent from WebSocket client.
 */
class WebSocketActionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string ACTION = 'action';
    public const string DATA = 'data';

    public function __construct(
        public readonly string $acceptKey,
        public readonly string $action,
        public readonly array $data = [],
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
            self::ACTION => $this->action,
        ];

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
            acceptKey: $data[self::ACCEPT_KEY] ?? '',
            action: $data[self::ACTION] ?? '',
            data: $data[self::DATA] ?? [],
        );
    }
}

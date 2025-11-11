<?php

declare(strict_types=1);

namespace Hilos\DTO\WebSocket;

use Hilos\Core\Router\SignalDataInterface;
use Hilos\DTO\BaseDTO;
use Hilos\DTO\SignalDataDTO;

/**
 * WebSocketUpdateSubscriptionSignalDTO - DTO for WebSocket update subscription signal
 *
 * Represents an update subscription signal sent from WebSocket client.
 */
class WebSocketUpdateSubscriptionSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface
{
    // Field name constants
    public const string CLIENT_ID = 'clientId';
    public const string PAGE = 'page';
    public const string GROUPS = 'groups';

    public function __construct(
        public readonly string $clientId,
        public readonly ?string $page = null,
        public readonly ?array $groups = null,
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

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        if ($this->groups !== null) {
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
            page: $data[self::PAGE] ?? null,
            groups: $data[self::GROUPS] ?? null,
        );
    }
}
